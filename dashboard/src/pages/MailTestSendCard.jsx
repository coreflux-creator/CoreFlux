import React from 'react';
import { api } from '../lib/api';

/**
 * MailTestSendCard — one-button confidence check that POSTs to
 * /api/admin/mail_test_send.php and renders the driver + Resend
 * message_id round-trip. Used by both the admin Branding page and
 * the tenant Mail Settings page so admins can verify delivery from
 * wherever they happen to be.
 *
 * Backend requires master_admin or tenant_admin; pass canWrite=false
 * to disable the form for read-only viewers and surface a hint.
 */
export default function MailTestSendCard({ canWrite = true }) {
  const [recipient, setRecipient] = React.useState('');
  const [busy, setBusy]           = React.useState(false);
  const [result, setResult]       = React.useState(null);
  const [err, setErr]             = React.useState(null);
  // Slice 3.3.1 — debounced lookup against the suppression list so the
  // operator sees "this address would be dropped" BEFORE pressing Send.
  const [suppressedHit, setSuppressedHit] = React.useState(null);

  React.useEffect(() => {
    const email = recipient.trim().toLowerCase();
    if (!email || !email.includes('@')) {
      setSuppressedHit(null);
      return;
    }
    let cancelled = false;
    const timer = setTimeout(async () => {
      try {
        const r = await api.get(
          `/api/admin/mail_suppressions.php?q=${encodeURIComponent(email)}&limit=10`
        );
        if (cancelled) return;
        const exact = (r.rows || []).find((row) => row.email === email);
        setSuppressedHit(exact || null);
      } catch {
        // Suppression list unreachable — never block the test send.
        if (!cancelled) setSuppressedHit(null);
      }
    }, 350);
    return () => { cancelled = true; clearTimeout(timer); };
  }, [recipient]);

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true); setResult(null); setErr(null);
    try {
      const r = await api.post('/api/admin/mail_test_send.php', { recipient });
      setResult(r);
    } catch (e2) {
      setErr(e2.message || 'Send failed');
    } finally {
      setBusy(false);
    }
  };

  const unsuppress = async () => {
    if (!suppressedHit) return;
    setBusy(true);
    try {
      await api.delete(`/api/admin/mail_suppressions.php?id=${suppressedHit.id}`);
      setSuppressedHit(null);
    } catch (e2) {
      setErr(e2.message || 'Un-suppress failed');
    } finally {
      setBusy(false);
    }
  };

  const driverTone = (d) => {
    if (d === 'resend')         return { bg: '#dcfce7', fg: '#065f46' };
    if (d === 'log')            return { bg: '#fef3c7', fg: '#92400e' };
    if (d === 'phpmailer_smtp') return { bg: '#dbeafe', fg: '#1e40af' };
    return { bg: '#f1f5f9', fg: '#334155' };
  };

  return (
    <section
      data-testid="admin-mail-test-send"
      style={{
        marginTop: 24, padding: 20, border: '1px solid #e5e7eb',
        borderRadius: 8, background: '#f9fafb',
      }}
    >
      <header style={{ marginBottom: 12 }}>
        <h2 style={{ fontSize: 16, margin: 0 }}>Send test email</h2>
        <p style={{ color: 'var(--cf-text-secondary)', fontSize: 12, margin: '4px 0 0' }}>
          Confidence check after configuring <code>RESEND_API_KEY</code>. The driver column tells
          you which transport actually delivered — <code>resend</code> means your key is live and
          the verified domain is healthy. Rate-limited to one send per admin per 10 seconds.
        </p>
      </header>

      <form onSubmit={submit} style={{ display: 'flex', gap: 8, alignItems: 'flex-start' }}>
        <input
          type="email" required placeholder="you@yourdomain.com"
          className="input" value={recipient}
          onChange={(e) => setRecipient(e.target.value)}
          disabled={!canWrite || busy}
          data-testid="admin-mail-test-send-recipient"
          style={{ flex: 1, maxWidth: 360 }}
        />
        <button
          type="submit" className="btn btn--primary"
          disabled={!canWrite || busy || !recipient}
          data-testid="admin-mail-test-send-submit"
        >
          {busy ? 'Sending…' : 'Send test'}
        </button>
      </form>

      {suppressedHit && (
        <div
          data-testid="admin-mail-test-send-suppression-warn"
          style={{
            marginTop: 10, padding: '8px 12px', borderRadius: 6,
            background: '#fef3c7', color: '#92400e',
            border: '1px solid #fde68a',
            display: 'flex', alignItems: 'center', justifyContent: 'space-between',
            gap: 12, flexWrap: 'wrap',
          }}
        >
          <span style={{ fontSize: 13 }}>
            ⚠ <code data-testid="admin-mail-test-send-suppression-email">{suppressedHit.email}</code> is on the
            suppression list (<strong>{suppressedHit.reason}</strong>). Your test send will be dropped before delivery.
            {suppressedHit.notes && <em style={{ marginLeft: 6, opacity: 0.8 }}>Note: {suppressedHit.notes}</em>}
          </span>
          <button
            type="button" className="btn"
            data-testid="admin-mail-test-send-unsuppress"
            onClick={unsuppress}
            disabled={busy}
            style={{ fontSize: 12 }}
          >
            Un-suppress to allow this test
          </button>
        </div>
      )}

      {!canWrite && (
        <p style={{ fontSize: 12, color: 'var(--cf-text-secondary)', marginTop: 8 }}>
          Admin role required to send test email.
        </p>
      )}

      {err && (
        <p className="error" data-testid="admin-mail-test-send-error" style={{ marginTop: 12 }}>
          {err}
        </p>
      )}

      {result && (
        <div
          data-testid="admin-mail-test-send-result"
          style={{
            marginTop: 12, padding: 12, borderRadius: 6,
            background: result.ok ? '#f0fdf4' : '#fef2f2',
            border: '1px solid ' + (result.ok ? '#bbf7d0' : '#fecaca'),
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 6, flexWrap: 'wrap' }}>
            <span
              data-testid="admin-mail-test-send-status"
              style={{
                padding: '2px 8px', borderRadius: 999, fontSize: 12, fontWeight: 600,
                background: result.ok ? '#bbf7d0' : '#fecaca',
                color:      result.ok ? '#065f46' : '#991b1b',
              }}
            >
              {result.ok ? 'Sent' : 'Failed'}
            </span>
            <span
              data-testid="admin-mail-test-send-driver"
              style={{
                padding: '2px 8px', borderRadius: 999, fontSize: 12, fontWeight: 600,
                ...driverTone(result.driver),
              }}
            >
              driver: {result.driver}
            </span>
            {result.resend_configured ? (
              <span style={{ fontSize: 12, color: '#065f46' }}
                    data-testid="admin-mail-test-send-resend-on">
                RESEND_API_KEY: configured
              </span>
            ) : (
              <span style={{ fontSize: 12, color: '#92400e' }}
                    data-testid="admin-mail-test-send-resend-off">
                RESEND_API_KEY: not set — drop it into <code>config.local.php</code> to enable live delivery
              </span>
            )}
          </div>
          {result.message_id && (
            <div style={{ fontSize: 12, color: '#334155' }} data-testid="admin-mail-test-send-msgid">
              Resend message_id: <code>{result.message_id}</code>
            </div>
          )}
          {result.fallback && (
            <div style={{ fontSize: 12, color: '#92400e', marginTop: 4 }}
                 data-testid="admin-mail-test-send-fallback">
              MailService unavailable — fell back to PHPMailer SMTP. Reason: <code>{result.fallback}</code>
            </div>
          )}
          {result.error && (
            <div style={{ fontSize: 12, color: '#991b1b', marginTop: 4 }}
                 data-testid="admin-mail-test-send-msg-error">
              Error: {result.error}
            </div>
          )}
          {result.ok && !result.error && (
            <div style={{ fontSize: 12, color: '#065f46', marginTop: 4 }}>
              Check your inbox (and spam folder). The send was accepted by{' '}
              <code>{result.driver}</code> at {new Date().toISOString().replace('T', ' ').slice(0, 19)} UTC.
            </div>
          )}
        </div>
      )}
    </section>
  );
}
