import React from 'react';
import Header from './Header';
import Sidebar from './Sidebar';

const AppLayout = ({ session, children, onModuleChange, onTenantChange }) => {
  const { user, modules, tenant, tenants, active_module } = session;

  return (
    <div className="app-layout">
      <Header
        user={user}
        modules={modules}
        tenant={tenant}
        tenants={tenants}
        activeModule={active_module}
        onModuleChange={onModuleChange}
        onTenantChange={onTenantChange}
      />

      <div className="app-main">
        <Sidebar session={session} activeModule={active_module} onModuleChange={onModuleChange} />
        
        <main className="main-content">
          {children}
        </main>
      </div>
    </div>
  );
};

export default AppLayout;
