import React, { useState } from 'react';
import { NavLink } from 'react-router-dom';
import { ChevronDown, ChevronRight, Folder } from 'lucide-react'; // Optional icons

const Sidebar = ({ activeModule }) => {
  const [expanded, setExpanded] = useState(true);

  if (!activeModule || !activeModule.actions) return null;

  return (
    <aside
      className="sidebar"
      style={{
        width: '240px',
        background: '#f8f9fb',
        borderRight: '1px solid #e0e0e0',
        padding: '1rem'
      }}
    >
      <div
        onClick={() => setExpanded(!expanded)}
        style={{
          display: 'flex',
          alignItems: 'center',
          cursor: 'pointer',
          fontWeight: '600',
          marginBottom: '1rem',
          color: '#003366'
        }}
      >
        <Folder size={16} style={{ marginRight: '6px' }} />
        {activeModule.name}
        {expanded ? <ChevronDown size={16} style={{ marginLeft: 'auto' }} /> : <ChevronRight size={16} style={{ marginLeft: 'auto' }} />}
      </div>

      {expanded && (
        <ul style={{ listStyle: 'none', paddingLeft: 0 }}>
          {activeModule.actions.map((action) => (
            <li key={action.name} style={{ marginBottom: '0.5rem' }}>
              <NavLink
                to={`/dashboard/${action.route.replace('.php', '')}`}
                className={({ isActive }) => isActive ? 'sidebar-link active' : 'sidebar-link'}
                style={{
                  display: 'block',
                  padding: '8px 12px',
                  borderRadius: '6px',
                  color: '#003366',
                  textDecoration: 'none'
                }}
              >
                {action.name}
              </NavLink>
            </li>
          ))}
        </ul>
      )}
    </aside>
  );
};

export default Sidebar;
