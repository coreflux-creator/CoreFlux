import React, { useState, useEffect } from 'react';
import { Routes, Route, Link, useLocation } from 'react-router-dom';
import { Section, StatsGrid, StatCard, ActionCardsGrid, ActionCard, Card } from '../components/UIComponents';
import { Building2, Users, Package, Plus, Search, Edit, Trash2 } from 'lucide-react';

// Admin Overview
const AdminOverview = () => (
  <>
    <div style={{ marginBottom: 'var(--cf-space-6)' }}>
      <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 'var(--cf-space-2)' }}>Admin Panel</h1>
      <p style={{ color: 'var(--cf-text-secondary)' }}>Manage tenants, users, and module access across the platform.</p>
    </div>

    <Section title="Quick Stats">
      <StatsGrid>
        <StatCard value="3" label="Total Tenants" type="completed" />
        <StatCard value="12" label="Total Users" type="active_users" />
        <StatCard value="6" label="Active Modules" type="this_month" />
        <StatCard value="100%" label="System Health" type="approved" />
      </StatsGrid>
    </Section>

    <Section title="Quick Actions">
      <ActionCardsGrid>
        <ActionCard icon={Building2} title="Manage Tenants" description="View and edit tenants" href="/admin/tenants" />
        <ActionCard icon={Users} title="Manage Users" description="View and edit users" href="/admin/users" />
        <ActionCard icon={Package} title="Module Access" description="Configure module access" href="/admin/modules" />
      </ActionCardsGrid>
    </Section>
  </>
);

// Tenants Management
const TenantsPage = () => {
  const [tenants, setTenants] = useState([
    { id: 1, name: 'CoreFlux', slug: 'coreflux', users: 5, modules: 3 },
    { id: 2, name: 'Acme Corp', slug: 'acme', users: 4, modules: 2 },
    { id: 3, name: 'Beta Industries', slug: 'beta', users: 3, modules: 1 },
  ]);

  return (
    <>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-6)' }}>
        <div>
          <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 'var(--cf-space-2)' }}>Manage Tenants</h1>
          <p style={{ color: 'var(--cf-text-secondary)' }}>Create and manage tenant organizations.</p>
        </div>
        <button className="btn-primary" style={{ display: 'flex', alignItems: 'center', gap: '8px', padding: '10px 20px', background: 'var(--cf-primary)', color: 'white', border: 'none', borderRadius: '8px', cursor: 'pointer' }}>
          <Plus size={18} /> Add Tenant
        </button>
      </div>

      <Card>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead>
            <tr style={{ borderBottom: '1px solid var(--cf-border)' }}>
              <th style={{ textAlign: 'left', padding: '12px 16px', color: 'var(--cf-text-secondary)', fontWeight: 500, fontSize: '13px' }}>Tenant Name</th>
              <th style={{ textAlign: 'left', padding: '12px 16px', color: 'var(--cf-text-secondary)', fontWeight: 500, fontSize: '13px' }}>Slug</th>
              <th style={{ textAlign: 'center', padding: '12px 16px', color: 'var(--cf-text-secondary)', fontWeight: 500, fontSize: '13px' }}>Users</th>
              <th style={{ textAlign: 'center', padding: '12px 16px', color: 'var(--cf-text-secondary)', fontWeight: 500, fontSize: '13px' }}>Modules</th>
              <th style={{ textAlign: 'right', padding: '12px 16px', color: 'var(--cf-text-secondary)', fontWeight: 500, fontSize: '13px' }}>Actions</th>
            </tr>
          </thead>
          <tbody>
            {tenants.map((tenant) => (
              <tr key={tenant.id} style={{ borderBottom: '1px solid var(--cf-border-light)' }}>
                <td style={{ padding: '16px', fontWeight: 500 }}>{tenant.name}</td>
                <td style={{ padding: '16px', color: 'var(--cf-text-secondary)' }}>{tenant.slug}</td>
                <td style={{ padding: '16px', textAlign: 'center' }}>{tenant.users}</td>
                <td style={{ padding: '16px', textAlign: 'center' }}>{tenant.modules}</td>
                <td style={{ padding: '16px', textAlign: 'right' }}>
                  <button style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--cf-accent)', marginRight: '8px' }}><Edit size={16} /></button>
                  <button style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#ef4444' }}><Trash2 size={16} /></button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>
    </>
  );
};

// Users Management
const UsersPage = () => {
  const [users, setUsers] = useState([
    { id: 1, name: 'Kunal', email: 'kunal@coreflux.app', role: 'Admin', tenant: 'CoreFlux' },
    { id: 2, name: 'John Doe', email: 'john@acme.com', role: 'Employee', tenant: 'Acme Corp' },
    { id: 3, name: 'Jane Smith', email: 'jane@beta.com', role: 'Manager', tenant: 'Beta Industries' },
  ]);

  return (
    <>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-6)' }}>
        <div>
          <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 'var(--cf-space-2)' }}>Manage Users</h1>
          <p style={{ color: 'var(--cf-text-secondary)' }}>Add users and assign roles.</p>
        </div>
        <button style={{ display: 'flex', alignItems: 'center', gap: '8px', padding: '10px 20px', background: 'var(--cf-primary)', color: 'white', border: 'none', borderRadius: '8px', cursor: 'pointer' }}>
          <Plus size={18} /> Add User
        </button>
      </div>

      <Card>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead>
            <tr style={{ borderBottom: '1px solid var(--cf-border)' }}>
              <th style={{ textAlign: 'left', padding: '12px 16px', color: 'var(--cf-text-secondary)', fontWeight: 500, fontSize: '13px' }}>Name</th>
              <th style={{ textAlign: 'left', padding: '12px 16px', color: 'var(--cf-text-secondary)', fontWeight: 500, fontSize: '13px' }}>Email</th>
              <th style={{ textAlign: 'left', padding: '12px 16px', color: 'var(--cf-text-secondary)', fontWeight: 500, fontSize: '13px' }}>Role</th>
              <th style={{ textAlign: 'left', padding: '12px 16px', color: 'var(--cf-text-secondary)', fontWeight: 500, fontSize: '13px' }}>Tenant</th>
              <th style={{ textAlign: 'right', padding: '12px 16px', color: 'var(--cf-text-secondary)', fontWeight: 500, fontSize: '13px' }}>Actions</th>
            </tr>
          </thead>
          <tbody>
            {users.map((user) => (
              <tr key={user.id} style={{ borderBottom: '1px solid var(--cf-border-light)' }}>
                <td style={{ padding: '16px', fontWeight: 500 }}>{user.name}</td>
                <td style={{ padding: '16px', color: 'var(--cf-text-secondary)' }}>{user.email}</td>
                <td style={{ padding: '16px' }}>
                  <span style={{ padding: '4px 12px', background: 'var(--cf-accent-light)', color: 'var(--cf-accent)', borderRadius: '20px', fontSize: '12px', fontWeight: 500 }}>{user.role}</span>
                </td>
                <td style={{ padding: '16px', color: 'var(--cf-text-secondary)' }}>{user.tenant}</td>
                <td style={{ padding: '16px', textAlign: 'right' }}>
                  <button style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--cf-accent)', marginRight: '8px' }}><Edit size={16} /></button>
                  <button style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#ef4444' }}><Trash2 size={16} /></button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>
    </>
  );
};

// Modules Management
const ModulesPage = () => {
  const [moduleAccess, setModuleAccess] = useState([
    { tenant: 'CoreFlux', accounting: true, people: true, finance: true },
    { tenant: 'Acme Corp', accounting: true, people: true, finance: false },
    { tenant: 'Beta Industries', accounting: true, people: false, finance: false },
  ]);

  return (
    <>
      <div style={{ marginBottom: 'var(--cf-space-6)' }}>
        <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 'var(--cf-space-2)' }}>Module Access</h1>
        <p style={{ color: 'var(--cf-text-secondary)' }}>Enable or disable modules per tenant.</p>
      </div>

      <Card>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead>
            <tr style={{ borderBottom: '1px solid var(--cf-border)' }}>
              <th style={{ textAlign: 'left', padding: '12px 16px', color: 'var(--cf-text-secondary)', fontWeight: 500, fontSize: '13px' }}>Tenant</th>
              <th style={{ textAlign: 'center', padding: '12px 16px', color: 'var(--cf-text-secondary)', fontWeight: 500, fontSize: '13px' }}>Accounting</th>
              <th style={{ textAlign: 'center', padding: '12px 16px', color: 'var(--cf-text-secondary)', fontWeight: 500, fontSize: '13px' }}>People</th>
              <th style={{ textAlign: 'center', padding: '12px 16px', color: 'var(--cf-text-secondary)', fontWeight: 500, fontSize: '13px' }}>Finance</th>
            </tr>
          </thead>
          <tbody>
            {moduleAccess.map((row, idx) => (
              <tr key={idx} style={{ borderBottom: '1px solid var(--cf-border-light)' }}>
                <td style={{ padding: '16px', fontWeight: 500 }}>{row.tenant}</td>
                <td style={{ padding: '16px', textAlign: 'center' }}>
                  <input type="checkbox" checked={row.accounting} onChange={() => {}} style={{ width: '18px', height: '18px', cursor: 'pointer' }} />
                </td>
                <td style={{ padding: '16px', textAlign: 'center' }}>
                  <input type="checkbox" checked={row.people} onChange={() => {}} style={{ width: '18px', height: '18px', cursor: 'pointer' }} />
                </td>
                <td style={{ padding: '16px', textAlign: 'center' }}>
                  <input type="checkbox" checked={row.finance} onChange={() => {}} style={{ width: '18px', height: '18px', cursor: 'pointer' }} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>
    </>
  );
};

// Admin Sidebar
const AdminSidebar = () => {
  const location = useLocation();
  
  const links = [
    { to: '/admin', label: 'Overview', icon: Package },
    { to: '/admin/tenants', label: 'Tenants', icon: Building2 },
    { to: '/admin/users', label: 'Users', icon: Users },
    { to: '/admin/modules', label: 'Module Access', icon: Package },
  ];

  return (
    <aside className="sidebar">
      <div className="sidebar-header">
        <h2 className="sidebar-title">Admin</h2>
      </div>
      <nav className="sidebar-nav">
        {links.map((link) => {
          const Icon = link.icon;
          const isActive = location.pathname === link.to;
          return (
            <div key={link.to} className="sidebar-item">
              <Link to={link.to} className={`sidebar-link ${isActive ? 'active' : ''}`}>
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

// Main Admin Module
const AdminModule = ({ session }) => {
  return (
    <div style={{ display: 'flex', marginLeft: '-220px', minHeight: 'calc(100vh - 56px)' }}>
      <AdminSidebar />
      <div style={{ flex: 1, marginLeft: '220px', padding: 'var(--cf-space-6)' }}>
        <Routes>
          <Route path="/" element={<AdminOverview />} />
          <Route path="/tenants" element={<TenantsPage />} />
          <Route path="/users" element={<UsersPage />} />
          <Route path="/modules" element={<ModulesPage />} />
        </Routes>
      </div>
    </div>
  );
};

export default AdminModule;
