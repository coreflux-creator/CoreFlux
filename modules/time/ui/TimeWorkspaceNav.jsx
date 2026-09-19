import React from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import {
  CalendarRange,
  ChartNoAxesColumnIncreasing,
  ClipboardCheck,
  FileSpreadsheet,
  ListChecks,
  Scale,
  Upload,
} from 'lucide-react';

const ITEMS = [
  { label: 'Timesheets', to: '/modules/staffing/timesheets', Icon: ListChecks, staffing: true },
  { label: 'Upload', to: '/modules/time/upload', Icon: Upload },
  { label: 'Review', to: '/modules/time/review', Icon: ClipboardCheck },
  { label: 'Settlement', to: '/modules/time/settlement', Icon: Scale },
  { label: 'Import time', to: '/modules/time/bulk', Icon: FileSpreadsheet },
  { label: 'Weekly periods', to: '/modules/time/periods', Icon: CalendarRange },
  { label: 'Reports', to: '/modules/time/reports', Icon: ChartNoAxesColumnIncreasing },
];

export default function TimeWorkspaceNav() {
  const { pathname } = useLocation();

  return (
    <nav
      aria-label="Time workflow"
      data-testid="time-workspace-nav"
      style={{
        display: 'flex',
        gap: 4,
        alignItems: 'center',
        overflowX: 'auto',
        padding: '0 0 10px',
        marginBottom: 18,
        borderBottom: '1px solid var(--cf-border, #dbe4f0)',
      }}
    >
      {ITEMS.map(({ label, to, Icon, staffing }) => {
        const active = staffing
          ? pathname.startsWith('/modules/staffing/timesheets')
          : pathname === to || pathname.startsWith(`${to}/`);
        return (
          <NavLink
            key={to}
            to={to}
            className={active ? 'btn btn--primary' : 'btn btn--ghost'}
            aria-current={active ? 'page' : undefined}
            data-testid={`time-nav-${label.toLowerCase().replace(/\s+/g, '-')}`}
            style={{ flex: '0 0 auto', display: 'inline-flex', alignItems: 'center', gap: 6 }}
          >
            <Icon size={15} aria-hidden="true" />
            {label}
          </NavLink>
        );
      })}
    </nav>
  );
}
