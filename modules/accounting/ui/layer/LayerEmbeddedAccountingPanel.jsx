import React, { useEffect, useState } from 'react';
import LayerErrorBoundary from './LayerErrorBoundary';

/**
 * LayerEmbeddedAccountingPanel — mounts the real @layerfi/components inside
 * a CoreFlux tenant's embedded accounting sandbox.
 *
 * The package is loaded dynamically so a load/runtime issue is contained and
 * never blocks the surrounding CoreFlux UI. Each Layer component is wrapped
 * in an error boundary; Layer's own onError + lifecycle callbacks are
 * forwarded to CoreFlux audit logging via onClientError.
 *
 * props:
 *   session       = { businessId, businessAccessToken, environment, stub }
 *   onClientError = (payload) => void   // POSTed to /layer-client-error
 */
export default function LayerEmbeddedAccountingPanel({ session, onClientError }) {
  const [lib, setLib] = useState(null);
  const [loadError, setLoadError] = useState(null);

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        const mod = await import('@layerfi/components');
        try { await import('@layerfi/components/dist/index.css'); } catch (e) { /* css optional */ }
        if (mounted) setLib(mod);
      } catch (e) {
        if (mounted) setLoadError(e?.message || String(e));
      }
    })();
    return () => { mounted = false; };
  }, []);

  if (loadError) {
    return (
      <div className="layer-embed-error" data-testid="layer-embed-load-error">
        Failed to load <code>@layerfi/components</code>: {loadError}
      </div>
    );
  }
  if (!lib) {
    return (
      <div className="layer-embed-loading" data-testid="layer-embed-loading">
        Loading embedded LayerFi components…
      </div>
    );
  }

  const {
    LayerProvider, Integrations, BankTransactions,
    ChartOfAccounts, ProfitAndLoss, BalanceSheet, LinkedAccounts,
  } = lib;

  const emit = (payload) => { try { onClientError && onClientError(payload); } catch (e) { /* noop */ } };
  const handleError = (err) =>
    emit({ type: err?.type || 'error', scope: err?.scope || 'LayerProvider', payload: { message: err?.message || 'Layer component error' } });

  const Section = ({ title, testid, Comp }) => {
    if (!Comp) return null;
    return (
      <section className="layer-embed-card" data-testid={testid}>
        <header className="layer-embed-card__head">
          <h3>{title}</h3>
        </header>
        <div className="layer-embed-card__body">
          <LayerErrorBoundary label={title} onError={(e) => emit({ type: 'render', scope: title, payload: { message: e?.message } })}>
            <Comp />
          </LayerErrorBoundary>
        </div>
      </section>
    );
  };

  return (
    <div data-testid="layer-embedded-panel">
      {session?.stub && (
        <div className="layer-stub-banner" data-testid="layer-stub-banner">
          Running against the in-process LayerFi sandbox stub. Embedded components will display their own
          empty/error states until live <code>LAYER_CLIENT_ID</code> / <code>LAYER_CLIENT_SECRET</code> are configured.
        </div>
      )}
      <LayerProvider
        businessId={session.businessId}
        businessAccessToken={session.businessAccessToken}
        environment={session.environment || 'sandbox'}
        usePlaidSandbox={true}
        onError={handleError}
        eventCallbacks={{
          onTransactionCategorized: () => emit({ type: 'transaction_categorized', scope: 'BankTransactions', payload: { message: 'categorized' } }),
          onTransactionsFetched: () => emit({ type: 'transactions_fetched', scope: 'BankTransactions', payload: { message: 'fetched' } }),
        }}
      >
        <div className="layer-embed-grid">
          <Section title="Integrations" testid="layer-embed-integrations" Comp={Integrations || LinkedAccounts} />
          <Section title="Bank Transactions" testid="layer-embed-bank-transactions" Comp={BankTransactions} />
          <Section title="Chart of Accounts" testid="layer-embed-coa" Comp={ChartOfAccounts} />
          <Section title="Profit & Loss" testid="layer-embed-pnl" Comp={ProfitAndLoss} />
          <Section title="Balance Sheet" testid="layer-embed-balance-sheet" Comp={BalanceSheet} />
        </div>
      </LayerProvider>
    </div>
  );
}
