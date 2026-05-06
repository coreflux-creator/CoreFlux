import React from 'react';
import { Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';

const STATUS_LABEL = {
  received:   'Received (waiting on attachment)',
  downloaded: 'Downloaded',
  extracted:  'Extracted ✓',
  dismissed:  'Dismissed',
  failed:     'Failed',
};
const SOURCE_LABEL = {
  poll_m365:        'M365 inbox',
  poll_gmail:       'Gmail inbox',
  poll_imap:        'IMAP inbox',
  webhook_sendgrid: 'Email (SendGrid)',
  webhook_postmark: 'Email (Postmark)',
  webhook_generic:  'Email (Generic webhook)',
  sms_twilio:       'SMS (Twilio)',
};

/**
 * Intake Queue — emails/SMS/poll pulls that came in for the Time module.
 *
 * The happy path is empty: AI auto-processes attachments and the user
 * picks up the resulting `time_uploaded_documents` rows from the upload
 * page (linked from each row's Open button).
 */
export default function IntakeQueue() {
  const { data, loading, reload } = useApi('/modules/time/api/intake.php');
  const rows = data?.rows || [];
  const [busy, setBusy] = React.useState(null);
  const [pollResult, setPollResult] = React.useState(null);
  const [err, setErr] = React.useState(null);

  const poll = async () => {
    setBusy('poll'); setErr(null); setPollResult(null);
    try {
      const r = await api.post('/modules/time/api/intake.php?action=poll', {});
      setPollResult(r);
      reload();
    } catch (e) { setErr(e.message); }
    finally { setBusy(null); }
  };

  const dismiss = async (id) => {
    setBusy(`dismiss-${id}`); setErr(null);
    try {
      await api.post(`/modules/time/api/intake.php?id=${id}&action=dismiss`, {});
      reload();
    } catch (e) { setErr(e.message); }
    finally { setBusy(null); }
  };
  const reprocess = async (id) => {
    setBusy(`process-${id}`); setErr(null);
    try {
      await api.post(`/modules/time/api/intake.php?id=${id}&action=process`, {});
      reload();
    } catch (e) { setErr(e.message); }
    finally { setBusy(null); }
  };

  return (
    <section data-testid="time-intake-queue" style={{ maxWidth: 1180 }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 16 }}>
        <div>
          <h2 style={{ margin: 0, fontSize: 18 }}>Intake queue</h2>
          <p className="muted" style={{ margin: '4px 0 0', fontSize: 12 }}>
            Inbound timesheets that arrived by email or M365/Gmail polling. AI auto-extracts attachments
            in bulk; click <em>Open</em> on an extracted row to review and save the entries.
          </p>
        </div>
        <button type="button" className="btn btn--primary" onClick={poll} disabled={busy === 'poll'} data-testid="time-intake-poll">
          {busy === 'poll' ? 'Polling…' : 'Poll mail folder'}
        </button>
      </header>

      {err && <p className="error" data-testid="time-intake-error">{err}</p>}
      {pollResult && (
        <p className="muted" data-testid="time-intake-poll-result" style={{ fontSize: 12 }}>
          Polled {pollResult.polled} message{pollResult.polled === 1 ? '' : 's'} · {pollResult.new_intakes} new intake{pollResult.new_intakes === 1 ? '' : 's'}
          {pollResult.note && <> · <em>{pollResult.note}</em></>}
        </p>
      )}

      {loading && <p>Loading…</p>}
      {!loading && rows.length === 0 && (
        <p className="empty" data-testid="time-intake-empty">
          ✓ Inbox clear. Foremen can email a timesheet to your tenant intake address; it'll land here.
        </p>
      )}

      {rows.length > 0 && (
        <table className="data-table" data-testid="time-intake-table">
          <thead>
            <tr>
              <th>Status</th><th>Source</th><th>From</th><th>Subject</th>
              <th>Received</th><th>Atts</th><th>Documents</th><th></th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} data-testid={`time-intake-row-${r.id}`}>
                <td><span className="badge">{STATUS_LABEL[r.status] || r.status}</span></td>
                <td style={{ fontSize: 12 }}>{SOURCE_LABEL[r.source] || r.source}</td>
                <td style={{ fontSize: 12 }}>{r.from_name ? <strong>{r.from_name}</strong> : null} {r.from_address || '—'}</td>
                <td style={{ fontSize: 12 }}>{r.subject || <span className="muted">(no subject)</span>}</td>
                <td style={{ fontSize: 12 }}>{r.received_at || r.created_at}</td>
                <td style={{ fontSize: 12 }}>{r.attachment_count || 0}</td>
                <td style={{ fontSize: 12 }}>
                  {(r.upload_document_ids || []).length > 0
                    ? r.upload_document_ids.map((id) => (
                        <Link key={id} to={`/modules/time/upload?doc=${id}`} data-testid={`time-intake-doc-link-${id}`} style={{ marginRight: 6 }}>
                          #{id}
                        </Link>
                      ))
                    : <span className="muted">—</span>}
                </td>
                <td style={{ fontSize: 12, whiteSpace: 'nowrap' }}>
                  {r.status === 'received' && (
                    <button type="button" className="btn btn--ghost" disabled={busy === `process-${r.id}`} onClick={() => reprocess(r.id)} style={{ padding: '2px 8px' }} data-testid={`time-intake-reprocess-${r.id}`}>Process</button>
                  )}
                  {r.status !== 'dismissed' && (
                    <button type="button" className="btn btn--ghost" disabled={busy === `dismiss-${r.id}`} onClick={() => dismiss(r.id)} style={{ padding: '2px 8px' }} data-testid={`time-intake-dismiss-${r.id}`}>Dismiss</button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
