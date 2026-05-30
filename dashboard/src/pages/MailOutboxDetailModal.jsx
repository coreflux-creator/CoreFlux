import React, { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { X, ExternalLink, Eye, EyeOff, ShieldOff, CheckCircle2, AlertCircle } from 'lucide-react';

/**
 * MailOutboxDetailModal — drill-in for a single mail_outbox row,
 * opened from the Mail Health card's failure list. Surfaces the
 * full envelope, the rendered HTML body (on demand), and any
 * webhook events Resend has fired for the message id (so an admin
 * can forward the rejection reason to the customer).
 *
 * Also offers a one-click "Suppress this recipient" action so
 * troubleshooting bounce → suppression takes seconds instead of
 * minutes.
 */
const STATUS_TONE = {
  sent:      { bg: '#dcfce7', fg: '#065f46' },
  queued:    { bg: '#dbeafe', fg: '#1e40af' },
  failed:    { bg: '#fef2f2', fg: '#991b1b' },
  bounced:   { bg: '#fef2f2', fg: '#991b1b' },
  complaint: { bg: '#fef3c7', fg: '#92400e' },
};

export default function MailOutboxDetailModal({ outboxId, onClose }) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [showBody, setShowBody] = useState(false);
  const [showPreview, setShowPreview] = useState(false);
  const [suppressing, setSuppressing] = useState(null);
  const [flash, setFlash] = useState(null);

  const load = async (includeBody = false) => {
    setLoading(true); setError(null);
    try {
      const r = await api.get(
        `/api/admin/mail_outbox_show.php?id=${outboxId}${includeBody ? '&include_body=1' : ''}`
      );
      setData(r);
    } catch (e) {
      setError(e.message || String(e));
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [outboxId]);

  const requestBody = async () => {
    if (data?.body_html != null || data?.body_text != null) {
      setShowBody((s) => !s);
      return;
    }
    setShowBody(true);
    await load(true);
  };

  const suppress = async (email) => {
    if (!email) return;
    if (!window.confirm(`Suppress ${email}? CoreFlux will skip this address on future sends until you remove the suppression.`)) return;
    setSuppressing(email); setFlash(null);
    try {
      await api.post('/api/admin/mail_suppressions.php', {
        email,
        reason: 'manual',
        notes:  `Suppressed from mail_outbox detail (outbox_id=${outboxId})`,
      });
      setFlash({ kind: 'ok', msg: `${email} suppressed.` });
    } catch (e) {
      setFlash({ kind: 'err', msg: e.message || 'Suppress failed' });
    } finally {
      setSuppressing(null);
    }
  };

  return (
    <div
      data-testid="mail-outbox-detail-modal"
      role="dialog" aria-modal="true"
      style={{
        position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.55)',
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        zIndex: 1000, padding: 24,
      }}
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div style={{
        background: '#fff', borderRadius: 8,
        width: 'min(820px, 96vw)', maxHeight: '92vh',
        overflow: 'hidden', display: 'flex', flexDirection: 'column',
        boxShadow: '0 20px 50px rgba(0,0,0,0.25)',
      }}>
        <header style={{ padding: '14px 20px', borderBottom: '1px solid #e5e7eb',
                         display: 'flex', alignItems: 'flex-start', gap: 12 }}>
          <div style={{ flex: 1, minWidth: 0 }}>
            <h3 style={{ margin: 0, fontSize: 17, fontWeight: 600 }}>
              Mail outbox · #{outboxId}
            </h3>
            <p style={{ margin: '4px 0 0', fontSize: 12, color: '#64748b' }}>
              {data?.subject || (loading ? 'Loading…' : 'No subject')}
            </p>
          </div>
          <button type="button" className="btn"
                  data-testid="mail-outbox-detail-close"
                  onClick={onClose}>
            <X size={14} />
          </button>
        </header>

        <div style={{ flex: 1, overflow: 'auto', padding: '14px 20px' }}>
          {loading && <p data-testid="mail-outbox-detail-loading">Loading…</p>}
          {error && (
            <p data-testid="mail-outbox-detail-error" style={{ color: '#b91c1c' }}>
              {error}
            </p>
          )}
          {!loading && !error && data && (
            <>
              {flash && (
                <div data-testid="mail-outbox-detail-flash"
                     style={{
                       marginBottom: 12, padding: '6px 10px', borderRadius: 4, fontSize: 13,
                       background: flash.kind === 'ok' ? '#dcfce7' : '#fef2f2',
                       color:      flash.kind === 'ok' ? '#065f46' : '#991b1b',
                     }}>
                  {flash.kind === 'ok' ? <CheckCircle2 size={13} style={{ marginRight: 4, verticalAlign: 'middle' }} /> :
                                          <AlertCircle  size={13} style={{ marginRight: 4, verticalAlign: 'middle' }} />}
                  {flash.msg}
                </div>
              )}

              {/* Envelope grid */}
              <dl data-testid="mail-outbox-detail-envelope"
                  style={{ display: 'grid', gridTemplateColumns: 'max-content 1fr',
                           gap: '4px 12px', fontSize: 13, margin: 0 }}>
                <DT label="Status">
                  <StatusPill status={data.status} />
                </DT>
                <DT label="Driver"><code>{data.driver}</code></DT>
                <DT label="Module / Purpose"><code>{data.module} / {data.purpose}</code></DT>
                <DT label="From">{data.from_address || <em style={{ color: '#94a3b8' }}>(platform default)</em>}</DT>
                {data.reply_to && <DT label="Reply-To">{data.reply_to}</DT>}
                <DT label="To">
                  <div data-testid="mail-outbox-detail-to" style={{ display: 'flex', flexWrap: 'wrap', gap: 4 }}>
                    {(data.to || []).map((t, i) => {
                      const email = typeof t === 'string' ? t : (t.email || t.address || '');
                      return (
                        <span key={i}
                              data-testid={`mail-outbox-detail-to-${i}`}
                              style={{ display: 'inline-flex', alignItems: 'center', gap: 4,
                                       padding: '1px 8px', borderRadius: 999,
                                       background: '#eef2ff', color: '#3730a3', fontSize: 12 }}>
                          {email}
                          {email && (
                            <button type="button"
                                    data-testid={`mail-outbox-detail-suppress-${i}`}
                                    onClick={() => suppress(email)}
                                    disabled={suppressing === email}
                                    title="Suppress this recipient"
                                    style={{ background: 'transparent', border: 'none',
                                             cursor: 'pointer', color: '#7c3aed',
                                             padding: 0, fontSize: 11 }}>
                              <ShieldOff size={11} />
                            </button>
                          )}
                        </span>
                      );
                    })}
                  </div>
                </DT>
                <DT label="Created at"><code>{data.created_at}</code></DT>
                {data.sent_at && <DT label="Sent at"><code>{data.sent_at}</code></DT>}
                {data.provider_message_id && (
                  <DT label="Message id">
                    <code data-testid="mail-outbox-detail-msgid">{data.provider_message_id}</code>
                  </DT>
                )}
                {data.error && (
                  <DT label="Error">
                    <pre data-testid="mail-outbox-detail-error-text"
                         style={{ margin: 0, padding: '6px 8px',
                                  background: '#fef2f2', color: '#991b1b',
                                  borderRadius: 4, fontSize: 12,
                                  whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>
                      {data.error}
                    </pre>
                  </DT>
                )}
              </dl>

              {/* Body toggle */}
              <div style={{ marginTop: 16 }}>
                <button type="button" className="btn"
                        data-testid="mail-outbox-detail-body-toggle"
                        onClick={requestBody}
                        style={{ fontSize: 12 }}>
                  {showBody
                    ? <><EyeOff size={13} style={{ marginRight: 4 }} />Hide body</>
                    : <><Eye    size={13} style={{ marginRight: 4 }} />Show body</>}
                </button>
                {data?.body_truncated_at && (
                  <span style={{ marginLeft: 8, fontSize: 12, color: '#64748b' }}>
                    Body was truncated for retention on {data.body_truncated_at}.
                  </span>
                )}
              </div>

              {showBody && (data.body_html != null || data.body_text != null) && (
                <div data-testid="mail-outbox-detail-body" style={{ marginTop: 8 }}>
                  {data.body_html && (
                    <>
                      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 4 }}>
                        <strong style={{ fontSize: 12, color: '#64748b' }}>HTML</strong>
                        <button type="button"
                                data-testid="mail-outbox-detail-preview-toggle"
                                onClick={() => setShowPreview((s) => !s)}
                                style={{ background: 'transparent', border: 'none',
                                         color: '#4338ca', fontSize: 12, cursor: 'pointer' }}>
                          {showPreview ? 'View raw HTML' : 'Render preview'}
                        </button>
                      </div>
                      {showPreview ? (
                        <iframe
                          data-testid="mail-outbox-detail-preview-frame"
                          srcDoc={data.body_html}
                          title={`mail-outbox-${outboxId}-preview`}
                          sandbox=""
                          style={{ width: '100%', height: 280, border: '1px solid #e5e7eb', borderRadius: 4 }}
                        />
                      ) : (
                        <pre style={{
                          fontSize: 11, background: '#0f172a', color: '#e2e8f0',
                          padding: 10, borderRadius: 4, maxHeight: 280, overflow: 'auto',
                        }}>{data.body_html}</pre>
                      )}
                    </>
                  )}
                  {data.body_text && (
                    <>
                      <strong style={{ fontSize: 12, color: '#64748b' }}>Plain text</strong>
                      <pre data-testid="mail-outbox-detail-body-text"
                           style={{
                             fontSize: 11, background: '#f8fafc',
                             padding: 10, borderRadius: 4, maxHeight: 220, overflow: 'auto',
                             whiteSpace: 'pre-wrap', wordBreak: 'break-word',
                           }}>{data.body_text}</pre>
                    </>
                  )}
                </div>
              )}

              {/* Webhook events */}
              {data.webhook_events && data.webhook_events.length > 0 && (
                <section data-testid="mail-outbox-detail-events" style={{ marginTop: 20 }}>
                  <strong style={{ fontSize: 12, color: '#64748b',
                                   textTransform: 'uppercase', letterSpacing: 0.3 }}>
                    Resend webhook events
                  </strong>
                  <ul style={{ listStyle: 'none', padding: 0, margin: '6px 0 0', fontSize: 12 }}>
                    {data.webhook_events.map((e) => (
                      <li key={e.id}
                          data-testid={`mail-outbox-detail-event-${e.id}`}
                          style={{ padding: '4px 8px', marginBottom: 4,
                                   background: '#f9fafb', border: '1px solid #e5e7eb',
                                   borderRadius: 4,
                                   display: 'flex', alignItems: 'center', gap: 8 }}>
                        <code style={{ color: '#3730a3' }}>{e.event_type}</code>
                        <span style={{ color: '#64748b' }}>· {e.received_at}</span>
                        {!e.signature_verified && (
                          <span style={{ marginLeft: 'auto', fontSize: 10, padding: '1px 6px',
                                         borderRadius: 4, background: '#fee2e2', color: '#991b1b' }}>
                            unverified
                          </span>
                        )}
                      </li>
                    ))}
                  </ul>
                </section>
              )}
            </>
          )}
        </div>
      </div>
    </div>
  );
}

function DT({ label, children }) {
  return (
    <>
      <dt style={{ color: '#64748b' }}>{label}</dt>
      <dd style={{ margin: 0 }}>{children}</dd>
    </>
  );
}

function StatusPill({ status }) {
  const tone = STATUS_TONE[status] || { bg: '#f1f5f9', fg: '#334155' };
  return (
    <span
      data-testid={`mail-outbox-detail-status-${status || 'unknown'}`}
      style={{
        padding: '1px 8px', borderRadius: 999, fontSize: 11, fontWeight: 600,
        background: tone.bg, color: tone.fg,
      }}>
      {status || 'unknown'}
    </span>
  );
}
