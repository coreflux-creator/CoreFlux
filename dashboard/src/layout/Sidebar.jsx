import React from 'react';
import { NavLink, useLocation } from 'react-router-dom';

const Sidebar = ({ activeModule }) => {
  const location = useLocation();
  
  if (!activeModule) return null;

  // Get navigation items from module - support both 'actions' and 'navItems' formats
  const navItems = activeModule.actions || activeModule.navItems || [];
  const moduleId = activeModule.id || activeModule.name?.toLowerCase().replace(/\s+/g, '_');

  return (
    <aside className="sidebar">
      <div className="sidebar-header">
        <h3>{activeModule.name}</h3>
      </div>
      
      <nav className="sidebar-nav">
        {navItems.map((item) => {
          const route = item.route?.replace('.php', '').replace(/_/g, '-') || 
                       item.name?.toLowerCase().replace(/\s+/g, '-');
          const path = `/modules/${moduleId}/${route}`;
          const isActive = location.pathname.includes(route);
          
          return (
            <NavLink
              key={item.name || route}
              to={path}
              className={`sidebar-link ${isActive ? 'active' : ''}`}
            >
              {item.name}
            </NavLink>
          );
        })}
      </nav>
      
      <div className="sidebar-footer">
        <small>CoreFlux v1.0</small>
      </div>
    </aside>
  );
};

export default Sidebar;
