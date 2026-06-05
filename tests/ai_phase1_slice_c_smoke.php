<?php
/**
 * Smoke — Slice C: JE Draft validation + approval-gated post (2026-02).
 *
 * Locks the Phase 3 finish work from the AI-Native Extension spec:
 *   - modules/accounting/lib/accounting.php — accountingValidateJe()
 *     (pure-read validation, mirrors accountingPostJe's pre-insert
 *     checks) + accountingPromoteDraftToPosted() (re-validates at
 *     promotion time, flips status draft→posted, idempotent).
 *   - core/ai/tool_gateway.php registers
 *       coreflux.validate_journal_entry      (read)
 *       coreflux.post_approved_journal_entry (risk_level=4)
 *     plus their handlers. Approval gate threads
 *     {_approval_id, _actor_user_id} into args.
 *   - /api/ai/je_drafts.php — list / detail / reject endpoints.
 *   - dashboard JeDraftsReview.jsx mounted at /admin/ai/je-drafts.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ──────────────────────────────────────────────────────────────────────
// 1) accountingValidateJe() + accountingPromoteDraftToPosted().
// ──────────────────────────────────────────────────────────────────────
echo "\n── modules/accounting/lib/accounting.php ──\n";
$acc = (string) file_get_contents('/app/modules/accounting/lib/accounting.php');
$a('accountingValidateJe defined',
    $c($acc, 'function accountingValidateJe(int $tenantId, array $je): array'));
$a('accountingPromoteDraftToPosted defined',
    $c($acc, 'function accountingPromoteDraftToPosted(int $tenantId, int $jeId, array $opts = []): array'));
$a('validator runs balance check',
    $c($acc, 'round(abs($totalDebit - $totalCredit), 2)'));
$a('validator surfaces period closed/soft_closed',
    $c($acc, "['closed', 'soft_closed']"));
$a('validator surfaces per-line errors',
    $c($acc, "'line_validations'"));
$a('validator returns ok bool',
    $c($acc, "'ok'                 => \$ok") || $c($acc, "'ok'"));
$a('validator returns ai_advice for the LLM',
    $c($acc, 'ai_advice'));
$a('promotion enforces approval_id required',
    $c($acc, "throw new \\InvalidArgumentException('approval_id required')"));
$a('promotion refuses non-draft rows',
    $c($acc, "JE #{\$jeId} is status='{\$row['status']}', not 'draft'"));
$a('promotion re-validates at promotion time',
    $c($acc, "\$report = accountingValidateJe(") && $c($acc, "if (!\$report['ok'])"));
$a('promotion is idempotent on already-posted row',
    $c($acc, "if (\$row['status'] === 'posted')")
    && $c($acc, "'idempotent_replay' => true"));
$a('promotion flips draft → posted in a transaction',
    $c($acc, '$pdo->beginTransaction()') && $c($acc, 'status = "posted"'));
$a('promotion stamps posted_at + posted_by_user_id',
    $c($acc, 'posted_at = NOW()') && $c($acc, 'posted_by_user_id = :u'));
$a('promotion invalidates FSC cache on promotion',
    $c($acc, "fscMarkDirty(\$tenantId, FSC_SCOPE_PERIOD"));

// PHP syntax check.
$lint = [];
exec('php -l /app/modules/accounting/lib/accounting.php 2>&1', $lint, $rc);
$a('accounting.php passes php -l',                   $rc === 0);

// ──────────────────────────────────────────────────────────────────────
// 2) Pure-function probes against accountingValidateJe.
// ──────────────────────────────────────────────────────────────────────
echo "\n── accountingValidateJe pure probes ──\n";
require_once '/app/modules/accounting/lib/accounting.php';

// Probe 1: unbalanced JE caught.
$r = accountingValidateJe(1, [
    'entity_id'    => 1,
    'posting_date' => '2026-02-15',
    'lines' => [
        ['account_code' => 'X', 'debit'  => 100],
        ['account_code' => 'Y', 'credit' => 90],
    ],
]);
$a('unbalanced JE caught (debits != credits)',       $r['balanced'] === false);
$a('unbalanced JE → ok=false',                       $r['ok'] === false);
$a('unbalanced JE → errors[] populated',             count($r['errors']) > 0);
$a('unbalanced JE → totals reported',                $r['total_debit'] === 100.0 && $r['total_credit'] === 90.0);

// Probe 2: negative amounts on a line caught.
$r = accountingValidateJe(1, [
    'entity_id'    => 1,
    'posting_date' => '2026-02-15',
    'lines' => [
        ['account_code' => 'X', 'debit' => -10],
        ['account_code' => 'Y', 'credit' => 10],
    ],
]);
$a('negative debit caught per-line',
    !empty($r['line_validations'][0]['errors']));

// Probe 3: both debit + credit on one line caught.
$r = accountingValidateJe(1, [
    'entity_id'    => 1,
    'posting_date' => '2026-02-15',
    'lines' => [
        ['account_code' => 'X', 'debit' => 10, 'credit' => 10],
        ['account_code' => 'Y', 'credit' => 10],
    ],
]);
$a('debit+credit on same line caught per-line',
    str_contains(implode('|', $r['line_validations'][0]['errors']),
                 'a line cannot have both debit and credit'));

// Probe 4: posting_date format validated.
$r = accountingValidateJe(1, [
    'entity_id'    => 1,
    'posting_date' => 'not-a-date',
    'lines' => [
        ['account_code' => 'X', 'debit'  => 100],
        ['account_code' => 'Y', 'credit' => 100],
    ],
]);
$a('invalid posting_date format flagged',
    str_contains(implode('|', $r['errors']), 'posting_date must be YYYY-MM-DD'));

// Probe 5: too-few lines flagged.
$r = accountingValidateJe(1, [
    'entity_id'    => 1,
    'posting_date' => '2026-02-15',
    'lines' => [
        ['account_code' => 'X', 'debit' => 100],
    ],
]);
$a('single-line JE rejected as < 2 lines',
    str_contains(implode('|', $r['errors']), 'at least 2 lines'));

// ──────────────────────────────────────────────────────────────────────
// 3) Tool registry — 2 new tools wired + handlers present.
// ──────────────────────────────────────────────────────────────────────
echo "\n── tool_gateway.php registry ──\n";
$gw = (string) file_get_contents('/app/core/ai/tool_gateway.php');
$a('coreflux.validate_journal_entry registered',     $c($gw, "'coreflux.validate_journal_entry'"));
$a('coreflux.post_approved_journal_entry registered',$c($gw, "'coreflux.post_approved_journal_entry'"));
$a('validate is read-tier risk_level',               $c($gw, "'risk_level'  => 'read'"));
$a('post is risk_level=4 (transactional + approval-gated)',
    $c($gw, "'risk_level'  => 4"));
$a('aiToolValidateJournalEntryHandler implemented',  $c($gw, 'function aiToolValidateJournalEntryHandler('));
$a('aiToolPostApprovedJournalEntryHandler implemented',
    $c($gw, 'function aiToolPostApprovedJournalEntryHandler('));
$a('post handler requires accounting.php',
    $c($gw, "require_once __DIR__ . '/../../modules/accounting/lib/accounting.php'"));
$a('post handler reads _approval_id from threaded args',
    $c($gw, "\$args['_approval_id']"));
$a('post handler forwards approval_id + actor to accountingPromoteDraftToPosted',
    $c($gw, "accountingPromoteDraftToPosted(\$tenantId, \$jeId, ["));
$a('validate handler forwards entity_id / posting_date / lines',
    $c($gw, "'entity_id'    => (int) (\$args['entity_id'] ?? 0)")
    && $c($gw, "'posting_date' => (string) (\$args['posting_date'] ?? '')"));

// risk-level=4 gate thread metadata into $args
$a('aiToolInvoke risk-4 gate threads _approval_id into args',
    $c($gw, "\$args['_approval_id']   = \$approvalId"));
$a('aiToolInvoke risk-4 gate threads _actor_user_id into args',
    $c($gw, "\$args['_actor_user_id'] = \$userId"));

// idempotency_args on the post tool.
$a('post tool declares idempotency_args=[je_id]',
    $c($gw, "'idempotency_args' => ['je_id']"));

// ──────────────────────────────────────────────────────────────────────
// 4) /api/ai/je_drafts.php — full reviewer surface.
// ──────────────────────────────────────────────────────────────────────
echo "\n── /api/ai/je_drafts.php ──\n";
$api = (string) file_get_contents('/app/api/ai/je_drafts.php');
$a('endpoint declares strict_types',                 $c($api, 'declare(strict_types=1)'));
$a('list query scopes by tenant_id + status=draft',
    $c($api, "WHERE je.tenant_id = :t AND je.status = 'draft'"));
$a('list ORDER BY newest first',                     $c($api, 'ORDER BY je.id DESC'));
$a('list returns line_count per row',                $c($api, "AS line_count"));
$a('detail returns header + lines + revalidate report',
    $c($api, "'draft' => \$je") && $c($api, "'lines' => \$lines") && $c($api, "'validation' => \$report"));
$a('detail refuses non-draft rows',
    $c($api, "JE #{\$id} is status='{\$je['status']}', not 'draft'"));
$a('reject action flips status to void',             $c($api, 'status = "void"'));
$a('reject gated on accounting.approve RBAC',
    $c($api, "rbac_legacy_can(\$user, 'accounting.approve')"));
$a('reject writes spec-§15 audit event ai_je_draft_rejected',
    $c($api, "'ai_je_draft_rejected'"));
$a('list+detail gated on ai.audit.view OR accounting.review',
    $c($api, "rbac_legacy_can(\$user, 'ai.audit.view')")
    && $c($api, "rbac_legacy_can(\$user, 'accounting.review')"));
$a('detail re-runs accountingValidateJe at view time',
    $c($api, '$report = accountingValidateJe('));

// PHP syntax.
$lint2 = [];
exec('php -l /app/api/ai/je_drafts.php 2>&1', $lint2, $rc2);
$a('je_drafts.php passes php -l',                    $rc2 === 0);

// ──────────────────────────────────────────────────────────────────────
// 5) JeDraftsReview.jsx — UI surface + testids.
// ──────────────────────────────────────────────────────────────────────
echo "\n── JeDraftsReview.jsx ──\n";
$ui = (string) file_get_contents('/app/dashboard/src/pages/JeDraftsReview.jsx');
$a('file exists',                                    $ui !== '');
$a('default export JeDraftsReview',                  $c($ui, 'export default function JeDraftsReview()'));
$a('reads /api/ai/je_drafts.php list',               $c($ui, "/api/ai/je_drafts.php'"));
$a('reads /api/ai/je_drafts.php detail',             $c($ui, "?action=detail&id="));
$a('POSTs reject to the same endpoint',              $c($ui, "/api/ai/je_drafts.php?action=reject"));
$a('two-column grid layout',                         $c($ui, "gridTemplateColumns: 'minmax(360px, 1fr) 2fr'"));
$a('detail renders re-validation block',             $c($ui, 'je-drafts-detail-validation'));
$a('detail surfaces per-line table',                 $c($ui, 'je-drafts-detail-lines'));
$a('reject button asks for reason via prompt',       $c($ui, "window.prompt"));
$a('detail links to Reviewer cockpit',               $c($ui, '/admin/ai-gateway/reviewer'));

// Static testids.
foreach ([
    'je-drafts-page',
    'je-drafts-title',
    'je-drafts-list-loading',
    'je-drafts-list-empty',
    'je-drafts-list',
    'je-drafts-detail-placeholder',
    'je-drafts-detail-loading',
    'je-drafts-detail-empty',
    'je-drafts-detail',
    'je-drafts-detail-memo',
    'je-drafts-detail-total-debit',
    'je-drafts-detail-total-credit',
    'je-drafts-detail-validation',
    'je-drafts-detail-validation-status',
    'je-drafts-detail-validation-errors',
    'je-drafts-detail-lines',
    'je-drafts-detail-reject',
    'je-drafts-detail-open-reviewer',
] as $tid) {
    $a("testid '$tid' present", $c($ui, "data-testid=\"$tid\""));
}

// Template testids.
$a("template testid 'je-drafts-row-\${r.id}' present",
    $c($ui, 'je-drafts-row-${r.id}'));
$a("template testid 'je-drafts-detail-line-\${ln.line_no}' present",
    $c($ui, 'je-drafts-detail-line-${ln.line_no}'));
$a("template testid 'je-drafts-status-\${status}' present",
    $c($ui, 'je-drafts-status-${status}'));
$a("template testid 'je-drafts-detail-validation-error-\${i}' present",
    $c($ui, 'je-drafts-detail-validation-error-${i}'));

// ──────────────────────────────────────────────────────────────────────
// 6) AdminModule routing — JE Drafts Review reachable.
// ──────────────────────────────────────────────────────────────────────
echo "\n── AdminModule.jsx routing ──\n";
$adm = (string) file_get_contents('/app/dashboard/src/pages/AdminModule.jsx');
$a('AdminModule imports JeDraftsReview',             $c($adm, "import JeDraftsReview from './JeDraftsReview'"));
$a('AdminModule routes /admin/ai/je-drafts',         $c($adm, 'path="/ai/je-drafts"'));
$a('JE drafts surfaced in sidebar nav',              $c($adm, "to: '/admin/ai/je-drafts'"));
$a('JE drafts surfaced as AdminOverview tile',       $c($adm, 'href="/admin/ai/je-drafts"'));

// ──────────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "Slice C smoke: $pass ✓ / $fail ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
