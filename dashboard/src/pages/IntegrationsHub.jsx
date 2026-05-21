import React from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../lib/api';
import { Section, ActionCardsGrid, ActionCard } from '../components/UIComponents';
import { PlugZap, Building2, Banknote, BookOpen, Database, ChevronRight } from 'lucide-react';

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
  const airtable = useApi('/api/airtable/status.php?action=status');

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
