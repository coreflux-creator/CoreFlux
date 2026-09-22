import React, { useState, useEffect } from 'react';
import { useNavigate, Link, useParams, useSearchParams } from 'react-router-dom';
import { api } from '../../../dashboard/src/lib/api';
import { useActiveEntity } from '../../../dashboard/src/lib/useActiveEntity';
import IntercompanySplitDialog from '../../../dashboard/src/components/IntercompanySplitDialog';
import PlacementPicker from '../../placements/ui/PlacementPicker';
import { ArrowLeft, Copy, Pencil, Plus, Save, Send, SlidersHorizontal, Trash2 } from 'lucide-react';

/**
 * Manual Journal Entry creator.
 *
 * POST /modules/accounting/api/journal_entries.php          → create + post immediately
 * POST /modules/accounting/api/journal_entries.php?action=draft → save as draft
 *
 * Validation:
 *   - posting_date required
 *   - at least 2 lines
 *   - sum(debit) === sum(credit), > 0
 *   - each line has account_code AND exactly one of debit/credit > 0
 */
const ASSIGNMENT_DIMENSION_KEYS = [
  'client', 'placement', 'worker', 'job', 'recruiter', 'account_manager',
  'branch', 'service_line', 'work_state', 'wc_class', 'department',
  'cost_center', 'vendor',
];

const newLine = () => ({
  account_code: '', debit: '', credit: '', description: '', dims: {},
  assignment_entity_id: null,
});

export default function JournalEntryCreate() {
  const navigate = useNavigate();
  const { activeEntityId, entities, loaded: entitiesLoaded } = useActiveEntity();
  const { id } = useParams();
  const [searchParams] = useSearchParams();
  const copyFrom = searchParams.get('copy_from');
  const replaceId = searchParams.get('replace_id');
  const isEdit = Boolean(id);
  const isCorrection = Boolean(replaceId);
  const [accounts, setAccounts] = useState([]);
  const [dimensions, setDimensions] = useState([]);
  const [entityId, setEntityId] = useState('');
  const [postingDate, setPostingDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [memo, setMemo]   = useState('');
  const [lines, setLines] = useState([newLine(), newLine()]);
  const [busy, setBusy]   = useState(false);
  const [loadingExisting, setLoadingExisting] = useState(Boolean(id || copyFrom || replaceId));
  const [error, setError] = useState(null);
  const [sourceEntry, setSourceEntry] = useState(null);
  const [correctionReason, setCorrectionReason] = useState('');
  const [icOpen, setIcOpen] = useState(false);
  const [icSeed, setIcSeed] = useState(null);
  const [expandedLine, setExpandedLine] = useState(null);
  const [assignmentStatus, setAssignmentStatus] = useState({});

  useEffect(() => {
    api.get('/modules/accounting/api/accounts.php').then((d) => {
      setAccounts(d?.rows || d?.accounts || []);
    }).catch(() => setAccounts([]));
    api.get('/modules/accounting/api/dimensions.php').then((d) => {
      setDimensions((d?.dimensions || []).filter((dimension) => Number(dimension.active) === 1 && dimension.dim_key !== 'legal_entity'));
    }).catch(() => setDimensions([]));
  }, []);

  useEffect(() => {
    if (!entitiesLoaded || entityId) return;
    const fallback = activeEntityId || (entities.length === 1 ? entities[0]?.id : null);
    if (fallback) setEntityId(String(fallback));
  }, [activeEntityId, entities, entitiesLoaded, entityId]);

  useEffect(() => {
    const sourceId = id || replaceId || copyFrom;
    if (!sourceId) return;
    let cancelled = false;
    setLoadingExisting(true);
    setError(null);
    api.get(`/modules/accounting/api/journal_entries.php?id=${sourceId}`)
      .then((data) => {
        if (cancelled) return;
        const entry = data?.entry;
        if (!entry) throw new Error('Journal entry not found.');
        if (isEdit && entry.status !== 'draft') {
          throw new Error('Only draft journal entries can be edited directly. Use Correct entry for a posted entry.');
        }
        if (isEdit && (entry.source_module === 'system' || ['ai_workflow', 'workflow_run'].includes(entry.source_ref_type))) {
          throw new Error('System-generated drafts must be reviewed in AI Agents.');
        }
        if (isCorrection && !['posted', 'reversed'].includes(entry.status)) {
          throw new Error('Only a posted or reversed entry can be corrected. Edit a draft directly.');
        }
        setSourceEntry(entry);
        setEntityId(entry.entity_id ? String(entry.entity_id) : '');
        setPostingDate((isEdit || isCorrection) ? entry.posting_date : new Date().toISOString().slice(0, 10));
        setMemo((isEdit || isCorrection)
          ? (entry.memo || '')
          : `Copy of ${entry.je_number}${entry.memo ? `: ${entry.memo}` : ''}`);
        setLines((data?.lines || []).map((line) => {
          const dims = parseDims(line.dim_json);
          const storedLegalEntity = dims.legal_entity || null;
          delete dims.legal_entity;
          if (line.counterparty_entity_id && !dims.counterparty_entity) {
            dims.counterparty_entity = String(line.counterparty_entity_id);
          }
          return {
            account_code: line.account_code || '',
            debit: Number(line.debit) > 0 ? String(line.debit) : '',
            credit: Number(line.credit) > 0 ? String(line.credit) : '',
            description: line.description || line.memo || '',
            counterparty_company_id: line.counterparty_company_id || null,
            counterparty_person_id: line.counterparty_person_id || null,
            counterparty_entity_id: line.counterparty_entity_id || null,
            dims,
            assignment_entity_id: dims.placement ? (storedLegalEntity || entry.entity_id || null) : null,
          };
        }));
      })
      .catch((e) => { if (!cancelled) setError(e.message || String(e)); })
      .finally(() => { if (!cancelled) setLoadingExisting(false); });
    return () => { cancelled = true; };
  }, [id, copyFrom, replaceId, isEdit, isCorrection]);

  const updateLine = (i, field, val) => {
    const next = [...lines];
    next[i] = { ...next[i], [field]: val };
    setLines(next);
  };

  const updateLineDimension = (i, key, value) => {
    const next = [...lines];
    const dims = { ...(next[i].dims || {}) };
    if (value === '') delete dims[key];
    else dims[key] = value;
    next[i] = {
      ...next[i],
      dims,
      ...(key === 'counterparty_entity' ? { counterparty_entity_id: value || null } : {}),
    };
    setLines(next);
  };

  const applyAssignmentDimensions = async (index, placement) => {
    if (!placement) {
      setLines((current) => {
        const next = [...current];
        const dims = { ...(next[index]?.dims || {}) };
        ASSIGNMENT_DIMENSION_KEYS.forEach((key) => delete dims[key]);
        next[index] = { ...next[index], dims, assignment_entity_id: null };
        return next;
      });
      setAssignmentStatus((current) => ({ ...current, [index]: null }));
      return;
    }
    setAssignmentStatus((current) => ({ ...current, [index]: { loading: true, message: 'Loading assignment context…' } }));
    try {
      const params = new URLSearchParams({
        placement_id: String(placement.id),
        as_of: postingDate,
      });
      if (entityId) params.set('entity_id', String(entityId));
      const data = await api.get(`/modules/staffing/api/assignment_dimensions.php?${params.toString()}`);
      const inherited = { ...(data?.dimensions || {}) };
      delete inherited.legal_entity;
      setLines((current) => {
        const next = [...current];
        const preserved = { ...(next[index]?.dims || {}) };
        ASSIGNMENT_DIMENSION_KEYS.forEach((key) => delete preserved[key]);
        if (accountNeedsVendor(next[index]?.account_code) && data?.vendor_dimension) {
          inherited.vendor = data.vendor_dimension;
        }
        next[index] = {
          ...next[index],
          dims: { ...preserved, ...inherited, placement: placement.id },
          assignment_entity_id: data?.resolved_entity_id || null,
        };
        return next;
      });
      const missing = relevantAssignmentMissing(
        data?.missing || [],
        accountNeedsVendor(lines[index]?.account_code)
      );
      setAssignmentStatus((current) => ({
        ...current,
        [index]: {
          loading: false,
          warning: missing.length > 0,
          message: missing.length > 0
            ? `Inherited available context. Assignment master is missing: ${missing.map(formatDimensionKey).join(', ')}.`
            : 'Assignment context inherited from the placement master.',
        },
      }));
    } catch (requestError) {
      setAssignmentStatus((current) => ({
        ...current,
        [index]: { loading: false, error: true, message: requestError.message || String(requestError) },
      }));
    }
  };

  const removeLine = (index) => {
    setLines(lines.filter((_, lineIndex) => lineIndex !== index));
    setExpandedLine((current) => {
      if (current === index) return null;
      return current !== null && current > index ? current - 1 : current;
    });
    setAssignmentStatus((current) => Object.fromEntries(
      Object.entries(current)
        .filter(([key]) => Number(key) !== index)
        .map(([key, value]) => [Number(key) > index ? Number(key) - 1 : Number(key), value])
    ));
  };

  const refreshAssignmentDimensions = async () => Promise.all(lines.map(async (line, index) => {
    const placementId = line.dims?.placement;
    if (!placementId) return line;
    const params = new URLSearchParams({
      placement_id: String(placementId),
      as_of: postingDate,
      entity_id: String(entityId),
    });
    const data = await api.get(`/modules/staffing/api/assignment_dimensions.php?${params.toString()}`);
    if (data?.resolved_entity_id && Number(data.resolved_entity_id) !== Number(entityId)) {
      throw new Error(`Assignment PL-${placementId} belongs to a different legal entity.`);
    }
    const needsVendor = accountNeedsVendor(line.account_code);
    const missing = relevantAssignmentMissing(data?.missing || [], needsVendor);
    if (missing.length > 0) {
      throw new Error(`Line ${index + 1}: assignment PL-${placementId} is missing ${missing.map(formatDimensionKey).join(', ')}.`);
    }
    const inherited = { ...(data?.dimensions || {}) };
    delete inherited.legal_entity;
    const preserved = { ...(line.dims || {}) };
    ASSIGNMENT_DIMENSION_KEYS.forEach((key) => delete preserved[key]);
    if (needsVendor) {
      if (!data?.vendor_dimension) {
        throw new Error(`Line ${index + 1}: assignment PL-${placementId} has no payable vendor.`);
      }
      inherited.vendor = data.vendor_dimension;
    }
    return {
      ...line,
      dims: { ...preserved, ...inherited, placement: placementId },
      assignment_entity_id: data?.resolved_entity_id || null,
    };
  }));

  const totals = lines.reduce((acc, l) => {
    const d = parseFloat(l.debit)  || 0;
    const c = parseFloat(l.credit) || 0;
    return { debit: acc.debit + d, credit: acc.credit + c };
  }, { debit: 0, credit: 0 });
  const balanced = Math.abs(totals.debit - totals.credit) < 0.005 && totals.debit > 0;
  const assignmentEntityMismatches = lines
    .map((line, index) => ({ line, index }))
    .filter(({ line }) => line.assignment_entity_id && entityId && Number(line.assignment_entity_id) !== Number(entityId));
  const assignmentsMatchEntity = assignmentEntityMismatches.length === 0;

  const submit = async (action) => {
    setBusy(true); setError(null);
    try {
      if (!entityId) throw new Error('Choose the legal entity for this journal entry.');
      if (!assignmentsMatchEntity) {
        throw new Error('One or more assignments belong to a different legal entity. Change the journal entity or choose a matching assignment.');
      }
      const submissionLines = await refreshAssignmentDimensions();
      setLines(submissionLines);
      const payload = {
        entity_id: Number(entityId),
        posting_date: postingDate,
        currency: sourceEntry?.currency || 'USD',
        memo,
        source_module: 'manual',
        lines: submissionLines
          .filter(l => l.account_code && (parseFloat(l.debit) > 0 || parseFloat(l.credit) > 0))
          .map(l => ({
            account_code: l.account_code,
            debit:  parseFloat(l.debit)  || 0,
            credit: parseFloat(l.credit) || 0,
            description: l.description || null,
            counterparty_company_id: l.counterparty_company_id || null,
            counterparty_person_id: l.counterparty_person_id || null,
            counterparty_entity_id: l.counterparty_entity_id || l.dims?.counterparty_entity || null,
            dims: l.dims || {},
          })),
      };
      let res;
      if (isCorrection) {
        res = await api.post(`/modules/accounting/api/journal_entries.php?action=replace&id=${replaceId}`, {
          ...payload,
          reason: correctionReason.trim(),
        });
      } else if (isEdit) {
        res = await api.patch(`/modules/accounting/api/journal_entries.php?id=${id}`, payload);
        if (action === 'post') {
          res = await api.post(`/modules/accounting/api/journal_entries.php?action=post_draft&id=${id}`, {});
        }
      } else {
        const url = '/modules/accounting/api/journal_entries.php' + (action === 'draft' ? '?action=draft' : '');
        res = await api.post(url, payload);
      }
      navigate(`/modules/accounting/journal-entries/${res.je_id}`);
    } catch (e) {
      setError(e.message || String(e));
    } finally {
      setBusy(false);
    }
  };

  if (loadingExisting) {
    return <p data-testid="accounting-je-create-loading">Loading journal entry…</p>;
  }
  if ((isEdit || copyFrom || isCorrection) && !sourceEntry && error) {
    return (
      <section className="ledger-page" data-testid="accounting-je-create-source-error">
        <Link to="/modules/accounting/journal-entries" className="entry-detail__back-link"><ArrowLeft size={14} aria-hidden="true" />Journal entries</Link>
        <p className="error">Could not prepare this entry: {error}</p>
      </section>
    );
  }

  return (
    <section className="ledger-page" data-testid="accounting-je-create">
      <Link
        to={(isEdit || isCorrection) ? `/modules/accounting/journal-entries/${id || replaceId}` : '/modules/accounting/journal-entries'}
        className="entry-detail__back-link"
      ><ArrowLeft size={14} aria-hidden="true" />{(isEdit || isCorrection) ? 'Journal entry' : 'Journal entries'}</Link>
      <header className="entry-editor__header">
        <div>
          <h2>{isEdit ? `Edit draft ${sourceEntry?.je_number || ''}` : (isCorrection ? `Correct ${sourceEntry?.je_number || 'journal entry'}` : 'New journal entry')}</h2>
          <p>{isEdit ? 'Changes remain off the ledger until you post the draft.' : (isCorrection ? 'Edit the entry below. Posting the correction replaces the original in balances and reports.' : 'Build a balanced entry, then save a draft or post it.')}</p>
        </div>
      </header>

      {isCorrection && sourceEntry && (
        <div className="entry-status-note entry-status-note--warning" data-testid="accounting-je-correction-notice">
          <Pencil size={16} aria-hidden="true" />
          <span>The original <Link to={`/modules/accounting/journal-entries/${sourceEntry.id}`}>{sourceEntry.je_number}</Link> stays unchanged until this correction posts. CoreFlux then removes the original from active books and preserves both entries in the audit trail.</span>
        </div>
      )}

      {!isEdit && !isCorrection && sourceEntry && (
        <div className="entry-status-note entry-status-note--info" data-testid="accounting-je-copy-notice">
          <Copy size={16} aria-hidden="true" />
          <span>Copied from <Link to={`/modules/accounting/journal-entries/${sourceEntry.id}`}>{sourceEntry.je_number}</Link>. Review every line before posting; the original entry is unchanged.</span>
        </div>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12, maxWidth: 980 }}>
        <label style={{ fontSize: 13 }}>
          <span style={{ color: 'var(--cf-text-secondary)' }}>Legal entity</span>
          <select
            className="input"
            value={entityId}
            onChange={(event) => setEntityId(event.target.value)}
            data-testid="accounting-je-entity"
            style={{ display: 'block', width: '100%', marginTop: 4 }}
            required
          >
            <option value="">— select entity —</option>
            {entities.filter((entity) => Number(entity.active ?? 1) === 1).map((entity) => (
              <option key={entity.id} value={entity.id}>{entity.legal_name || entity.code}{entity.code && entity.legal_name ? ` (${entity.code})` : ''}</option>
            ))}
          </select>
        </label>
        <label style={{ fontSize: 13 }}>
          <span style={{ color: 'var(--cf-text-secondary)' }}>Posting date</span>
          <input type="date" className="input" value={postingDate} onChange={(e) => setPostingDate(e.target.value)} data-testid="accounting-je-date" style={{ display: 'block', width: '100%', marginTop: 4 }} />
        </label>
        <label style={{ fontSize: 13, gridColumn: 'span 2' }}>
          <span style={{ color: 'var(--cf-text-secondary)' }}>Memo</span>
          <input className="input" value={memo} onChange={(e) => setMemo(e.target.value)} data-testid="accounting-je-memo" style={{ display: 'block', width: '100%', marginTop: 4 }} placeholder="Optional — helps the auditor understand intent" />
        </label>
        {isCorrection && (
          <label style={{ fontSize: 13, gridColumn: '1 / -1' }}>
            <span style={{ color: 'var(--cf-text-secondary)' }}>Reason for correction</span>
            <input
              className="input"
              value={correctionReason}
              onChange={(event) => setCorrectionReason(event.target.value)}
              data-testid="accounting-je-correction-reason"
              style={{ display: 'block', width: '100%', marginTop: 4 }}
              placeholder="What was wrong with the original entry?"
              required
            />
          </label>
        )}
      </div>

      <h3 style={{ marginTop: 24 }}>Lines</h3>
      <table className="data-table" data-testid="accounting-je-lines">
        <thead>
          <tr><th style={{ width: 220 }}>Account</th><th>Description</th><th style={{ width: 120, textAlign: 'right' }}>Debit</th><th style={{ width: 120, textAlign: 'right' }}>Credit</th><th style={{ width: 92 }}>Dimensions</th><th style={{ width: 44 }}></th></tr>
        </thead>
        <tbody>
          {lines.map((l, i) => (
            <React.Fragment key={i}>
            <tr>
              <td>
                <select
                  className="input"
                  value={l.account_code}
                  onChange={(e) => updateLine(i, 'account_code', e.target.value)}
                  data-testid={`accounting-je-line-account-${i}`}
                >
                  <option value="">— select —</option>
                  {accounts.map((a) => (
                    <option key={a.id} value={a.code}>{a.code} — {a.name}</option>
                  ))}
                </select>
              </td>
              <td>
                <input
                  className="input"
                  value={l.description}
                  onChange={(e) => updateLine(i, 'description', e.target.value)}
                  data-testid={`accounting-je-line-desc-${i}`}
                />
              </td>
              <td>
                <input
                  className="input"
                  type="number" step="0.01"
                  value={l.debit}
                  onChange={(e) => updateLine(i, 'debit', e.target.value)}
                  data-testid={`accounting-je-line-debit-${i}`}
                  style={{ textAlign: 'right' }}
                />
              </td>
              <td>
                <input
                  className="input"
                  type="number" step="0.01"
                  value={l.credit}
                  onChange={(e) => updateLine(i, 'credit', e.target.value)}
                  data-testid={`accounting-je-line-credit-${i}`}
                  style={{ textAlign: 'right' }}
                />
              </td>
              <td>
                <button
                  type="button"
                  className="btn btn--ghost btn--icon"
                  onClick={() => setExpandedLine(expandedLine === i ? null : i)}
                  data-testid={`accounting-je-line-dimensions-${i}`}
                  aria-expanded={expandedLine === i}
                  aria-label={`Edit dimensions for line ${i + 1}`}
                  title={`Edit dimensions for line ${i + 1}`}
                >
                  <SlidersHorizontal size={15} aria-hidden="true" />
                  <span style={{ fontSize: 11 }}>{dimensionCount(l.dims)}</span>
                </button>
              </td>
              <td>
                <button
                  type="button"
                  className="btn btn--ghost btn--icon"
                  onClick={() => removeLine(i)}
                  data-testid={`accounting-je-line-remove-${i}`}
                  aria-label={`Remove line ${i + 1}`}
                  title={`Remove line ${i + 1}`}
                ><Trash2 size={15} aria-hidden="true" /></button>
              </td>
            </tr>
            {expandedLine === i && (
              <tr data-testid={`accounting-je-line-dimensions-panel-${i}`}>
                <td colSpan={6} style={{ background: 'var(--cf-surface-subtle, #f7fbff)', borderLeft: '3px solid var(--cf-primary, #1683ff)', padding: '14px 16px' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
                    <SlidersHorizontal size={15} aria-hidden="true" />
                    <strong style={{ fontSize: 13 }}>Line {i + 1} dimensions</strong>
                    <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>The legal entity is inherited from the journal header.</span>
                  </div>
                  {dimensions.length === 0 ? (
                    <p style={{ margin: 0, fontSize: 13, color: 'var(--cf-text-secondary)' }}>No additional dimensions are configured.</p>
                  ) : (
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: 10 }}>
                      {dimensions.map((dimension) => (
                        <label key={dimension.id || dimension.dim_key} style={{ minWidth: 0, fontSize: 12 }}>
                          <span style={{ display: 'block', color: 'var(--cf-text-secondary)', marginBottom: 4 }}>{dimension.label}</span>
                          {dimension.dim_key === 'placement' ? (
                            <div>
                              <PlacementPicker
                                value={l.dims?.placement || ''}
                                onChange={(placement) => applyAssignmentDimensions(i, placement)}
                                placeholder="Search all assignments"
                                testId={`accounting-je-line-${i}-dimension-placement`}
                              />
                              {assignmentStatus[i]?.message ? (
                                <span style={{
                                  display: 'block', marginTop: 4, fontSize: 11,
                                  color: assignmentStatus[i].error
                                    ? '#b42318'
                                    : (assignmentStatus[i].warning ? '#9a6700' : 'var(--cf-text-secondary)'),
                                }}>
                                  {assignmentStatus[i].message}
                                </span>
                              ) : null}
                            </div>
                          ) : dimension.dim_key === 'counterparty_entity' ? (
                            <select
                              className="input"
                              value={l.dims?.[dimension.dim_key] || ''}
                              onChange={(event) => updateLineDimension(i, dimension.dim_key, event.target.value)}
                              data-testid={`accounting-je-line-${i}-dimension-${dimension.dim_key}`}
                              style={{ width: '100%' }}
                            >
                              <option value="">— none —</option>
                              {entities.filter((entity) => Number(entity.active ?? 1) === 1).map((entity) => (
                                <option key={entity.id} value={entity.id}>{entity.legal_name || entity.code}</option>
                              ))}
                            </select>
                          ) : (
                            <input
                              className="input"
                              value={l.dims?.[dimension.dim_key] || ''}
                              onChange={(event) => updateLineDimension(i, dimension.dim_key, event.target.value)}
                              data-testid={`accounting-je-line-${i}-dimension-${dimension.dim_key}`}
                              placeholder={dimensionPlaceholder(dimension)}
                              style={{ width: '100%' }}
                            />
                          )}
                        </label>
                      ))}
                    </div>
                  )}
                </td>
              </tr>
            )}
            </React.Fragment>
          ))}
          <tr>
            <td colSpan={2} style={{ fontWeight: 600 }}>Totals</td>
            <td style={{ textAlign: 'right', fontWeight: 600 }} data-testid="accounting-je-total-debit">{totals.debit.toFixed(2)}</td>
            <td style={{ textAlign: 'right', fontWeight: 600 }} data-testid="accounting-je-total-credit">{totals.credit.toFixed(2)}</td>
            <td></td>
            <td></td>
          </tr>
        </tbody>
      </table>
      <button type="button" className="btn btn--ghost" onClick={() => setLines([...lines, newLine()])} data-testid="accounting-je-add-line" style={{ marginTop: 8 }}><Plus size={15} aria-hidden="true" />Add line</button>

      <p style={{ marginTop: 16, fontSize: 13, color: balanced ? '#065f46' : '#991b1b' }} data-testid="accounting-je-balance-status">
        {balanced ? '✓ Entry is balanced.' : `Debits and credits must match. Diff: ${(totals.debit - totals.credit).toFixed(2)}`}
      </p>

      {!assignmentsMatchEntity && (
        <p className="error" data-testid="accounting-je-assignment-entity-error">
          {assignmentEntityMismatches.length === 1 ? 'Line' : 'Lines'} {assignmentEntityMismatches.map(({ index }) => index + 1).join(', ')} use {assignmentEntityMismatches.length === 1 ? 'an assignment' : 'assignments'} owned by a different legal entity.
        </p>
      )}

      {error && <p className="error" data-testid="accounting-je-error">Error: {error}</p>}

      <div style={{ display: 'flex', gap: 8, marginTop: 16 }}>
        {!isCorrection && <button type="button" className="btn btn--ghost" onClick={() => submit('draft')} disabled={busy || !balanced || !entityId || !assignmentsMatchEntity} data-testid="accounting-je-save-draft"><Save size={15} aria-hidden="true" />{busy ? 'Saving…' : (isEdit ? 'Save draft' : 'Save as draft')}</button>}
        <button type="button" className="btn btn--primary" onClick={() => submit('post')} disabled={busy || !balanced || !entityId || !assignmentsMatchEntity || (isCorrection && !correctionReason.trim())} data-testid="accounting-je-post"><Send size={15} aria-hidden="true" />{busy ? 'Posting…' : (isCorrection ? 'Post correction' : 'Save & post')}</button>
        {!isEdit && !isCorrection && <button
          type="button"
          className="btn btn--ghost"
          onClick={() => {
            // Seed the IC dialog from the largest line as the "source offset"
            // (the single line that balances all the others); remaining lines
            // become the splits.
            const rows = lines.filter(l => l.account_code && (parseFloat(l.debit) > 0 || parseFloat(l.credit) > 0));
            if (rows.length < 2) { setError('Add at least 2 lines before splitting across entities'); return; }
            const byAmount = [...rows].sort((a, b) => (parseFloat(b.debit) + parseFloat(b.credit) || 0) - (parseFloat(a.debit) + parseFloat(a.credit) || 0));
            const offsetRow = byAmount[0];
            const offsetAmt = parseFloat(offsetRow.debit) || parseFloat(offsetRow.credit) || 0;
            const offsetSide = parseFloat(offsetRow.debit) > 0 ? 'debit' : 'credit';
            const splitSeeds = rows.filter(r => r !== offsetRow).map(r => ({
              entity_id: Number(entityId),
              account_code: r.account_code,
              amount: parseFloat(r.debit) || parseFloat(r.credit) || 0,
              memo: r.description,
            }));
            setIcSeed({
              amount: offsetAmt,
              sourceOffsetAccountCode: offsetRow.account_code,
              sourceOffsetSide: offsetSide,
              splits: splitSeeds,
            });
            setIcOpen(true);
          }}
          data-testid="accounting-je-ic-split"
          disabled={busy}
        ><Plus size={15} aria-hidden="true" />Split across entities</button>}
      </div>
      {icOpen && icSeed && (
        <IntercompanySplitDialog
          open={icOpen}
          onClose={() => setIcOpen(false)}
          onPosted={(res) => {
            setIcOpen(false);
            if (res?.jes?.[0]?.je_id) navigate(`/modules/accounting/journal-entries/${res.jes[0].je_id}`);
          }}
          amount={icSeed.amount}
          sourceEntityId={Number(entityId)}
          sourceOffsetAccountCode={icSeed.sourceOffsetAccountCode}
          sourceOffsetSide={icSeed.sourceOffsetSide}
          defaultMemo={memo}
          initialPostingDate={postingDate}
          initialSplits={icSeed.splits}
        />
      )}
    </section>
  );
}

function parseDims(value) {
  if (!value) return {};
  if (typeof value === 'object') return value;
  try { return JSON.parse(value) || {}; }
  catch { return {}; }
}

function dimensionCount(dims) {
  return Object.entries(dims || {}).filter(([key, value]) => key !== 'legal_entity' && value !== null && String(value).trim() !== '').length;
}

function dimensionPlaceholder(dimension) {
  if (dimension.reference_table === 'placements') return 'Placement ID';
  if (dimension.reference_table === 'people') return 'Person ID';
  if (dimension.reference_table === 'companies') return 'Company ID';
  if (dimension.reference_table === 'users') return 'User ID';
  if (dimension.reference_table) return 'Reference ID';
  return `Enter ${String(dimension.label || dimension.dim_key).toLowerCase()}`;
}

function accountNeedsVendor(accountCode) {
  return ['2000', '2050', '5010', '5070'].includes(String(accountCode || '').trim());
}

function relevantAssignmentMissing(missing, needsVendor) {
  return (missing || []).filter((key) => needsVendor || !['vendor', 'vendor_ap_link'].includes(key));
}

function formatDimensionKey(key) {
  return String(key || '').replaceAll('_', ' ');
}
