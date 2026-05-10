import React, { useState, useEffect, useRef } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { api } from '../lib/api';

/**
 * Magic-link consumer.
 *
 * Mounted at /auth/m/:token. POSTs the token to the consume endpoint,
 * stamps the session, then navigates to the redirect path embedded in
 * the link (or `/` by default).
 *
 * Three failure modes ('invalid' | 'expired' | 'consumed') get distinct
 * messages so the user knows whether to retry or just request a fresh link.
 */
export default function MagicLinkConsume() {
  const { token } = useParams();
  const navigate = useNavigate();
  const [status, setStatus] = useState('verifying'); // 'verifying' | 'ok' | 'error'
  const [error, setError] = useState(null);
  const [reason, setReason] = useState(null);
  const consumed = useRef(false);

  useEffect(() => {
    if (consumed.current) return;
    consumed.current = true;

    (async () => {
      try {
        const r = await api.post('/api/auth/consume_magic_link.php', { token });
        setStatus('ok');
        // Brief pause so the user sees the success state, then deep-link.
        setTimeout(() => {
          const path = r?.redirect_path || '/';
          // Use full reload so the App.jsx session bootstrap re-runs and
          // picks up the new $_SESSION['user'] without us needing to
          // rehydrate state manually.
          window.location.href = path;
        }, 600);
      } catch (e) {
        setStatus('error');
        setError(e?.message || 'Sign-in failed');
        setReason(e?.detail?.reason || null);
      }
    })();
  }, [token]);

  return (
    <div data-testid="magic-link-consume-page" style={styles.page}>
      <div style={styles.card}>
        {status === 'verifying' && (
          <div data-testid="magic-link-verifying">
            <div style={styles.spinner} />
            <h2 style={styles.title}>Signing you in…</h2>
            <p style={styles.subtitle}>One moment.</p>
          </div>
        )}

        {status === 'ok' && (
          <div data-testid="magic-link-ok">
            <div style={{ ...styles.icon, color: '#16a34a' }}>✓</div>
            <h2 style={styles.title}>You're in.</h2>
            <p style={styles.subtitle}>Redirecting…</p>
          </div>
        )}

        {status === 'error' && (
          <div data-testid="magic-link-error">
            <div style={{ ...styles.icon, color: '#dc2626' }}>✕</div>
            <h2 style={styles.title}>{titleFor(reason)}</h2>
            <p style={styles.subtitle} data-testid="magic-link-error-message">
              {messageFor(reason, error)}
            </p>
            <Link
              to="/login"
              data-testid="magic-link-back-to-login"
              style={styles.primaryBtn}
            >
              Request a new link
            </Link>
          </div>
        )}
      </div>
    </div>
  );
}

function titleFor(reason) {
  switch (reason) {
    case 'expired':  return 'This link has expired';
    case 'consumed': return 'This link was already used';
    case 'invalid':  return 'Link not recognized';
    default:         return 'Sign-in failed';
  }
}
function messageFor(reason, fallback) {
  switch (reason) {
    case 'expired':  return 'Sign-in links are valid for 15 minutes. Request a new one.';
    case 'consumed': return 'For security, each link works only once. Request a new one to sign in again.';
    case 'invalid':  return "We couldn't find this link. It may have been mistyped or already cleaned up.";
    default:         return fallback || 'Try requesting a fresh link.';
  }
}

const styles = {
  page: {
    minHeight: '100vh',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    background: 'linear-gradient(135deg, #f1f5f9 0%, #e0f2fe 100%)',
    padding: 16,
  },
  card: {
    background: 'white',
    borderRadius: 16,
    padding: 40,
    width: '100%',
    maxWidth: 420,
    boxShadow: '0 4px 24px rgba(15, 23, 42, 0.06)',
    border: '1px solid #e2e8f0',
    textAlign: 'center',
  },
  spinner: {
    width: 36, height: 36,
    border: '3px solid #e0f2fe',
    borderTopColor: '#0ea5e9',
    borderRadius: '50%',
    margin: '0 auto 16px',
    animation: 'spin 0.8s linear infinite',
  },
  icon: { fontSize: 48, marginBottom: 8, fontWeight: 700 },
  title: { margin: '8px 0 6px', fontSize: 18, color: '#0f172a' },
  subtitle: { margin: 0, color: '#64748b', fontSize: 13 },
  primaryBtn: {
    display: 'inline-block',
    marginTop: 20,
    padding: '11px 22px',
    background: '#0ea5e9',
    color: 'white',
    textDecoration: 'none',
    borderRadius: 8,
    fontSize: 13,
    fontWeight: 600,
  },
};
