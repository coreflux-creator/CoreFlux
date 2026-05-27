import React from 'react';
import { Routes, Route, Link, useLocation } from 'react-router-dom';
import { Section, StatsGrid, StatCard, ActionCardsGrid, ActionCard } from '../components/UIComponents';
import { Building2, Users, Package, Layers, FileText, Sparkles, ScrollText, FlaskConical, PlugZap, BarChart3, KeyRound, Palette, CalendarClock, Activity, Shield, Zap } from 'lucide-react';
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
import PlaidTransferSettings from '../../../modules/treasury/ui/PlaidTransferSettings';
import MercurySettings from '../../../modules/treasury/ui/MercurySettings';
import QboSettings from './QboSettings';
import ZohoBooksSettings from './ZohoBooksSettings';
import AirtableSettings from './AirtableSettings';
import AccountingSyncDashboard from './AccountingSyncDashboard';
import RolesReference from './RolesReference';
import AuditorTokensAdmin from './AuditorTokensAdmin';
import CrossTenantAuditAdmin from './CrossTenantAuditAdmin';
import IntegrationFieldMapAdmin from './IntegrationFieldMapAdmin';
import FieldMappingStudio from './FieldMappingStudio';
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
        <ActionCard icon={ScrollText} title="Roles reference" description="What each persona_type grants — quick lookup before assigning a role" href="/admin/roles" />
        <ActionCard icon={Package}   title="Module access"  description="Toggle which apps a tenant can see" href="/admin/modules" />
        <ActionCard icon={FileText}  title="Export templates" description="CSV templates for any module" href="/admin/export-templates" />
        <ActionCard icon={Sparkles}  title="AI settings"     description="Per-tenant master switch + per-feature toggles (off by default)" href="/admin/ai-settings" />
        <ActionCard icon={Sparkles}  title="AI accuracy"    description="Confidence-score moat dashboard" href="/admin/ai-accuracy" />
        <ActionCard icon={ScrollText} title="Audit log"     description="Tenant-scoped audit trail with CSV export" href="/admin/audit-log" />
        <ActionCard icon={FlaskConical} title="Rule sandbox" description="Dry-run posting rules without writing to the GL" href="/admin/rule-sandbox" />
        <ActionCard icon={PlugZap} title="Integrations" description="Connect Plaid, Mercury, JobDiva and other external systems" href="/admin/integrations" />
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
    { to: '/admin/roles',            label: 'Roles reference',  icon: ScrollText },
    { to: '/admin/modules',          label: 'Module access',    icon: Package },
    { to: '/admin/export-templates', label: 'Export Templates', icon: FileText },
    { to: '/admin/ai-settings',      label: 'AI Settings',      icon: Sparkles },
    { to: '/admin/ai-accuracy',      label: 'AI Accuracy',      icon: Sparkles },
    { to: '/admin/audit-log',        label: 'Audit Log',        icon: ScrollText },
    { to: '/admin/rule-sandbox',     label: 'Rule Sandbox',     icon: FlaskConical },
    { to: '/admin/integrations',     label: 'Integrations',     icon: PlugZap },
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
          <Route path="/integrations/zoho-books" element={<ZohoBooksSettings session={session} />} />
          <Route path="/integrations/airtable" element={<AirtableSettings session={session} />} />
          <Route path="/integrations/jobdiva" element={<JobDivaSettings session={session} />} />
          <Route path="/integrations/field-map" element={<IntegrationFieldMapAdmin session={session} />} />
          <Route path="/integrations/field-map/studio" element={<FieldMappingStudio session={session} />} />
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
