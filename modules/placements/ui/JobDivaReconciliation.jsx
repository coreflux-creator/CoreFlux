import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  AlertTriangle,
  CheckCircle2,
  ChevronDown,
  ChevronRight,
  Database,
  FileUp,
  Play,
  RefreshCw,
  ShieldCheck,
  XCircle,
} from 'lucide-react';
import { api } from '../../../dashboard/src/lib/api';

const ENDPOINT = '/modules/placements/api/jobdiva_reconciliation.php';
const outcomeTone = {
  create: { bg: '#eff6ff', fg: '#1d4ed8', label: 'Create' },
  update: { bg: '#fff7ed', fg: '#c2410c', label: 'Update' },
  restore: { bg: '#f5f3ff', fg: '#6d28d9', label: 'Restore' },
  unchanged: { bg: '#f0fdf4', fg: '#15803d', label: 'Unchanged' },
  blocked: { bg: '#fef2f2', fg: '#b91c1c', label: 'Blocked' },
};

export default function JobDivaReconciliation() {
  const fileRef = useRef(null);
  const [storedPreview, setStoredPreview] = useState(null);
  const [storedSelected, setStoredSelected] = useState(new Set());
  const [storedExpanded, setStoredExpanded] = useState(new Set());
  const [storedBusy, setStoredBusy] = useState('');
  const [storedError, setStoredError] = useState('');
  const [storedResult, setStoredResult] = useState(null);
  const [csv, setCsv] = useState('');
  const [fileName, setFileName] = useState('');
  const [inspect, setInspect] = useState(null);
  const [columnMap, setColumnMap] = useState({});
  const [preview, setPreview] = useState(null);
  const [selected, setSelected] = useState(new Set());
  const [expanded, setExpanded] = useState(new Set());
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [result, setResult] = useState(null);

  const refreshStored = useCallback(async () => {
    setStoredBusy('preview');
    setStoredError('');
    try {
      const data = await api.post(`${ENDPOINT}?action=stored_preview`, {});
      setStoredPreview(data);
      setStoredSelected(new Set());
      setStoredExpanded(new Set());
    } catch (e) {
      setStoredError(e.message || 'Could not read the stored JobDiva assignments.');
    } finally {
      setStoredBusy('');
    }
  }, []);

  useEffect(() => {
    refreshStored();
  }, [refreshStored]);

  const toggleStoredSelected = (startId) => {
    setStoredSelected(current => {
      const next = new Set(current);
      if (next.has(startId)) next.delete(startId);
      else next.add(startId);
      return next;
    });
  };

  const selectStoredOutcome = (outcome) => {
    setStoredSelected(current => {
      const next = new Set(current);
      (storedPreview?.rows || [])
        .filter(row => row.selectable && (!outcome || row.outcome === outcome))
        .forEach(row => next.add(row.start_id));
      return next;
    });
  };

  const toggleStoredExpanded = (startId) => {
    setStoredExpanded(current => {
      const next = new Set(current);
      if (next.has(startId)) next.delete(startId);
      else next.add(startId);
      return next;
    });
  };

  const applyStored = async () => {
    if (!storedPreview || storedSelected.size === 0) return;
    const ok = window.confirm(
      `Project ${storedSelected.size} verified JobDiva assignment${storedSelected.size === 1 ? '' : 's'} into CoreFlux?\n\n` +
      'Each selected Start ID will update one canonical placement and its joined people, job, client, rate, vendor, commission, referral, and economic records. No placement will be deleted or archived.',
    );
    if (!ok) return;
    setStoredBusy('apply');
    setStoredError('');
    try {
      const data = await api.post(`${ENDPOINT}?action=stored_apply`, {
        dry_run_token: storedPreview.dry_run_token,
        selected_start_ids: Array.from(storedSelected),
        confirm: 'APPLY_STORED_JOBDIVA_ASSIGNMENTS',
      });
      setStoredResult(data);
      await refreshStored();
    } catch (e) {
      setStoredError(e.message || 'Stored assignment projection failed. No selected rows were written.');
    } finally {
      setStoredBusy('');
    }
  };

  const usedTargets = useMemo(
    () => new Set(Object.values(columnMap).filter(Boolean)),
    [columnMap],
  );
  const mappedStartId = Object.values(columnMap).filter(v => v === 'start_id').length === 1;

  const readFile = async (file) => {
    if (!file) return;
    setBusy('inspect');
    setError('');
    setPreview(null);
    setResult(null);
    try {
      const text = await file.text();
      const data = await api.post(`${ENDPOINT}?action=inspect`, { csv: text });
      const nextMap = {};
      (data.headers || []).forEach((_, index) => {
        nextMap[index] = data.auto_map?.[index] || data.auto_map?.[String(index)] || '';
      });
      setCsv(text);
      setFileName(file.name);
      setInspect(data);
      setColumnMap(nextMap);
      setSelected(new Set());
      setExpanded(new Set());
    } catch (e) {
      setError(e.message || 'Could not inspect the CSV.');
    } finally {
      setBusy('');
    }
  };

  const changeMapping = (index, field) => {
    setColumnMap(current => ({ ...current, [index]: field }));
    setPreview(null);
    setSelected(new Set());
    setResult(null);
  };

  const runPreview = async () => {
    if (!csv || !mappedStartId) return;
    setBusy('preview');
    setError('');
    setResult(null);
    try {
      const data = await api.post(`${ENDPOINT}?action=dry_run`, {
        csv,
        column_map: columnMap,
      });
      setPreview(data);
      setSelected(new Set());
      setExpanded(new Set());
    } catch (e) {
      setError(e.message || 'Dry-run failed.');
    } finally {
      setBusy('');
    }
  };

  const toggleSelected = (startId) => {
    setSelected(current => {
      const next = new Set(current);
      if (next.has(startId)) next.delete(startId);
      else next.add(startId);
      return next;
    });
  };

  const selectOutcome = (outcome) => {
    setSelected(current => {
      const next = new Set(current);
      (preview?.rows || [])
        .filter(row => row.selectable && (!outcome || row.outcome === outcome))
        .forEach(row => next.add(row.start_id));
      return next;
    });
  };

  const applySelected = async () => {
    if (!preview || selected.size === 0) return;
    const ok = window.confirm(
      `Apply the previewed changes for ${selected.size} exact JobDiva Start ID${selected.size === 1 ? '' : 's'}?\n\n` +
      'This will create or update only the selected rows. It will not delete or archive placements, and approved rates remain locked.',
    );
    if (!ok) return;
    setBusy('apply');
    setError('');
    try {
      const data = await api.post(`${ENDPOINT}?action=apply`, {
        csv,
        column_map: columnMap,
        dry_run_token: preview.dry_run_token,
        selected_start_ids: Array.from(selected),
        confirm: 'APPLY_EXACT_START_ID_RECONCILIATION',
      });
      setResult(data);
      setSelected(new Set());
      const refreshed = await api.post(`${ENDPOINT}?action=dry_run`, {
        csv,
        column_map: columnMap,
      });
      setPreview(refreshed);
    } catch (e) {
      setError(e.message || 'Apply failed. No changes were made.');
    } finally {
      setBusy('');
    }
  };

  const toggleExpanded = (rowNumber) => {
    setExpanded(current => {
      const next = new Set(current);
      if (next.has(rowNumber)) next.delete(rowNumber);
      else next.add(rowNumber);
      return next;
    });
  };

  return (
    <section data-testid="jobdiva-reconciliation" style={{ padding: 'var(--cf-space-4, 1rem)', maxWidth: 1500 }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', gap: 16, alignItems: 'flex-start', marginBottom: 18 }}>
        <div>
          <Link to="../list" className="btn btn--ghost" style={{ marginBottom: 10 }}>&larr; Placements</Link>
          <h2 style={{ margin: 0 }}>JobDiva placement reconciliation</h2>
          <p style={{ margin: '5px 0 0', color: '#64748b', fontSize: 13 }}>
            Project verified JobDiva assignments and their related source records into the canonical CoreFlux graphs.
          </p>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 7, color: '#166534', fontSize: 12, fontWeight: 600 }}>
          <ShieldCheck size={17} aria-hidden="true" />
          No fuzzy matching / No delete or archive
        </div>
      </header>

      {storedError && <div className="error" data-testid="jobdiva-stored-reconciliation-error" style={{ marginBottom: 12 }}>{storedError}</div>}
      {storedResult && (
        <div data-testid="jobdiva-stored-reconciliation-result" style={{
          border: '1px solid #86efac', background: '#f0fdf4', color: '#166534',
          borderRadius: 6, padding: '10px 12px', marginBottom: 12, fontSize: 13,
        }}>
          <strong>Canonical projection complete.</strong>{' '}
          {storedResult.created || 0} created, {storedResult.updated || 0} updated, {storedResult.restored || 0} restored, {storedResult.mapping_writes || 0} identity bindings,
          and {storedResult.field_map_writes} tenant field-map writes. No placements were deleted or archived.
        </div>
      )}

      <section aria-labelledby="jd-stored-heading" style={{ borderTop: '1px solid #e2e8f0', padding: '16px 0' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12, flexWrap: 'wrap' }}>
          <div>
            <h3 id="jd-stored-heading" style={{ margin: 0, fontSize: 16 }}>Stored JobDiva assignments</h3>
            <p style={{ margin: '4px 0 0', color: '#64748b', fontSize: 12, maxWidth: 850 }}>
              This is the authoritative Start/Assignment set already pulled from JobDiva. Each row joins its Job,
              Candidate, Contact, Company, rate, and economic evidence before the canonical projector writes CoreFlux.
            </p>
          </div>
          <button
            type="button"
            className="btn"
            onClick={refreshStored}
            disabled={Boolean(storedBusy)}
            data-testid="jobdiva-stored-reconciliation-refresh"
          >
            <RefreshCw size={15} aria-hidden="true" />
            <span style={{ marginLeft: 6 }}>{storedBusy === 'preview' ? 'Reading source graph...' : 'Refresh source preview'}</span>
          </button>
        </div>

        {storedPreview && (
          <>
            <div style={{
              display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))',
              border: '1px solid #dbe3ee', borderRadius: 6, margin: '12px 0', overflow: 'hidden',
            }}>
              {[
                ['assignments', 'Assignments', '#0f172a', '#f8fafc'],
                ['create', 'Create', '#1d4ed8', '#eff6ff'],
                ['restore', 'Restore exact match', '#6d28d9', '#f5f3ff'],
                ['update', 'Reproject', '#c2410c', '#fff7ed'],
                ['fully_joined', 'Job + candidate joined', '#047857', '#ecfdf5'],
                ['with_rates', 'Bill + pay evidence', '#166534', '#f0fdf4'],
                ['contract_complete', 'Complete contracts', '#166534', '#f0fdf4'],
                ['blocked', 'Blocked', '#b91c1c', '#fef2f2'],
              ].map(([key, label, fg, bg], index) => (
                <div key={key} style={{
                  padding: '10px 12px',
                  borderLeft: index ? '1px solid #dbe3ee' : 0,
                  background: bg,
                }}>
                  <div style={{ fontSize: 11, color: fg, textTransform: 'uppercase', fontWeight: 700 }}>{label}</div>
                  <div style={{ fontSize: 21, fontWeight: 700, color: fg }}>{storedPreview.summary?.[key] || 0}</div>
                </div>
              ))}
            </div>

            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 10 }}>
              <button type="button" className="btn btn--ghost" onClick={() => selectStoredOutcome('update')}>Select reprojections</button>
              <button type="button" className="btn btn--ghost" onClick={() => selectStoredOutcome('restore')}>Select restores</button>
              <button type="button" className="btn btn--ghost" onClick={() => selectStoredOutcome('create')}>Select creates</button>
              <button type="button" className="btn btn--ghost" onClick={() => setStoredSelected(new Set())}>Clear</button>
            </div>

            <div style={{ overflowX: 'auto', border: '1px solid #dbe3ee', borderRadius: 6 }}>
              <table className="table" data-testid="jobdiva-stored-reconciliation-table" style={{ width: '100%', margin: 0 }}>
                <thead>
                  <tr>
                    <th style={{ width: 36 }} />
                    <th>Action</th>
                    <th>Start ID</th>
                    <th>Assignment</th>
                    <th>Joined source graph</th>
                    <th>Rate evidence</th>
                    <th>Issues</th>
                  </tr>
                </thead>
                <tbody>
                  {(storedPreview.rows || []).map(row => {
                    const isOpen = storedExpanded.has(row.start_id);
                    const tone = outcomeTone[row.outcome] || outcomeTone.blocked;
                    const joined = [
                      row.joins?.assignments_joined ? 'Assignment' : null,
                      row.joins?.jobs_joined ? 'Job' : null,
                      row.joins?.candidates_joined ? 'Candidate' : null,
                      row.joins?.contacts_joined ? 'Contact' : null,
                      row.joins?.companies_joined ? 'Company' : null,
                    ].filter(Boolean);
                    return (
                      <React.Fragment key={row.start_id}>
                        <tr>
                          <td>
                            {row.selectable ? (
                              <input
                                type="checkbox"
                                checked={storedSelected.has(row.start_id)}
                                onChange={() => toggleStoredSelected(row.start_id)}
                                aria-label={`Select stored Start ID ${row.start_id}`}
                              />
                            ) : null}
                          </td>
                          <td>
                            <span style={{
                              display: 'inline-block', padding: '2px 7px', borderRadius: 10,
                              background: tone.bg, color: tone.fg, fontSize: 11, fontWeight: 700,
                            }}>{tone.label === 'Update' ? 'Reproject' : tone.label}</span>
                          </td>
                          <td><code>{row.start_id}</code></td>
                          <td>
                            <div style={{ fontWeight: 600 }}>{row.source?.title || row.placement_title || `JobDiva Start ${row.start_id}`}</div>
                            <small style={{ color: '#64748b' }}>
                              {row.source?.candidate_name || row.source?.candidate_email || `Candidate ${row.source?.candidate_id || 'unresolved'}`}
                              {' / '}{row.source?.end_client_name || 'client unresolved'}
                            </small>
                            {row.placement_id ? (
                              <div><Link to={`../${row.placement_id}/overview`}>Current PL-{row.placement_id}</Link></div>
                            ) : null}
                          </td>
                          <td>
                            <div>{joined.length ? joined.join(' + ') : 'Assignment only'}</div>
                            <small style={{ color: '#64748b' }}>
                              Job {row.source?.job_id || '-'} / Candidate {row.source?.candidate_id || '-'} / Company {row.source?.company_id || '-'}
                            </small>
                          </td>
                          <td>
                            <div>
                              Bill {displayMoney(row.source?.bill_rate)} / Pay {displayMoney(row.source?.pay_rate)}
                            </div>
                            <small style={{ color: '#64748b' }}>
                              Invoice {displayMoney(row.contract?.economics?.invoice_rate)} / Margin {displayMoney(row.contract?.economics?.gross_margin)}
                              {' / '}
                              {row.economics?.vendor || 'no vendor evidence'}
                              {row.economics?.paid_when_paid ? ' / paid when paid' : ''}
                            </small>
                          </td>
                          <td>
                            <button type="button" className="btn btn--ghost" onClick={() => toggleStoredExpanded(row.start_id)}>
                              {isOpen ? <ChevronDown size={14} aria-hidden="true" /> : <ChevronRight size={14} aria-hidden="true" />}
                              <span style={{ marginLeft: 4 }}>
                                {row.errors?.length
                                  ? `${row.errors.length} blocked`
                                  : row.warnings?.length
                                    ? `${row.warnings.length} warning${row.warnings.length === 1 ? '' : 's'}`
                                    : 'Verified'}
                              </span>
                            </button>
                          </td>
                        </tr>
                        {isOpen && (
                          <tr>
                            <td colSpan={7} style={{ background: '#f8fafc', padding: '10px 14px' }}>
                              <StoredAssignmentDetails row={row} />
                            </td>
                          </tr>
                        )}
                      </React.Fragment>
                    );
                  })}
                </tbody>
              </table>
            </div>

            <div style={{
              display: 'flex', justifyContent: 'space-between', alignItems: 'center',
              gap: 12, marginTop: 12, paddingTop: 12, borderTop: '1px solid #e2e8f0',
            }}>
              <span style={{ color: '#475569', fontSize: 13 }}>
                {storedSelected.size} exact Start ID{storedSelected.size === 1 ? '' : 's'} selected
              </span>
              <button
                type="button"
                className="btn btn--primary"
                disabled={storedSelected.size === 0 || Boolean(storedBusy)}
                onClick={applyStored}
                data-testid="jobdiva-stored-reconciliation-apply"
              >
                <Database size={15} aria-hidden="true" />
                <span style={{ marginLeft: 6 }}>{storedBusy === 'apply' ? 'Projecting canonical graph...' : 'Project selected assignments'}</span>
              </button>
            </div>
          </>
        )}
      </section>

      {error && <div className="error" data-testid="jobdiva-reconciliation-error" style={{ marginBottom: 12 }}>{error}</div>}
      {result && (
        <div data-testid="jobdiva-reconciliation-result" style={{
          border: '1px solid #86efac', background: '#f0fdf4', color: '#166534',
          borderRadius: 6, padding: '10px 12px', marginBottom: 12, fontSize: 13,
        }}>
          <strong>Applied successfully.</strong>{' '}
          {result.created} created, {result.updated} updated, {result.rate_drafts} rate drafts, {result.mapping_writes} identity bindings.
          No records were deleted or archived.
        </div>
      )}

      <section aria-labelledby="jd-upload-heading" style={{ borderTop: '1px solid #e2e8f0', padding: '16px 0' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
          <div>
            <h3 id="jd-upload-heading" style={{ margin: 0, fontSize: 16 }}>CSV fallback</h3>
            <p style={{ margin: '4px 0 0', color: '#64748b', fontSize: 12 }}>
              Use only when an assignment is not present in the stored JobDiva source graph. Exact Start ID remains required.
            </p>
          </div>
          <input
            ref={fileRef}
            type="file"
            accept=".csv,text/csv"
            hidden
            onChange={e => readFile(e.target.files?.[0])}
            data-testid="jobdiva-reconciliation-file"
          />
          <button
            type="button"
            className="btn"
            onClick={() => fileRef.current?.click()}
            disabled={Boolean(busy)}
            data-testid="jobdiva-reconciliation-choose-file"
          >
            <FileUp size={15} aria-hidden="true" style={{ marginRight: 6 }} />
            {busy === 'inspect' ? 'Reading...' : fileName || 'Choose CSV'}
          </button>
        </div>
      </section>

      {inspect && (
        <section aria-labelledby="jd-map-heading" style={{ borderTop: '1px solid #e2e8f0', padding: '16px 0' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, alignItems: 'flex-end', marginBottom: 10 }}>
            <div>
              <h3 id="jd-map-heading" style={{ margin: 0, fontSize: 16 }}>2. Confirm column mapping</h3>
              <p style={{ margin: '4px 0 0', color: '#64748b', fontSize: 12 }}>
                One source column must map to Start ID. Unmapped columns are ignored.
              </p>
            </div>
            <span style={{ fontSize: 12, color: mappedStartId ? '#166534' : '#b91c1c', fontWeight: 600 }}>
              {mappedStartId ? 'Start ID mapped' : 'Start ID mapping required'}
            </span>
          </div>

          <div style={{ overflowX: 'auto', border: '1px solid #dbe3ee', borderRadius: 6 }}>
            <table className="table" data-testid="jobdiva-reconciliation-column-map" style={{ width: '100%', margin: 0 }}>
              <thead>
                <tr>
                  <th>JobDiva CSV column</th>
                  <th>CoreFlux destination</th>
                  <th>Requirement</th>
                </tr>
              </thead>
              <tbody>
                {(inspect.headers || []).map((header, index) => {
                  const selectedField = columnMap[index] || '';
                  return (
                    <tr key={`${header}-${index}`}>
                      <td><code>{header || `(column ${index + 1})`}</code></td>
                      <td>
                        <select
                          className="input"
                          value={selectedField}
                          onChange={e => changeMapping(index, e.target.value)}
                          data-testid={`jobdiva-reconciliation-map-${index}`}
                          style={{ minWidth: 260 }}
                        >
                          <option value="">Ignore this column</option>
                          {(inspect.fields || []).map(field => (
                            <option
                              key={field.key}
                              value={field.key}
                              disabled={usedTargets.has(field.key) && selectedField !== field.key}
                            >
                              {field.label}{field.required ? ' (required)' : ''}
                            </option>
                          ))}
                        </select>
                      </td>
                      <td style={{ color: selectedField === 'start_id' ? '#166534' : '#64748b', fontSize: 12 }}>
                        {selectedField === 'start_id' ? 'Exact identity key' : 'Optional enrichment'}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 12 }}>
            <button
              type="button"
              className="btn btn--primary"
              disabled={!mappedStartId || Boolean(busy)}
              onClick={runPreview}
              data-testid="jobdiva-reconciliation-preview"
            >
              {busy === 'preview' ? <RefreshCw size={15} aria-hidden="true" /> : <Play size={15} aria-hidden="true" />}
              <span style={{ marginLeft: 6 }}>{busy === 'preview' ? 'Building preview...' : 'Run dry-run'}</span>
            </button>
          </div>
        </section>
      )}

      {preview && (
        <section aria-labelledby="jd-preview-heading" style={{ borderTop: '1px solid #e2e8f0', padding: '16px 0' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12, flexWrap: 'wrap' }}>
            <div>
              <h3 id="jd-preview-heading" style={{ margin: 0, fontSize: 16 }}>3. Review and select changes</h3>
              <p style={{ margin: '4px 0 0', color: '#64748b', fontSize: 12 }}>
                Creates are never inferred from titles or people. Updates apply only to the exact Start ID shown.
              </p>
            </div>
            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
              <button type="button" className="btn btn--ghost" onClick={() => selectOutcome('update')}>Select updates</button>
              <button type="button" className="btn btn--ghost" onClick={() => selectOutcome('create')}>Select creates</button>
              <button type="button" className="btn btn--ghost" onClick={() => setSelected(new Set())}>Clear</button>
            </div>
          </div>

          <div style={{
            display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))',
            border: '1px solid #dbe3ee', borderRadius: 6, margin: '12px 0', overflow: 'hidden',
          }}>
            {['create', 'update', 'unchanged', 'blocked'].map((key, index) => (
              <div key={key} style={{
                padding: '10px 12px',
                borderLeft: index ? '1px solid #dbe3ee' : 0,
                background: outcomeTone[key].bg,
              }}>
                <div style={{ fontSize: 11, color: outcomeTone[key].fg, textTransform: 'uppercase', fontWeight: 700 }}>{outcomeTone[key].label}</div>
                <div style={{ fontSize: 21, fontWeight: 700, color: outcomeTone[key].fg }}>{preview.summary?.[key] || 0}</div>
              </div>
            ))}
          </div>

          <div style={{ overflowX: 'auto', border: '1px solid #dbe3ee', borderRadius: 6 }}>
            <table className="table" data-testid="jobdiva-reconciliation-preview-table" style={{ width: '100%', margin: 0 }}>
              <thead>
                <tr>
                  <th style={{ width: 36 }} />
                  <th>Outcome</th>
                  <th>Start ID</th>
                  <th>CoreFlux placement</th>
                  <th>Person resolution</th>
                  <th>Changes</th>
                  <th>Issues</th>
                </tr>
              </thead>
              <tbody>
                {(preview.rows || []).map(row => {
                  const isOpen = expanded.has(row.row_number);
                  const rowTone = outcomeTone[row.outcome] || outcomeTone.blocked;
                  return (
                    <React.Fragment key={`${row.row_number}-${row.start_id}`}>
                      <tr>
                        <td>
                          {row.selectable ? (
                            <input
                              type="checkbox"
                              checked={selected.has(row.start_id)}
                              onChange={() => toggleSelected(row.start_id)}
                              aria-label={`Select Start ID ${row.start_id}`}
                            />
                          ) : null}
                        </td>
                        <td>
                          <span style={{
                            display: 'inline-block', padding: '2px 7px', borderRadius: 10,
                            background: rowTone.bg, color: rowTone.fg, fontSize: 11, fontWeight: 700,
                          }}>{rowTone.label}</span>
                        </td>
                        <td><code>{row.start_id || '-'}</code></td>
                        <td>
                          {row.placement_id
                            ? <Link to={`../${row.placement_id}/overview`}>PL-{row.placement_id} / {row.placement_title || 'Untitled'}</Link>
                            : <span>{row.placement_title || 'Missing placement'}</span>}
                        </td>
                        <td>
                          <div>{row.person?.label || row.person?.email || 'Unresolved'}</div>
                          <small style={{ color: '#64748b' }}>{row.person?.matched_by || 'no exact person match'}</small>
                        </td>
                        <td>
                          <button type="button" className="btn btn--ghost" onClick={() => toggleExpanded(row.row_number)}>
                            {isOpen ? <ChevronDown size={14} aria-hidden="true" /> : <ChevronRight size={14} aria-hidden="true" />}
                            <span style={{ marginLeft: 4 }}>{row.changes?.length || 0} proposed</span>
                          </button>
                        </td>
                        <td>
                          {row.errors?.length ? (
                            <span style={{ color: '#b91c1c', display: 'inline-flex', gap: 4, alignItems: 'center' }}>
                              <XCircle size={14} aria-hidden="true" /> {row.errors.length} blocked
                            </span>
                          ) : row.warnings?.length || row.protected_changes?.length ? (
                            <span style={{ color: '#b45309', display: 'inline-flex', gap: 4, alignItems: 'center' }}>
                              <AlertTriangle size={14} aria-hidden="true" /> {(row.warnings?.length || 0) + (row.protected_changes?.length || 0)}
                            </span>
                          ) : (
                            <span style={{ color: '#15803d', display: 'inline-flex', gap: 4, alignItems: 'center' }}>
                              <CheckCircle2 size={14} aria-hidden="true" /> Clear
                            </span>
                          )}
                        </td>
                      </tr>
                      {isOpen && (
                        <tr>
                          <td colSpan={7} style={{ background: '#f8fafc', padding: '10px 14px' }}>
                            <DiffDetails row={row} />
                          </td>
                        </tr>
                      )}
                    </React.Fragment>
                  );
                })}
              </tbody>
            </table>
          </div>

          <div style={{
            display: 'flex', justifyContent: 'space-between', alignItems: 'center',
            gap: 12, marginTop: 12, paddingTop: 12, borderTop: '1px solid #e2e8f0',
          }}>
            <span style={{ color: '#475569', fontSize: 13 }}>{selected.size} selected for apply</span>
            <button
              type="button"
              className="btn btn--primary"
              disabled={selected.size === 0 || Boolean(busy)}
              onClick={applySelected}
              data-testid="jobdiva-reconciliation-apply"
            >
              <ShieldCheck size={15} aria-hidden="true" />
              <span style={{ marginLeft: 6 }}>{busy === 'apply' ? 'Applying verified plan...' : 'Apply selected changes'}</span>
            </button>
          </div>
        </section>
      )}
    </section>
  );
}

function StoredAssignmentDetails({ row }) {
  const contract = row.contract || {};
  const sourcePairs = [
    ['Candidate ID', row.source?.candidate_id],
    ['Candidate', row.source?.candidate_name],
    ['Candidate email', row.source?.candidate_email],
    ['Job ID', row.source?.job_id],
    ['Title', row.source?.title],
    ['Contact ID', row.source?.contact_id],
    ['Company ID', row.source?.company_id],
    ['End client', row.source?.end_client_name],
    ['Classification', row.source?.engagement_type],
    ['Start', row.source?.start_date],
    ['End', row.source?.end_date],
  ];
  const economicsPairs = [
    ['Bill rate', displayMoney(row.source?.bill_rate)],
    ['Pay rate', displayMoney(row.source?.pay_rate)],
    ['Vendor / contractor company', row.economics?.vendor],
    ['Referrer', row.economics?.referrer],
    ['Commission evidence', row.economics?.commission],
    ['Vendor terms', row.economics?.vendor_payment_terms],
    ['Client terms', row.economics?.client_payment_terms],
    ['Paid when paid', row.economics?.paid_when_paid === null ? null : row.economics?.paid_when_paid ? 'Yes' : 'No'],
  ];
  const currentGraphPairs = [
    ['Placement', row.current?.id ? `PL-${row.current.id}` : null],
    ['Current status', row.current?.status],
    ['Current classification', row.current?.engagement_type],
    ['Rate rows', row.current_graph?.rates?.length ?? 0],
    ['Client/vendor chain rows', row.current_graph?.chain?.length ?? 0],
    ['Corp/vendor records', row.current_graph?.corp?.length ?? 0],
    ['Commission rows', row.current_graph?.commissions?.length ?? 0],
    ['Referral rows', row.current_graph?.referrals?.length ?? 0],
    ['Economic parties', row.current_graph?.economic_parties?.length ?? 0],
    ['Time entries', row.current_graph?.time_summary?.row_count ?? 0],
    ['Downstream AR/AP/payroll bundles', row.current_graph?.downstream_summary?.row_count ?? 0],
  ];
  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 18 }}>
      <KeyValueList title="Assignment projection" items={sourcePairs} />
      <KeyValueList title="Economic projection evidence" items={economicsPairs} />
      <KeyValueList title="Current CoreFlux graph" items={currentGraphPairs} />
      <div style={{ gridColumn: '1 / -1' }}>
        <strong style={{ fontSize: 12 }}>Canonical contract proposal</strong>
        <table style={{ width: '100%', marginTop: 6, fontSize: 12, borderCollapse: 'collapse' }} data-testid={`jobdiva-contract-fields-${row.start_id}`}>
          <thead><tr><th style={{ textAlign: 'left' }}>CoreFlux field</th><th style={{ textAlign: 'left' }}>Current</th><th style={{ textAlign: 'left' }}>Proposed</th><th style={{ textAlign: 'left' }}>JobDiva authority</th></tr></thead>
          <tbody>{(contract.fields || []).map((field) => <tr key={`${field.group}-${field.field}`} style={{ background: field.changes ? '#fff7ed' : 'transparent' }}>
            <td style={{ padding: 5 }}><span style={{ color: '#64748b' }}>{field.group}</span><br /><strong>{field.label}</strong></td>
            <td style={{ padding: 5 }}>{displayValue(field.current)}</td>
            <td style={{ padding: 5 }}>{displayValue(field.proposed)}</td>
            <td style={{ padding: 5 }}><code>{field.source}</code><br /><span style={{ color: '#64748b' }}>{field.authority?.replace(/_/g, ' ')}</span></td>
          </tr>)}</tbody>
        </table>
      </div>
      <div style={{ gridColumn: '1 / -1', display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 18 }}>
        <div>
          <strong style={{ fontSize: 12 }}>Contract checks</strong>
          <table style={{ width: '100%', marginTop: 6, fontSize: 12, borderCollapse: 'collapse' }}>
            <tbody>{(contract.checks || []).map((check) => <tr key={check.code}>
              <td style={{ padding: 4, color: check.status === 'pass' ? '#166534' : check.status === 'warning' ? '#b45309' : '#b91c1c', fontWeight: 700 }}>{check.status === 'pass' ? 'Pass' : check.status === 'warning' ? 'Review' : 'Blocked'}</td>
              <td style={{ padding: 4 }}><strong>{check.label}</strong><br /><span style={{ color: '#64748b' }}>{check.detail}</span></td>
            </tr>)}</tbody>
          </table>
        </div>
        <div>
          <strong style={{ fontSize: 12 }}>Settlement participants</strong>
          <table style={{ width: '100%', marginTop: 6, fontSize: 12, borderCollapse: 'collapse' }}>
            <tbody>{(contract.participants || []).map((party, index) => <tr key={`${party.role}-${party.external_id || index}`}>
              <td style={{ padding: 4 }}><strong>{party.name || party.external_id || 'Unresolved'}</strong><br /><span style={{ color: '#64748b' }}>{party.role?.replace(/_/g, ' ')}</span></td>
              <td style={{ padding: 4 }}>{party.settlement_channel?.toUpperCase()} / {party.calculation?.replace(/_/g, ' ')}</td>
              <td style={{ padding: 4 }}>{party.cadence || '-'} / {party.payment_terms || '-'}</td>
            </tr>)}</tbody>
          </table>
          {(contract.attributions || []).length > 0 && <><strong style={{ display: 'block', fontSize: 12, marginTop: 12 }}>Attribution only</strong>
            <ul style={{ margin: '5px 0 0', paddingLeft: 18, fontSize: 12 }}>{contract.attributions.map((owner) => <li key={`${owner.role}-${owner.name_or_id}`}>{owner.role.replace(/^source_/, '').replace(/_/g, ' ')}: {owner.name_or_id}{owner.allocation_pct ? ` (${(Number(owner.allocation_pct) * 100).toFixed(2)}% source allocation)` : ''}. No payment created.</li>)}</ul></>}
        </div>
      </div>
      <div>
        {row.errors?.length ? <MessageList title="Blocked" items={row.errors} color="#b91c1c" /> : null}
        {row.warnings?.length ? <MessageList title="Missing source facets" items={row.warnings} color="#b45309" /> : null}
        {!row.errors?.length && !row.warnings?.length ? (
          <p style={{ margin: 0, color: '#166534', fontSize: 12 }}>
            Exact assignment identity and referenced source facets are ready for canonical projection.
          </p>
        ) : null}
      </div>
    </div>
  );
}

function KeyValueList({ title, items }) {
  return (
    <div>
      <strong style={{ fontSize: 12 }}>{title}</strong>
      <dl style={{ display: 'grid', gridTemplateColumns: 'minmax(120px, 0.8fr) minmax(140px, 1.2fr)', gap: '5px 10px', margin: '7px 0 0', fontSize: 12 }}>
        {items.map(([label, value]) => (
          <React.Fragment key={label}>
            <dt style={{ color: '#64748b' }}>{label}</dt>
            <dd style={{ margin: 0, color: '#0f172a', overflowWrap: 'anywhere' }}>{displayValue(value)}</dd>
          </React.Fragment>
        ))}
      </dl>
    </div>
  );
}

function DiffDetails({ row }) {
  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 16 }}>
      <div>
        <strong style={{ fontSize: 12 }}>Proposed writes</strong>
        {row.changes?.length ? (
          <table style={{ width: '100%', marginTop: 6, fontSize: 12, borderCollapse: 'collapse' }}>
            <tbody>
              {row.changes.map((change, index) => (
                <tr key={`${change.field}-${index}`}>
                  <td style={{ padding: '4px 8px 4px 0', color: '#475569' }}>{change.group}</td>
                  <td style={{ padding: 4, fontWeight: 600 }}>{change.label}</td>
                  <td style={{ padding: 4, color: '#64748b' }}>{displayValue(change.from)}</td>
                  <td style={{ padding: 4 }}>-&gt;</td>
                  <td style={{ padding: 4, color: '#0f172a' }}>{displayValue(change.to)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : <p style={{ color: '#64748b', fontSize: 12 }}>No field writes proposed.</p>}
      </div>
      <div>
        {row.errors?.length ? <MessageList title="Blocked" items={row.errors} color="#b91c1c" /> : null}
        {row.warnings?.length ? <MessageList title="Warnings" items={row.warnings} color="#b45309" /> : null}
        {row.protected_changes?.length ? (
          <MessageList
            title="Protected CoreFlux overrides"
            color="#7c3aed"
            items={row.protected_changes.map(item => `${item.label}: source proposed ${displayValue(item.to)}; retained ${displayValue(item.from)}`)}
          />
        ) : null}
      </div>
    </div>
  );
}

function MessageList({ title, items, color }) {
  return (
    <div style={{ marginBottom: 10 }}>
      <strong style={{ fontSize: 12, color }}>{title}</strong>
      <ul style={{ margin: '4px 0 0', paddingLeft: 18, fontSize: 12, color }}>
        {items.map((item, index) => <li key={index}>{item}</li>)}
      </ul>
    </div>
  );
}

function displayValue(value) {
  if (value === null || value === undefined || value === '') return '-';
  return String(value);
}

function displayMoney(value) {
  if (value === null || value === undefined || value === '') return '-';
  const number = Number(value);
  return Number.isFinite(number) ? `USD ${number.toFixed(2)}` : String(value);
}
