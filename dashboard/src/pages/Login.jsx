import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../lib/api';

/**
 * Login page with two modes: magic-link (default) and password (fallback).
 *
 * Magic link is the primary UX for staffing — works regardless of email
 * domain (Gmail, Yahoo, client-issued, employer-issued), no password
 * to remember, link expires in 15 minutes. Password is kept for legacy
 * users and admins who explicitly set one up.
 */
export default function Login() {
  const navigate = useNavigate();
  const [mode, setMode] = useState('magic'); // 'magic' | 'password'

  // Magic link
  const [email, setEmail] = useState('');
  const [magicError, setMagicError] = useState('');
  const [magicMessage, setMagicMessage] = useState('');
  const [magicLoading, setMagicLoading] = useState(false);
  const [devLink, setDevLink] = useState(null);

  // Password
  const [pwUsername, setPwUsername] = useState('');
  const [pwPassword, setPwPassword] = useState('');
  const [pwError, setPwError] = useState('');

  const requestMagicLink = async (e) => {
    e.preventDefault();
    setMagicError('');
    setMagicMessage('');
    setDevLink(null);
    setMagicLoading(true);
    try {
      const r = await api.post('/api/auth/request_magic_link.php', {
        email: email.trim().toLowerCase(),
        redirect_path: '/',
      });
      setMagicMessage(r.message || 'Check your inbox.');
      if (r._dev_link) setDevLink(r._dev_link);
    } catch (err) {
      setMagicError(err.message || 'Could not send link');
    } finally {
      setMagicLoading(false);
    }
  };

  const handlePasswordLogin = async (e) => {
    e.preventDefault();
    setPwError('');
    const formData = new FormData();
    formData.append('username', pwUsername);
    formData.append('password', pwPassword);
    try {
      const response = await fetch('/login.php', {
        method: 'POST',
        body: formData,
        credentials: 'include',
      });
      if (response.redirected) {
        const target = new URL(response.url).pathname;
        navigate(target);
      } else {
        const text = await response.text();
        setPwError(text || 'Login failed');
      }
    } catch (err) {
      setPwError('Network error');
    }
  };

  return (
    <div data-testid="login-page" style={styles.page}>
      <div style={styles.card}>
        <header style={{ textAlign: 'center', marginBottom: 28 }}>
          <h1 style={{ margin: 0, fontSize: 24, color: '#0f172a' }}>Welcome back</h1>
          <p style={{ margin: '6px 0 0', color: '#64748b', fontSize: 13 }}>
            Sign in to CoreFlux
          </p>
        </header>

        {/* Tabs */}
        <div role="tablist" style={styles.tabs}>
          <button
            type="button"
            role="tab"
            aria-selected={mode === 'magic'}
            data-testid="login-tab-magic"
            onClick={() => setMode('magic')}
            style={{ ...styles.tab, ...(mode === 'magic' ? styles.tabActive : {}) }}
          >
            Email me a link
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={mode === 'password'}
            data-testid="login-tab-password"
            onClick={() => setMode('password')}
            style={{ ...styles.tab, ...(mode === 'password' ? styles.tabActive : {}) }}
          >
            Use password
          </button>
        </div>

        {mode === 'magic' && (
          <form onSubmit={requestMagicLink} data-testid="login-form-magic">
            <label style={styles.label}>
              <span>Email address</span>
              <input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="[email protected]"
                required
                autoFocus
                disabled={magicLoading || !!magicMessage}
                data-testid="login-magic-email-input"
                style={styles.input}
              />
            </label>

            <button
              type="submit"
              disabled={magicLoading || !!magicMessage}
              data-testid="login-magic-submit"
              style={styles.primaryBtn}
            >
              {magicLoading ? 'Sending…' : magicMessage ? 'Sent ✓' : 'Send sign-in link'}
            </button>

            {magicError && (
              <p data-testid="login-magic-error" style={styles.errorText}>{magicError}</p>
            )}
            {magicMessage && (
              <div data-testid="login-magic-success" style={styles.successBox}>
                <strong>Check your inbox.</strong>
                <div style={{ fontSize: 12, marginTop: 4 }}>{magicMessage}</div>
                <div style={{ fontSize: 11, color: '#64748b', marginTop: 8 }}>
                  Link expires in 15 minutes. Single use.
                </div>
                {devLink && (
                  <details style={{ marginTop: 12, fontSize: 11 }}>
                    <summary style={{ cursor: 'pointer', color: '#0284c7' }}>Dev link (no mail configured)</summary>
                    <a href={devLink} data-testid="login-magic-dev-link"
                       style={{ wordBreak: 'break-all', display: 'block', marginTop: 6 }}>
                      {devLink}
                    </a>
                  </details>
                )}
              </div>
            )}
            <p style={styles.helperHint}>
              No account? Type your email anyway — if your admin invited you, the
              link will sign you in directly.
            </p>
          </form>
        )}

        {mode === 'password' && (
          <form onSubmit={handlePasswordLogin} data-testid="login-form-password">
            <label style={styles.label}>
              <span>Username</span>
              <input
                type="text"
                value={pwUsername}
                onChange={(e) => setPwUsername(e.target.value)}
                required
                autoFocus
                data-testid="login-password-username"
                style={styles.input}
              />
            </label>
            <label style={styles.label}>
              <span>Password</span>
              <input
                type="password"
                value={pwPassword}
                onChange={(e) => setPwPassword(e.target.value)}
                required
                data-testid="login-password-password"
                style={styles.input}
              />
            </label>
            <button type="submit" data-testid="login-password-submit" style={styles.primaryBtn}>
              Sign in
            </button>
            {pwError && (
              <p data-testid="login-password-error" style={styles.errorText}>{pwError}</p>
            )}
          </form>
        )}
      </div>
    </div>
  );
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
    padding: 36,
    width: '100%',
    maxWidth: 420,
    boxShadow: '0 4px 24px rgba(15, 23, 42, 0.06)',
    border: '1px solid #e2e8f0',
  },
  tabs: {
    display: 'flex',
    gap: 4,
    background: '#f1f5f9',
    padding: 4,
    borderRadius: 10,
    marginBottom: 20,
  },
  tab: {
    flex: 1,
    padding: '8px 12px',
    border: 'none',
    background: 'transparent',
    color: '#64748b',
    fontSize: 13,
    fontWeight: 500,
    borderRadius: 8,
    cursor: 'pointer',
  },
  tabActive: {
    background: 'white',
    color: '#0f172a',
    boxShadow: '0 1px 3px rgba(15, 23, 42, 0.08)',
  },
  label: { display: 'flex', flexDirection: 'column', gap: 6, marginBottom: 16, fontSize: 13, color: '#334155', fontWeight: 500 },
  input: {
    padding: '10px 12px',
    border: '1px solid #cbd5e1',
    borderRadius: 8,
    fontSize: 14,
    fontWeight: 400,
    color: '#0f172a',
  },
  primaryBtn: {
    width: '100%',
    padding: '11px 16px',
    background: '#0ea5e9',
    color: 'white',
    border: 'none',
    borderRadius: 8,
    fontSize: 14,
    fontWeight: 600,
    cursor: 'pointer',
    marginTop: 4,
  },
  errorText: { color: '#dc2626', fontSize: 13, marginTop: 12, marginBottom: 0 },
  successBox: {
    marginTop: 16,
    padding: 14,
    background: '#f0fdf4',
    border: '1px solid #bbf7d0',
    color: '#166534',
    borderRadius: 8,
    fontSize: 13,
  },
  helperHint: {
    fontSize: 11,
    color: '#94a3b8',
    marginTop: 16,
    marginBottom: 0,
    lineHeight: 1.5,
  },
};
