import React, { useState, useRef, useEffect } from 'react';
import { ChevronDown } from 'lucide-react';

const Header = ({ user, modules, tenant, tenants, activeModule, onModuleChange, onTenantChange }) => {
  const [moduleOpen, setModuleOpen] = useState(false);
  const [tenantOpen, setTenantOpen] = useState(false);
  const [userOpen, setUserOpen] = useState(false);
  
  const moduleRef = useRef(null);
  const tenantRef = useRef(null);
  const userRef = useRef(null);

  // Close dropdowns when clicking outside
  useEffect(() => {
    const handleClickOutside = (e) => {
      if (moduleRef.current && !moduleRef.current.contains(e.target)) setModuleOpen(false);
      if (tenantRef.current && !tenantRef.current.contains(e.target)) setTenantOpen(false);
      if (userRef.current && !userRef.current.contains(e.target)) setUserOpen(false);
    };
    document.addEventListener('click', handleClickOutside);
    return () => document.removeEventListener('click', handleClickOutside);
  }, []);

  return (
    <header className="top-header">
      {/* Left - Logo */}
      <div className="header-left">
        <a href="/dashboard.php" className="logo-link">
          <img 
            src="/assets/icons/logo-white.png" 
            alt="CoreFlux" 
            className="header-logo"
            onError={(e) => { e.target.style.display = 'none'; }}
          />
          <span className="logo-text">CoreFlux</span>
        </a>
      </div>

      {/* Center - Module Selector */}
      <div className="header-center">
        {modules && modules.length > 0 && (
          <div className={`dropdown ${moduleOpen ? 'open' : ''}`} ref={moduleRef}>
            <button 
              className="dropdown-trigger"
              onClick={(e) => { e.stopPropagation(); setModuleOpen(!moduleOpen); }}
            >
              <img 
                src={activeModule?.icon || '/assets/icons/icon-module.png'} 
                alt=""
                className="dropdown-icon"
                onError={(e) => { e.target.style.display = 'none'; }}
              />
              <span>{activeModule?.name || 'Select Module'}</span>
              <ChevronDown size={16} className="caret" />
            </button>
            
            {moduleOpen && (
              <div className="dropdown-menu">
                {modules.map((mod) => (
                  <div
                    key={mod.id || mod.name}
                    className={`dropdown-item ${mod.id === activeModule?.id ? 'active' : ''}`}
                    onClick={() => {
                      onModuleChange?.(mod);
                      setModuleOpen(false);
                    }}
                  >
                    <img 
                      src={mod.icon} 
                      alt="" 
                      className="dropdown-icon"
                      onError={(e) => { e.target.style.display = 'none'; }}
                    />
                    <span>{mod.name}</span>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}
      </div>

      {/* Right - Tenant & User */}
      <div className="header-right">
        {/* Tenant Selector */}
        {tenants && tenants.length > 1 ? (
          <div className={`dropdown ${tenantOpen ? 'open' : ''}`} ref={tenantRef}>
            <button 
              className="dropdown-trigger"
              onClick={(e) => { e.stopPropagation(); setTenantOpen(!tenantOpen); }}
            >
              <span>{tenant || 'Select Tenant'}</span>
              <ChevronDown size={14} className="caret" />
            </button>
            
            {tenantOpen && (
              <div className="dropdown-menu dropdown-menu-right">
                {tenants.map((t) => (
                  <div
                    key={t.id || t}
                    className={`dropdown-item ${(t.name || t) === tenant ? 'active' : ''}`}
                    onClick={() => {
                      onTenantChange?.(t.id || t);
                      setTenantOpen(false);
                    }}
                  >
                    {t.name || t}
                    {t.role && <small style={{ opacity: 0.6, marginLeft: '8px' }}>{t.role}</small>}
                  </div>
                ))}
              </div>
            )}
          </div>
        ) : (
          <span className="tenant-name">{tenant}</span>
        )}

        {/* User Menu */}
        <div className={`dropdown ${userOpen ? 'open' : ''}`} ref={userRef}>
          <button 
            className="dropdown-trigger user-trigger"
            onClick={(e) => { e.stopPropagation(); setUserOpen(!userOpen); }}
          >
            <div className="user-avatar-placeholder">
              {(user?.first_name || user?.name || 'U').charAt(0).toUpperCase()}
            </div>
            <span className="user-name">{user?.first_name || user?.name || 'User'}</span>
            <ChevronDown size={14} className="caret" />
          </button>
          
          {userOpen && (
            <div className="dropdown-menu dropdown-menu-right">
              <div className="dropdown-header">
                <strong>{user?.first_name} {user?.last_name}</strong>
                <small>{user?.email}</small>
                {user?.role && (
                  <small style={{ display: 'block', marginTop: '4px', color: 'var(--color-accent)' }}>
                    {user.role}
                  </small>
                )}
              </div>
              <hr className="dropdown-divider" />
              <a href="/dashboard.php?page=profile" className="dropdown-item">Profile</a>
              <a href="/dashboard.php?page=settings" className="dropdown-item">Settings</a>
              <hr className="dropdown-divider" />
              <a href="/logout.php" className="dropdown-item dropdown-item-danger">Logout</a>
            </div>
          )}
        </div>
      </div>
    </header>
  );
};

export default Header;
