import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import {
  forgetCsvImportRun, pendingCsvImportRuns, recoverCsvImportAudit,
} from '../lib/csvImportAudit';

const LABELS = {
  ap_vendors: 'Vendors',
  accounting_accounts: 'Chart of accounts',
  billing_items: 'Products & services',
  ap_bills: 'AP bills',
  billing_invoices: 'AR invoices',
  ap_payments: 'Vendor payments',
  billing_payments: 'Customer payments',
};

export default function CsvImportRecoveryNotice({ entity = null, onRecovered = null }) {
  const [runs, setRuns] = useState(() => pendingCsvImportRuns().filter(run => !entity || run.entity === entity));
  const [results, setResults] = useState([]);
  const [errors, setErrors] = useState({});
  const [checking, setChecking] = useState(null);

  const check = async (run) => {
    setChecking(run.id);
    setErrors(previous => ({ ...previous, [run.id]: null }));
    try {
      const receipt = await recoverCsvImportAudit({ runId: run.id });
      forgetCsvImportRun(run.id);
      setRuns(previous => previous.filter(item => item.id !== run.id));
      setResults(previous => [...previous, { ...run, ...receipt.result }]);
      if (onRecovered) onRecovered();
    } catch (error) {
      if (error.status === 404) {
        forgetCsvImportRun(run.id);
        setRuns(previous => previous.filter(item => item.id !== run.id));
        setErrors(previous => ({ ...previous, [run.id]: 'This run is not available to this sign-in. Check Import History for your own runs.' }));
      } else {
        setErrors(previous => ({ ...previous, [run.id]: error.message || 'The server outcome is not available yet.' }));
      }
    } finally { setChecking(null); }
  };

  if (!runs.length && !results.length && !Object.values(errors).some(Boolean)) return null;
  return (
    <div className="alert alert--warning" data-testid="csv-import-recovery-notice" style={{ marginBottom: 'var(--cf-space-4)' }}>
      {runs.length > 0 && <strong>Previous import needs checking</strong>}
      {runs.map(run => (
        <div key={run.id} data-testid={`csv-import-pending-${run.id}`} style={{ marginTop: 8 }}>
          <span>{LABELS[run.entity]} run #{run.id}. Check its server receipt before importing that file again.</span>{' '}
          <button className="btn" onClick={() => check(run)} disabled={checking !== null} data-testid={`csv-import-recover-${run.id}`}>
            {checking === run.id ? 'Checking...' : 'Check server outcome'}
          </button>
          {errors[run.id] && <p className="error" role="alert">{errors[run.id]}</p>}
        </div>
      ))}
      {results.map(result => (
        <p key={result.id} data-testid={`csv-import-recovered-${result.id}`} style={{ margin: '8px 0 0' }}>
          {LABELS[result.entity]} run #{result.id}: {result.imported_count} imported, {result.skipped_count} skipped. Verified from the server receipt.
        </p>
      ))}
      {runs.length === 0 && Object.values(errors).filter(Boolean).map((message, index) => (
        <p key={index} className="error" role="alert">{message}</p>
      ))}
      <Link to="/modules/accounting/import-history">View import history</Link>
    </div>
  );
}
