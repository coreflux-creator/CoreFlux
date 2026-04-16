import React, { useState, useRef, useEffect } from 'react';
import { ChevronDown, LayoutDashboard, Shield, Building2 } from 'lucide-react';

const Header = ({ user, modules, tenant, tenants, activeModule, onModuleChange, onTenantChange }) => {
  const [moduleOpen, setModuleOpen] = useState(false);
  const [tenantOpen, setTenantOpen] = useState(false);
  const [userOpen, setUserOpen] = useState(false);
  
  const moduleRef = useRef(null);
  const tenantRef = useRef(null);
  const userRef = useRef(null);

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
    <header className="header">
      {/* Left - Logo */}
      <div className="header-left">
        <a href="/" className="logo">
          <img 
            src="/assets/icons/logo.png" 
            alt="CoreFlux" 
            className="logo-img"
            onError={(e) => { 
              e.target.onerror = null;
              e.target.src = '/assets/logo.png';
            }}
          />
        </a>
      </div>

      {/* Center - Dashboard & Module Selector */}
      <div className="header-center">
        <a href="/" className="header-btn active">
          <LayoutDashboard size={18} className="header-btn-icon" />
          <span>Dashboard</span>
        </a>
        
        {modules && modules.length > 0 && (
          <div className={`dropdown ${moduleOpen ? 'open' : ''}`} ref={moduleRef}>
            <button 
              className="header-btn"
              onClick={(e) => { e.stopPropagation(); setModuleOpen(!moduleOpen); }}
            >
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
                    {mod.name}
                  </div>
                ))}
              </div>
            )}
          </div>
        )}
      </div>

      {/* Right - Admin, Tenant & User */}
      <div className="header-right">
        {user?.global_role === 'master_admin' && (
          <a href="/dashboard.php?page=admin" className="header-btn">
            <Shield size={18} className="header-btn-icon" />
            <span>Admin Panel</span>
          </a>
        )}
        
        {/* Tenant Selector */}
        {tenants && tenants.length > 0 && (
          <div className={`dropdown ${tenantOpen ? 'open' : ''}`} ref={tenantRef}>
            <button 
              className="header-btn"
              onClick={(e) => { e.stopPropagation(); setTenantOpen(!tenantOpen); }}
            >
              <Building2 size={18} className="header-btn-icon" />
              <span>{tenant || 'Tenant'}</span>
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
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        {/* User Menu */}
        <div className={`dropdown ${userOpen ? 'open' : ''}`} ref={userRef}>
          <button 
            className="header-btn"
            onClick={(e) => { e.stopPropagation(); setUserOpen(!userOpen); }}
          >
            <div className="user-avatar">
              {(user?.first_name || user?.name || 'U').charAt(0).toUpperCase()}
            </div>
            <span>{user?.first_name || user?.name || 'User'}</span>
            <ChevronDown size={14} className="caret" />
          </button>
          
          {userOpen && (
            <div className="dropdown-menu dropdown-menu-right">
              <div className="dropdown-header">
                <div className="dropdown-header-name">{user?.first_name} {user?.last_name}</div>
                <div className="dropdown-header-email">{user?.email}</div>
              </div>
              <hr className="dropdown-divider" />
              <a href="/dashboard.php?page=profile" className="dropdown-item">Profile</a>
              <a href="/dashboard.php?page=settings" className="dropdown-item">Settings</a>
              <hr className="dropdown-divider" />
              <a href="/logout.php" className="dropdown-item" style={{ color: 'var(--cf-danger)' }}>Logout</a>
            </div>
          )}
        </div>
      </div>
    </header>
  );
};

export default Header;
