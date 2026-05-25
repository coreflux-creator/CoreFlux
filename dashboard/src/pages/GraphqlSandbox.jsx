import React, { useEffect, useState } from 'react';
import { Section } from '../components/UIComponents';
import { ExternalLink, Zap, CheckCircle2, AlertCircle, Loader2 } from 'lucide-react';

/**
 * GraphqlSandbox — admin landing page for the production GraphQL endpoint.
 *
 * Apollo Sandbox itself can't be safely iframed (their X-Frame-Options
 * blocks it), so we present three things:
 *
 *   1. A big "Open Sandbox" button → new tab to the live router.
 *   2. The endpoint URL spelled out (so curl/postman users can copy).
 *   3. A live introspection check that fires from inside the dashboard
 *      — proves CORS + TLS + router + subgraphs are all green end-to-end.
 *      No auth required; introspection is enabled on the router.
 */
const GRAPHQL_URL = 'https://graphql.corefluxapp.com/';

function useIntrospection() {
  const [state, setState] = useState({ loading: true, ok: null, types: 0, error: null });

  useEffect(() => {
    const ctrl = new AbortController();
    fetch(GRAPHQL_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ query: '{ __schema { queryType { name } types { name } } }' }),
      signal: ctrl.signal,
    })
      .then(async (r) => {
        const json = await r.json();
        if (json.errors) throw new Error(json.errors[0]?.message || 'GraphQL error');
        const types = (json.data?.__schema?.types || []).filter(t => !t.name.startsWith('__'));
        setState({ loading: false, ok: true, types: types.length, error: null });
      })
      .catch((e) => {
        if (e.name === 'AbortError') return;
        setState({ loading: false, ok: false, types: 0, error: e.message || String(e) });
      });
    return () => ctrl.abort();
  }, []);

  return state;
}

const StatusPill = ({ state }) => {
  const base = {
    display: 'inline-flex', alignItems: 'center', gap: 6,
    padding: '6px 12px', borderRadius: 999, fontSize: 'var(--cf-text-sm)', fontWeight: 600,
  };
  if (state.loading) {
    return (
      <span data-testid="gql-sandbox-status-loading" style={{ ...base, background: 'var(--cf-surface-2, #f3f4f6)', color: 'var(--cf-text-secondary)' }}>
        <Loader2 size={14} className="cf-spin" /> Probing endpoint…
      </span>
    );
  }
  if (state.ok) {
    return (
      <span data-testid="gql-sandbox-status-ok" style={{ ...base, background: 'rgba(34,197,94,0.12)', color: '#16a34a' }}>
        <CheckCircle2 size={14} /> Endpoint healthy — {state.types} types exposed
      </span>
    );
  }
  return (
    <span data-testid="gql-sandbox-status-fail" style={{ ...base, background: 'rgba(239,68,68,0.12)', color: '#dc2626' }}>
      <AlertCircle size={14} /> Endpoint unreachable
    </span>
  );
};

const GraphqlSandbox = () => {
  const state = useIntrospection();

  return (
    <div data-testid="gql-sandbox-page">
      <div style={{ marginBottom: 'var(--cf-space-6)' }}>
        <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 'var(--cf-space-2)' }}>
          GraphQL Sandbox
        </h1>
        <p style={{ color: 'var(--cf-text-secondary)' }}>
          Interactive playground for the federated GraphQL endpoint. Write queries, explore
          the schema across CoreFlux and JobDiva subgraphs, and copy ready-to-paste curl snippets.
        </p>
      </div>

      <Section title="Endpoint">
        <div style={{
          padding: 'var(--cf-space-4)',
          background: 'var(--cf-surface)',
          border: '1px solid var(--cf-border)',
          borderRadius: 'var(--cf-radius)',
          display: 'flex',
          flexDirection: 'column',
          gap: 'var(--cf-space-3)',
        }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--cf-space-3)', flexWrap: 'wrap' }}>
            <code
              data-testid="gql-sandbox-endpoint-url"
              style={{
                fontSize: 'var(--cf-text-sm)',
                padding: '8px 12px',
                background: 'var(--cf-surface-2, #f3f4f6)',
                borderRadius: 6,
                fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
              }}
            >
              {GRAPHQL_URL}
            </code>
            <StatusPill state={state} />
          </div>

          {state.error && (
            <div data-testid="gql-sandbox-error-detail" style={{ fontSize: 'var(--cf-text-sm)', color: '#dc2626' }}>
              {state.error}
            </div>
          )}

          <div style={{ display: 'flex', gap: 'var(--cf-space-2)', flexWrap: 'wrap' }}>
            <a
              data-testid="gql-sandbox-open-button"
              href={GRAPHQL_URL}
              target="_blank"
              rel="noopener noreferrer"
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: 8,
                padding: '10px 16px',
                background: 'var(--cf-accent, #7c3aed)',
                color: '#fff',
                borderRadius: 8,
                fontWeight: 600,
                textDecoration: 'none',
                fontSize: 'var(--cf-text-sm)',
              }}
            >
              <Zap size={16} /> Open Apollo Sandbox <ExternalLink size={14} />
            </a>
            <button
              data-testid="gql-sandbox-copy-curl"
              type="button"
              onClick={() => {
                const cmd = `curl -sX POST ${GRAPHQL_URL} \\\n  -H 'Content-Type: application/json' \\\n  -d '{"query":"{ __schema { queryType { name } } }"}'`;
                navigator.clipboard?.writeText(cmd);
              }}
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: 8,
                padding: '10px 16px',
                background: 'var(--cf-surface-2, #f3f4f6)',
                color: 'var(--cf-text-primary)',
                border: '1px solid var(--cf-border)',
                borderRadius: 8,
                fontWeight: 500,
                cursor: 'pointer',
                fontSize: 'var(--cf-text-sm)',
              }}
            >
              Copy curl snippet
            </button>
          </div>
        </div>
      </Section>

      <Section title="What you can do here">
        <ul style={{ paddingLeft: 24, color: 'var(--cf-text-secondary)', lineHeight: 1.8 }}>
          <li><strong>Explore the schema</strong> — every Placement, Person, Company, and JobDiva field is documented inline.</li>
          <li><strong>Write a query</strong> — Apollo Sandbox autocompletes as you type and shows live errors.</li>
          <li><strong>Test with auth</strong> — paste a JWT from the browser DevTools <code>Application → Cookies</code> tab into the <code>Authorization: Bearer ...</code> header in Sandbox.</li>
          <li><strong>Export to code</strong> — the Sandbox can generate ready-to-paste fetch / Apollo Client / curl snippets for any query.</li>
        </ul>
      </Section>

      <Section title="Architecture">
        <pre
          data-testid="gql-sandbox-arch-diagram"
          style={{
            padding: 'var(--cf-space-4)',
            background: 'var(--cf-surface-2, #0f172a)',
            color: '#e2e8f0',
            borderRadius: 'var(--cf-radius)',
            fontSize: 'var(--cf-text-xs)',
            overflowX: 'auto',
            lineHeight: 1.6,
          }}
        >{`Browser
   │
   ▼
graphql.corefluxapp.com  (Caddy + Let's Encrypt, DO droplet)
   │
   ▼
Apollo Router :4000
   │
   ├──► subgraph-coreflux :4001  ──► corefluxapp.com/api/*
   │                                  (Placement, Person, Company)
   │
   └──► subgraph-jobdiva  :4002  ──► JobDiva REST API
                                      (Assignment, Candidate, Job)`}</pre>
      </Section>
    </div>
  );
};

export default GraphqlSandbox;
