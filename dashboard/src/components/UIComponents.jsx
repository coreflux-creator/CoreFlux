import React from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, RefreshCw, Users, Clock, TrendingUp, CheckCircle, DollarSign, FileText, Building2, UserPlus } from 'lucide-react';

// Icon mapping for quick overview stats
const statIconMap = {
  'users': Users,
  'active_users': Users,
  'employees': Users,
  'time': Clock,
  'this_month': Clock,
  'pending': Clock,
  'revenue': DollarSign,
  'trend': TrendingUp,
  'hours': TrendingUp,
  'completed': CheckCircle,
  'approved': CheckCircle,
};

// Color mapping
const colorMap = {
  'users': 'purple',
  'active_users': 'purple',
  'employees': 'purple',
  'time': 'orange',
  'this_month': 'orange',
  'pending': 'orange',
  'revenue': 'green',
  'hours': 'green',
  'trend': 'green',
  'completed': 'teal',
  'approved': 'teal',
};

// Module Cards at top of dashboard
export const ModuleCards = ({ modules, onModuleClick }) => (
  <div className="module-cards">
    {modules.map((mod) => {
      const IconComponent = mod.id === 'accounting' ? DollarSign : 
                          mod.id === 'people' ? Users : 
                          mod.id === 'finance' ? TrendingUp : Building2;
      const color = mod.id === 'accounting' ? 'green' : 
                   mod.id === 'people' ? 'purple' : 
                   mod.id === 'finance' ? 'blue' : 'orange';
      
      return (
        <Link
          key={mod.id}
          to={`/modules/${mod.id}/overview`}
          className="module-card"
          onClick={() => onModuleClick?.(mod)}
        >
          <div className="module-card-content">
            <div className={`module-card-icon ${color}`}>
              <IconComponent size={20} />
            </div>
            <div className="module-card-title">{mod.name}</div>
            <div className="module-card-desc">Access {mod.name} module</div>
          </div>
          <span className="module-card-arrow"><ArrowRight size={20} /></span>
        </Link>
      );
    })}
  </div>
);

// Section with optional header link
export const Section = ({ title, linkText, linkHref, onRefresh, children }) => (
  <section className="section">
    <div className="section-header">
      <h2 className="section-title">{title}</h2>
      {onRefresh && (
        <span className="section-link" onClick={onRefresh}>
          <RefreshCw size={14} /> Refresh
        </span>
      )}
      {linkText && linkHref && (
        <Link to={linkHref} className="section-link">
          {linkText}
        </Link>
      )}
    </div>
    {children}
  </section>
);

// Quick Overview Stats Grid
export const StatsGrid = ({ children }) => (
  <div className="stats-grid">
    {children}
  </div>
);

// Individual Stat Card with icon
export const StatCard = ({ value, label, type = 'default' }) => {
  const IconComponent = statIconMap[type] || Users;
  const color = colorMap[type] || 'blue';
  
  return (
    <div className="stat-card">
      <div className={`stat-icon ${color}`}>
        <IconComponent size={20} />
      </div>
      <div className="stat-content">
        <div className="stat-value">{value}</div>
        <div className="stat-label">{label}</div>
      </div>
    </div>
  );
};

// Action Cards Grid
export const ActionCardsGrid = ({ children }) => (
  <div className="action-cards">
    {children}
  </div>
);

// Individual Action Card
export const ActionCard = ({ icon: Icon, title, description, href, onClick }) => {
  const content = (
    <>
      <div className="action-card-icon">
        {Icon && <Icon size={24} />}
      </div>
      <div className="action-card-title">{title}</div>
      {description && <div className="action-card-desc">{description}</div>}
    </>
  );

  if (href) {
    if (href.startsWith('/modules/')) {
      return <Link to={href} className="action-card">{content}</Link>;
    }
    return <a href={href} className="action-card">{content}</a>;
  }

  return (
    <div className="action-card" onClick={onClick} role="button" tabIndex={0}>
      {content}
    </div>
  );
};

// Help Section
export const HelpSection = () => (
  <div className="help-section">
    <div className="help-content">
      <h3>Need help getting started?</h3>
      <p>Check out our documentation or contact support for assistance.</p>
    </div>
    <button className="help-btn">View Docs</button>
  </div>
);

// Module Hero (for sub-pages)
export const ModuleHero = ({ title, description, image }) => (
  <section className="module-hero">
    <div className="module-hero-content">
      <h1 className="module-hero-title">{title}</h1>
      <p className="module-hero-desc">{description}</p>
    </div>
    {image && (
      <img 
        src={image} 
        alt={title} 
        className="module-hero-image"
        onError={(e) => { e.target.style.display = 'none'; }}
      />
    )}
  </section>
);

// Card component (for general use)
export const Card = ({ title, children, className = '' }) => (
  <div className={`stat-card ${className}`} style={{ flexDirection: 'column', alignItems: 'stretch' }}>
    {title && <h3 style={{ marginBottom: 'var(--cf-space-4)', fontWeight: 600 }}>{title}</h3>}
    {children}
  </div>
);

// Empty State
export const EmptyState = ({ title, description }) => (
  <div style={{ textAlign: 'center', padding: 'var(--cf-space-8)', color: 'var(--cf-text-secondary)' }}>
    {title && <h4 style={{ marginBottom: 'var(--cf-space-2)', color: 'var(--cf-text)' }}>{title}</h4>}
    {description && <p>{description}</p>}
  </div>
);
