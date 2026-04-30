<?php
/**
 * Public tokenized-approval landing page.
 *
 * This is NOT behind login — the token in the URL is the credential. The
 * page renders a summary of the timesheet and lets the client approver
 * Approve or Reject.
 *
 * Flow:
 *   GET  /time_approve.php?t=<token>[&a=approve|reject]
 *     → HTML preview. If ?a= is present and user confirms, the form POSTs
 *       to the API endpoint below.
 *
 * The actual state change is handled by /modules/time/api/approval_tokens.php
 * (?action=respond). This file only renders.
 */

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/modules/time/lib/approval_tokens.php';

$raw    = (string) ($_GET['t'] ?? '');
$prefer = (string) ($_GET['a'] ?? '');
$valid  = preg_match('/^[a-f0-9]{64}$/', $raw) === 1;
$row    = $valid ? timeTokenFindByRaw($raw) : null;

// Auto-expire lazily on page load
if ($row && $row['response'] === 'pending' && $row['expires_at'] < date('Y-m-d H:i:s')) {
    $pdo = getDB();
    if ($pdo) {
        $pdo->prepare('UPDATE time_approval_tokens SET response = "expired" WHERE id = :id')->execute(['id' => $row['id']]);
        $row['response'] = 'expired';
    }
}

$title = 'Timesheet approval — CoreFlux';
$appUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://www.corefluxapp.com';

$entries = [];
$placement = null;
if ($row) {
    $entryIds = json_decode((string) $row['entries_json'], true)['entry_ids'] ?? [];
    $pdo = getDB();
    if ($pdo) {
        $pstmt = $pdo->prepare('SELECT p.title, p.end_client_name, p.person_id, pe.first_name, pe.last_name
                                FROM placements p
                                LEFT JOIN people pe ON pe.id = p.person_id AND pe.tenant_id = p.tenant_id
                                WHERE p.id = :id AND p.tenant_id = :tid');
        $pstmt->execute(['id' => $row['placement_id'], 'tid' => $row['tenant_id']]);
        $placement = $pstmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!empty($entryIds)) {
            $in = implode(',', array_map('intval', $entryIds));
            $estmt = $pdo->query("SELECT id, work_date, category, hours, description
                                  FROM time_entries
                                  WHERE tenant_id = {$row['tenant_id']}
                                    AND id IN ({$in})
                                  ORDER BY work_date, id");
            $entries = $estmt ? $estmt->fetchAll(PDO::FETCH_ASSOC) : [];
        }
    }
}

function cf_esc($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex, nofollow" />
<title><?= cf_esc($title) ?></title>
<style>
  :root { --fg:#111827; --muted:#6b7280; --bg:#f7f8fa; --surface:#fff; --border:#e5e7eb; --brand:#1f2937; --success:#047857; --danger:#b91c1c; }
  * { box-sizing: border-box; }
  body { margin:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; color: var(--fg); background: var(--bg); }
  .wrap { max-width: 640px; margin: 40px auto; padding: 0 20px; }
  .card { background: var(--surface); border:1px solid var(--border); border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); overflow: hidden; }
  .card__header { padding: 20px 24px; border-bottom:1px solid var(--border); }
  .card__header h1 { margin: 0 0 4px; font-size: 20px; }
  .card__header p { margin: 0; color: var(--muted); font-size: 14px; }
  .card__body { padding: 20px 24px; }
  .summary { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
  .summary > div { flex: 1 1 140px; padding: 12px; background: var(--bg); border-radius: 8px; }
  .summary .label { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
  .summary .value { font-size: 20px; font-weight: 600; margin-top: 2px; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th, td { padding: 8px 10px; border-bottom: 1px solid var(--border); text-align: left; }
  th { background: var(--bg); font-weight: 600; font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
  td.right, th.right { text-align: right; }
  .actions { display: flex; gap: 12px; margin-top: 24px; }
  .btn { flex: 1; padding: 12px 18px; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; }
  .btn--approve { background: var(--success); color: #fff; }
  .btn--reject  { background: #fff; color: var(--danger); border: 1px solid var(--danger); }
  .state { padding: 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
  .state--ok   { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
  .state--bad  { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
  .state--warn { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
  textarea { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px; font: inherit; min-height: 80px; margin-top: 12px; }
  .footer { padding: 16px 24px; font-size: 12px; color: var(--muted); text-align: center; border-top: 1px solid var(--border); }
  .footer a { color: var(--muted); }
</style>
</head>
<body>
<div class="wrap">
  <div class="card" data-testid="time-approve-page">
  <?php if (!$row): ?>
    <div class="card__header"><h1>Link not recognised</h1></div>
    <div class="card__body"><div class="state state--bad" data-testid="time-approve-invalid">This approval link is not valid. If you received this email from CoreFlux, please contact the sender.</div></div>
  <?php elseif ($row['response'] !== 'pending'): ?>
    <div class="card__header"><h1>This link has already been used</h1>
      <p>Status: <strong data-testid="time-approve-status"><?= cf_esc($row['response']) ?></strong><?php if ($row['responded_at']): ?> at <?= cf_esc($row['responded_at']) ?><?php endif; ?></p>
    </div>
    <div class="card__body">
      <div class="state state--warn">Approval links are single-use. If you need to make a change, please contact your staffing partner.</div>
    </div>
  <?php else:
    $total = 0; $byDay = [];
    foreach ($entries as $e) { $total += (float) $e['hours']; $byDay[$e['work_date']] = ($byDay[$e['work_date']] ?? 0) + (float) $e['hours']; }
    $who = trim(($placement['first_name'] ?? '') . ' ' . ($placement['last_name'] ?? '')) ?: 'the consultant';
  ?>
    <div class="card__header">
      <h1>Timesheet approval</h1>
      <p>Please review hours for <strong data-testid="time-approve-who"><?= cf_esc($who) ?></strong> on <strong data-testid="time-approve-placement"><?= cf_esc($placement['title'] ?? ('Placement #' . $row['placement_id'])) ?></strong>.</p>
    </div>
    <div class="card__body">
      <div class="summary">
        <div><div class="label">Total hours</div><div class="value" data-testid="time-approve-total"><?= number_format($total, 2) ?></div></div>
        <div><div class="label">Days</div><div class="value"><?= count($byDay) ?></div></div>
        <div><div class="label">Expires</div><div class="value" style="font-size:14px"><?= cf_esc($row['expires_at']) ?></div></div>
      </div>

      <table data-testid="time-approve-table">
        <thead><tr><th>Day</th><th>Category</th><th class="right">Hours</th></tr></thead>
        <tbody>
          <?php foreach ($entries as $e): ?>
          <tr>
            <td><?= cf_esc($e['work_date']) ?></td>
            <td><?= cf_esc(str_replace('_', ' ', $e['category'])) ?></td>
            <td class="right"><?= number_format((float) $e['hours'], 2) ?></td>
          </tr>
          <?php endforeach; ?>
          <tr><td colspan="2" style="font-weight:600">Total</td><td class="right" style="font-weight:600"><?= number_format($total, 2) ?></td></tr>
        </tbody>
      </table>

      <form id="respond" method="POST" action="/modules/time/api/approval_tokens.php?action=respond" data-testid="time-approve-form"
            onsubmit="return cfSubmit(event)">
        <input type="hidden" name="t" value="<?= cf_esc($raw) ?>" />
        <textarea name="note" placeholder="Optional note (shown in the audit log)" data-testid="time-approve-note"></textarea>
        <div class="actions">
          <button class="btn btn--approve" type="submit" name="action" value="approve" data-testid="time-approve-btn-approve">Approve timesheet</button>
          <button class="btn btn--reject"  type="submit" name="action" value="reject"  data-testid="time-approve-btn-reject">Reject</button>
        </div>
      </form>
    </div>
  <?php endif; ?>
    <div class="footer">CoreFlux · This is a one-time approval link. <a href="<?= cf_esc($appUrl) ?>"><?= cf_esc(parse_url($appUrl, PHP_URL_HOST) ?: 'corefluxapp.com') ?></a></div>
  </div>
</div>
<script>
async function cfSubmit(ev) {
  ev.preventDefault();
  const form = ev.target;
  const submitter = ev.submitter || form.querySelector('button[type=submit]');
  const action = submitter?.value || 'approve';
  const body = {
    t: form.elements['t'].value,
    action,
    note: form.elements['note'].value,
  };
  submitter.disabled = true;
  submitter.textContent = action === 'approve' ? 'Approving…' : 'Rejecting…';
  try {
    const res = await fetch(form.action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({}));
    const card = document.querySelector('.card__body');
    if (res.ok) {
      card.innerHTML = '<div class="state state--ok" data-testid="time-approve-success">Thank you — your response has been recorded as <strong>' + (data.response || action) + '</strong>. You can close this page.</div>';
    } else {
      card.innerHTML = '<div class="state state--bad" data-testid="time-approve-error">Could not record response: ' + (data.error || ('HTTP ' + res.status)) + '</div>';
    }
  } catch (e) {
    alert('Network error: ' + e.message);
    submitter.disabled = false;
  }
  return false;
}
</script>
</body>
</html>
