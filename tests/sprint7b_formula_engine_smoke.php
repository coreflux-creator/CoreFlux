<?php
/**
 * Sprint 7b smoke — sandboxed formula evaluator.
 *
 * Asserts:
 *   - 30+ valid arithmetic expressions evaluate correctly
 *   - 20+ malicious / illegal inputs are rejected with PostingFormulaError
 *   - {payload.x.y} interpolation returns expected strings, gracefully
 *     handles missing keys
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/posting_engine/formula.php';

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$ev = function (string $expr, array $ctx = []): float {
    return formulaEvaluate($expr, $ctx);
};
$rej = function (string $expr, array $ctx = []): bool {
    try { formulaEvaluate($expr, $ctx); return false; }
    catch (\Throwable $_) { return true; }
};

echo "Valid expressions\n";
$ctx = ['payload' => ['amount' => 1000.0, 'rate' => 0.07, 'qty' => 3, 'sub' => ['fee' => 25.5]]];
$assert('integer literal',          $ev('42') === 42.0);
$assert('decimal literal',           $ev('3.14') === 3.14);
$assert('addition',                  $ev('2 + 3') === 5.0);
$assert('subtraction',               $ev('10 - 4') === 6.0);
$assert('multiplication',            $ev('6 * 7') === 42.0);
$assert('division',                  $ev('10 / 4') === 2.5);
$assert('modulo',                    $ev('11 % 4') === 3.0);
$assert('parens',                    $ev('(1 + 2) * 3') === 9.0);
$assert('precedence',                $ev('1 + 2 * 3') === 7.0);
$assert('unary minus',               $ev('-5') === -5.0);
$assert('unary minus in expr',       $ev('10 + -5') === 5.0);
$assert('payload ref',               $ev('payload.amount', $ctx) === 1000.0);
$assert('payload + literal',         $ev('payload.amount + 100', $ctx) === 1100.0);
$assert('payload * payload',         $ev('payload.amount * payload.rate', $ctx) === 70.0);
$assert('nested ref',                $ev('payload.sub.fee', $ctx) === 25.5);
$assert('integer-shaped float',      $ev('payload.qty * 2', $ctx) === 6.0);
$assert('whitespace tolerance',      $ev('   5   +   3   ') === 8.0);
$assert('chained division',          $ev('100 / 5 / 2') === 10.0);
$assert('mixed signs',               $ev('-(5 + 3)') === -8.0);
$assert('decimal precision',         abs($ev('0.1 + 0.2') - 0.3) < 1e-9);
$assert('very small fraction',       abs($ev('1 / 1000') - 0.001) < 1e-9);
$assert('large multiplication',      $ev('1000000 * 12') === 12000000.0);
$assert('zero result',               $ev('5 - 5') === 0.0);
$assert('parens in payload context', $ev('(payload.amount + 100) * 2', $ctx) === 2200.0);
$assert('nested parens',             $ev('((1 + 2) * (3 + 4))') === 21.0);
$assert('precedence right-assoc-ish', $ev('2 + 3 * 4 - 1') === 13.0);
$assert('underscore identifiers',    $ev('payload.line_amount + 1', ['payload' => ['line_amount' => 99]]) === 100.0);
$assert('numeric string ref ok',     $ev('payload.amt', ['payload' => ['amt' => '50']]) === 50.0);
$assert('zero',                      $ev('0') === 0.0);
$assert('negative ref',              $ev('-payload.rate', $ctx) === -0.07);

echo "\nIllegal / malicious inputs rejected\n";
$assert('empty string',                $rej(''));
$assert('only whitespace',             $rej('   '));
$assert('division by zero',            $rej('10 / 0'));
$assert('modulo by zero',              $rej('10 % 0'));
$assert('unknown ref strict',          $rej('payload.does_not_exist'));
$assert('non-numeric ref',             $rej('payload.s', ['payload' => ['s' => 'hello']]));
$assert('string literal disallowed',   $rej('"hello"'));
$assert('php function call',           $rej('system("ls")'));
$assert('shell exec',                  $rej('exec("ls")'));
$assert('dollar variable',             $rej('$foo'));
$assert('semicolon injection',         $rej('1 + 1;'));
$assert('php tag',                     $rej('<?php 1 ?>'));
$assert('back-tick',                   $rej('`ls`'));
$assert('brace block',                 $rej('{1+1}'));
$assert('square bracket',              $rej('payload[0]'));
$assert('boolean op',                  $rej('1 || 2'));
$assert('compare op',                  $rej('1 < 2'));
$assert('concat op',                   $rej('1 . 2'));
$assert('lone operator',               $rej('+'));
$assert('trailing operator',           $rej('1 +'));
$assert('mismatched parens',           $rej('(1 + 2'));
$assert('extra close paren',           $rej('1 + 2)'));
$assert('arrow access',                $rej('payload->amount'));
$assert('null coalesce',               $rej('payload.x ?? 0'));

echo "\nInterpolation\n";
$ctx2 = ['payload' => ['vendor' => 'Acme', 'amount' => 500, 'meta' => ['ref' => 'X-1']]];
$assert('simple interp',             formulaInterpolate('Bill to {payload.vendor}', $ctx2) === 'Bill to Acme');
$assert('numeric interp',            formulaInterpolate('Amount: {payload.amount}', $ctx2) === 'Amount: 500');
$assert('nested interp',             formulaInterpolate('Ref {payload.meta.ref}', $ctx2) === 'Ref X-1');
$assert('missing key → empty',       formulaInterpolate('Hi {payload.absent}', $ctx2) === 'Hi ');
$assert('multiple placeholders',     formulaInterpolate('{payload.vendor}/{payload.amount}', $ctx2) === 'Acme/500');
$assert('no placeholders pass-thru', formulaInterpolate('plain text', $ctx2) === 'plain text');

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
