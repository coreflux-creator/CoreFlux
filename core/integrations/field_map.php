<?php
/**
 * Tenant Integration Field Map — Slice 3 scaffolding (2026-02).
 *
 * Read/write helpers for the per-tenant registry that controls which
 * external-system fields map to which CoreFlux internal columns per
 * (integration, entity_type) pair.
 *
 * WIRING STATUS: scaffolding. The syncer (`core/jobdiva/sync.php` &
 * siblings) doesn't read this table yet; that's the next slice. This
 * file exists so the admin UI + API can be built and the schema
 * locked in before the syncer integration.
 *
 * Public surface:
 *   tenantIntegrationFieldMapList(tid, integration, entityType): array
 *   tenantIntegrationFieldMapUpsert(tid, payload, actorUserId): array
 *   tenantIntegrationFieldMapDelete(tid, id, actorUserId): bool
 *   tenantIntegrationFieldMapAllowedInternalFields(entityType): array
 *
 * Internal-field allow-list is enforced server-side so a tenant_admin
 * can't accidentally (or maliciously) map an external field into e.g.
 * `tenant_id` or `created_by_user_id`. Add entries as new entity types
 * gain admin-mappable fields.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

const TENANT_INTEGRATION_FIELD_MAP_TRANSFORMS = [
    'none',
    'date_normalise',     // epoch ms / ISO / m/d/Y → Y-m-d
    'lowercase',
    'uppercase',
    'trim',
    'cents_to_dollars',   // divide by 100
    'dollars_to_cents',   // multiply by 100
];

/**
 * Internal-field allow-list per entity_type. Restricts what columns the
 * admin UI can target. Keys MUST match real CoreFlux columns on the
 * relevant table; the syncer trusts this list to be safe.
 */
function tenantIntegrationFieldMapAllowedInternalFields(string $entityType): array
{
    // Allow-list per entity_type. Restricts what columns the admin UI
    // can target. Keys MUST match real columns on the relevant table;
    // the syncer trusts this list to be safe.
    //
    // Curation rules:
    //   • Only columns the SYNCER can safely write — no FKs, no
    //     denormalized rollups, no `_at` / `_by` audit timestamps.
    //   • Cross-table fields (placement_rates.bill_rate,
    //     placement_corp_details.corp_legal_name, etc.) are NOT exposed
    //     here yet — they need a multi-table writer in the syncer.
    //   • PII columns (people.dob, people.ssn_last4) are intentionally
    //     omitted — they should never come from an external sync.
    static $map = [
        'placement' => [
            // -- placements table (one-row-per-engagement) --
            // Identity / lifecycle
            'external_id', 'title', 'status',
            // Dates
            'start_date', 'end_date', 'actual_end_date', 'due_date',
            // Engagement metadata
            'engagement_type', 'remote_policy',
            'worksite_state', 'worksite_country',
            // Client / approval
            'end_client_name',
            'client_approver_name', 'client_approver_email',
            // JobDiva cross-reference / sales metadata (migration 071, Slice 5b).
            // jobdiva_job_id is the JobDiva *Job* entity ID — distinct from
            // `external_id` which stores the Assignment ID. Recruiter and
            // account-manager fields are denormalised snapshots from the
            // source system; FK linkage to internal user rows is intentionally
            // omitted so operators can backfill names that don't match any
            // CoreFlux user.
            'jobdiva_job_id',
            'recruiter_name', 'recruiter_email',
            'account_manager_name', 'account_manager_email',
            // Free-text
            'notes',
            // Approval-flow toggles
            'tokenized_email_approval_enabled',
            'bulk_uploads_can_be_pre_approved',

            // -- placement_rates table (cross-table — the syncer
            //    routes these via jobdivaSyncUpsertPlacementRates).
            //    Surfacing them under the 'placement' entity_type
            //    matches the operator's mental model: from JobDiva's
            //    Assignment screen, bill/pay rate live on the
            //    placement, not in a separate table.
            'bill_rate', 'bill_rate_unit',
            'pay_rate',  'pay_rate_unit',
            'currency',
            'ot_multiplier', 'dt_multiplier',
            // -- placements (cycle config, migration 002_cycles +
            //    002_cycle_config). Mappable so JobDiva tenants can
            //    inherit upstream billing/pay cadences without
            //    re-entering them in CoreFlux. ENUMs are coerced
            //    server-side; anchors normalised via date_normalise.
            'client_bill_cycle', 'client_bill_cycle_anchor',
            'vendor_pay_cycle',  'vendor_pay_cycle_anchor',
        ],
        'person' => [
            // -- people table (one row per person; talent-pool model) --
            'external_id',
            // Names
            'first_name', 'middle_name', 'last_name', 'preferred_name',
            // Contact
            'email_primary', 'email_secondary',
            'phone_primary', 'phone_secondary',
            // Classification / lifecycle
            'classification', 'status',
            // Work auth
            'work_auth_status', 'work_auth_expiry', 'requires_sponsorship',
            // Home address (non-PII — public-record fields are fine to sync)
            'home_address_line1', 'home_address_line2',
            'home_city', 'home_state', 'home_postal_code', 'home_country',
            // Career / sourcing
            'linkedin_url', 'source', 'recruiter_notes',
            // Slice 5b broader-mapping (2026-02): additional people columns
            // surfaced from migrations 006_unify_and_extend + 007_worker_class.
            // Lets JobDiva tenants pull employment lifecycle + worker
            // classification + mailing address from the upstream payload.
            // PII (gender, marital_status) intentionally still omitted.
            'employment_type', 'hire_date', 'termination_date',
            'pay_frequency', 'worker_class',
            'mailing_address_line1', 'mailing_address_line2',
            'mailing_city', 'mailing_state', 'mailing_postal_code', 'mailing_country',
            // INTENTIONALLY EXCLUDED:
            //   id, tenant_id                 — FKs / system
            //   dob, ssn_last4                — PII (people.pii.view gates them)
            //   resume_storage_object_id      — FK to object storage
            //   referred_by_person_id         — FK
            //   created_by_user_id, *_at      — audit columns
        ],
        'company' => [
            // -- companies table --
            'external_id',
            'name', 'legal_name', 'website', 'phone',
            'duns', 'ein_last4',
            'primary_contact_name', 'primary_contact_email', 'primary_contact_phone',
            'address_line1', 'address_line2',
            'city', 'state', 'postal_code', 'country',
            'msa_signed_at', 'notes',
            // Slice 5b broader-mapping (2026-02): companies-v2 columns
            // (migration 005_companies_v2). Account lifecycle + terms +
            // compliance flags are typical fields JobDiva exposes for
            // the Company entity.
            'payment_terms_days', 'default_terms', 'currency',
            'status', 'tax_classification',
            'industry', 'employee_size_range',
            'w9_on_file', 'w9_expires_on',
            'coi_on_file', 'coi_expires_on',
            'tags_json',
            // INTENTIONALLY EXCLUDED:
            //   id, tenant_id, deleted_at, created_by_user_id, *_at  — system
            //   ein_full_ct                                           — ciphertext column
            //   msa_storage_object_id, use_count, last_used_at        — system-managed
            //   account_manager_user_id, w9_storage_object_id,
            //   coi_storage_object_id                                 — FKs
        ],
        'contact' => [
            // -- company_contacts table (multiple per company) --
            'external_id',
            'name', 'first_name', 'last_name',     // first/last virtual: split server-side
            'title', 'email', 'phone',
            'contact_role', 'is_primary', 'notes',
            // Slice 5b broader-mapping (2026-02): companies-v2 additions
            // (migration 005_companies_v2). mobile_phone, linkedin_url,
            // and department are routinely populated in JobDiva's
            // ClientContacts feed; decision_role lets ops capture
            // sales-cycle context. is_active mirrors the upstream
            // active/inactive toggle.
            'mobile_phone', 'linkedin_url', 'department',
            'decision_role', 'is_active',
            // INTENTIONALLY EXCLUDED:
            //   id, tenant_id, company_id, *_at  — system
        ],

        // -- Accounting integrations (QuickBooks Online / Zoho Books / Xero).
        //    Different entity_types here because the source-side data
        //    model is fundamentally different from staffing payloads —
        //    GL accounts, journal entries, invoices, bills.
        //    All three accounting integrations share the same target
        //    schema (`accounting_*` tables) so a single entity_type per
        //    target table works across QBO/Zoho/Xero.
        'gl_account' => [
            // -- accounting_chart_of_accounts --
            'external_id',
            'name', 'account_number', 'account_type', 'account_subtype',
            'currency', 'is_active',
            'parent_external_id',  // dotted-path resolved on import
            'description',
        ],
        'journal_entry' => [
            // -- accounting_journal_entries (header only; line items are
            //    posted separately by the syncer once the header lands).
            'external_id',
            'entry_date', 'doc_number', 'memo', 'currency',
            'source_module', 'source_ref',
            'private_note',
        ],
        'bill' => [
            // -- ap_bills (vendor invoices we owe; QBO Bill, Zoho Bill,
            //    Xero Purchase Invoice).
            'external_id',
            'vendor_name',          // resolved → companies.id server-side
            'bill_number', 'bill_date', 'due_date',
            'amount', 'currency',
            'memo', 'reference',
            'status',               // mapped to ENUM server-side
            'department', 'class',  // QBO/Zoho dimension fields
        ],
        'invoice' => [
            // -- billing_invoices (customer invoices we issue; QBO
            //    Invoice, Zoho Invoice, Xero Sales Invoice).
            'external_id',
            'customer_name',        // resolved → companies.id server-side
            'invoice_number', 'invoice_date', 'due_date',
            'amount', 'currency',
            'memo', 'reference',
            'status',
            'department', 'class',
        ],
        'payment' => [
            // -- ap_payments / customer_payments. Source identifies
            //    direction via `kind` ('vendor_payment' | 'customer_payment').
            'external_id',
            'payment_date', 'amount', 'currency',
            'method',               // ach / check / wire / card
            'reference',             // check number / ACH trace / wire id
            'memo',
            'kind',                  // vendor_payment | customer_payment
        ],
    ];
    return $map[$entityType] ?? [];
}

function tenantIntegrationFieldMapList(int $tenantId, ?string $integration = null, ?string $entityType = null): array
{
    $pdo = getDB();
    $where = ['tenant_id = :t'];
    $params = ['t' => $tenantId];
    if ($integration !== null && $integration !== '') {
        $where[] = 'integration = :i';
        $params['i'] = $integration;
    }
    if ($entityType !== null && $entityType !== '') {
        $where[] = 'entity_type = :e';
        $params['e'] = $entityType;
    }
    $stmt = $pdo->prepare(
        'SELECT id, integration, entity_type, external_field, internal_field,
                transform, enabled, notes, updated_by_user_id, created_at, updated_at
           FROM tenant_integration_field_map
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY integration, entity_type, internal_field'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']      = (int) $r['id'];
        $r['enabled'] = (int) $r['enabled'] === 1;
        $r['updated_by_user_id'] = $r['updated_by_user_id'] !== null ? (int) $r['updated_by_user_id'] : null;
    }
    return $rows;
}

/**
 * Upsert a field-map row. If `id` is set, updates that row (tenant-scoped);
 * otherwise inserts a new row, or updates the existing row keyed by
 * (tenant_id, integration, entity_type, internal_field).
 *
 * Returns the resulting row. Throws InvalidArgumentException on
 * validation failure.
 */
function tenantIntegrationFieldMapUpsert(int $tenantId, array $payload, ?int $actorUserId): array
{
    $integration   = trim((string) ($payload['integration']    ?? ''));
    $entityType    = trim((string) ($payload['entity_type']    ?? ''));
    $externalField = trim((string) ($payload['external_field'] ?? ''));
    $internalField = trim((string) ($payload['internal_field'] ?? ''));
    $transform     = trim((string) ($payload['transform']      ?? 'none'));
    $enabled       = isset($payload['enabled']) ? (int) (bool) $payload['enabled'] : 1;
    $notes         = isset($payload['notes']) && $payload['notes'] !== '' ? (string) $payload['notes'] : null;
    // Phase 2 generalised shape — dotted path on the source side +
    // explicit (target_module, target_table, target_column,
    // linked_entity) on the destination side. All five are optional
    // during cutover so legacy callers still work; new callers
    // should provide source_path + the four target fields.
    $sourcePath    = isset($payload['source_path'])
                       ? trim((string) $payload['source_path']) : '';
    $targetModule  = isset($payload['target_module'])
                       ? trim((string) $payload['target_module']) : '';
    $targetTable   = isset($payload['target_table'])
                       ? trim((string) $payload['target_table'])  : '';
    $targetColumn  = isset($payload['target_column'])
                       ? trim((string) $payload['target_column']) : '';
    $linkedEntity  = isset($payload['linked_entity'])
                       ? trim((string) $payload['linked_entity']) : '';

    if ($integration === '')   throw new \InvalidArgumentException('integration required');
    if ($entityType === '')    throw new \InvalidArgumentException('entity_type required');
    if ($externalField === '' && $sourcePath === '') {
        throw new \InvalidArgumentException('external_field or source_path required');
    }
    if ($internalField === '' && $targetColumn === '') {
        throw new \InvalidArgumentException('internal_field or target_column required');
    }

    // If caller used the generalised shape, validate against the
    // DB-driven catalog and backfill internal_field for legacy
    // syncer compatibility. If caller used the legacy shape, fall
    // through to the hardcoded allow-list.
    if ($targetTable !== '' && $targetColumn !== '') {
        $allowed = false;
        try {
            require_once __DIR__ . '/field_map_apply.php';
            $cat = integrationWritableTargetsList($targetModule !== '' ? $targetModule : null,
                                                  $targetTable);
            foreach ($cat as $row) {
                if ($row['target_table'] === $targetTable
                    && ($row['target_column'] === $targetColumn || $row['target_column'] === '*')) {
                    $allowed = true; break;
                }
            }
        } catch (\Throwable $e) {
            // Catalog missing — fall through to legacy allow-list.
        }
        if (!$allowed) {
            // Try legacy allow-list as a fallback gate before refusing.
            $legacyAllowed = tenantIntegrationFieldMapAllowedInternalFields($entityType);
            if (!in_array($targetColumn, $legacyAllowed, true)
                && !in_array($internalField, $legacyAllowed, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'target %s.%s not in writable-targets catalog and not in legacy allow-list for "%s"',
                    $targetTable, $targetColumn, $entityType
                ));
            }
        }
        if ($internalField === '') $internalField = $targetColumn;
    } else {
        // Legacy code path — hardcoded allow-list.
        $allowed = tenantIntegrationFieldMapAllowedInternalFields($entityType);
        if (!in_array($internalField, $allowed, true)) {
            throw new \InvalidArgumentException(
                sprintf('internal_field "%s" is not in the allow-list for entity_type "%s"', $internalField, $entityType)
            );
        }
    }
    if (!in_array($transform, TENANT_INTEGRATION_FIELD_MAP_TRANSFORMS, true)) {
        throw new \InvalidArgumentException('unknown transform: ' . $transform);
    }

    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO tenant_integration_field_map
            (tenant_id, integration, entity_type, external_field, source_path,
             internal_field, target_module, target_table, target_column, linked_entity,
             transform, enabled, notes, updated_by_user_id)
         VALUES (:t, :i, :e, :ef, :sp, :if, :tm, :tt, :tc, :le, :tr, :en, :n, :u)
         ON DUPLICATE KEY UPDATE
             external_field = VALUES(external_field),
             source_path    = VALUES(source_path),
             target_module  = VALUES(target_module),
             target_table   = VALUES(target_table),
             target_column  = VALUES(target_column),
             linked_entity  = VALUES(linked_entity),
             transform      = VALUES(transform),
             enabled        = VALUES(enabled),
             notes          = VALUES(notes),
             updated_by_user_id = VALUES(updated_by_user_id)'
    )->execute([
        't'  => $tenantId, 'i' => $integration, 'e' => $entityType,
        'ef' => $externalField, 'sp' => $sourcePath !== '' ? $sourcePath : null,
        'if' => $internalField,
        'tm' => $targetModule !== '' ? $targetModule : null,
        'tt' => $targetTable  !== '' ? $targetTable  : null,
        'tc' => $targetColumn !== '' ? $targetColumn : null,
        'le' => $linkedEntity !== '' ? $linkedEntity : null,
        'tr' => $transform, 'en' => $enabled, 'n' => $notes, 'u' => $actorUserId,
    ]);

    // Return the resulting canonical row.
    $stmt = $pdo->prepare(
        'SELECT id, integration, entity_type, external_field, source_path,
                internal_field, target_module, target_table, target_column, linked_entity,
                transform, enabled, notes, updated_by_user_id, created_at, updated_at
           FROM tenant_integration_field_map
          WHERE tenant_id = :t AND integration = :i AND entity_type = :e AND internal_field = :if
          LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'i' => $integration, 'e' => $entityType, 'if' => $internalField]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    if ($row) {
        $row['id']      = (int) $row['id'];
        $row['enabled'] = (int) $row['enabled'] === 1;
        $row['updated_by_user_id'] = $row['updated_by_user_id'] !== null ? (int) $row['updated_by_user_id'] : null;
    }
    return $row;
}

function tenantIntegrationFieldMapDelete(int $tenantId, int $id, ?int $actorUserId): bool
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'DELETE FROM tenant_integration_field_map WHERE id = :id AND tenant_id = :t'
    );
    $stmt->execute(['id' => $id, 't' => $tenantId]);
    return $stmt->rowCount() > 0;
}

/**
 * Per-request cache for resolved (tenant, integration, entity_type)
 * field maps. Sync runs upsert hundreds of records in a single tick;
 * we MUST NOT round-trip the DB per record.
 *
 * Shape: $cache[$tenantId][$integration][$entityType] = [
 *   'title'      => ['external_field' => 'job.title', 'transform' => 'none'],
 *   'start_date' => ['external_field' => 'startDate',  'transform' => 'date_normalise'],
 *   …
 * ]
 *
 * Key insight: only the FIRST call for an (integration, entity_type)
 * pair hits the DB; subsequent calls are O(1) lookups against the
 * cached array.
 */
/**
 * Backing cache for resolveAll. Top-level scope so the flush function
 * can clear it without reflection trickery.
 *
 * Shape: $GLOBALS['CF_FIELD_MAP_CACHE'][$compositeKey] = [internal_field => spec]
 */
function &tenantIntegrationFieldMapCache(): array
{
    if (!isset($GLOBALS['CF_FIELD_MAP_CACHE']) || !is_array($GLOBALS['CF_FIELD_MAP_CACHE'])) {
        $GLOBALS['CF_FIELD_MAP_CACHE'] = [];
    }
    return $GLOBALS['CF_FIELD_MAP_CACHE'];
}

function tenantIntegrationFieldMapResolveAll(int $tenantId, string $integration, string $entityType): array
{
    $cache =& tenantIntegrationFieldMapCache();
    $key = $tenantId . '|' . $integration . '|' . $entityType;
    if (isset($cache[$key])) return $cache[$key];

    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT internal_field, external_field, transform
           FROM tenant_integration_field_map
          WHERE tenant_id = :t AND integration = :i AND entity_type = :e
            AND enabled = 1'
    );
    $stmt->execute(['t' => $tenantId, 'i' => $integration, 'e' => $entityType]);
    $map = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $map[(string) $row['internal_field']] = [
            'external_field' => (string) $row['external_field'],
            'transform'      => (string) $row['transform'],
        ];
    }
    $cache[$key] = $map;
    return $map;
}

/**
 * Reset the resolveAll cache. Cron loops that span multiple tenants
 * SHOULD call this between tenants. Smoke tests use it to assert
 * behaviour across different configured states within a single process.
 */
function tenantIntegrationFieldMapFlushCache(): void
{
    $GLOBALS['CF_FIELD_MAP_CACHE'] = [];
}

/**
 * Apply a configured transform to a raw value. Returns the transformed
 * string, or the original if no transform applies.
 *
 * Note: `date_normalise` requires `jobdivaNormaliseDate()` to be in
 * scope — it's loaded by `core/jobdiva/sync.php`, the only caller path
 * that uses this transform today. If you need date_normalise from
 * another integration, require the helper there too.
 */
function tenantIntegrationFieldMapApplyTransform(mixed $value, string $transform): mixed
{
    if ($value === null || $value === '') return $value;
    $s = is_scalar($value) ? (string) $value : null;
    switch ($transform) {
        case 'none':
            return $value;
        case 'lowercase':
            return $s !== null ? strtolower($s) : $value;
        case 'uppercase':
            return $s !== null ? strtoupper($s) : $value;
        case 'trim':
            return $s !== null ? trim($s) : $value;
        case 'cents_to_dollars':
            return is_numeric($value) ? ((float) $value / 100) : $value;
        case 'dollars_to_cents':
            return is_numeric($value) ? (int) round(((float) $value) * 100) : $value;
        case 'date_normalise':
            // jobdivaNormaliseDate() returns null when the input isn't
            // parseable as a date (epoch ms, ISO, m/d/Y). Returning null
            // here silently nukes the value — which is a footgun when an
            // operator mis-targets the transform onto a non-date column
            // (e.g. mapping `customer name` → `end_client_name` with
            // `date_normalise` selected from the dropdown by mistake;
            // observed 2026-02 on Andrew Lee's placement where the end
            // client kept showing "(no end client)" because "Public
            // Storage" was being parsed as a date and discarded).
            //
            // Defensive contract: if the value clearly LOOKS like a date
            // (epoch-ms digits, or jobdivaNormaliseDate returns non-null)
            // we apply the transform. Otherwise we return the trimmed
            // original so the column still gets the operator-intended
            // value. The UI surfaces a warning so this doesn't become
            // an invisible silent-fallback.
            if (function_exists('jobdivaNormaliseDate')) {
                $normalised = jobdivaNormaliseDate($value);
                if ($normalised !== null) return $normalised;
            }
            // Fallback — input doesn't look like a date; preserve the
            // raw string trimmed (better than dropping the value).
            return $s !== null ? trim($s) : $value;
        default:
            return $value;
    }
}

/**
 * Pluck a value from a payload using an `external_field` spec that may
 * include a dotted path for nested objects (e.g. `job.title` to walk
 * into `$payload['job']['title']`). The final segment is matched
 * case/separator-insensitively via the same normalisation as
 * jobdivaPluckField() (so `job.JobTitle` matches `{job: {jobtitle: …}}`).
 *
 * Returns '' when the path doesn't resolve.
 */
function tenantIntegrationFieldMapPluckPath(array $payload, string $path): string
{
    if ($path === '') return '';
    $segments = explode('.', $path);
    $cursor = $payload;
    while (count($segments) > 1) {
        $head = array_shift($segments);
        $matched = null;
        if (is_array($cursor)) {
            $nh = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $head));
            foreach ($cursor as $k => $v) {
                if (!is_string($k)) continue;
                $nk = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $k));
                if ($nk === $nh) { $matched = $v; break; }
            }
        }
        if (!is_array($matched)) return '';
        $cursor = $matched;
    }
    $final = $segments[0];
    $nf = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $final));
    foreach ($cursor as $k => $v) {
        if (!is_string($k)) continue;
        $nk = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $k));
        if ($nk === $nf) {
            if (is_scalar($v)) return trim((string) $v);
            return '';
        }
    }
    return '';
}

/**
 * Pluck a value for an internal field, consulting the per-tenant
 * registry first. If the registry has a row for this internal_field,
 * we use the configured external_field + transform. Otherwise we fall
 * back to $defaultFn() — typically the syncer's built-in
 * jobdivaPluckField() call with hard-coded candidate keys.
 *
 * This is the canonical entry point for syncers — call this instead of
 * touching the registry table directly.
 *
 * Returns the resolved value (string), or '' if neither registry nor
 * fallback produced anything.
 */
function tenantIntegrationFieldMapPluckInternal(
    int $tenantId,
    string $integration,
    string $entityType,
    string $internalField,
    array $payload,
    callable $defaultFn
): mixed {
    $map = tenantIntegrationFieldMapResolveAll($tenantId, $integration, $entityType);
    if (isset($map[$internalField])) {
        $raw = tenantIntegrationFieldMapPluckPath($payload, $map[$internalField]['external_field']);
        if ($raw !== '') {
            return tenantIntegrationFieldMapApplyTransform($raw, $map[$internalField]['transform']);
        }
        // Registry was configured but the payload didn't contain the
        // mapped field. Fall through to default so the operator's
        // misconfiguration doesn't wipe out the value entirely.
    }
    return $defaultFn();
}

/**
 * Test helper — flush the per-request cache. Re-exported as an alias for
 * the same-effect helper above; kept so external callers can use a
 * single canonical name.
 */
// (Alias removed — tenantIntegrationFieldMapFlushCache is now the
// canonical entry point defined alongside the resolver above.)

/**
 * Bulk export — return a portable JSON-ready snapshot of every field-map
 * row for a tenant, optionally filtered by integration. Output shape is
 * a hand-editable manifest that round-trips cleanly through
 * tenantIntegrationFieldMapBulkImport().
 *
 * Format intentionally drops tenant_id, ids, audit timestamps so the
 * same JSON can be shared between tenants (e.g. paste a vetted JobDiva
 * mapping from tenant A into tenant B). The receiving tenant binds it
 * back to its own scope on import.
 */
function tenantIntegrationFieldMapBulkExport(int $tenantId, ?string $integration = null): array
{
    $rows = tenantIntegrationFieldMapList($tenantId, $integration, null);
    $mappings = [];
    foreach ($rows as $r) {
        $mappings[] = [
            'integration'    => (string) $r['integration'],
            'entity_type'    => (string) $r['entity_type'],
            'external_field' => (string) $r['external_field'],
            'internal_field' => (string) $r['internal_field'],
            'transform'      => (string) ($r['transform'] ?? 'none'),
            'enabled'        => (bool) $r['enabled'],
            'notes'          => $r['notes'] ?? null,
        ];
    }
    return [
        'version'      => 1,
        'exported_at'  => gmdate('c'),
        'integration'  => $integration,
        'source_tenant_id'    => $tenantId, // informational; ignored on import
        'mappings'     => $mappings,
    ];
}

/**
 * Bulk import — accept a {mappings: [...]} payload (the same shape
 * tenantIntegrationFieldMapBulkExport returns) and apply it to this
 * tenant.
 *
 * Modes:
 *   - 'merge'   — upsert each row by (integration, entity_type, internal_field);
 *                 existing unrelated rows are left untouched.
 *   - 'replace' — DELETE every existing row scoped to the import's
 *                 integration set first, then insert. Use to wipe a stale
 *                 mapping config and start over from a vetted JSON.
 *
 * Validation is row-by-row and non-atomic — invalid rows are skipped
 * with an error entry, valid rows still land. This matches the operator
 * mental model ("import what you can, tell me what broke") and avoids
 * the all-or-nothing trap where a single typo blocks 200 good rows.
 *
 * Returns:
 *   {
 *     mode, imported, replaced, skipped, errors: [{row_index, error}],
 *     integrations_affected: [...]
 *   }
 */
function tenantIntegrationFieldMapBulkImport(
    int $tenantId,
    array $payload,
    string $mode,
    ?int $actorUserId
): array {
    if (!in_array($mode, ['merge', 'replace'], true)) {
        throw new \InvalidArgumentException('mode must be "merge" or "replace"');
    }
    $mappings = $payload['mappings'] ?? null;
    if (!is_array($mappings)) {
        throw new \InvalidArgumentException('payload missing "mappings" array');
    }

    $pdo = getDB();
    $imported = 0; $skipped = 0; $errors = [];
    $integrationsAffected = [];

    if ($mode === 'replace') {
        // Determine integration scope from the import payload. We only
        // delete rows for integrations present in the import so a JobDiva
        // replace doesn't wipe out a tenant's QBO mappings as collateral.
        $scope = [];
        foreach ($mappings as $r) {
            $i = trim((string) ($r['integration'] ?? ''));
            if ($i !== '') $scope[$i] = true;
        }
        foreach (array_keys($scope) as $i) {
            $pdo->prepare(
                'DELETE FROM tenant_integration_field_map
                  WHERE tenant_id = :t AND integration = :i'
            )->execute(['t' => $tenantId, 'i' => $i]);
        }
        $replaced = array_keys($scope);
        // After delete the cache is stale.
        tenantIntegrationFieldMapFlushCache();
    } else {
        $replaced = [];
    }

    foreach ($mappings as $idx => $row) {
        if (!is_array($row)) {
            $errors[] = ['row_index' => $idx, 'error' => 'row is not an object'];
            $skipped++;
            continue;
        }
        try {
            $upsertPayload = [
                'integration'    => $row['integration']    ?? '',
                'entity_type'    => $row['entity_type']    ?? '',
                'external_field' => $row['external_field'] ?? '',
                'internal_field' => $row['internal_field'] ?? '',
                'transform'      => $row['transform']      ?? 'none',
                'enabled'        => array_key_exists('enabled', $row)
                                    ? (bool) $row['enabled'] : true,
                'notes'          => $row['notes']          ?? null,
            ];
            tenantIntegrationFieldMapUpsert($tenantId, $upsertPayload, $actorUserId);
            $integrationsAffected[(string) $upsertPayload['integration']] = true;
            $imported++;
        } catch (\Throwable $e) {
            $errors[] = ['row_index' => $idx, 'error' => $e->getMessage()];
            $skipped++;
        }
    }

    // Mutations invalidate the resolver cache.
    tenantIntegrationFieldMapFlushCache();

    return [
        'mode'                  => $mode,
        'imported'              => $imported,
        'skipped'               => $skipped,
        'replaced_integrations' => $replaced,
        'integrations_affected' => array_keys($integrationsAffected),
        'errors'                => $errors,
    ];
}

/**
 * Test-mapping dry run — apply the configured rules to a sample payload
 * WITHOUT writing anything. Operator pastes a JobDiva record (or any
 * source-side JSON), picks (integration, entity_type), and sees:
 *   - which rules matched, and what value each one resolved to
 *   - which rules didn't match (external_field path missing in payload)
 *   - which allow-listed internal fields have NO rule yet
 *
 * Crucially this does NOT invoke the syncer's built-in candidate-key
 * fallbacks. Operators are testing the REGISTRY config, so the registry
 * is what we evaluate. Built-in defaults are noted alongside each
 * unconfigured internal field for context.
 *
 * Returns:
 *   {
 *     integration, entity_type,
 *     resolved: [
 *       { internal_field, external_field, transform, value, raw_value, matched: bool }
 *     ],
 *     unmapped_internal_fields: [...allow-listed columns with no rule...]
 *   }
 */
function tenantIntegrationFieldMapTestPayload(
    int $tenantId,
    string $integration,
    string $entityType,
    array $payload
): array {
    $allowed = tenantIntegrationFieldMapAllowedInternalFields($entityType);
    $rules   = tenantIntegrationFieldMapResolveAll($tenantId, $integration, $entityType);

    $resolved = [];
    $configured = [];
    foreach ($rules as $internalField => $spec) {
        $raw = tenantIntegrationFieldMapPluckPath($payload, $spec['external_field']);
        $matched = ($raw !== '');
        $value = $matched
            ? tenantIntegrationFieldMapApplyTransform($raw, $spec['transform'])
            : null;
        $resolved[] = [
            'internal_field' => $internalField,
            'external_field' => $spec['external_field'],
            'transform'      => $spec['transform'],
            'raw_value'      => $matched ? $raw : null,
            'value'          => $value,
            'matched'        => $matched,
        ];
        $configured[$internalField] = true;
    }
    $unmapped = array_values(array_filter(
        $allowed,
        static fn(string $f): bool => !isset($configured[$f])
    ));

    return [
        'integration'              => $integration,
        'entity_type'              => $entityType,
        'resolved'                 => $resolved,
        'unmapped_internal_fields' => $unmapped,
    ];
}
