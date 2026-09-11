import React, { useEffect, useRef, useState } from 'react';
import { ChevronDown } from 'lucide-react';
import { NavLink, useLocation } from 'react-router-dom';

export default function ModuleTabs({ items, primaryCount = 5, label = 'Module sections', testId = 'module-tabs' }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  const location = useLocation();
  const primary = items.slice(0, primaryCount);
  const overflow = items.slice(primaryCount);
  const overflowActive = overflow.some(item => routeIsActive(location.pathname, item.to));

  useEffect(() => {
    const close = event => {
      if (ref.current && !ref.current.contains(event.target)) setOpen(false);
    };
    document.addEventListener('click', close);
    return () => document.removeEventListener('click', close);
  }, []);

  return (
    <nav className="module-tabs" aria-label={label} data-testid={testId}>
      <div className="module-tabs__primary">
        {primary.map(item => <ModuleTab key={item.to} item={item} />)}
      </div>
      {overflow.length > 0 && (
        <div className="module-tabs__more" ref={ref}>
          <button
            type="button"
            className={`module-tabs__more-button${overflowActive ? ' is-active' : ''}`}
            aria-expanded={open}
            onClick={event => { event.stopPropagation(); setOpen(value => !value); }}
          >
            More <ChevronDown size={14} aria-hidden="true" />
          </button>
          {open && (
            <div className="module-tabs__menu">
              {overflow.map(item => <ModuleTab key={item.to} item={item} menu onSelect={() => setOpen(false)} />)}
            </div>
          )}
        </div>
      )}
    </nav>
  );
}

function ModuleTab({ item, menu = false, onSelect }) {
  return (
    <NavLink
      to={item.to}
      className={({ isActive }) => `${menu ? 'module-tabs__menu-link' : 'module-tabs__link'}${isActive ? ' is-active' : ''}`}
      data-testid={item.testId}
      onClick={onSelect}
    >
      {item.label}
    </NavLink>
  );
}

function routeIsActive(pathname, to) {
  const absolute = to.startsWith('/') ? to : `/${to}`;
  return pathname === absolute || pathname.endsWith(absolute) || pathname.includes(`${absolute}/`);
}
