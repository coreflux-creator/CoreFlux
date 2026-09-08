import React from 'react';
import { Link } from 'react-router-dom';

/** Canonical navigation to a CoreFlux ledger-account workspace. */
export default function AccountLink({ accountId, accountCode, entityId, children, style, ...props }) {
  if (!accountId && !accountCode) return <span style={style}>{children}</span>;
  const base = accountId
    ? `/modules/accounting/accounts/${accountId}`
    : `/modules/accounting/accounts/detail?code=${encodeURIComponent(accountCode)}`;
  const to = entityId
    ? `${base}${base.includes('?') ? '&' : '?'}entity_id=${encodeURIComponent(entityId)}`
    : base;
  return (
    <Link
      to={to}
      style={{ color: '#075fc7', textDecoration: 'none', ...style }}
      {...props}
    >
      {children}
    </Link>
  );
}
