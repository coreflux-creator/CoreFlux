<?php
/** Source wiring complement to the native-session, HTTP, race and posting fixtures. */
declare(strict_types=1);
$pass=0;$fail=0;
$a=static function(string $name,bool $ok)use(&$pass,&$fail):void{if($ok)$pass++;else$fail++;echo ($ok?'ok ':'FAIL ').$name."\n";};
$read=static fn(string $path)=>(string)file_get_contents(__DIR__.'/../'.$path);
$service=$read('core/staffing_email_review.php');$helper=$read('core/staffing_email_approval.php');
$page=$read('api/staffing/approve_timesheet_by_email.php');$api=$read('modules/staffing/api/timesheet_email_approver.php');$ui=$read('modules/staffing/ui/StaffingApprovals.jsx');
foreach(['staffingEmailReviewIssue','staffingEmailApprovalReview','staffingEmailApprovalDecide']as$fn)$a($fn.' is defined',str_contains($service,'function '.$fn.'('));
$a('issued facts use a nullable additive canonical migration',str_contains($read('core/migrations/201_staffing_email_review_basis.sql'),'ADD COLUMN IF NOT EXISTS issued_review_json LONGTEXT NULL'));
$a('weekly decisions use native approval and rejection writers',str_contains($service,'staffingTimesheetApplyApproval(')&&str_contains($service,'timeNativeRejectRows('));
$a('weekly issue and decision require durable receipts',str_contains($service,"timeNativeRequiredAudit('time.timesheet.email_token_issued'")&&str_contains($service,"timeNativeRequiredAudit('time.timesheet.email_token_consumed'"));
$a('public form confirms a revision over POST',str_contains($page,'method="post"')&&str_contains($page,'name="review_revision"')&&!str_contains($page,'confirm=1'));
$a('public responses prevent caching and referrer disclosure',str_contains($page,'Cache-Control: private, no-store')&&str_contains($page,'Referrer-Policy: no-referrer'));
$a('dispatcher requires actual staffing approval permission',str_contains($api,"api_require_legacy_permission(\$ctx,'staffing.time.approve')"));
$a('dispatcher uses provider delivery result',str_contains($api,'cf_mail_bootstrap()->send')&&str_contains($api,"!=='log'"));
$a('response contains no secret link',!str_contains(substr($api,strrpos($api,'api_ok(')),"approve_url")&&!str_contains(substr($api,strrpos($api,'api_ok(')),"reject_url"));
$a('screen does not offer approver impersonation',!str_contains($ui,'emailResult.approve_url')&&!str_contains($ui,'staffing-email-approver-fallback'));
$a('accounting handoff follows committed decision',strpos($helper,'staffingEmailApprovalDecide(')<strpos($helper,'staffingEmitWorkerHoursApprovedEvent(')&&str_contains($helper,'getDB()->inTransaction()'));
$a('mail requires review and does not disclose internal bill rates',!str_contains($helper,'one click')&&!str_contains($helper,'Billable amount:'));
echo "$pass passed, $fail failed\n";exit($fail?1:0);
