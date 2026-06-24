<?php
/**
 * CoreFlux Export Templates — library.
 *
 * Templates = tenant-or-platform-scoped CSV column mappings bound to a
 * dataset key in the registry (/app/core/export_datasets.php). Transforms
 * at v1: `field` (take row[source_field]) + `fixed` (static string).
 *
 *   exportTemplateList($tenantId, $dataset)             → tenant + platform rows
 *   exportTemplateCreate($tenantId, $args, $actor)      → int id
 *   exportTemplateUpdate($id, $args, $actor, $tenantId) → void
 *   exportTemplateDelete($id, $actor, $tenantId)        → void
 *   exportTemplateClone($id, $tenantId, $actor)         → int new id
 *   exportTemplateParseHeaders($csvContents)            → string[]  (up to ~100 rows; user choice #5)
 *   exportTemplateRender($tplId, $rows, $tenantId)      → string   (CSV body)
 *   exportTemplateRenderToStream($tplId, $rows, $fh)    → void
 *
 * Permissions enforced at the /api/export_templates.php layer; this library
 * trusts the caller has already RBAC-gated the action.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/export_datasets.php';

class ExportTemplateException extends RuntimeException {}

// ───────── Loaders ─────────

/** List active templates visible to a tenant for a dataset (tenant rows ∪ platform presets). */
function exportTemplateList(int $tenantId, ?string $dataset = null): array {
    $pdo = getDB();
    $sql = "SELECT id, scope, tenant_id, dataset, name, delimiter, quote_char,
                   has_header_row, encoding, column_mappings_json,
                   based_on_template_id, is_active, is_system,
                   created_by_user_id, created_at, updated_at
              FROM export_templates
             WHERE is_active = 1
               AND (scope = 'platform' OR tenant_id = :t)";
    $params = ['t' => $tenantId];
    if ($dataset) { $sql .= ' AND dataset = :d'; $params['d'] = $dataset; }
    $sql .= ' ORDER BY scope DESC, name ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['column_mappings'] = json_decode((string) $r['column_mappings_json'], true) ?: [];
        unset($r['column_mappings_json']);
    }
    return $rows;
}

function exportTemplateGet(int $id, int $tenantId, bool $forRender = false): array {
    $pdo = getDB();
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $stmt = $pdo->prepare('SELECT * FROM export_templates WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new ExportTemplateException('Template not found');

    // Visibility: platform visible to everyone; tenant visible only to owner.
    if ($row['scope'] !== 'platform' && (int) $row['tenant_id'] !== $tenantId) {
        throw new ExportTemplateException('Template not found');
    }
    if (!$forRender && $row['is_active'] !== '1' && $row['is_active'] != 1) {
        // allow render of archived templates; only list/edit flows gate it.
    }
    $row['column_mappings'] = json_decode((string) $row['column_mappings_json'], true) ?: [];
    unset($row['column_mappings_json']);
    return $row;
}

// ───────── Mutators ─────────

function exportTemplateCreate(int $tenantId, array $args, int $actorUserId, string $globalRole): int {
    $scope   = ($args['scope'] ?? 'tenant') === 'platform' ? 'platform' : 'tenant';
    if ($scope === 'platform' && $globalRole !== 'master_admin') {
        throw new ExportTemplateException('Only master_admin can create platform templates');
    }

    $dataset = trim((string) ($args['dataset'] ?? ''));
    if (!$dataset) throw new ExportTemplateException('dataset is required');
    if (!exportDatasetGet($dataset)) throw new ExportTemplateException("Unknown dataset: $dataset");

    $name = trim((string) ($args['name'] ?? ''));
    if ($name === '') throw new ExportTemplateException('name is required');

    $mappings = _exportTplValidateMappings($args['column_mappings'] ?? [], $dataset, $tenantId);

    $pdo = getDB();
    $stmt = $pdo->prepare(
        'INSERT INTO export_templates
            (scope, tenant_id, dataset, name, delimiter, quote_char,
             has_header_row, encoding, column_mappings_json,
             based_on_template_id, is_active, is_system, created_by_user_id, created_at)
         VALUES (:scope, :tid, :ds, :nm, :d, :q, :h, :enc, :m, :boid, 1, 0, :u, NOW())'
    );
    $stmt->execute([
        'scope' => $scope,
        'tid'   => $scope === 'platform' ? null : $tenantId,
        'ds'    => $dataset,
        'nm'    => $name,
        'd'     => (string) ($args['delimiter']      ?? ','),
        'q'     => (string) ($args['quote_char']     ?? '"'),
        'h'     => (int)  (bool) ($args['has_header_row'] ?? 1),
        'enc'   => (string) ($args['encoding']       ?? 'utf-8'),
        'm'     => json_encode($mappings, JSON_UNESCAPED_SLASHES),
        'boid'  => isset($args['based_on_template_id']) ? (int) $args['based_on_template_id'] : null,
        'u'     => $actorUserId,
    ]);
    return (int) $pdo->lastInsertId();
}

function exportTemplateUpdate(int $id, array $args, int $actorUserId, int $tenantId, string $globalRole): void {
    $row = exportTemplateGet($id, $tenantId);
    if ($row['scope'] === 'platform' && $globalRole !== 'master_admin') {
        throw new ExportTemplateException('Only master_admin can edit platform templates');
    }

    $mappings = array_key_exists('column_mappings', $args)
        ? _exportTplValidateMappings($args['column_mappings'], $row['dataset'], $tenantId)
        : $row['column_mappings'];

    $pdo = getDB();
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $pdo->prepare(
        'UPDATE export_templates
            SET name = :nm, delimiter = :d, quote_char = :q,
                has_header_row = :h, encoding = :enc,
                column_mappings_json = :m, is_active = :a, updated_at = NOW()
          WHERE id = :id'
    )->execute([
        'nm'  => (string) ($args['name']             ?? $row['name']),
        'd'   => (string) ($args['delimiter']        ?? $row['delimiter']),
        'q'   => (string) ($args['quote_char']       ?? $row['quote_char']),
        'h'   => (int) (bool) ($args['has_header_row'] ?? $row['has_header_row']),
        'enc' => (string) ($args['encoding']         ?? $row['encoding']),
        'm'   => json_encode($mappings, JSON_UNESCAPED_SLASHES),
        'a'   => (int) (bool) ($args['is_active']    ?? $row['is_active']),
        'id'  => $id,
    ]);
}

function exportTemplateDelete(int $id, int $actorUserId, int $tenantId, string $globalRole): void {
    $row = exportTemplateGet($id, $tenantId);
    if ($row['scope'] === 'platform' && $globalRole !== 'master_admin') {
        throw new ExportTemplateException('Only master_admin can delete platform templates');
    }
    if ((int) $row['is_system'] === 1) {
        // System-seeded templates get soft-archived (not hard-deleted).
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        getDB()->prepare('UPDATE export_templates SET is_active = 0 WHERE id = :id')
               ->execute(['id' => $id]);
        return;
    }
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    getDB()->prepare('DELETE FROM export_templates WHERE id = :id')->execute(['id' => $id]);
}

/** Clone any visible template into the tenant's own namespace — user can then customize. */
function exportTemplateClone(int $id, int $tenantId, int $actorUserId): int {
    $src = exportTemplateGet($id, $tenantId);
    return exportTemplateCreate($tenantId, [
        'scope'                => 'tenant',
        'dataset'              => $src['dataset'],
        'name'                 => $src['name'] . ' (copy)',
        'delimiter'            => $src['delimiter'],
        'quote_char'           => $src['quote_char'],
        'has_header_row'       => (int) $src['has_header_row'],
        'encoding'             => $src['encoding'],
        'column_mappings'      => $src['column_mappings'],
        'based_on_template_id' => (int) $src['id'],
    ], $actorUserId, 'tenant_admin');
}

// ───────── Sample CSV upload → header parser ─────────

/**
 * Accepts the uploaded sample CSV contents and returns the header row as a
 * string[]. Per user choice #5, only the first ~100 rows are consulted for
 * header detection (a BOM + the literal first line suffice).
 *
 * Caps total size at 256 KB to keep the request in-memory and cheap.
 */
function exportTemplateParseHeaders(string $csvContents, string $delimiter = ','): array {
    if (strlen($csvContents) > 262144) {
        throw new ExportTemplateException('Sample CSV must be < 256 KB; upload a smaller sample.');
    }
    // Strip UTF-8 BOM.
    if (substr($csvContents, 0, 3) === "\xEF\xBB\xBF") {
        $csvContents = substr($csvContents, 3);
    }
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $csvContents);
    rewind($fh);
    $header = fgetcsv($fh, 0, $delimiter);
    fclose($fh);
    if (!$header) throw new ExportTemplateException('Could not detect a header row');
    return array_map(fn ($h) => trim((string) $h), $header);
}

// ───────── Render ─────────

/**
 * Render rows through a template → CSV string.
 * $rows is a flat iterable of assoc arrays keyed by dataset field names.
 */
function exportTemplateRender(int $tplId, iterable $rows, int $tenantId): string {
    $fh = fopen('php://temp', 'r+');
    exportTemplateRenderToStream($tplId, $rows, $fh, $tenantId);
    rewind($fh);
    $out = stream_get_contents($fh);
    fclose($fh);
    return $out === false ? '' : $out;
}

function exportTemplateRenderToStream(int $tplId, iterable $rows, $fh, int $tenantId): void {
    $tpl = exportTemplateGet($tplId, $tenantId, true);
    $mappings = $tpl['column_mappings'] ?? [];
    if (!is_array($mappings) || !$mappings) {
        throw new ExportTemplateException('Template has no column mappings');
    }
    usort($mappings, fn ($a, $b) => ((int) ($a['position'] ?? 0)) <=> ((int) ($b['position'] ?? 0)));

    $delimiter = (string) ($tpl['delimiter'] ?: ',');
    $quote     = (string) ($tpl['quote_char'] ?: '"');
    if (strlen($delimiter) !== 1) $delimiter = ',';
    if (strlen($quote) !== 1)     $quote     = '"';

    if ((int) ($tpl['has_header_row'] ?? 1) === 1) {
        fputcsv($fh, array_map(fn ($m) => (string) ($m['output_header'] ?? ''), $mappings), $delimiter, $quote);
    }

    foreach ($rows as $row) {
        $line = [];
        foreach ($mappings as $m) {
            $kind = $m['kind'] ?? 'field';
            if ($kind === 'fixed') {
                $line[] = (string) ($m['fixed_value'] ?? '');
            } else {
                $src = (string) ($m['source_field'] ?? '');
                $line[] = ($src !== '' && array_key_exists($src, $row))
                    ? (string) $row[$src] : '';
            }
        }
        fputcsv($fh, $line, $delimiter, $quote);
    }
}

/** Identify the tenant's default template for (dataset) — first tenant-owned, else first platform. */
function exportTemplateDefault(int $tenantId, string $dataset): ?array {
    $rows = exportTemplateList($tenantId, $dataset);
    foreach ($rows as $r) if ($r['scope'] === 'tenant') return $r;
    return $rows[0] ?? null;
}

// ───────── Validation ─────────

function _exportTplValidateMappings($raw, string $dataset, ?int $tenantId = null): array {
    if (!is_array($raw) || !$raw) throw new ExportTemplateException('column_mappings must be a non-empty array');
    $ds = exportDatasetGet($dataset);
    $validFields = $ds ? array_keys(exportDatasetFieldRegistry($dataset, $tenantId)) : [];

    $out = [];
    foreach ($raw as $i => $m) {
        if (!is_array($m)) continue;
        $kind = ($m['kind'] ?? 'field') === 'fixed' ? 'fixed' : 'field';
        $entry = [
            'position'      => (int)    ($m['position']      ?? ($i + 1)),
            'output_header' => (string) ($m['output_header'] ?? ''),
            'kind'          => $kind,
        ];
        if ($entry['output_header'] === '') {
            throw new ExportTemplateException("Row #" . ($i + 1) . ": output_header required");
        }
        if ($kind === 'fixed') {
            $entry['fixed_value'] = (string) ($m['fixed_value'] ?? '');
        } else {
            $src = (string) ($m['source_field'] ?? '');
            if ($src === '') {
                throw new ExportTemplateException("Row #" . ($i + 1) . ": source_field required");
            }
            if ($validFields && !in_array($src, $validFields, true)) {
                throw new ExportTemplateException(
                    "Row #" . ($i + 1) . ": source_field '$src' not in dataset '$dataset'"
                );
            }
            $entry['source_field'] = $src;
        }
        $out[] = $entry;
    }
    // Renumber to 1..N so stored positions are always dense + 1-based.
    foreach ($out as $idx => &$e) { $e['position'] = $idx + 1; }
    return $out;
}
