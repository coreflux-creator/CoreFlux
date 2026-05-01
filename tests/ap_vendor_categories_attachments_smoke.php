<?php
/**
 * AP receipt-attach + vendor categories + inline vendor creation — contract smoke.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function ($n, $c) use (&$pass, &$fail) {
    if ($c) { echo "  \u{2713} {$n}\n"; $pass++; } else { echo "  \u{2717} {$n}\n"; $fail++; }
};

echo "Migration 008\n";
$sql = (string) file_get_contents(__DIR__ . '/../modules/ap/migrations/008_vendor_categories_attachments.sql');
$a('migration exists',                              strlen($sql) > 0);
$a('utf8mb4_unicode_ci safe',                       strpos($sql, 'utf8mb4_0900_ai_ci') === false);
foreach ([
    'vendor_category', 'payment_method', 'remit_to_email', 'remit_to_phone',
    'payment_account_last4', 'payment_account_ct', 'kms_key_version_payment',
] as $col) {
    $a("ap_vendors_index.{$col} added", strpos($sql, "TABLE_NAME='ap_vendors_index' AND COLUMN_NAME='{$col}'") !== false);
}
$a('ap_bill_lines.attachment_storage_object_id',    strpos($sql, "TABLE_NAME='ap_bill_lines' AND COLUMN_NAME='attachment_storage_object_id'") !== false);
$a('vendor_category enum',                          strpos($sql, 'ENUM("hourly_labor","service_provider")') !== false);
$a('vendor_category default service_provider',      strpos($sql, 'NOT NULL DEFAULT "service_provider"') !== false);
$a('payment_method ENUM',                           strpos($sql, 'ENUM("ach","wire","check","card","cash","plaid","other")') !== false);
$a('1099 + c2c backfill to hourly_labor',           strpos($sql, "vendor_type IN ('1099_individual','c2c_corp')") !== false);
$a('idx_apv_tenant_category',                       strpos($sql, 'idx_apv_tenant_category') !== false);

echo "\nstorage_register helper\n";
$reg = (string) file_get_contents(__DIR__ . '/../core/storage_register.php');
$a('helper file exists',                            strlen($reg) > 0);
$a('registerStorageObject() exists',                strpos($reg, 'function registerStorageObject') !== false);
$a('idempotent on s3_key',                          strpos($reg, 'SELECT id FROM storage_objects WHERE s3_key = :k LIMIT 1') !== false);
$a('returns existing id when found',                strpos($reg, 'if ($found > 0) return $found') !== false);

echo "\nAP bills.php attachment endpoints\n";
$bills = (string) file_get_contents(__DIR__ . '/../modules/ap/api/bills.php');
$a('imports StorageService',                        strpos($bills, "use Core\\StorageService") !== false);
$a('imports storage_register helper',               strpos($bills, "require_once __DIR__ . '/../../../core/storage_register.php'") !== false);
$a('GET upload_url route',                          strpos($bills, "GET' && \$action === 'upload_url'") !== false);
$a('upload_url supports bill-level (id)',           strpos($bills, "\$_GET['id'] ?? 0") !== false
                                                     && strpos($bills, "\$entityType = \$lineId ? 'bill_line' : 'bill'") !== false);
$a('upload_url supports per-line (line_id)',        strpos($bills, "\$_GET['line_id'] ?? 0") !== false);
$a('POST attach route',                             strpos($bills, "POST' && \$action === 'attach'") !== false);
$a('attach updates ap_bills.attachment',            strpos($bills, 'UPDATE ap_bills SET attachment_storage_object_id = :s') !== false);
$a('attach audits ap.bill.attachment.added',        strpos($bills, "'ap.bill.attachment.added'") !== false);
$a('POST attach_line route',                        strpos($bills, "POST' && \$action === 'attach_line'") !== false);
$a('attach_line tenant-scoped via JOIN',            strpos($bills, 'JOIN ap_bills b ON b.id = bl.bill_id') !== false);
$a('attach_line updates bill_lines.attachment',     strpos($bills, 'UPDATE ap_bill_lines SET attachment_storage_object_id = :s') !== false);
$a('attach_line audit',                             strpos($bills, "'ap.bill.line.attachment.added'") !== false);
$a('GET attachment_url returns signed URL',         strpos($bills, "GET' && \$action === 'attachment_url'") !== false
                                                     && strpos($bills, 'get_signed_url') !== false);

echo "\nAP vendors.php — categories + payment\n";
$vapi = (string) file_get_contents(__DIR__ . '/../modules/ap/api/vendors.php');
$a('GET list returns vendor_category',              strpos($vapi, 'v.vendor_category') !== false);
$a('GET list filter ?category=',                    strpos($vapi, "\$_GET['category']") !== false);
$a('POST validates vendor_category',                strpos($vapi, "\$allowedCats = ['hourly_labor','service_provider']") !== false);
$a('POST sensible default by vendor_type',          strpos($vapi, "in_array(\$vendorType, ['1099_individual','c2c_corp'], true) ? 'hourly_labor' : 'service_provider'") !== false);
$a('POST validates payment_method',                 strpos($vapi, "['ach','wire','check','card','cash','plaid','other']") !== false);
$a('POST encrypts payment_account_full',            strpos($vapi, '$payAcctFull ? encryptField($payAcctFull)') !== false);
$a('POST persists vendor_category',                 strpos($vapi, 'vendor_category') !== false && strpos($vapi, ':cat') !== false);
$a('POST persists remit_to_email',                  strpos($vapi, ':rmail') !== false);
$a('UPSERT preserves payment_account_last4',        strpos($vapi, 'payment_account_last4   = COALESCE(VALUES(payment_account_last4), payment_account_last4)') !== false);

echo "\nManifest audit_events\n";
$man = (string) file_get_contents(__DIR__ . '/../modules/ap/manifest.php');
$a('ap.bill.attachment.added',                      strpos($man, 'ap.bill.attachment.added') !== false);
$a('ap.bill.line.attachment.added',                 strpos($man, 'ap.bill.line.attachment.added') !== false);

echo "\nReact uploads helper\n";
$up = (string) file_get_contents(__DIR__ . '/../dashboard/src/lib/uploads.js');
$a('uploadFileViaPresignedPost exported',           strpos($up, 'export async function uploadFileViaPresignedPost') !== false);
$a('uses FormData + S3 POST',                       strpos($up, 'new FormData()') !== false && strpos($up, 'fetch(meta.upload.url') !== false);
$a('S3 success accepts 204 too',                    strpos($up, 'r.status !== 204') !== false);

echo "\nVendorQuickCreate dialog\n";
$vq = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/VendorQuickCreate.jsx');
$a('dialog testid',                                 strpos($vq, 'data-testid="vendor-quick-create"') !== false);
$a('two category radio cards',                      strpos($vq, "vendor-quick-create-cat-\${opt.value}") !== false
                                                     && strpos($vq, "value: 'service_provider'") !== false
                                                     && strpos($vq, "value: 'hourly_labor'") !== false);
$a('hourly_labor blocks shortcut',                  strpos($vq, 'vendor-quick-create-hourly-notice') !== false);
$a('hourly_labor redirects to onboarding',          strpos($vq, 'window.location.href = ') !== false);
$a('service_provider posts to vendors.php',         strpos($vq, "/modules/ap/api/vendors.php") !== false);
$a('payment_method picker',                         strpos($vq, 'vendor-quick-create-payment-method') !== false);
$a('remit_to_email field',                          strpos($vq, 'vendor-quick-create-remit-email') !== false);
$a('bank acct last4 field',                         strpos($vq, 'vendor-quick-create-acct-last4') !== false);

echo "\nBillCreate wiring\n";
$bc = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/BillCreate.jsx');
$a('imports VendorQuickCreate',                     strpos($bc, "import VendorQuickCreate") !== false);
$a('imports uploads helper',                        strpos($bc, "import { uploadFileViaPresignedPost }") !== false);
$a('typeahead has onCreate prop',                   strpos($bc, 'onCreate={(typedName)') !== false);
$a('FileDropZone for invoice PDF',                  strpos($bc, 'testIdPrefix="ap-bill-create-attachment"') !== false);
$a('drag-drop handlers',                            strpos($bc, 'onDragOver') !== false && strpos($bc, 'onDrop') !== false);
$a('25MB client-side limit',                        strpos($bc, '25 * 1024 * 1024') !== false);
$a('post-create attach via uploadFileViaPresignedPost', strpos($bc, 'uploadFileViaPresignedPost(') !== false
                                                     && strpos($bc, "action=upload_url&id=") !== false
                                                     && strpos($bc, "action=attach&id=") !== false);
$a('attach failure does not lose bill (route to detail with error)', strpos($bc, 'attach_error=') !== false);
$a('VendorQuickCreate renders conditionally',       strpos($bc, '{showCreateVendor && (') !== false);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
