<?php
/**
 * Sandboxed formula evaluator (Sprint 7b, spec §13).
 *
 * Restricted arithmetic grammar — NO PHP eval, NO callables, NO string
 * concatenation, NO file system, NO global state. The only inputs are
 * numeric literals, parentheses, the 5 arithmetic operators, and
 * dotted payload references like `payload.amount` or `payload.line.amount`.
 *
 *     EXPR    := TERM (('+'|'-') TERM)*
 *     TERM    := FACTOR (('*'|'/'|'%') FACTOR)*
 *     FACTOR  := '-' FACTOR | '(' EXPR ')' | NUMBER | REF
 *     REF     := IDENT ('.' IDENT)*
 *     IDENT   := [a-zA-Z_][a-zA-Z0-9_]*
 *     NUMBER  := [0-9]+ ('.' [0-9]+)?
 *
 * Anything outside this grammar throws InvalidArgumentException.
 *
 * Public API:
 *   formulaEvaluate(string $expr, array $context): float
 *   formulaInterpolate(string $template, array $context): string
 *
 * `formulaInterpolate` only resolves `{payload.x.y}` placeholders (no
 * evaluation, no escaping; safe because templates render to plain text
 * memo fields, never HTML).
 */
declare(strict_types=1);

class PostingFormulaError extends \InvalidArgumentException {}

/**
 * Evaluate an arithmetic formula against a context array.
 * Returns a float. Throws PostingFormulaError on syntax/lookup error.
 */
function formulaEvaluate(string $expr, array $context): float {
    $expr = trim($expr);
    if ($expr === '') {
        throw new PostingFormulaError('empty formula');
    }
    $tokens = formulaTokenize($expr);
    if (!$tokens) {
        throw new PostingFormulaError('no tokens');
    }
    $pos = 0;
    $value = formulaParseExpr($tokens, $pos, $context);
    if ($pos < count($tokens)) {
        throw new PostingFormulaError('unexpected token "' . $tokens[$pos][1] . '"');
    }
    return (float) $value;
}

/**
 * Resolve {payload.x.y} placeholders inside a template.
 * Missing keys interpolate to empty string.
 */
function formulaInterpolate(string $template, array $context): string {
    return (string) preg_replace_callback(
        '/\{([a-zA-Z_][a-zA-Z0-9_.]*)\}/',
        function ($m) use ($context) {
            $v = formulaResolveRef($m[1], $context, /* strict */ false);
            if ($v === null) return '';
            if (is_scalar($v)) return (string) $v;
            return json_encode($v);
        },
        $template
    );
}

// ──────────────────────────────────────────────────────────────────
// internals
// ──────────────────────────────────────────────────────────────────

function formulaTokenize(string $expr): array {
    $tokens = [];
    $len = strlen($expr);
    $i = 0;
    while ($i < $len) {
        $c = $expr[$i];
        if (ctype_space($c)) { $i++; continue; }
        if (in_array($c, ['+', '-', '*', '/', '%', '(', ')'], true)) {
            $tokens[] = ['op', $c];
            $i++;
            continue;
        }
        if (ctype_digit($c) || $c === '.') {
            $j = $i;
            $sawDot = false;
            while ($j < $len && (ctype_digit($expr[$j]) || ($expr[$j] === '.' && !$sawDot))) {
                if ($expr[$j] === '.') $sawDot = true;
                $j++;
            }
            $tokens[] = ['num', substr($expr, $i, $j - $i)];
            $i = $j;
            continue;
        }
        if (ctype_alpha($c) || $c === '_') {
            $j = $i;
            while ($j < $len && (ctype_alnum($expr[$j]) || $expr[$j] === '_' || $expr[$j] === '.')) {
                $j++;
            }
            $tokens[] = ['ref', substr($expr, $i, $j - $i)];
            $i = $j;
            continue;
        }
        throw new PostingFormulaError("illegal character '{$c}' at offset {$i}");
    }
    return $tokens;
}

function formulaParseExpr(array $tokens, int &$pos, array $context): float {
    $left = formulaParseTerm($tokens, $pos, $context);
    while ($pos < count($tokens) && $tokens[$pos][0] === 'op' && in_array($tokens[$pos][1], ['+', '-'], true)) {
        $op = $tokens[$pos][1]; $pos++;
        $right = formulaParseTerm($tokens, $pos, $context);
        $left = $op === '+' ? $left + $right : $left - $right;
    }
    return $left;
}

function formulaParseTerm(array $tokens, int &$pos, array $context): float {
    $left = formulaParseFactor($tokens, $pos, $context);
    while ($pos < count($tokens) && $tokens[$pos][0] === 'op' && in_array($tokens[$pos][1], ['*', '/', '%'], true)) {
        $op = $tokens[$pos][1]; $pos++;
        $right = formulaParseFactor($tokens, $pos, $context);
        if (($op === '/' || $op === '%') && $right == 0.0) {
            throw new PostingFormulaError('division by zero');
        }
        $left = match ($op) {
            '*' => $left * $right,
            '/' => $left / $right,
            '%' => fmod($left, $right),
        };
    }
    return $left;
}

function formulaParseFactor(array $tokens, int &$pos, array $context): float {
    if ($pos >= count($tokens)) {
        throw new PostingFormulaError('unexpected end of expression');
    }
    $tok = $tokens[$pos];
    if ($tok[0] === 'op' && $tok[1] === '-') {
        $pos++;
        return -formulaParseFactor($tokens, $pos, $context);
    }
    if ($tok[0] === 'op' && $tok[1] === '(') {
        $pos++;
        $val = formulaParseExpr($tokens, $pos, $context);
        if ($pos >= count($tokens) || $tokens[$pos] !== ['op', ')']) {
            throw new PostingFormulaError('missing close paren');
        }
        $pos++;
        return $val;
    }
    if ($tok[0] === 'num') {
        $pos++;
        return (float) $tok[1];
    }
    if ($tok[0] === 'ref') {
        $pos++;
        $resolved = formulaResolveRef($tok[1], $context, /* strict */ true);
        if (!is_numeric($resolved)) {
            throw new PostingFormulaError("non-numeric reference '{$tok[1]}' resolved to " . var_export($resolved, true));
        }
        return (float) $resolved;
    }
    throw new PostingFormulaError('unexpected token "' . ($tok[1] ?? '?') . '"');
}

/**
 * Resolve a dotted path against the context. When $strict, missing keys
 * throw; otherwise null is returned (used by formulaInterpolate).
 */
function formulaResolveRef(string $path, array $context, bool $strict): mixed {
    $parts = explode('.', $path);
    $cur = $context;
    foreach ($parts as $p) {
        if ($p === '') {
            if ($strict) throw new PostingFormulaError("empty ref segment in '{$path}'");
            return null;
        }
        if (is_array($cur) && array_key_exists($p, $cur)) {
            $cur = $cur[$p];
            continue;
        }
        if ($strict) throw new PostingFormulaError("unknown reference '{$path}' (failed at '{$p}')");
        return null;
    }
    return $cur;
}
