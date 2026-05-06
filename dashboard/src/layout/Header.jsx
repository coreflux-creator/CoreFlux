import React, { useState, useRef, useEffect } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { ChevronDown, LayoutDashboard, Shield, Building2, Inbox, Briefcase } from 'lucide-react';
import { api } from '../lib/api';

const Header = ({ user, modules, tenant, tenants, activeModule, onModuleChange, onTenantChange }) => {
  const [moduleOpen, setModuleOpen] = useState(false);
  const [tenantOpen, setTenantOpen] = useState(false);
  const [userOpen, setUserOpen] = useState(false);
  const [entityOpen, setEntityOpen] = useState(false);
  const [entities, setEntities] = useState([]);
  const [activeEntityId, setActiveEntityId] = useState(null);
  const location = useLocation();

  const moduleRef = useRef(null);
  const tenantRef = useRef(null);
  const userRef = useRef(null);
  const entityRef = useRef(null);

  useEffect(() => {
    const handleClickOutside = (e) => {
      if (moduleRef.current && !moduleRef.current.contains(e.target)) setModuleOpen(false);
      if (tenantRef.current && !tenantRef.current.contains(e.target)) setTenantOpen(false);
      if (userRef.current && !userRef.current.contains(e.target)) setUserOpen(false);
      if (entityRef.current && !entityRef.current.contains(e.target)) setEntityOpen(false);
    };
    document.addEventListener('click', handleClickOutside);
    return () => document.removeEventListener('click', handleClickOutside);
  }, []);

  // Sprint 6b — multi-entity switcher.
  // Best-effort: if the active_entity API isn't available (e.g. the
  // tenant has zero accounting_entities seeded yet) the dropdown
  // simply doesn't render. Never blocks header rendering.
  useEffect(() => {
    let cancelled = false;
    api.get('/api/active_entity.php').then(r => {
      if (cancelled) return;
      setEntities(r?.entities ?? []);
      setActiveEntityId(r?.active_entity_id ?? null);
    }).catch(() => { /* silent */ });
    return () => { cancelled = true; };
  }, [tenant]);

  const onEntityChange = async (entityId) => {
    try {
      const r = await api.post('/api/active_entity.php', { entity_id: entityId });
      setActiveEntityId(r?.active_entity_id ?? entityId);
      setEntityOpen(false);
      // Soft refresh so module data scoped by entity reloads.
      window.dispatchEvent(new CustomEvent('cf:active-entity-changed', { detail: { entity_id: entityId } }));
    } catch (e) {
      console.error('active entity switch failed', e);
    }
  };

  const isOnDashboard = location.pathname === '/' || location.pathname === '/dashboard';
  const isOnInbox = location.pathname === '/inbox';
  const activeEntity = entities.find(e => e.id === activeEntityId);

  return (
    <header className="header">
      {/* Left - Logo */}
      <div className="header-left">
        <Link to="/" className="logo">
          <img 
            src="/assets/icons/logo.png" 
            alt="CoreFlux" 
            className="logo-img"
            onError={(e) => { 
              e.target.onerror = null;
              e.target.src = '/assets/logo.png';
            }}
          />
        </Link>
      </div>

      {/* Center - Dashboard & Module Selector */}
      <div className="header-center">
        <Link to="/" className={`header-btn ${isOnDashboard ? 'active' : ''}`}>
          <LayoutDashboard size={18} className="header-btn-icon" />
          <span>Dashboard</span>
        </Link>
        
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
                  <Link
                    key={mod.id || mod.name}
                    to={`/modules/${mod.id}/overview`}
                    className={`dropdown-item ${mod.id === activeModule?.id ? 'active' : ''}`}
                    onClick={() => {
                      onModuleChange?.(mod);
                      setModuleOpen(false);
                    }}
                  >
                    {mod.name}
                  </Link>
                ))}
              </div>
            )}
          </div>
        )}
      </div>

      {/* Right - Admin, Tenant & User */}
      <div className="header-right">
        {/* Sprint 6b — quick link to cross-module approval inbox */}
        <Link to="/inbox" className={`header-btn ${isOnInbox ? 'active' : ''}`} data-testid="header-inbox-link">
          <Inbox size={18} className="header-btn-icon" />
          <span>Inbox</span>
        </Link>

        {/* Sprint 6b — Multi-entity switcher (only renders if tenant has ≥1 entity) */}
        {entities.length > 0 && (
          <div className={`dropdown ${entityOpen ? 'open' : ''}`} ref={entityRef} data-testid="header-entity-switcher">
            <button className="header-btn"
                    data-testid="header-entity-button"
                    onClick={(e) => { e.stopPropagation(); setEntityOpen(!entityOpen); }}>
              <Briefcase size={18} className="header-btn-icon" />
              <span>{activeEntity ? activeEntity.code : 'Entity'}</span>
              <ChevronDown size={14} className="caret" />
            </button>
            {entityOpen && (
              <div className="dropdown-menu dropdown-menu-right">
                {entities.map(ent => (
                  <div key={ent.id}
                       className={`dropdown-item ${ent.id === activeEntityId ? 'active' : ''}`}
                       data-testid={`header-entity-option-${ent.id}`}
                       onClick={() => onEntityChange(ent.id)}>
                    <strong>{ent.code}</strong>
                    <span style={{ color: '#64748b', marginLeft: 8, fontSize: 12 }}>
                      {ent.legal_name}{ent.base_currency ? ` · ${ent.base_currency}` : ''}
                    </span>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        {(user?.global_role === 'master_admin' || user?.global_role === 'tenant_admin') && (
          <Link to="/admin" className="header-btn">
            <Shield size={18} className="header-btn-icon" />
            <span>Admin Panel</span>
          </Link>
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
              <Link to="/profile" className="dropdown-item" onClick={() => setUserOpen(false)}>Profile</Link>
              <Link to="/settings" className="dropdown-item" onClick={() => setUserOpen(false)}>Settings</Link>
              <hr className="dropdown-divider" />
              <a href="/logout.php" className="dropdown-item" style={{ color: '#ef4444' }}>Logout</a>
            </div>
          )}
        </div>
      </div>
    </header>
  );
};

export default Header;
