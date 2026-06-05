<?php
/**
 * Smoke — JE drafts post-approval gate hardening (P2, 2026-02).
 *
 * Locks the 6-rule gate the AI-Native Extension spec §15 demands
 * around risk-level=4 promote-to-posted writes:
 *
 *   Rule 1 — Approval ↔ JE binding   (request_payload.je_id)
 *   Rule 2 — Single-use              (workflow_approvals.consumed_at)
 *   Rule 3 — SoD self-approval       (decided_by != created_by)
 *   Rule 4 — expires_at honored
 *   Rule 5 — JE audit trail          (accounting_journal_entries.approval_id)
 *   Rule 6 — Draft-mutation guard    (request_payload.draft_hash)
 *
 * Static-analyzer only (no DB) — locks the source surface so future
 * refactors can't silently drop a gate.  Pure-function probes exercise
 * accountingComputeDraftHash determinism / mutation sensitivity.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ──────────────────────────────────────────────────────────────────────
// 1) Migration 112 — schema columns + indexes.
// ──────────────────────────────────────────────────────────────────────
echo "\n── core/migrations/112_je_post_approval_gates.sql ──\n";
$mig = (string) file_get_contents('/app/core/migrations/112_je_post_approval_gates.sql');
$a('migration file exists',                          $mig !== '');
$a('adds accounting_journal_entries.approval_id',
    $c($mig, 'ALTER TABLE accounting_journal_entries')
    && $c($mig, 'ADD COLUMN IF NOT EXISTS approval_id BIGINT UNSIGNED NULL'));
$a('adds accounting_journal_entries.draft_hash',
    $c($mig, 'ADD COLUMN IF NOT EXISTS draft_hash CHAR(64) NULL'));
$a('adds tenant+approval index on accounting_journal_entries',
    $c($mig, 'ix_aje_tenant_approval'));
$a('adds workflow_approvals.consumed_at',
    $c($mig, 'ALTER TABLE workflow_approvals')
    && $c($mig, 'ADD COLUMN IF NOT EXISTS consumed_at TIMESTAMP NULL DEFAULT NULL'));
$a('adds workflow_approvals.consumed_by_je_id',
    $c($mig, 'ADD COLUMN IF NOT EXISTS consumed_by_je_id BIGINT UNSIGNED NULL'));
$a('adds index on workflow_approvals(tenant_id, consumed_at)',
    $c($mig, 'ix_wfa_consumed'));

// ──────────────────────────────────────────────────────────────────────
// 2) core/accounting/post_approval_gates.php — helper module.
// ──────────────────────────────────────────────────────────────────────
echo "\n── core/accounting/post_approval_gates.php ──\n";
$path = '/app/core/accounting/post_approval_gates.php';
$src = (string) file_get_contents($path);
$a('file exists',                                    $src !== '');
$a('declares strict_types',                          $c($src, 'declare(strict_types=1)'));
$a('accountingComputeDraftHash defined',
    $c($src, 'function accountingComputeDraftHash(int $tenantId, int $jeId): string'));
$a('accountingApprovalRequestPayloadForJe defined',
    $c($src, 'function accountingApprovalRequestPayloadForJe(int $tenantId, int $jeId): array'));
$a('accountingCheckPostApprovalGates defined',
    $c($src, 'function accountingCheckPostApprovalGates('));
$a('hash function pulls header + lines tenant-scoped',
    $c($src, 'WHERE id = :id AND tenant_id = :t'));
$a('hash function orders lines by line_no for determinism',
    $c($src, 'ORDER BY line_no ASC'));
$a('hash function uses sha256',                      $c($src, "hash('sha256', \$json)"));
$a('hash function sorts dim_json keys (ksort)',      $c($src, 'ksort($dims)'));
$a('hash function uses fixed-precision number_format for amounts',
    $c($src, "number_format((float) \$r['debit'],  2, '.', '')")
    && $c($src, "number_format((float) \$r['credit'], 2, '.', '')"));
$a('request-payload helper snapshots draft_hash',
    $c($src, "'draft_hash'  => accountingComputeDraftHash(\$tenantId, \$jeId)"));
$a('request-payload helper carries snapshot_at',
    $c($src, "'snapshot_at' => date('c')"));

// Each rule has an explicit error code so callers can distinguish.
echo "\n── gate verdict codes ──\n";
foreach ([
    'approval_already_consumed',
    'approval_expired',
    'approval_missing_binding',
    'approval_je_mismatch',
    'approval_missing_hash',
    'draft_mutated',
    'sod_self_approval',
] as $code) {
    $a("emits '$code' verdict", $c($src, "'code'    => '$code'"));
}
$a('mutation-guard uses hash_equals (timing-safe)', $c($src, 'hash_equals'));

// PHP lint.
exec("php -l $path 2>&1", $out, $rc);
$a('post_approval_gates.php passes php -l',          $rc === 0);

// ──────────────────────────────────────────────────────────────────────
// 3) Gate wired into tool_gateway risk-4 path.
// ──────────────────────────────────────────────────────────────────────
echo "\n── tool_gateway.php risk-4 hardening ──\n";
$gw = (string) file_get_contents('/app/core/ai/tool_gateway.php');
$a('risk-4 fetch widened to include consumed_at + expires_at',
    $c($gw, 'consumed_at, consumed_by_je_id')
    && $c($gw, 'expires_at,'));
$a('risk-4 fetch includes request_payload',
    $c($gw, 'request_payload, decided_by_user_id'));
$a('gate emits approval_already_consumed at gateway',
    $c($gw, "'code' => 'approval_already_consumed'"));
$a('gate emits approval_expired at gateway',
    $c($gw, "'code' => 'approval_expired'"));
$a('gate requires accountingCheckPostApprovalGates for post tool',
    $c($gw, "require_once __DIR__ . '/../accounting/post_approval_gates.php'")
    && $c($gw, 'accountingCheckPostApprovalGates'));
$a('JE-specific gate scoped to coreflux.post_approved_journal_entry',
    $c($gw, "\$toolName === 'coreflux.post_approved_journal_entry'"));
$a('verdict failure short-circuits invocation with verdict code',
    $c($gw, "'code' => (string) (\$verdict['code'] ?? 'approval_check_failed')"));

// ──────────────────────────────────────────────────────────────────────
// 4) accountingPromoteDraftToPosted stamps + atomically consumes.
// ──────────────────────────────────────────────────────────────────────
echo "\n── accountingPromoteDraftToPosted stamping ──\n";
$acc = (string) file_get_contents('/app/modules/accounting/lib/accounting.php');
$a('UPDATE stamps approval_id on JE',
    $c($acc, 'approval_id = :a'));
$a('UPDATE binds approvalId via :a placeholder',
    $c($acc, "'a' => \$approvalId"));
$a('approval consumption uses conditional UPDATE (single-use)',
    $c($acc, 'UPDATE workflow_approvals')
    && $c($acc, 'consumed_at IS NULL'));
$a('approval consumption sets consumed_by_je_id',
    $c($acc, 'consumed_by_je_id = :je'));
$a('approval consumption requires tenant_id match',
    $c($acc, 'AND tenant_id = :t AND consumed_at IS NULL'));
$a('race-loss raises clear error',
    $c($acc, 'race-consumed by another promotion'));

// ──────────────────────────────────────────────────────────────────────
// 5) Pure-function probes — accountingComputeDraftHash determinism.
//    No DB, but we can exercise the canonical encoding by stubbing
//    getDB() — instead we just smoke the encoding helpers that are
//    deterministic by virtue of number_format + ksort.
// ──────────────────────────────────────────────────────────────────────
echo "\n── canonical-encoding probes ──\n";

// Probe — ksort + number_format produce a stable canonical shape
// for the same logical input. We mimic the inner loop directly.
$row = ['debit' => '100.00', 'credit' => '0', 'memo' => 'x', 'dim_json' => '{"dept":"R&D","class":"A"}'];
$rowReordered = ['debit' => '100.00', 'credit' => '0', 'memo' => 'x', 'dim_json' => '{"class":"A","dept":"R&D"}'];
$canonical = function (array $r): array {
    $dims = json_decode((string) $r['dim_json'], true) ?: [];
    ksort($dims);
    return [
        'debit'  => number_format((float) $r['debit'],  2, '.', ''),
        'credit' => number_format((float) $r['credit'], 2, '.', ''),
        'memo'   => $r['memo'],
        'dims'   => $dims,
    ];
};
$a('canonical encoding is order-insensitive for dims',
    json_encode($canonical($row)) === json_encode($canonical($rowReordered)));

// Mutation sensitivity — any amount change flips the canonical shape.
$rowMut = ['debit' => '100.01', 'credit' => '0', 'memo' => 'x', 'dim_json' => '{"dept":"R&D","class":"A"}'];
$a('canonical encoding flips on amount mutation',
    json_encode($canonical($row)) !== json_encode($canonical($rowMut)));

// Format normalisation — '100' and '100.00' canonicalise the same.
$rowLoose  = ['debit' => '100',    'credit' => '0', 'memo' => 'x', 'dim_json' => '{}'];
$rowStrict = ['debit' => '100.00', 'credit' => '0', 'memo' => 'x', 'dim_json' => '{}'];
$a('canonical encoding normalises loose decimal strings',
    json_encode($canonical($rowLoose)) === json_encode($canonical($rowStrict)));

// hash_equals semantics — string-typed sha256 equality matches.
$h1 = hash('sha256', 'coreflux');
$h2 = hash('sha256', 'coreflux');
$h3 = hash('sha256', 'coreflux-mutated');
$a('hash_equals returns true for identical sha256',  hash_equals($h1, $h2));
$a('hash_equals returns false for differing sha256', !hash_equals($h1, $h3));

// ──────────────────────────────────────────────────────────────────────
// 6) Slice-C smoke wasn't broken by the changes (regression check).
// ──────────────────────────────────────────────────────────────────────
echo "\n── Slice-C regression touchpoints ──\n";
$a('accountingPromoteDraftToPosted still re-validates draft at promotion',
    $c($acc, "\$report = accountingValidateJe(") && $c($acc, "if (!\$report['ok'])"));
$a('accountingPromoteDraftToPosted still idempotent on already-posted',
    $c($acc, "if (\$row['status'] === 'posted')")
    && $c($acc, "'idempotent_replay' => true"));
$a('coreflux.post_approved_journal_entry still registered at risk-4',
    $c($gw, "'coreflux.post_approved_journal_entry'")
    && $c($gw, "'risk_level'  => 4"));

// ──────────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "JE post-approval gates smoke: $pass ✓ / $fail ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
