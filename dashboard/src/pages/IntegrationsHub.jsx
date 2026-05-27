import React from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../lib/api';
import { Section, ActionCardsGrid, ActionCard } from '../components/UIComponents';
import { PlugZap, Building2, Banknote, BookOpen, Database, TrendingUp, ChevronRight, ShieldCheck, AlertTriangle, AlertOctagon, Sparkles } from 'lucide-react';

/**
 * IntegrationsHub — tenant admin "single pane of glass" for every external
 * integration CoreFlux supports. Mounted at /admin/integrations.
 *
 * Each card surfaces a live "connected / not connected" badge by hitting
 * the integration's status endpoint, so an admin can see at a glance
 * which rails are wired up. Click-through opens the integration's
 * dedicated settings page (already living elsewhere in the codebase) —
 * we don't rewrite the underlying forms, we just route to them.
 */
export default function IntegrationsHub() {
  const plaid    = useApi('/api/plaid_transfer_link.php?action=status');
  const mercury  = useApi('/api/mercury_connection.php?action=status');
  const jobdiva  = useApi('/api/jobdiva/status.php?action=status');
  const qbo      = useApi('/api/qbo/status.php?action=status');
  const zoho     = useApi('/api/zoho_books/status.php?action=status');
  const airtable = useApi('/api/airtable/status.php?action=status');
  const health   = useApi('/api/admin/schema_health.php');

  const plaidStatus = plaid.loading
    ? 'loading'
    : plaid.data?.linked
      ? 'connected'
      : plaid.data?.configured
        ? 'not_linked'
        : 'not_configured';

  const mercuryStatus = mercury.loading
    ? 'loading'
    : mercury.data?.connected ? 'connected' : 'not_connected';

  const jobdivaStatus = jobdiva.loading
    ? 'loading'
    : jobdiva.data?.connected ? 'connected' : 'not_connected';

  const qboStatus = qbo.loading
    ? 'loading'
    : qbo.data?.connected
      ? 'connected'
      : qbo.data?.configured
        ? 'not_connected'
        : 'not_configured';

  const zohoStatus = zoho.loading
    ? 'loading'
    : zoho.data?.connected
      ? 'connected'
      : zoho.data?.configured
        ? 'not_connected'
        : 'not_configured';

  const airtableStatus = airtable.loading
    ? 'loading'
    : airtable.data?.connected
      ? 'connected'
      : airtable.data?.configured
        ? 'not_connected'
        : 'not_configured';

  return (
    <div data-testid="integrations-hub">
      <div style={{ marginBottom: 'var(--cf-space-6)' }}>
        <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 'var(--cf-space-2)' }}>
          Integrations
        </h1>
        <p style={{ color: 'var(--cf-text-secondary)' }}>
          Connect external systems CoreFlux uses to originate payments, sync staffing data, and reconcile bank activity.
          Tokens are encrypted at rest; each integration is scoped to the active tenant.
        </p>
      </div>

      <SchemaHealthPanel data={health.data} loading={health.loading} error={health.error} />

      <Section title="Payment Rails">
        <ActionCardsGrid>
          <IntegrationCard
            testid="integration-card-plaid"
            icon={Banknote}
            title="Plaid Transfer"
            description="ACH / RTP pay-outs from your operating account (0–1 business day settlement)."
            href="/admin/integrations/plaid"
            status={plaidStatus}
          />
          <IntegrationCard
            testid="integration-card-mercury"
            icon={Building2}
            title="Mercury Bank"
            description="Mercury workspace connection — feeds, recipient vault, pay-out engine."
            href="/admin/integrations/mercury"
            status={mercuryStatus}
          />
        </ActionCardsGrid>
      </Section>

      <Section title="Staffing & Workforce">
        <ActionCardsGrid>
          <IntegrationCard
            testid="integration-card-jobdiva"
            icon={PlugZap}
            title="JobDiva"
            description="Sync companies, contacts, placements, and time entries with your JobDiva ATS."
            href="/admin/integrations/jobdiva"
            status={jobdivaStatus}
          />
        </ActionCardsGrid>
      </Section>

      <Section title="Accounting">
        <ActionCardsGrid>
          <IntegrationCard
            testid="integration-card-qbo"
            icon={BookOpen}
            title="QuickBooks Online"
            description="OAuth connection to your Intuit QuickBooks company. Per-entity push / pull / two-way controls for journal entries, customers, vendors, invoices, bills, payments, and the chart of accounts."
            href="/admin/integrations/qbo"
            status={qboStatus}
          />
          <IntegrationCard
            testid="integration-card-zoho-books"
            icon={BookOpen}
            title="Zoho Books"
            description="OAuth connection to your Zoho Books organization (region auto-detected). Per-entity push / pull / two-way controls for journal entries, contacts, invoices, bills, payments, and the chart of accounts."
            href="/admin/integrations/zoho-books"
            status={zohoStatus}
          />
          <IntegrationCard
            testid="integration-card-accounting-sync"
            icon={TrendingUp}
            title="Sync Dashboard"
            description="Side-by-side view of QBO + Zoho Books — coverage scorecard, per-entity drift signals, and a unified activity feed across both systems."
            href="/admin/integrations/accounting-sync"
            status="connected"
          />
        </ActionCardsGrid>
      </Section>

      <Section title="Operations & CRM">
        <ActionCardsGrid>
          <IntegrationCard
            testid="integration-card-airtable"
            icon={Database}
            title="Airtable"
            description="Pull records from any Airtable base/table into the integrations vault. Per-mapping field map; pull-only v1; PAT auth (encrypted at rest)."
            href="/admin/integrations/airtable"
            status={airtableStatus}
          />
        </ActionCardsGrid>
      </Section>

      <Section title="Field Mapping">
        <ActionCardsGrid>
          <IntegrationCard
            testid="integration-card-field-map-studio"
            icon={Sparkles}
            title="Field Mapping Studio"
            description="Pick any path from any integration's live payload (JobDiva, QBO, Zoho, Airtable) and route it to any CoreFlux column — including custom fields. Tenant mappings win over built-in defaults. Includes a dry-run Test Mappings panel."
            href="/admin/integrations/field-map/studio"
            status="connected"
          />
          <IntegrationCard
            testid="integration-card-field-map-legacy"
            icon={Database}
            title="Field Map (legacy table view)"
            description="Flat per-row admin view of the existing mapping rows. Use this for bulk JSON import/export. New mappings should be created in the Studio."
            href="/admin/integrations/field-map"
            status="connected"
          />
        </ActionCardsGrid>
      </Section>
    </div>
  );
}

const STATUS_META = {
  loading:        { label: '…',                bg: '#f1f5f9', fg: '#475569' },
  connected:      { label: 'Connected',        bg: '#d1fae5', fg: '#065f46' },
  not_linked:     { label: 'Not linked',       bg: '#fef3c7', fg: '#92400e' },
  not_connected:  { label: 'Not connected',    bg: '#fef3c7', fg: '#92400e' },
  not_configured: { label: 'Not configured',   bg: '#fee2e2', fg: '#991b1b' },
};

function IntegrationCard({ testid, icon: Icon, title, description, href, status }) {
  const meta = STATUS_META[status] || STATUS_META.loading;
  return (
    <Link to={href} style={{ textDecoration: 'none', color: 'inherit' }} data-testid={testid}>
      <div
        className="action-card"
        style={{
          padding: 'var(--cf-space-5)',
          border: '1px solid var(--cf-border)',
          borderRadius: 8,
          background: 'var(--cf-surface)',
          height: '100%',
          display: 'flex',
          flexDirection: 'column',
          gap: 'var(--cf-space-3)',
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <div style={{
            width: 40, height: 40, borderRadius: 8,
            background: 'var(--cf-blue-bg, #eff6ff)', color: 'var(--cf-blue, #2563eb)',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
          }}>
            <Icon size={20} />
          </div>
          <span
            data-testid={`${testid}-status`}
            style={{
              fontSize: 11, fontWeight: 600, padding: '2px 8px', borderRadius: 4,
              background: meta.bg, color: meta.fg,
            }}
          >
            {meta.label}
          </span>
        </div>
        <div>
          <div style={{ fontWeight: 600, marginBottom: 4 }}>{title}</div>
          <div style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>{description}</div>
        </div>
        <div style={{ marginTop: 'auto', display: 'flex', alignItems: 'center', gap: 4, fontSize: 12, color: 'var(--cf-accent, #2563eb)' }}>
          Manage <ChevronRight size={14} />
        </div>
      </div>
    </Link>
  );
}

/* ─────────────────────── schema health diagnostic panel ─────────────────────── */

const HEALTH_META = {
  green: { Icon: ShieldCheck,   bg: '#d1fae5', fg: '#065f46', dot: '#10b981', label: 'All integration credential columns sized correctly' },
  amber: { Icon: AlertTriangle, bg: '#fef3c7', fg: '#92400e', dot: '#f59e0b', label: 'Some columns missing — migration pending' },
  red:   { Icon: AlertOctagon,  bg: '#fee2e2', fg: '#991b1b', dot: '#ef4444', label: 'Undersized credential columns detected — action required' },
  loading: { Icon: ShieldCheck, bg: '#f1f5f9', fg: '#475569', dot: '#94a3b8', label: 'Checking integration schema…' },
};

function SchemaHealthPanel({ data, loading, error }) {
  const [expanded, setExpanded] = React.useState(false);

  if (loading) {
    return (
      <div data-testid="schema-health-panel-loading" style={{ marginBottom: 'var(--cf-space-6)', padding: 12, border: '1px solid var(--cf-border)', borderRadius: 8, fontSize: 13, color: 'var(--cf-text-secondary)' }}>
        Checking integration credential schema…
      </div>
    );
  }
  if (error) {
    return (
      <div data-testid="schema-health-panel-error" style={{ marginBottom: 'var(--cf-space-6)', padding: 12, border: '1px solid var(--cf-red, #b91c1c)', borderRadius: 8, fontSize: 13, color: 'var(--cf-red, #b91c1c)' }}>
        Schema health probe failed: {error.message || String(error)}
      </div>
    );
  }
  if (!data) return null;

  const meta    = HEALTH_META[data.status] || HEALTH_META.loading;
  const Icon    = meta.Icon;
  const columns = Array.isArray(data.columns) ? data.columns : [];
  const counts  = data.counts || {};
  const problemRows = columns.filter((c) => c.verdict !== 'ok');

  return (
    <div
      data-testid="schema-health-panel"
      style={{
        marginBottom: 'var(--cf-space-6)',
        padding: 16,
        border: `1px solid ${meta.dot}`,
        borderRadius: 8,
        background: meta.bg,
        color: meta.fg,
      }}
    >
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12 }}>
        <Icon size={20} style={{ flexShrink: 0, marginTop: 2 }} />
        <div style={{ flex: 1 }}>
          <div style={{ fontWeight: 600, fontSize: 14 }} data-testid="schema-health-panel-label">{meta.label}</div>
          <div style={{ fontSize: 12, marginTop: 4, opacity: 0.85 }} data-testid="schema-health-panel-counts">
            {counts.ok || 0} ok · {counts.undersized || 0} undersized · {counts.missing || 0} missing · {counts.unknown || 0} unknown
          </div>
          {problemRows.length > 0 && (
            <button
              type="button"
              data-testid="schema-health-panel-toggle"
              onClick={() => setExpanded((v) => !v)}
              style={{
                marginTop: 8, fontSize: 12, fontWeight: 500, background: 'transparent',
                border: `1px solid ${meta.fg}`, color: meta.fg, borderRadius: 4,
                padding: '4px 10px', cursor: 'pointer',
              }}
            >
              {expanded ? 'Hide details' : `Show ${problemRows.length} flagged column${problemRows.length === 1 ? '' : 's'}`}
            </button>
          )}
          {expanded && problemRows.length > 0 && (
            <table data-testid="schema-health-panel-details" style={{ marginTop: 12, width: '100%', fontSize: 12, borderCollapse: 'collapse', background: 'rgba(255,255,255,0.5)', borderRadius: 4 }}>
              <thead>
                <tr style={{ textAlign: 'left', borderBottom: `1px solid ${meta.fg}` }}>
                  <th style={{ padding: '6px 8px' }}>Integration</th>
                  <th style={{ padding: '6px 8px' }}>Column</th>
                  <th style={{ padding: '6px 8px' }}>Width / Min</th>
                  <th style={{ padding: '6px 8px' }}>Verdict</th>
                  <th style={{ padding: '6px 8px' }}>Remediation</th>
                </tr>
              </thead>
              <tbody>
                {problemRows.map((r, i) => (
                  <tr key={i} data-testid={`schema-health-row-${r.integration}-${r.column}`} style={{ borderBottom: `1px solid ${meta.fg}30` }}>
                    <td style={{ padding: '6px 8px', fontWeight: 500 }}>{r.integration_label}</td>
                    <td style={{ padding: '6px 8px', fontFamily: 'var(--cf-mono, ui-monospace)' }}>{r.table}.{r.column}</td>
                    <td style={{ padding: '6px 8px', fontVariantNumeric: 'tabular-nums' }}>
                      {r.actual_bytes === null ? '—' : `${r.actual_bytes} / ${r.min_bytes}`}
                    </td>
                    <td style={{ padding: '6px 8px', textTransform: 'uppercase', fontSize: 11, letterSpacing: 0.5 }}>{r.verdict}</td>
                    <td style={{ padding: '6px 8px', fontFamily: 'var(--cf-mono, ui-monospace)', fontSize: 11 }}>{r.message || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </div>
  );
}
