import React from 'react';

const Header = ({ user, modules, tenant, tenants }) => {
  const handleModuleClick = (mod) => {
    fetch('/update_active_module.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ module: mod.name })
    }).then(() => {
      window.location.href = mod.route;
    });
  };

  const handleTenantChange = (e) => {
    const selectedTenant = e.target.value;
    fetch('/update_tenant.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ tenant: selectedTenant })
    }).then(() => {
      window.location.reload();
    });
  };

  return (
    <header className="top-nav" style={{
      display: 'flex',
      justifyContent: 'space-between',
      alignItems: 'center',
      padding: '0.75rem 1.5rem',
      backgroundColor: '#002c70',
      color: 'white'
    }}>
      {/* Logo only, no redundant CoreFlux text */}
      <div className="logo-container" style={{ display: 'flex', alignItems: 'center' }}>
        <img src="https://www.corefluxapp.com/dashboard/assets/icons/logo.png" alt="CoreFlux Logo" style={{ height: '36px' }} />
      </div>

      {/* Dropdowns + User Info */}
      <div style={{ display: 'flex', alignItems: 'center', gap: '1rem' }}>
        {/* Module Dropdown */}
        <select
          onChange={(e) => {
            const mod = modules.find(m => m.name === e.target.value);
            if (mod) handleModuleClick(mod);
          }}
          style={{ padding: '6px', borderRadius: '4px' }}
        >
          {modules.map(mod => (
            <option key={mod.name} value={mod.name}>
              {mod.name}
            </option>
          ))}
        </select>

        {/* Tenant Dropdown */}
        <select
          value={tenant}
          onChange={handleTenantChange}
          style={{ padding: '6px', borderRadius: '4px' }}
        >
          {tenants.map((t) => (
            <option key={t} value={t}>{t}</option>
          ))}
        </select>

        {/* User + Logout */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
          <div style={{
            background: '#ccc',
            width: '32px',
            height: '32px',
            borderRadius: '50%',
            textAlign: 'center',
            lineHeight: '32px',
            fontWeight: 'bold'
          }}>
            {user.first_name?.charAt(0) ?? 'U'}
          </div>
          <a
            href="/logout.php"
            style={{
              color: '#fff',
              backgroundColor: '#004d99',
              padding: '4px 10px',
              borderRadius: '4px',
              textDecoration: 'none'
            }}
          >
            Logout
          </a>
        </div>
      </div>
    </header>
  );
};

export default Header;
