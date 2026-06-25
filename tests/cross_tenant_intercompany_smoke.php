<?php
/**
 * Smoke test for the cross-tenant intercompany improvements:
 *
 *   - FX support: from_currency != to_currency requires positive fx_rate;
 *     to-leg amount = amount * fx_rate, rounded to cents.
 *   - Symmetric audit: both .posted (out) AND .received (in) events fire.
 *   - Reversal helper: accountingReverseCrossTenantIntercompany($ref) exists
 *     and reverses both legs by shared intercompany_group_id.
 *   - Compensating reversal: if the to-leg post throws, the from-leg gets
 *     auto-reversed so books never carry a half-posted pair.
 *   - intercompany_group_id stamping function present.
 *
 * Source-level smoke (function-shape + literal-string assertions). The
 * full posting flow against a live DB is covered by integration tests.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$read = function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    return is_file($full) ? (string) file_get_contents($full) : '';
};

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

$src = $read('modules/accounting/lib/cross_tenant_intercompany.php');

// ─── Function presence ────────────────────────────────────────────────
$a('accountingPostCrossTenantIntercompany() defined',
   str_contains($src, 'function accountingPostCrossTenantIntercompany('));
$a('accountingReverseCrossTenantIntercompany() defined',
   str_contains($src, 'function accountingReverseCrossTenantIntercompany('));
$a('_cxIcStampGroupId() helper defined',
   str_contains($src, 'function _cxIcStampGroupId('));

// ─── FX surface ───────────────────────────────────────────────────────
$a('post fn reads to_currency option',     str_contains($src, "\$opts['to_currency']"));
$a('post fn reads fx_rate option',          str_contains($src, "\$opts['fx_rate']"));
$a('post fn rejects fx_rate <= 0 cross-currency',
   str_contains($src, 'fx_rate must be > 0 when from_currency'));
$a('post fn computes to-leg amount via $amount * $fxRate',
   str_contains($src, '$amount * $fxRate'));
$a('post fn rejects computed to_amount <= 0',
   str_contains($src, 'computed to-leg amount must be > 0'));
$a('return shape exposes fx_rate + to_amount',
   str_contains($src, "'fx_rate'") && str_contains($src, "'to_amount'"));
$a('return shape exposes currency on both legs',
   preg_match("/'from' => \[[\s\S]+'currency'[\s\S]+'to' => \[[\s\S]+'currency'/", $src) === 1);

// ─── Symmetric audit ──────────────────────────────────────────────────
$a('post emits cross_tenant.intercompany.posted on from-master',
   str_contains($src, "'cross_tenant.intercompany.posted'"));
$a('post emits cross_tenant.intercompany.received on to-master',
   str_contains($src, "'cross_tenant.intercompany.received'"));
$a('reverse emits cross_tenant.intercompany.reversed',
   str_contains($src, "'cross_tenant.intercompany.reversed'"));

// ─── Compensating reversal on to-leg failure ──────────────────────────
$a('to-leg post wrapped in try/catch',
   preg_match('/\$toJe = accountingPostJe\([\s\S]+?catch \(\\\\Throwable \$e\)/', $src) === 1);
$a('catch invokes accountingReverseJe on the from-leg',
   preg_match('/catch \(\\\\Throwable \$e\)[\s\S]+?accountingReverseJe\([\s\S]+?\$fromJe/', $src) === 1);
$a('catch re-throws the original error',
   preg_match('/catch \(\\\\Throwable \$e\)[\s\S]+?throw \$e;/', $src) === 1);

// ─── intercompany_group_id stamping ───────────────────────────────────
$a('both legs stamped with intercompany_group_id via _cxIcStampGroupId',
   substr_count($src, '_cxIcStampGroupId($pdo, $fromTenantId') >= 1
   && substr_count($src, '_cxIcStampGroupId($pdo, $toTenantId') >= 1);
$a('reverse stamps reversal JE with same group id',
   preg_match('/accountingReverseJe\([\s\S]+?_cxIcStampGroupId/', $src) === 1);
$a('reverse fetches legs by intercompany_group_id',
   str_contains($src, 'intercompany_group_id = :ref')
   && str_contains($src, "source_module = 'cross_tenant_intercompany'"));

// ─── Same-master sibling guard preserved ──────────────────────────────
$a('same-master parent guard preserved',
   str_contains($src, 'cross-tenant intercompany requires the same master parent'));

// ─── Ref format validation ────────────────────────────────────────────
$a('intercompany_ref regex enforced on both post and reverse',
   substr_count($src, '[A-Za-z0-9_-]{4,64}') >= 2);

echo "\n=========================================\n";
echo "Cross-tenant intercompany improvements smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
