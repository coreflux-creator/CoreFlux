import React, { useState, useRef, useEffect } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { ChevronDown, LayoutDashboard, Shield, Building2, Inbox, Briefcase, TrendingUp, UserCog } from 'lucide-react';
import { api } from '../lib/api';

const Header = ({ user, modules, tenant, tenants, activeModule, onModuleChange, onTenantChange }) => {
  const [moduleOpen, setModuleOpen] = useState(false);
  const [tenantOpen, setTenantOpen] = useState(false);
  const [userOpen, setUserOpen] = useState(false);
  const [entityOpen, setEntityOpen] = useState(false);
  const [entities, setEntities] = useState([]);
  const [activeEntityId, setActiveEntityId] = useState(null);
  const [personaOpen, setPersonaOpen] = useState(false);
  const [personas, setPersonas] = useState([]);
  const [activePersonaId, setActivePersonaId] = useState(null);
  const location = useLocation();

  const moduleRef = useRef(null);
  const tenantRef = useRef(null);
  const userRef = useRef(null);
  const entityRef = useRef(null);
  const personaRef = useRef(null);

  useEffect(() => {
    const handleClickOutside = (e) => {
      if (moduleRef.current && !moduleRef.current.contains(e.target)) setModuleOpen(false);
      if (tenantRef.current && !tenantRef.current.contains(e.target)) setTenantOpen(false);
      if (userRef.current && !userRef.current.contains(e.target)) setUserOpen(false);
      if (entityRef.current && !entityRef.current.contains(e.target)) setEntityOpen(false);
      if (personaRef.current && !personaRef.current.contains(e.target)) setPersonaOpen(false);
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

  // RBAC B5 — Persona switcher.
  // Only renders when the current user holds ≥2 active memberships in
  // the current tenant. Single-persona users (the common case) see no
  // extra chrome. Switching writes $_SESSION['active_persona_id'] so
  // RBACResolver picks the chosen membership for every can() check.
  useEffect(() => {
    let cancelled = false;
    api.get('/api/active_persona.php').then(r => {
      if (cancelled) return;
      setPersonas(r?.personas ?? []);
      setActivePersonaId(r?.active_persona_id ?? null);
    }).catch(() => { /* silent */ });
    return () => { cancelled = true; };
  }, [tenant]);

  const onPersonaChange = async (personaId) => {
    try {
      const r = await api.post('/api/active_persona.php', { persona_id: personaId });
      setActivePersonaId(r?.active_persona_id ?? personaId);
      setPersonaOpen(false);
      // Soft refresh so permission-gated UI re-renders against the new persona.
      window.dispatchEvent(new CustomEvent('cf:active-persona-changed', { detail: { persona_id: personaId } }));
    } catch (e) {
      console.error('persona switch failed', e);
      alert(e.message || 'Persona switch failed');
    }
  };

  const isOnDashboard = location.pathname === '/' || location.pathname === '/dashboard';
  const isOnInbox = location.pathname === '/inbox';
  const isOnCfo   = location.pathname.startsWith('/cfo');
  const activeEntity  = entities.find(e => e.id === activeEntityId);
  const activePersona = personas.find(p => p.id === activePersonaId);

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

        <Link to="/cfo" className={`header-btn ${isOnCfo ? 'active' : ''}`} data-testid="header-cfo-link">
          <TrendingUp size={18} className="header-btn-icon" />
          <span>CFO</span>
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

        {/* RBAC B5 — Persona switcher (only renders when user has ≥2 personas in current tenant) */}
        {personas.length > 1 && (
          <div className={`dropdown ${personaOpen ? 'open' : ''}`} ref={personaRef} data-testid="header-persona-switcher">
            <button className="header-btn"
                    data-testid="header-persona-button"
                    onClick={(e) => { e.stopPropagation(); setPersonaOpen(!personaOpen); }}>
              <UserCog size={18} className="header-btn-icon" />
              <span>{activePersona ? activePersona.persona_label : 'Persona'}</span>
              <ChevronDown size={14} className="caret" />
            </button>
            {personaOpen && (
              <div className="dropdown-menu dropdown-menu-right">
                {personas.map(p => (
                  <div key={p.id}
                       className={`dropdown-item ${p.id === activePersonaId ? 'active' : ''}`}
                       data-testid={`header-persona-option-${p.id}`}
                       onClick={() => onPersonaChange(p.id)}>
                    <strong>{p.persona_label}</strong>
                    {p.is_primary && (
                      <span style={{ marginLeft: 6, fontSize: 9, fontWeight: 600,
                                     background: '#2f7a3b22', color: '#2f7a3b',
                                     padding: '1px 5px', borderRadius: 8, verticalAlign: 'middle' }}>
                        PRIMARY
                      </span>
                    )}
                    <span style={{ color: '#64748b', marginLeft: 8, fontSize: 12 }}>
                      {p.persona_type}
                    </span>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

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
