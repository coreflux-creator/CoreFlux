<?php
/** Public weekly review. GET only reads; a revision-bound POST records the decision. */
declare(strict_types=1);
require_once __DIR__.'/../../core/staffing_email_approval.php';
header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
$method=$_SERVER['REQUEST_METHOD']??'GET';
$input=$method==='POST'?$_POST:$_GET;
$raw=is_string($input['t']??null)?$input['t']:'';
$action=is_string($input['a']??null)?$input['a']:'';
if (!in_array($method,['GET','POST'],true)) {
    http_response_code(405);header('Allow: GET, POST');echo cfWeeklyReviewPage('Method not allowed','<p>Open the link from your approval email.</p>');exit;
}
if ($method==='POST') {
    $result=staffingEmailApprovalConsume($raw,$action,is_string($input['note']??null)?$input['note']:'',$_SERVER['REMOTE_ADDR']??null,is_string($input['review_revision']??null)?$input['review_revision']:null);
    http_response_code($result['status_code']??200);
    echo cfWeeklyReviewPage($result['ok']?($result['state']==='approved'?'Approved':'Returned for correction'):'Decision not recorded',
        '<div data-testid="staffing-email-approval-receipt"><p>'.cfWeeklyReviewEscape($result['message']).'</p>'.
        (isset($result['posting_warning'])?'<p>'.cfWeeklyReviewEscape($result['posting_warning']).'</p>':'').'</div>');exit;
}
try {$review=staffingEmailApprovalReview($raw,$action);}
catch(Throwable $error) {$result=staffingEmailApprovalFailure($error);http_response_code($result['status_code']);echo cfWeeklyReviewPage('Review unavailable','<p>'.cfWeeklyReviewEscape($result['message']).'</p>');exit;}
if ($review['state']!=='pending') {echo cfWeeklyReviewPage('Review closed','<p>'.cfWeeklyReviewEscape($review['message']).'</p>');exit;}
$h='cfWeeklyReviewEscape';$rows='';
foreach($review['entries']as$entry) {
    $rows.='<tr data-entry-id="'.$h($entry['id']).'"><td>'.$h($entry['work_date']).'</td><td>'.$h($entry['placement_number']).' · '.$h($entry['placement_title']).'</td><td>'.
        $h(number_format((float)$entry['hours'],2)).'</td><td>'.$h($entry['hour_type']).' / '.$h($entry['category']).'</td><td>'.$h($entry['description']??'').'</td><td>'.
        $h(($entry['source_system']??'')?:($entry['source']??'')).'</td><td>'.($entry['will_change']?'Awaiting this decision':'Already approved — retained').'</td></tr>';
}
$other=$action==='approve'?'reject':'approve';
$content='<p><strong>'.$h($review['person_number']).' · '.$h($review['worker_name']).'</strong></p><p>'.$h($review['display_id']).' · '.$h($review['period_start']).' through '.$h($review['period_end']).'</p>'.
    '<p>Total week: <strong>'.$h(number_format((float)$review['total_hours'],2)).' hours</strong>. Awaiting this decision: <strong>'.$h(number_format((float)$review['pending_hours'],2)).' hours</strong>.</p>'.
    '<div class="table"><table><thead><tr><th>Date</th><th>Assignment</th><th>Hours</th><th>Type</th><th>Description</th><th>Source</th><th>Review status</th></tr></thead><tbody>'.$rows.'</tbody></table></div>'.
    '<p>Confirm only after reviewing every entry. Previously approved entries keep their existing approval.</p>'.
    '<form method="post" action="/api/staffing/approve_timesheet_by_email.php" data-testid="staffing-email-approval-review">'.
    '<input type="hidden" name="t" value="'.$h($raw).'"><input type="hidden" name="a" value="'.$h($action).'">'.
    '<input type="hidden" name="review_revision" value="'.$h($review['revision']).'">'.
    '<label for="note">'.($action==='reject'?'Reason for returning the week':'Note (optional)').'</label>'.
    '<textarea id="note" name="note" maxlength="500" '.($action==='reject'?'required':'').'></textarea>'.
    '<button type="submit">'.($action==='approve'?'Confirm approval':'Confirm rejection').'</button></form>'.
    '<p><a href="?t='.$h($raw).'&amp;a='.$h($other).'">Review '.$h($other==='approve'?'approval':'rejection').' instead</a></p>'.
    '<p class="muted">Link expires '.$h($review['expires_at']).'. Your decision is recorded when you confirm.</p>'.
    '<script>document.querySelector("form").addEventListener("submit",function(event){if(this.dataset.submitting){event.preventDefault();return;}this.dataset.submitting="1";this.querySelector("button").disabled=true;});</script>';
echo cfWeeklyReviewPage($action==='approve'?'Review weekly approval':'Review weekly rejection',$content);

function cfWeeklyReviewEscape(mixed $value): string {return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function cfWeeklyReviewPage(string $title,string $content): string
{
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.cfWeeklyReviewEscape($title).' — CoreFlux</title>'.
        '<style>body{font-family:system-ui;background:#f8fafc;color:#0f172a;margin:0;padding:24px 12px}.card{max-width:1100px;margin:auto;background:white;padding:24px;border-radius:12px}h1{font-size:24px}p{line-height:1.6}.table{overflow:auto}table{border-collapse:collapse;width:100%;font-size:14px}th,td{text-align:left;padding:10px;border-bottom:1px solid #e2e8f0}textarea{display:block;width:100%;box-sizing:border-box;min-height:90px;margin:8px 0 16px;padding:10px}button{padding:12px 18px;border:0;border-radius:6px;background:#173b64;color:white;font-weight:600}button:disabled{opacity:.6}.muted{color:#64748b;font-size:12px}</style></head><body><main class="card"><h1>'.cfWeeklyReviewEscape($title).'</h1>'.$content.'</main></body></html>';
}
