import React from 'react';
import { Routes, Route, Link, useLocation } from 'react-router-dom';
import { Section, StatsGrid, StatCard, ActionCardsGrid, ActionCard } from '../components/UIComponents';
import { Building2, Users, Package, Layers, FileText, Sparkles, ScrollText, FlaskConical, PlugZap } from 'lucide-react';
import SubTenantsAdmin from './SubTenantsAdmin';
import ExportTemplatesAdmin from './ExportTemplatesAdmin';
import MasterTenantsAdmin from './MasterTenantsAdmin';
import AiAccuracyDashboard from './AiAccuracyDashboard';
import UsersAdmin from './UsersAdmin';
import ModuleAccessAdmin from './ModuleAccessAdmin';
import AuditLogViewer from './AuditLogViewer';
import RuleSandbox from './RuleSandbox';
import JobDivaSettings from './JobDivaSettings';

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
        <ActionCard icon={Users}     title="Users"          description="Add users, assign roles, reset passwords" href="/admin/users" />
        <ActionCard icon={Package}   title="Module access"  description="Toggle which apps a tenant can see" href="/admin/modules" />
        <ActionCard icon={FileText}  title="Export templates" description="CSV templates for any module" href="/admin/export-templates" />
        <ActionCard icon={Sparkles}  title="AI accuracy"    description="Confidence-score moat dashboard" href="/admin/ai-accuracy" />
        <ActionCard icon={ScrollText} title="Audit log"     description="Tenant-scoped audit trail with CSV export" href="/admin/audit-log" />
        <ActionCard icon={FlaskConical} title="Rule sandbox" description="Dry-run posting rules without writing to the GL" href="/admin/rule-sandbox" />
        <ActionCard icon={PlugZap} title="JobDiva integration" description="Tenant-level JobDiva connection — webhooks + manual sync" href="/admin/integrations/jobdiva" />
      </ActionCardsGrid>
    </Section>
  </>
);

const AdminSidebar = () => {
  const location = useLocation();
  const links = [
    { to: '/admin',                  label: 'Overview',         icon: Package },
    { to: '/admin/tenants',          label: 'Master tenants',   icon: Building2 },
    { to: '/admin/sub-tenants',      label: 'Sub-Tenants',      icon: Layers },
    { to: '/admin/users',            label: 'Users',            icon: Users },
    { to: '/admin/modules',          label: 'Module access',    icon: Package },
    { to: '/admin/export-templates', label: 'Export Templates', icon: FileText },
    { to: '/admin/ai-accuracy',      label: 'AI Accuracy',      icon: Sparkles },
    { to: '/admin/audit-log',        label: 'Audit Log',        icon: ScrollText },
    { to: '/admin/rule-sandbox',     label: 'Rule Sandbox',     icon: FlaskConical },
    { to: '/admin/integrations/jobdiva', label: 'JobDiva',      icon: PlugZap },
  ];
  return (
    <aside className="sidebar">
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
    <div style={{ display: 'flex', marginLeft: '-220px', minHeight: 'calc(100vh - 56px)' }}>
      <AdminSidebar />
      <div style={{ flex: 1, marginLeft: '220px', padding: 'var(--cf-space-6)' }}>
        <Routes>
          <Route path="/"                  element={<AdminOverview />} />
          <Route path="/tenants"           element={<MasterTenantsAdmin session={session} />} />
          <Route path="/sub-tenants"       element={<SubTenantsAdmin   session={session} />} />
          <Route path="/users"             element={<UsersAdmin        session={session} />} />
          <Route path="/modules"           element={<ModuleAccessAdmin session={session} />} />
          <Route path="/export-templates"  element={<ExportTemplatesAdmin session={session} />} />
          <Route path="/ai-accuracy"       element={<AiAccuracyDashboard session={session} />} />
          <Route path="/audit-log"         element={<AuditLogViewer session={session} />} />
          <Route path="/rule-sandbox"      element={<RuleSandbox session={session} />} />
          <Route path="/integrations/jobdiva" element={<JobDivaSettings session={session} />} />
        </Routes>
      </div>
    </div>
  );
};

export default AdminModule;
