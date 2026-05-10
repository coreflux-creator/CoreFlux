import React from 'react';

/**
 * Module-level error boundary.
 *
 * Wraps each module's <Routes> so a single component crash (e.g. SQL-error
 * fallback shape that breaks .map(), null deref, missing prop) shows a
 * recoverable inline banner instead of a blank-screen / dropped tab nav.
 *
 * Pattern: place ONCE high in the tree (App.jsx around module routes) so
 * every module gets it for free. Per-module boundaries are still cheap to
 * add by importing this component directly.
 */
export default class ErrorBoundary extends React.Component {
  constructor(props) {
    super(props);
    this.state = { error: null, info: null };
    this.lastPath = typeof window !== 'undefined' ? window.location.pathname : '';
  }

  static getDerivedStateFromError(error) {
    return { error };
  }

  componentDidCatch(error, info) {
    // Surface to console + log to browser dev tools. We don't ship a remote
    // error sink yet — when we do, this is the chokepoint.
    // eslint-disable-next-line no-console
    console.error('[ErrorBoundary]', error, info?.componentStack);
    this.setState({ info });
  }

  componentDidUpdate() {
    // Auto-reset when the route changes so the user can navigate away from
    // the broken page without a full reload. Cheap path comparison.
    if (typeof window === 'undefined') return;
    const here = window.location.pathname;
    if (this.state.error && here !== this.lastPath) {
      this.lastPath = here;
      this.setState({ error: null, info: null });
    }
  }

  reset = () => {
    this.setState({ error: null, info: null });
  };

  render() {
    if (!this.state.error) return this.props.children;

    const msg = this.state.error?.message || String(this.state.error);
    const stack = this.state.info?.componentStack || this.state.error?.stack || '';

    return (
      <div data-testid="error-boundary" style={{ padding: 24 }}>
        <div style={{
          background: '#fef2f2', border: '1px solid #fecaca',
          color: '#991b1b', borderRadius: 12, padding: 20, maxWidth: 880,
        }}>
          <div style={{ fontWeight: 700, fontSize: 15, marginBottom: 6 }}>Something broke on this screen.</div>
          <div style={{ fontSize: 13, marginBottom: 14, fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace' }} data-testid="error-boundary-message">
            {msg}
          </div>
          <div style={{ display: 'flex', gap: 8, marginBottom: 14 }}>
            <button className="btn btn--primary" onClick={this.reset} data-testid="error-boundary-retry">Try again</button>
            <button className="btn" onClick={() => window.location.reload()} data-testid="error-boundary-reload">Hard reload</button>
            <a href="/" className="btn btn--ghost" data-testid="error-boundary-home">Back to dashboard</a>
          </div>
          {stack && (
            <details style={{ fontSize: 11, color: '#7f1d1d' }}>
              <summary style={{ cursor: 'pointer' }}>Component stack</summary>
              <pre style={{ whiteSpace: 'pre-wrap', marginTop: 8 }} data-testid="error-boundary-stack">{stack}</pre>
            </details>
          )}
        </div>
      </div>
    );
  }
}
