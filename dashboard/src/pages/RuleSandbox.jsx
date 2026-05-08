import React, { useMemo, useState } from 'react';
import { api } from '../lib/api';
import { Sparkles, FlaskConical, AlertTriangle, CheckCircle2, RefreshCw } from 'lucide-react';

/**
 * Rule Sandbox — dry-run any accounting event against the posting-rule
 * engine and see the rendered JE WITHOUT inserting anything.
 *
 * Backed by `POST /api/accounting_events.php?action=sandbox`.
 *
 * Goals (Sprint 7b):
 *   - Build trust in posting-rules before flipping AP/AR/Payroll/Time
 *     to the event layer in 7e.
 *   - Make rule debugging visual: paste payload → see which rule matched,
 *     which template rendered, and every line's resolved account + amount.
 */
const SAMPLE_BANK_FEE = {
  entity_id: 1,
  event_type: 'treasury.bank_fee.detected',
  source_module: 'sandbox',
  source_record_id: 'sandbox-' + Date.now(),
  event_date: new Date().toISOString().slice(0, 10),
  payload: { amount: 12.5, bank_account_name: 'Operating Account', currency: 'USD' },
};

const SAMPLE_INTEREST = {
  entity_id: 1,
  event_type: 'treasury.interest.received',
  source_module: 'sandbox',
  source_record_id: 'sandbox-' + Date.now(),
  event_date: new Date().toISOString().slice(0, 10),
  payload: { amount: 84.32, bank_account_name: 'Money Market', currency: 'USD' },
};

const SAMPLE_BILL_APPROVED = {
  entity_id: 1,
  event_type: 'ap.bill.approved',
  source_module: 'sandbox',
  source_record_id: 'sandbox-' + Date.now(),
  event_date: new Date().toISOString().slice(0, 10),
  payload: { amount: 2500, vendor_id: 42, expense_account_code: '6010', currency: 'USD' },
};

export default function RuleSandbox() {
  const [json, setJson] = useState(JSON.stringify(SAMPLE_BANK_FEE, null, 2));
  const [running, setRunning] = useState(false);
  const [result, setResult] = useState(null);
  const [parseErr, setParseErr] = useState(null);
  const [seeding, setSeeding] = useState(false);
  const [seedResult, setSeedResult] = useState(null);

  const parsed = useMemo(() => {
    try { setParseErr(null); return JSON.parse(json); }
    catch (e) { setParseErr(e.message); return null; }
  }, [json]);

  const run = async () => {
    if (!parsed) return;
    setRunning(true); setResult(null);
    try {
      const r = await api.post('/api/accounting_events.php?action=sandbox', parsed);
      setResult(r);
    } catch (e) {
      setResult({ status: 'error', error: e?.message || String(e) });
    } finally {
      setRunning(false);
    }
  };

  const loadSample = (s) => {
    const fresh = { ...s, source_record_id: 'sandbox-' + Date.now(), event_date: new Date().toISOString().slice(0, 10) };
    setJson(JSON.stringify(fresh, null, 2));
    setResult(null);
  };

  const seedDefaults = async () => {
    setSeeding(true); setSeedResult(null);
    try {
      const r = await api.post('/api/posting_rules_seed.php', {});
      setSeedResult(r);
    } catch (e) {
      setSeedResult({ error: e?.message || 'Seed failed' });
    } finally {
      setSeeding(false);
    }
  };

  const [replayDays, setReplayDays] = useState(30);
  const [replayDryRun, setReplayDryRun] = useState(true);
  const [replaying, setReplaying] = useState(false);
  const [replayResult, setReplayResult] = useState(null);
  const replayHistory = async () => {
    setReplaying(true); setReplayResult(null);
    try {
      const qs = `?days=${replayDays}${replayDryRun ? '&dry_run=1' : ''}`;
      const r = await api.post('/api/posting_rules_replay.php' + qs, {});
      setReplayResult(r);
    } catch (e) {
      setReplayResult({ error: e?.message || 'Replay failed' });
    } finally {
      setReplaying(false);
    }
  };

  const status = result?.status;
  const statusBg = {
    preview: '#ecfdf5', failed: '#fef2f2', ignored: '#fffbeb', error: '#fef2f2',
  }[status] || '#f8fafc';
  const statusBorder = {
    preview: '#a7f3d0', failed: '#fecaca', ignored: '#fde68a', error: '#fecaca',
  }[status] || '#e2e8f0';
  const StatusIcon = status === 'preview' ? CheckCircle2 : (status ? AlertTriangle : FlaskConical);
  const statusLabel = {
    preview: 'Rule matched · JE rendered (not posted)',
    failed:  'Rule matched but render failed',
    ignored: 'No rule matched this event',
    error:   'Engine error',
  }[status] || '—';

  return (
    <div data-testid="rule-sandbox-page">
      <header style={{ marginBottom: 16 }}>
        <h1 style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 22, fontWeight: 700, margin: 0 }}>
          <FlaskConical size={22} color="#7c3aed" /> Posting-rule sandbox
        </h1>
        <p style={{ color: '#64748b', margin: '4px 0 0' }}>
          Dry-run an accounting event against the posting-rule engine and inspect the rendered JE without posting anything.
          Ideal before migrating modules (AP, AR, Payroll, Time) to the event layer in Sprint 7e.
        </p>
      </header>

      {/* Seed defaults strip */}
      <div data-testid="rule-sandbox-seed-strip"
           style={{ padding: 12, background: '#fffbeb', border: '1px solid #fde68a', borderRadius: 8, marginBottom: 14, display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
        <strong style={{ fontSize: 13, color: '#92400e' }}>First time on this tenant?</strong>
        <span style={{ fontSize: 12, color: '#78350f' }}>
          Seed the 17 spec system accounts + 6 default posting rules (bank fee, interest, payment, transfers, intercompany, uncategorized fallback).
          Idempotent — safe to run multiple times.
        </span>
        <button className="btn btn--primary" data-testid="rule-sandbox-seed-defaults"
                onClick={seedDefaults} disabled={seeding} style={{ marginLeft: 'auto', fontSize: 13 }}>
          {seeding ? 'Seeding…' : 'Seed default rules'}
        </button>
        {seedResult && !seedResult.error && (
          <span data-testid="rule-sandbox-seed-result" style={{ fontSize: 12, color: '#065f46', flexBasis: '100%' }}>
            ✓ {seedResult.accounts?.inserted ?? 0} accounts inserted ({seedResult.accounts?.stamped ?? 0} re-stamped),{' '}
            {seedResult.rules?.rules_inserted ?? 0} of {seedResult.rules?.pack_size ?? 6} default rules now active.
          </span>
        )}
        {seedResult?.error && (
          <span data-testid="rule-sandbox-seed-error" style={{ fontSize: 12, color: '#7f1d1d', flexBasis: '100%' }}>
            ✗ {seedResult.error}
          </span>
        )}
      </div>

      {/* Replay strip */}
      <div data-testid="rule-sandbox-replay-strip"
           style={{ padding: 12, background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: 8, marginBottom: 14, display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
        <strong style={{ fontSize: 13, color: '#1e40af' }}>Backfill audit ledger?</strong>
        <span style={{ fontSize: 12, color: '#1e3a8a' }}>
          Replay already-cleared bank transactions through the engine so each gets an `accounting_events` + `subledger_links` row.
          Idempotent — re-runs skip lines that already have events.
        </span>
        <label style={{ fontSize: 12, color: '#475569', display: 'inline-flex', alignItems: 'center', gap: 4, marginLeft: 'auto' }}>
          Window
          <select className="input" data-testid="rule-sandbox-replay-days" value={replayDays}
                  onChange={e => setReplayDays(Number(e.target.value))} style={{ padding: '2px 6px', fontSize: 12 }}>
            <option value={7}>7d</option>
            <option value={30}>30d</option>
            <option value={90}>90d</option>
            <option value={180}>180d</option>
            <option value={365}>365d</option>
          </select>
        </label>
        <label style={{ fontSize: 12, color: '#475569', display: 'inline-flex', alignItems: 'center', gap: 4 }}>
          <input type="checkbox" data-testid="rule-sandbox-replay-dry-run"
                 checked={replayDryRun} onChange={e => setReplayDryRun(e.target.checked)} />
          Dry run only
        </label>
        <button className="btn btn--primary" data-testid="rule-sandbox-replay-run"
                onClick={replayHistory} disabled={replaying} style={{ fontSize: 13 }}>
          {replaying ? 'Replaying…' : (replayDryRun ? 'Preview replay' : 'Replay now')}
        </button>
        {replayResult && !replayResult.error && (
          <span data-testid="rule-sandbox-replay-result" style={{ fontSize: 12, color: '#065f46', flexBasis: '100%' }}>
            {replayResult.dry_run ? '(dry run)' : '✓'} scanned {replayResult.scanned}, replayed {replayResult.replayed},{' '}
            skipped (already event) {replayResult.skipped_already_event},{' '}
            skipped (no bank GL) {replayResult.skipped_no_bank_gl}
            {replayResult.failed > 0 && <span style={{ color: '#b91c1c' }}>, failed {replayResult.failed}</span>}
          </span>
        )}
        {replayResult?.error && (
          <span data-testid="rule-sandbox-replay-error" style={{ fontSize: 12, color: '#7f1d1d', flexBasis: '100%' }}>
            ✗ {replayResult.error}
          </span>
        )}
      </div>

      {/* Sample chips */}
      <div data-testid="rule-sandbox-samples" style={{ display: 'flex', gap: 8, marginBottom: 12, flexWrap: 'wrap' }}>
        <span style={{ fontSize: 12, color: '#64748b', alignSelf: 'center' }}>Try:</span>
        <button className="btn btn--ghost" data-testid="rule-sandbox-sample-bank-fee"
                onClick={() => loadSample(SAMPLE_BANK_FEE)} style={{ fontSize: 12 }}>
          Bank fee
        </button>
        <button className="btn btn--ghost" data-testid="rule-sandbox-sample-interest"
                onClick={() => loadSample(SAMPLE_INTEREST)} style={{ fontSize: 12 }}>
          Interest received
        </button>
        <button className="btn btn--ghost" data-testid="rule-sandbox-sample-bill-approved"
                onClick={() => loadSample(SAMPLE_BILL_APPROVED)} style={{ fontSize: 12 }}>
          AP bill approved
        </button>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
        {/* Editor */}
        <div>
          <label style={{ fontSize: 13, fontWeight: 600, color: '#334155', display: 'block', marginBottom: 6 }}>
            Event payload (JSON)
          </label>
          <textarea
            data-testid="rule-sandbox-json"
            value={json}
            onChange={(e) => setJson(e.target.value)}
            spellCheck={false}
            style={{
              width: '100%', minHeight: 360,
              fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
              fontSize: 13, lineHeight: 1.5,
              padding: 12, border: '1px solid #cbd5e1', borderRadius: 8,
              background: '#0f172a', color: '#e2e8f0',
              resize: 'vertical',
            }}
          />
          {parseErr && (
            <p data-testid="rule-sandbox-parse-error" style={{ color: '#dc2626', fontSize: 12, marginTop: 6 }}>
              JSON parse error: {parseErr}
            </p>
          )}
          <div style={{ marginTop: 10, display: 'flex', gap: 8 }}>
            <button className="btn btn--primary" data-testid="rule-sandbox-run"
                    onClick={run} disabled={!parsed || running}>
              <Sparkles size={14} style={{ marginRight: 4, verticalAlign: 'middle' }} />
              {running ? 'Running…' : 'Run dry-run'}
            </button>
            <button className="btn btn--ghost" data-testid="rule-sandbox-clear"
                    onClick={() => { setJson('{}'); setResult(null); }}>
              <RefreshCw size={14} style={{ marginRight: 4, verticalAlign: 'middle' }} /> Clear
            </button>
          </div>
        </div>

        {/* Result */}
        <div data-testid="rule-sandbox-result"
             style={{ minHeight: 360, padding: 14, background: statusBg, border: `1px solid ${statusBorder}`, borderRadius: 8 }}>
          {!result && (
            <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', height: '100%', color: '#94a3b8' }}>
              <FlaskConical size={36} />
              <p style={{ marginTop: 12, fontSize: 13 }}>Paste or load an event payload, then click "Run dry-run".</p>
            </div>
          )}
          {result && (
            <>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
                <StatusIcon size={16} color={status === 'preview' ? '#059669' : '#b91c1c'} />
                <strong style={{ fontSize: 13 }} data-testid="rule-sandbox-status">{statusLabel}</strong>
              </div>

              {(status === 'failed' || status === 'ignored' || status === 'error') && (
                <p data-testid="rule-sandbox-error" style={{ fontSize: 13, color: '#7f1d1d', marginTop: 0 }}>
                  {result.error}
                </p>
              )}

              {status === 'preview' && result.je && (
                <div data-testid="rule-sandbox-preview">
                  <div style={{ fontSize: 12, color: '#475569', marginBottom: 8 }}>
                    Matched rule <strong>#{result.rule_id} {result.rule_name && `· ${result.rule_name}`}</strong>
                    {' · template '}<strong>#{result.template_id}</strong>
                  </div>
                  <table data-testid="rule-sandbox-lines"
                         style={{ width: '100%', fontSize: 12, borderCollapse: 'collapse', background: '#fff', borderRadius: 6, overflow: 'hidden' }}>
                    <thead style={{ background: '#f1f5f9', textAlign: 'left' }}>
                      <tr>
                        <th style={th}>Account</th>
                        <th style={{ ...th, textAlign: 'right' }}>Debit</th>
                        <th style={{ ...th, textAlign: 'right' }}>Credit</th>
                        <th style={th}>Memo</th>
                      </tr>
                    </thead>
                    <tbody>
                      {(result.je.lines || []).map((l, i) => (
                        <tr key={i} data-testid={`rule-sandbox-line-${i}`} style={{ borderTop: '1px solid #e2e8f0' }}>
                          <td style={td}>#{l.account_id}</td>
                          <td style={{ ...td, textAlign: 'right', fontFamily: 'ui-monospace, monospace' }}>
                            {l.debit > 0 ? l.debit.toFixed(2) : ''}
                          </td>
                          <td style={{ ...td, textAlign: 'right', fontFamily: 'ui-monospace, monospace' }}>
                            {l.credit > 0 ? l.credit.toFixed(2) : ''}
                          </td>
                          <td style={td}>{l.description || '—'}</td>
                        </tr>
                      ))}
                    </tbody>
                    <tfoot>
                      <tr style={{ borderTop: '2px solid #cbd5e1', background: '#f8fafc', fontWeight: 600 }}>
                        <td style={td}>Totals</td>
                        <td data-testid="rule-sandbox-total-debit" style={{ ...td, textAlign: 'right', fontFamily: 'ui-monospace, monospace' }}>
                          {(result.je.lines || []).reduce((s, l) => s + (l.debit || 0), 0).toFixed(2)}
                        </td>
                        <td data-testid="rule-sandbox-total-credit" style={{ ...td, textAlign: 'right', fontFamily: 'ui-monospace, monospace' }}>
                          {(result.je.lines || []).reduce((s, l) => s + (l.credit || 0), 0).toFixed(2)}
                        </td>
                        <td style={td}></td>
                      </tr>
                    </tfoot>
                  </table>
                  {result.je.memo && (
                    <p style={{ fontSize: 12, color: '#64748b', marginTop: 8 }}>
                      <strong>JE memo:</strong> {result.je.memo}
                    </p>
                  )}
                </div>
              )}

              <details style={{ marginTop: 16, fontSize: 12 }}>
                <summary style={{ cursor: 'pointer', color: '#64748b' }}>Raw response</summary>
                <pre data-testid="rule-sandbox-raw"
                     style={{ marginTop: 8, padding: 10, background: '#0f172a', color: '#e2e8f0', borderRadius: 6, overflow: 'auto', maxHeight: 220 }}>
{JSON.stringify(result, null, 2)}
                </pre>
              </details>
            </>
          )}
        </div>
      </div>
    </div>
  );
}

const th = { padding: '8px 10px', fontWeight: 600, color: '#334155' };
const td = { padding: '6px 10px', color: '#0f172a' };
