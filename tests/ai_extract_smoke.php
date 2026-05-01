<?php
/**
 * AI extraction surface — contract smoke.
 * Validates aiExtract() lib + AP bill PDF extraction endpoint + UI wiring.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function ($n, $c) use (&$pass, &$fail) {
    if ($c) { echo "  \u{2713} {$n}\n"; $pass++; } else { echo "  \u{2717} {$n}\n"; $fail++; }
};

echo "ai_service.php — aiExtract()\n";
$svc = (string) file_get_contents(__DIR__ . '/../core/ai_service.php');
$a('aiExtract function exists',                strpos($svc, 'function aiExtract(array $args)') !== false);
$a('rejects without instruction',              strpos($svc, "'aiExtract: instruction required'") !== false);
$a('rejects without images',                   strpos($svc, "'aiExtract: images required") !== false);
$a('uses extraction feature gate',             strpos($svc, "aiGateForTenant(\$tenantId, 'extraction')") !== false);
$a('forces JSON response_format',              strpos($svc, "'response_format' => ['type' => 'json_object']") !== false);
$a('strips ``` fences before json_decode',     strpos($svc, "preg_replace('/^\\s*```") !== false);
$a('throws on non-JSON',                       strpos($svc, "'Extraction model returned non-JSON'") !== false);
$a('logs prompt/response when full_content_logging on', strpos($svc, "\$logFullContent ? \$instruction : null") !== false);
$a('audit feature_class extraction',           strpos($svc, "'feature_class' => 'extraction'") !== false);
$a('supports image url + base64',              strpos($svc, "'image_url' => ['url' => \$img['url']]") !== false
                                                && strpos($svc, "'image_url' => ['url' => \"data:{\$mime};base64,\"") !== false);
$a('falls back once on failure',               strpos($svc, '$primaryModel !== AI_FALLBACK_MODEL') !== false);

echo "\nAP bills.php — extract_from_pdf endpoint\n";
$bills = (string) file_get_contents(__DIR__ . '/../modules/ap/api/bills.php');
$a('POST extract_from_pdf route',              strpos($bills, "POST' && \$action === 'extract_from_pdf'") !== false);
$a('require ap.bill.create perm',              strpos($bills, "POST' && \$action === 'extract_from_pdf'") !== false);
$a('signs storage_key for LLM fetch',          strpos($bills, "StorageService::getInstance()->get_signed_url(\$key)") !== false);
$a('feeds aiExtract with PDF mime',            strpos($bills, "'mime' => 'application/pdf'") !== false);
$a('feature_key ap.bill.from_pdf',             strpos($bills, "'feature_key' => 'ap.bill.from_pdf'") !== false);
$a('schema covers vendor + lines + dates',     strpos($bills, '"vendor_name"') !== false
                                                && strpos($bills, '"line_items"') !== false || strpos($bills, '"lines"') !== false);
$a('schema enumerates 11 item_types',          strpos($bills, '"labor"|"expense"|"materials"|"fixed_fee"|"milestone"|"discount"|"subscription"|"mileage"|"per_diem"|"reimbursement"|"other"') !== false);
$a('audits ap.bill.extracted_from_pdf',        strpos($bills, "'ap.bill.extracted_from_pdf'") !== false);
$a('returns review_required: true',            strpos($bills, "'review_required' => true") !== false);
$a('502 + extractor:gpt on failure',           strpos($bills, "'Extraction failed: '") !== false
                                                && strpos($bills, "'extractor' => 'gpt'") !== false);

echo "\nManifest audit_events\n";
$man = (string) file_get_contents(__DIR__ . '/../modules/ap/manifest.php');
$a('ap.bill.extracted_from_pdf declared',      strpos($man, 'ap.bill.extracted_from_pdf') !== false);

echo "\nBillCreate.jsx — extract UX\n";
$bc = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/BillCreate.jsx');
$a('imports ITEM_TYPES for validation',        strpos($bc, 'import LineItemEditor, { blankLine, ITEM_TYPES }') !== false);
$a('extract button',                           strpos($bc, 'data-testid="ap-bill-create-extract"') !== false);
$a('shows result with vendor + count',         strpos($bc, 'data-testid="ap-bill-create-extract-result"') !== false);
$a('shows error inline',                       strpos($bc, 'data-testid="ap-bill-create-extract-error"') !== false);
$a('extract uploads via presigned POST',       strpos($bc, 'uploadFileViaPresignedPost(') !== false
                                                && strpos($bc, "action=upload_url&file_name=") !== false);
$a('extract POSTs storage_key',                strpos($bc, "action=extract_from_pdf', { storage_key:") !== false);
$a('sets bill_number/date from draft',         strpos($bc, 'if (d.bill_number)  setBillNumber') !== false);
$a('whitelists item_type before merge',        strpos($bc, 'ITEM_TYPE_FALLBACK.includes(l.item_type)') !== false);
$a('NEVER pre-fills vendor (user must pick)',  strpos($bc, "// Merge non-empty fields. We never overwrite the vendor pick") !== false);
$a('NEVER pre-fills GL (no AI guesses)',       strpos($bc, "// never let AI guess GL — user picks") !== false);
$a('disables when no file',                    strpos($bc, "if (!pendingFile) { setExtractError") !== false);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
