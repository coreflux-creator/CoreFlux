import React from 'react';
import Header from './Header';
import Sidebar from './Sidebar';
import Footer from './Footer';

const AppLayout = ({ session, children }) => {
  const { user, modules, tenant, tenants, active_module } = session;

  return (
    <div className="app-layout" style={{ display: 'flex', flexDirection: 'column', minHeight: '100vh' }}>
      {/* Header with user, tenant, and module switching */}
      <Header
        user={user}
        modules={modules}
        tenant={tenant}
        tenants={tenants}
      />

      {/* Main content with sidebar + page */}
      <div style={{ display: 'flex', flex: 1 }}>
        <Sidebar activeModule={active_module} />
        <main style={{ flex: 1, padding: '2rem' }}>
          {children}
        </main>
      </div>

      {/* Footer */}
      <Footer />
    </div>
  );
};

export default AppLayout;
