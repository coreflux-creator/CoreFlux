import React from 'react';
import { Link } from 'react-router-dom';
import {
  ArrowDownLeft, ArrowRight, ArrowUpRight, BookOpen,
  Scale, TrendingUp, Wallet,
} from 'lucide-react';

export const FINANCIAL_REPORTS = [
  { to: '/modules/accounting/pnl', label: 'Income statement', meta: 'Revenue, expenses and profit', Icon: TrendingUp, tone: 'blue', moduleId: 'accounting' },
  { to: '/modules/accounting/balance', label: 'Balance sheet', meta: 'Assets, liabilities and equity', Icon: BookOpen, tone: 'navy', moduleId: 'accounting' },
  { to: '/modules/accounting/cash-flow', label: 'Cash flow', meta: 'Operating, investing and financing', Icon: Wallet, tone: 'teal', moduleId: 'accounting' },
  { to: '/modules/accounting/trial', label: 'Trial balance', meta: 'Debit and credit control totals', Icon: Scale, tone: 'slate', moduleId: 'accounting' },
  { to: '/modules/billing/aging', label: 'AR aging', meta: 'Outstanding customer balances', Icon: ArrowDownLeft, tone: 'green', moduleId: 'billing' },
  { to: '/modules/ap/aging', label: 'AP aging', meta: 'Outstanding vendor balances', Icon: ArrowUpRight, tone: 'amber', moduleId: 'ap' },
];

export default function FinancialReportLibrary({ session, prominent = false }) {
  const scopedModules = Array.isArray(session?.modules)
    ? new Set(session.modules.map(module => module.id))
    : null;
  const reports = scopedModules
    ? FINANCIAL_REPORTS.filter(report => scopedModules.has(report.moduleId))
    : FINANCIAL_REPORTS;
  const Heading = prominent ? 'h2' : 'h3';

  if (reports.length === 0) return null;

  return (
    <section
      className={`report-library${prominent ? ' report-library--prominent' : ''}`}
      aria-labelledby="financial-report-heading"
      data-testid="financial-report-library"
    >
      <div className="report-library__heading">
        <div>
          {prominent && <span className="report-library__eyebrow">Accounting</span>}
          <Heading id="financial-report-heading">Core financial reports</Heading>
          <p>Statement-ready views that reconcile to the posted ledger.</p>
        </div>
        {prominent && scopedModules?.has('accounting') && (
          <Link to="/modules/accounting/reports" className="report-library__all-link">
            Accounting reports <ArrowRight size={14} aria-hidden="true" />
          </Link>
        )}
      </div>
      <div className="report-library__grid">
        {reports.map(({ to, label, meta, Icon, tone }) => (
          <Link
            key={to}
            to={to}
            className={`report-library__item report-library__item--${tone}`}
            data-testid={`financial-report-${to.split('/').pop()}`}
          >
            <span className="report-library__item-icon" aria-hidden="true"><Icon size={18} /></span>
            <span className="report-library__item-copy">
              <strong>{label}</strong>
              <small>{meta}</small>
            </span>
            <ArrowRight className="report-library__item-arrow" size={16} aria-hidden="true" />
          </Link>
        ))}
      </div>
    </section>
  );
}
