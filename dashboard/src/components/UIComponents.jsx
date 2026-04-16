import React from 'react';
import { Link } from 'react-router-dom';

// Hero Section - matches the production dashboard
export const ModuleHero = ({ title, description, image }) => (
  <section className="module-hero">
    <div className="module-hero-content">
      <h1 className="module-hero-title">{title}</h1>
      <p className="module-hero-description">{description}</p>
    </div>
    {image && (
      <img 
        src={image} 
        alt={title} 
        className="module-hero-image"
        onError={(e) => { e.target.style.opacity = '0'; }}
      />
    )}
  </section>
);

// Stats Grid Container
export const StatsGrid = ({ children }) => (
  <div className="stats-grid">
    {children}
  </div>
);

// Individual Stat Card
export const StatCard = ({ value, label, sublabel }) => (
  <div className="stat-card">
    <div className="stat-value">{value}</div>
    <div className="stat-label">{label}</div>
    {sublabel && <div className="stat-sublabel">{sublabel}</div>}
  </div>
);

// Section with Title
export const Section = ({ title, children }) => (
  <section style={{ marginBottom: 'var(--space-xl)' }}>
    {title && (
      <div className="section-header">
        <h2 className="section-title">{title}</h2>
      </div>
    )}
    {children}
  </section>
);

// Action Cards Grid
export const ActionCardsGrid = ({ children }) => (
  <div className="action-cards">
    {children}
  </div>
);

// Individual Action Card
export const ActionCard = ({ icon, title, description, href, onClick }) => {
  const content = (
    <>
      {icon && (
        <img 
          src={icon} 
          alt="" 
          className="action-card-icon"
          onError={(e) => { e.target.style.display = 'none'; }}
        />
      )}
      <div className="action-card-title">{title}</div>
      {description && <div className="action-card-description">{description}</div>}
    </>
  );

  if (href) {
    // Use React Router Link for internal navigation
    if (href.startsWith('/modules/')) {
      return (
        <Link to={href} className="action-card">
          {content}
        </Link>
      );
    }
    // External or PHP links
    return (
      <a href={href} className="action-card">
        {content}
      </a>
    );
  }

  return (
    <div className="action-card" onClick={onClick} role="button" tabIndex={0}>
      {content}
    </div>
  );
};

// Card Component
export const Card = ({ title, children, className = '' }) => (
  <div className={`card ${className}`}>
    {title && (
      <div className="card-header">
        <h3 className="card-title">{title}</h3>
      </div>
    )}
    <div className="card-body">
      {children}
    </div>
  </div>
);

// Empty State
export const EmptyState = ({ title, description, action }) => (
  <div className="empty-state">
    <h3 className="empty-state-title">{title}</h3>
    {description && <p>{description}</p>}
    {action}
  </div>
);

// Page wrapper
export const Page = ({ children }) => (
  <div className="page">
    {children}
  </div>
);
