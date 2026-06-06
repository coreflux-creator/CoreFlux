import React from 'react';
import { Routes, Route, Link, useLocation } from 'react-router-dom';
import { Section, StatsGrid, StatCard, ActionCardsGrid, ActionCard } from '../components/UIComponents';
import { Building2, Users, Package, Layers, FileText, Sparkles, ScrollText, FlaskConical, PlugZap, BarChart3, KeyRound, Palette, CalendarClock, Activity, Shield, Zap, Inbox, Bot, AlertTriangle, UserCheck, Cpu, BookMarked, Network } from 'lucide-react';
import SubTenantsAdmin from './SubTenantsAdmin';
import SubTenantWizard from './SubTenantWizard';
import SubTenantConsolidatedReports from './SubTenantConsolidatedReports';
import ExportTemplatesAdmin from './ExportTemplatesAdmin';
import MasterTenantsAdmin from './MasterTenantsAdmin';
import AiAccuracyDashboard from './AiAccuracyDashboard';
import AiSettingsAdmin from './AiSettingsAdmin';
import UsersAdmin from './UsersAdmin';
import ModuleAccessAdmin from './ModuleAccessAdmin';
import AuditLogViewer from './AuditLogViewer';
import RuleSandbox from './RuleSandbox';
import JobDivaSettings from './JobDivaSettings';
import SsoConfigAdmin from './SsoConfigAdmin';
import MailBrandingAdmin from './MailBrandingAdmin';
import DigestSchedulesAdmin from './DigestSchedulesAdmin';
import HealthcheckAdmin from './HealthcheckAdmin';
import IntegrationsHub from './IntegrationsHub';
import RbacMembershipsAdmin from './RbacMembershipsAdmin';
import RecentAccessChangesPanel from './RecentAccessChangesPanel';
import RbacBridgeHealthPanel from './RbacBridgeHealthPanel';
import IntegrationsHealthPanel from './IntegrationsHealthPanel';
import LayerFiToggleCard from './LayerFiToggleCard';
import PermissionProfileBuilder from './PermissionProfileBuilder';
import CpaPortfolio from './CpaPortfolio';
import CpaFirmClientsAdmin from './CpaFirmClientsAdmin';
import CpaFirmDashboard from './CpaFirmDashboard';
import CpaAuditPage from './CpaAuditPage';
import PlaidTransferSettings from '../../../modules/treasury/ui/PlaidTransferSettings';
import MercurySettings from '../../../modules/treasury/ui/MercurySettings';
import QboSettings from './QboSettings';
import JazIntegrationSettings from './JazIntegrationSettings';
import AccountingOutbox from './AccountingOutbox';
import AiGatewayAdmin from './AiGatewayAdmin';
import AskAiPanel from './AskAiPanel';
import ArtifactsAdmin from './ArtifactsAdmin';
import AccountingExceptionQueue from './AccountingExceptionQueue';
import JeDraftsReview from './JeDraftsReview';
import PayrollReviewPacket from './PayrollReviewPacket';
import AiWorkersAdmin from './AiWorkersAdmin';
import KnowledgeGraphExplorer from './KnowledgeGraphExplorer';
import AgentRegistryAdmin from './AgentRegistryAdmin';
import WorkflowTimeline from './WorkflowTimeline';
import AiReviewerDashboard from './AiReviewerDashboard';
import ZohoBooksSettings from './ZohoBooksSettings';
import AirtableSettings from './AirtableSettings';
import AccountingSyncDashboard from './AccountingSyncDashboard';
import RolesReference from './RolesReference';
import AuditorTokensAdmin from './AuditorTokensAdmin';
import CrossTenantAuditAdmin from './CrossTenantAuditAdmin';
import IntegrationFieldMapAdmin from './IntegrationFieldMapAdmin';
import FieldMappingStudio from './FieldMappingStudio';
import AssignmentSchemaPreview from './AssignmentSchemaPreview';
import ApprovalPoliciesAdmin from './ApprovalPoliciesAdmin';
import GraphqlSandbox from './GraphqlSandbox';

/**
 * AdminModule — administrator surface.
 *
 * Sprint 2 (2026-02 fork): replaced the mock UsersPage / ModulesPage that
 * shipped with hardcoded arrays. Both are now real React + API.
 */

const AdminOverview = () => (
  <>
    <div style={{ marginBottom: 'var(--cf-space-6)' }}>
      <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 'var(--cf-space-2)' }}>
        Admin Panel
      </h1>
      <p style={{ color: 'var(--cf-text-secondary)' }}>
        Manage tenants, users, and module access across the platform.
      </p>
    </div>

    <Section title="Quick Actions">
      <ActionCardsGrid>
        <ActionCard icon={Building2} title="Master tenants" description="Top-level customers + branding" href="/admin/tenants" />
        <ActionCard icon={Layers}    title="Sub-tenants"    description="Provision sub-tenants & module scope" href="/admin/sub-tenants" />
        <ActionCard icon={BarChart3} title="Consolidated reports" description="Roll-up P&L / BS / CF across all sub-tenants" href="/admin/consolidated-reports" />
        <ActionCard icon={Users}     title="Users"          description="Add users, assign roles, reset passwords" href="/admin/users" />
        <ActionCard icon={Shield}    title="Memberships & access" description="Granular per-tenant RBAC — personas, modules, copy permissions" href="/admin/memberships" />
        <ActionCard icon={Shield}    title="Permission profiles" description="Author tenant-private grant bundles you can apply to a membership in one click" href="/admin/permission-profiles" />
        <ActionCard icon={Building2} title="My CPA clients"  description="Every client tenant any firm you belong to manages — jump into a client's books in one click" href="/admin/cpa-portfolio" />
        <ActionCard icon={Building2} title="Firm clients"    description="Wire new client tenants to this firm. Bulk-seat your CPA roster + apply default profiles in one save" href="/admin/cpa-clients" />
        <ActionCard icon={BarChart3} title="Firm dashboard"  description="Open exceptions / draft outbox / late-close periods across every client your firms manage" href="/admin/cpa-dashboard" />
        <ActionCard icon={ScrollText} title="CPA audit"      description="Cross-tenant audit feed scoped to your CPA portfolio — accounting + membership events" href="/admin/cpa-audit" />
        <ActionCard icon={ScrollText} title="Roles reference" description="What each persona_type grants — quick lookup before assigning a role" href="/admin/roles" />
        <ActionCard icon={Package}   title="Module access"  description="Toggle which apps a tenant can see" href="/admin/modules" />
        <ActionCard icon={FileText}  title="Export templates" description="CSV templates for any module" href="/admin/export-templates" />
        <ActionCard icon={Sparkles}  title="AI settings"     description="Per-tenant master switch + per-feature toggles (off by default)" href="/admin/ai-settings" />
        <ActionCard icon={Sparkles}  title="AI accuracy"    description="Confidence-score moat dashboard" href="/admin/ai-accuracy" />
        <ActionCard icon={ScrollText} title="Audit log"     description="Tenant-scoped audit trail with CSV export" href="/admin/audit-log" />
        <ActionCard icon={FlaskConical} title="Rule sandbox" description="Dry-run posting rules without writing to the GL" href="/admin/rule-sandbox" />
        <ActionCard icon={PlugZap} title="Integrations" description="Connect Plaid, Mercury, JobDiva and other external systems" href="/admin/integrations" />
        <ActionCard icon={Inbox}   title="Accounting outbox" description="Per-tenant view of every draft queued to Jaz — payloads, provider errors, retry / cancel controls" href="/admin/accounting/outbox" />
        <ActionCard icon={Bot}     title="AI Tool Gateway" description="Admin trace explorer for every AI-originated run: tool calls in order + spec-§15 audit events + registry catalog" href="/admin/ai-gateway" />
        <ActionCard icon={Sparkles} title="Ask AI (Slice 1)" description="Plumbing-only Ask-AI shell. Sends a deterministic tool call through the gateway. LLM planner ships in Slice 2." href="/admin/ai-gateway/ask" />
        <ActionCard icon={Activity} title="Workflow runtime" description="Durable workflow graphs (LangGraph-style): per-node timeline, paused approvals, output. Spec §6." href="/admin/ai-gateway/workflows" />
        <ActionCard icon={Shield}   title="AI Reviewer cockpit" description="Single landing page: open exceptions, pending approvals, recently drafted JEs. The reviewer's home." href="/admin/ai-gateway/reviewer" />
        <ActionCard icon={FileText}  title="Artifacts" description="First-class platform artifacts: close packets, recon packets, JE drafts, forecasts. Lifecycle + lineage + event history. Spec §2A." href="/admin/ai/artifacts" />
        <ActionCard icon={AlertTriangle} title="Exception queue" description="Bank-feed classifications, JE drafts, and workflow runs that need human attention. Resolve / dismiss from one inbox. Spec §11." href="/admin/ai/exceptions" />
        <ActionCard icon={ScrollText} title="JE drafts review" description="AI-drafted journal entries awaiting approval. Re-validates each draft on open + Reject affordance; posting goes through coreflux.post_approved_journal_entry (risk-4)." href="/admin/ai/je-drafts" />
        <ActionCard icon={UserCheck} title="Payroll review packet" description="Weekly timesheet anomaly packet: spikes / zero-weeks / billable drift / >24h overlaps. Rule-based, per-person, severity-scored. Spec §11." href="/admin/ai/payroll-review" />
        <ActionCard icon={Cpu} title="Worker runtime" description="Durable async job queue: registered workers + per-status queue depth + retry / cancel. Long-running tools run through here. Spec §2." href="/admin/ai/workers" />
        <ActionCard icon={BookMarked} title="Knowledge graph" description="FULLTEXT-indexed documents + entity / edge graph the LLM cites back to. Vector search via pgvector deferred. Spec §7." href="/admin/ai/knowledge" />
        <ActionCard icon={Network} title="Agent registry" description="Named agents (Close, Cash, AP, Payroll) + handoffs between them. Spec §7." href="/admin/ai/agents" />
        <ActionCard icon={Sparkles} title="Field Mapping Studio" description="Route any integration payload field (JobDiva, QBO, Zoho, Airtable) into any CoreFlux column — including custom fields. Tenant overrides + dry-run test panel." href="/admin/integrations/field-map/studio" />
        <ActionCard icon={FileText} title="Assignment schema preview" description="Auto-built CoreFlux clone of the JobDiva Assignment edit screen. Shows every indexed field grouped into Assignment / Placement / Job / Person / End-client / Contact sections." href="/admin/integrations/assignment-schema" />
        <ActionCard icon={KeyRound} title="SSO configuration" description="Register your Okta or Microsoft Entra identity provider" href="/admin/sso" />
        <ActionCard icon={Palette}  title="Email branding"     description="Logo, accent colour, and signature on every digest" href="/admin/mail-branding" />
        <ActionCard icon={CalendarClock} title="Digest schedules" description="When each weekly / daily email gets sent per tenant" href="/admin/digest-schedules" />
        <ActionCard icon={Activity} title="Healthcheck"       description="One-click status of every freshly-shipped endpoint" href="/admin/healthcheck" />
        <ActionCard icon={ScrollText} title="Auditor links" description="Issue read-only access for external auditors (revocable, time-limited)" href="/admin/auditor-tokens" />
        <ActionCard icon={ScrollText} title="Cross-tenant audit trail" description="Every consolidation edge & intercompany mapping that crossed tenants" href="/admin/cross-tenant-audit" />
        <ActionCard icon={Zap} title="GraphQL Sandbox" description="Interactive playground for the federated GraphQL endpoint — query, explore, export snippets" href="/admin/graphql-sandbox" />
        <ActionCard icon={Shield} title="Approval policies" description="Mercury payment approval rules — amount thresholds, co-approver chains, cool-off windows" href="/admin/treasury/approval-policies" />
      </ActionCardsGrid>
    </Section>

    <div style={{ marginTop: 'var(--cf-space-6)', display: 'grid', gridTemplateColumns: 'minmax(0, 2fr) minmax(0, 1fr)', gap: 'var(--cf-space-4)' }}>
      <RecentAccessChangesPanel limit={8} />
      <RbacBridgeHealthPanel windowHours={24} />
    </div>

    <div style={{ marginTop: 'var(--cf-space-4)', display: 'grid', gridTemplateColumns: 'minmax(0, 2fr) minmax(0, 1fr)', gap: 'var(--cf-space-4)' }}>
      <IntegrationsHealthPanel />
      <LayerFiToggleCard />
    </div>
  </>
);

const AdminSidebar = () => {
  const location = useLocation();
  const links = [
    { to: '/admin',                  label: 'Overview',         icon: Package },
    { to: '/admin/tenants',          label: 'Master tenants',   icon: Building2 },
    { to: '/admin/sub-tenants',      label: 'Sub-Tenants',      icon: Layers },
    { to: '/admin/consolidated-reports', label: 'Consolidated Reports', icon: BarChart3 },
    { to: '/admin/users',            label: 'Users',            icon: Users },
    { to: '/admin/memberships',      label: 'Memberships & access', icon: Shield },
    { to: '/admin/permission-profiles', label: 'Permission profiles', icon: Shield },
    { to: '/admin/cpa-portfolio',    label: 'My CPA clients',   icon: Building2 },
    { to: '/admin/cpa-clients',      label: 'Firm clients',     icon: Building2 },
    { to: '/admin/cpa-dashboard',    label: 'Firm dashboard',   icon: BarChart3 },
    { to: '/admin/cpa-audit',        label: 'CPA audit',        icon: ScrollText },
    { to: '/admin/roles',            label: 'Roles reference',  icon: ScrollText },
    { to: '/admin/modules',          label: 'Module access',    icon: Package },
    { to: '/admin/export-templates', label: 'Export Templates', icon: FileText },
    { to: '/admin/ai-settings',      label: 'AI Settings',      icon: Sparkles },
    { to: '/admin/ai-accuracy',      label: 'AI Accuracy',      icon: Sparkles },
    { to: '/admin/audit-log',        label: 'Audit Log',        icon: ScrollText },
    { to: '/admin/rule-sandbox',     label: 'Rule Sandbox',     icon: FlaskConical },
    { to: '/admin/integrations',     label: 'Integrations',     icon: PlugZap },
    { to: '/admin/accounting/outbox', label: 'Accounting outbox', icon: Inbox },
    { to: '/admin/ai-gateway',       label: 'AI Tool Gateway',  icon: Bot },
    { to: '/admin/ai-gateway/ask',   label: 'Ask AI (Slice 1)', icon: Sparkles },
    { to: '/admin/ai-gateway/workflows', label: 'Workflow runtime', icon: Activity },
    { to: '/admin/ai-gateway/reviewer',  label: 'AI Reviewer',     icon: Shield },
    { to: '/admin/ai/artifacts',         label: 'Artifacts',        icon: FileText },
    { to: '/admin/ai/exceptions',        label: 'Exception queue',  icon: AlertTriangle },
    { to: '/admin/ai/je-drafts',         label: 'JE drafts review', icon: ScrollText },
    { to: '/admin/ai/payroll-review',     label: 'Payroll review',  icon: UserCheck },
    { to: '/admin/ai/workers',            label: 'Worker runtime',  icon: Cpu },
    { to: '/admin/ai/knowledge',          label: 'Knowledge graph', icon: BookMarked },
    { to: '/admin/ai/agents',             label: 'Agent registry',  icon: Network },
    { to: '/admin/integrations/field-map/studio', label: 'Field Mapping Studio', icon: Sparkles },
    { to: '/admin/integrations/assignment-schema', label: 'Assignment schema',    icon: FileText },
    { to: '/admin/sso',              label: 'SSO',              icon: KeyRound },
    { to: '/admin/mail-branding',    label: 'Branding',         icon: Palette },
    { to: '/admin/digest-schedules', label: 'Digests',          icon: CalendarClock },
    { to: '/admin/healthcheck',      label: 'Healthcheck',      icon: Activity },
    { to: '/admin/auditor-tokens',   label: 'Auditor links',    icon: ScrollText },
    { to: '/admin/cross-tenant-audit', label: 'Cross-tenant audit', icon: ScrollText },
    { to: '/admin/graphql-sandbox',  label: 'GraphQL Sandbox',  icon: Zap },
  ];
  // Local sub-sidebar — override the global .sidebar class which is
  // position:fixed; left:0 (intended for the app-level shell sidebar).
  // Inside AdminModule the app-shell already shifts content right by
  // var(--cf-sidebar-width), so this nav must flow in the flex layout.
  const localStyle = {
    position: 'sticky',
    top: 'var(--cf-header-height)',
    alignSelf: 'flex-start',
    width: 'var(--cf-sidebar-width)',
    flexShrink: 0,
    maxHeight: 'calc(100vh - var(--cf-header-height))',
    overflowY: 'auto',
    background: 'var(--cf-surface)',
    borderRight: '1px solid var(--cf-border)',
  };
  return (
    <aside className="sidebar" style={localStyle}>
      <div className="sidebar-header">
        <h2 className="sidebar-title">Admin</h2>
      </div>
      <nav className="sidebar-nav">
        {links.map(link => {
          const Icon = link.icon;
          const active = location.pathname === link.to;
          return (
            <div key={link.to} className="sidebar-item">
              <Link to={link.to} className={`sidebar-link ${active ? 'active' : ''}`}
                    data-testid={`admin-link-${link.to.split('/').pop() || 'overview'}`}>
                <Icon size={18} className="sidebar-icon" />
                <span>{link.label}</span>
              </Link>
            </div>
          );
        })}
      </nav>
    </aside>
  );
};

const AdminModule = ({ session }) => {
  return (
    <div style={{ display: 'flex', gap: 0, minHeight: 'calc(100vh - var(--cf-header-height, 56px))' }}>
      <AdminSidebar />
      <div style={{ flex: 1, minWidth: 0, padding: 'var(--cf-space-6)' }} data-testid="admin-main-content">
        <Routes>
          <Route path="/"                  element={<AdminOverview />} />
          <Route path="/tenants"           element={<MasterTenantsAdmin session={session} />} />
          <Route path="/sub-tenants"       element={<SubTenantsAdmin   session={session} />} />
          <Route path="/sub-tenants/new"   element={<SubTenantWizard   session={session} />} />
          <Route path="/consolidated-reports" element={<SubTenantConsolidatedReports session={session} />} />
          <Route path="/users"             element={<UsersAdmin        session={session} />} />
          <Route path="/memberships"       element={<RbacMembershipsAdmin session={session} />} />
          <Route path="/permission-profiles" element={<PermissionProfileBuilder session={session} />} />
          <Route path="/cpa-portfolio"     element={<CpaPortfolio session={session} />} />
          <Route path="/cpa-clients"       element={<CpaFirmClientsAdmin session={session} />} />
          <Route path="/cpa-dashboard"     element={<CpaFirmDashboard session={session} />} />
          <Route path="/cpa-audit"         element={<CpaAuditPage session={session} />} />
          <Route path="/roles"             element={<RolesReference session={session} />} />
          <Route path="/modules"           element={<ModuleAccessAdmin session={session} />} />
          <Route path="/export-templates"  element={<ExportTemplatesAdmin session={session} />} />
          <Route path="/ai-settings"       element={<AiSettingsAdmin session={session} />} />
          <Route path="/ai-accuracy"       element={<AiAccuracyDashboard session={session} />} />
          <Route path="/audit-log"         element={<AuditLogViewer session={session} />} />
          <Route path="/rule-sandbox"      element={<RuleSandbox session={session} />} />
          <Route path="/integrations"          element={<IntegrationsHub session={session} />} />
          <Route path="/integrations/accounting-sync" element={<AccountingSyncDashboard session={session} />} />
          <Route path="/integrations/plaid"    element={<PlaidTransferSettings session={session} />} />
          <Route path="/integrations/mercury"  element={<MercurySettings session={session} />} />
          <Route path="/integrations/qbo"      element={<QboSettings session={session} />} />
          <Route path="/integrations/jaz"      element={<JazIntegrationSettings session={session} />} />
          <Route path="/accounting/outbox"     element={<AccountingOutbox session={session} />} />
          <Route path="/ai-gateway"            element={<AiGatewayAdmin session={session} />} />
          <Route path="/ai-gateway/ask"        element={<AskAiPanel session={session} />} />
          <Route path="/ai-gateway/workflows"  element={<WorkflowTimeline session={session} />} />
          <Route path="/ai-gateway/reviewer"   element={<AiReviewerDashboard session={session} />} />
          <Route path="/ai/artifacts"          element={<ArtifactsAdmin session={session} />} />
          <Route path="/ai/exceptions"         element={<AccountingExceptionQueue session={session} />} />
          <Route path="/ai/je-drafts"          element={<JeDraftsReview session={session} />} />
          <Route path="/ai/payroll-review"     element={<PayrollReviewPacket session={session} />} />
          <Route path="/ai/workers"            element={<AiWorkersAdmin session={session} />} />
          <Route path="/ai/knowledge"          element={<KnowledgeGraphExplorer session={session} />} />
          <Route path="/ai/agents"             element={<AgentRegistryAdmin session={session} />} />
          <Route path="/integrations/zoho-books" element={<ZohoBooksSettings session={session} />} />
          <Route path="/integrations/airtable" element={<AirtableSettings session={session} />} />
          <Route path="/integrations/jobdiva" element={<JobDivaSettings session={session} />} />
          <Route path="/integrations/field-map" element={<IntegrationFieldMapAdmin session={session} />} />
          <Route path="/integrations/field-map/studio" element={<FieldMappingStudio session={session} />} />
          <Route path="/integrations/assignment-schema" element={<AssignmentSchemaPreview session={session} />} />
          <Route path="/treasury/approval-policies" element={<ApprovalPoliciesAdmin session={session} />} />
          <Route path="/sso"               element={<SsoConfigAdmin session={session} />} />
          <Route path="/mail-branding"     element={<MailBrandingAdmin session={session} />} />
          <Route path="/digest-schedules"  element={<DigestSchedulesAdmin session={session} />} />
          <Route path="/healthcheck"       element={<HealthcheckAdmin session={session} />} />
          <Route path="/auditor-tokens"    element={<AuditorTokensAdmin session={session} />} />
          <Route path="/cross-tenant-audit" element={<CrossTenantAuditAdmin session={session} />} />
          <Route path="/graphql-sandbox"   element={<GraphqlSandbox session={session} />} />
        </Routes>
      </div>
    </div>
  );
};

export default AdminModule;
