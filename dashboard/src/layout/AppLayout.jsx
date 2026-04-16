import React from 'react';
import Header from './Header';
import Sidebar from './Sidebar';

const AppLayout = ({ session, children, onModuleChange, onTenantChange, showSidebar = true }) => {
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
        {showSidebar && active_module && (
          <Sidebar activeModule={active_module} />
        )}
        
        <main className="main-content" style={!showSidebar ? { marginLeft: 0 } : {}}>
          {children}
        </main>
      </div>
    </div>
  );
};

export default AppLayout;
