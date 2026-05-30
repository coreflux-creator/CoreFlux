import React, { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { X, Sparkles, AlertTriangle } from 'lucide-react';

/**
 * PromoteVaultModal — Slice 4 wizard for converting a stored-only
 * Airtable mapping (entity=generic, link_strategy=none) into a real
 * entity bound to a CoreFlux table. Picks the new entity, the link
 * strategy, optional match field (for match_column strategy), and
 * whether to auto-create stubs for everything still unmatched after
 * re-linking.
 *
 * Calls POST /api/airtable/promote_vault.php on submit and shows the
 * rollup so the operator sees exactly how many vault rows moved into
 * the queue / linked rows / created stubs.
 */
const ENTITIES = [
  { value: 'placement',  label: 'Placement (placements)',          example: 'Time Entries → placements.external_id' },
  { value: 'contact',    label: 'Contact (people)',                example: 'CRM Contacts → people.email_primary' },
  { value: 'company',    label: 'Company (companies)',             example: 'Customer list → companies.name' },
  { value: 'customer',   label: 'Customer (companies)',            example: 'AR customers → companies.name' },
  { value: 'vendor',     label: 'Vendor (ap_vendors_index)',       example: 'AP suppliers → ap_vendors_index.vendor_name' },
  { value: 'note',       label: 'Note (no auto-link)',             example: 'Free-form annotations' },
  { value: 'task',       label: 'Task (no auto-link)',             example: 'Workflow tasks' },
  { value: 'opportunity',label: 'Opportunity (no auto-link)',      example: 'Sales pipeline' },
  { value: 'generic',    label: 'Generic (storage only)',          example: 'Anything else' },
];
const STRATEGIES = [
  { value: 'external_id',  label: 'external_id', help: 'Match Airtable\'s record id (rec…) against {table}.external_id.' },
  { value: 'match_column', label: 'match_column', help: 'Match a specific Airtable field against a specific CoreFlux column.' },
  { value: 'manual',       label: 'manual', help: 'Never auto-link — every record lands in the reconciliation queue.' },
  { value: 'none',         label: 'none', help: 'Do not link to anything — stored only.' },
];

export default function PromoteVaultModal({ mapping, onClose, onComplete }) {
  const [entity, setEntity] = useState(mapping.internal_entity === 'generic' ? 'placement' : mapping.internal_entity);
  const [strategy, setStrategy] = useState('external_id');
  const [atField, setAtField] = useState('');
  const [intCol, setIntCol]   = useState('');
  const [unmatched, setUnmatched] = useState('park');
  const [createStubs, setCreateStubs] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState(null);
  const [rollup, setRollup] = useState(null);
  const [topFields, setTopFields] = useState([]);

  // Slice 3 vault includes a top_fields fingerprint — fetch once so the
  // operator can pick an Airtable field name from the dropdown rather
  // than typing freehand.
  useEffect(() => {
    api.get(`/api/airtable/vault.php?action=vault&mapping_id=${mapping.id}&limit=1`)
      .then((r) => setTopFields(r.top_fields || []))
      .catch(() => setTopFields([]));
  }, [mapping.id]);

  const submit = async () => {
    setSubmitting(true); setError(null); setRollup(null);
    try {
      const body = {
        mapping_id:               mapping.id,
        internal_entity:          entity,
        link_strategy:            strategy,
        link_unmatched_action:    unmatched,
        create_stubs:             createStubs,
      };
      if (strategy === 'match_column') {
        body.link_match_airtable_field  = atField;
        body.link_match_internal_column = intCol;
      }
      const r = await api.post('/api/airtable/promote_vault.php?action=promote_vault', body);
      setRollup(r);
      if (onComplete) onComplete(r);
    } catch (e) {
      setError(e.message || 'Promote failed');
    } finally {
      setSubmitting(false);
    }
  };

  const strategyHelp = STRATEGIES.find((s) => s.value === strategy)?.help;

  return (
    <div
      data-testid="airtable-promote-modal"
      role="dialog" aria-modal="true"
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
      style={{
        position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.55)',
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        zIndex: 1000, padding: 24,
      }}
    >
      <div style={{
        background: '#fff', borderRadius: 8, width: 'min(640px, 96vw)',
        maxHeight: '92vh', overflow: 'auto',
        boxShadow: '0 20px 50px rgba(0,0,0,0.25)',
      }}>
        <header style={{ padding: '14px 20px', borderBottom: '1px solid #e5e7eb',
                         display: 'flex', alignItems: 'flex-start', gap: 12 }}>
          <div style={{ flex: 1 }}>
            <h3 style={{ margin: 0, fontSize: 17, fontWeight: 600,
                         display: 'flex', alignItems: 'center', gap: 6 }}>
              <Sparkles size={16} /> Promote vault → entity
            </h3>
            <p style={{ margin: '4px 0 0', fontSize: 12, color: '#64748b' }}>
              Convert <code>{mapping.base_name || mapping.base_id} / {mapping.table_name || mapping.table_id}</code>{' '}
              from storage-only into a real CoreFlux entity. Existing vault rows will be re-linked under the new policy,
              and (optionally) anything that doesn't match an existing row will get a brand-new stub created from its payload.
            </p>
          </div>
          <button type="button" className="btn"
                  data-testid="airtable-promote-close" onClick={onClose}>
            <X size={14} />
          </button>
        </header>

        <div style={{ padding: '14px 20px', display: 'flex', flexDirection: 'column', gap: 12 }}>
          <Field label="Target entity">
            <select
              data-testid="airtable-promote-entity"
              value={entity}
              onChange={(e) => setEntity(e.target.value)}
              style={{ width: '100%', padding: '6px 10px', fontSize: 13 }}>
              {ENTITIES.map((en) => (
                <option key={en.value} value={en.value}>{en.label}</option>
              ))}
            </select>
            <small style={{ color: '#64748b', fontSize: 11 }}>
              {ENTITIES.find((en) => en.value === entity)?.example}
            </small>
          </Field>

          <Field label="Link strategy">
            <select
              data-testid="airtable-promote-strategy"
              value={strategy}
              onChange={(e) => setStrategy(e.target.value)}
              style={{ width: '100%', padding: '6px 10px', fontSize: 13 }}>
              {STRATEGIES.map((s) => (
                <option key={s.value} value={s.value}>{s.label}</option>
              ))}
            </select>
            {strategyHelp && (
              <small style={{ color: '#64748b', fontSize: 11 }}>{strategyHelp}</small>
            )}
          </Field>

          {strategy === 'match_column' && (
            <>
              <Field label="Airtable field name">
                {topFields.length > 0 ? (
                  <select
                    data-testid="airtable-promote-at-field"
                    value={atField}
                    onChange={(e) => setAtField(e.target.value)}
                    style={{ width: '100%', padding: '6px 10px', fontSize: 13 }}>
                    <option value="">— pick one —</option>
                    {topFields.map((f) => (
                      <option key={f.field} value={f.field}>
                        {f.field} ({f.occurrences})
                      </option>
                    ))}
                  </select>
                ) : (
                  <input
                    type="text"
                    data-testid="airtable-promote-at-field"
                    value={atField}
                    onChange={(e) => setAtField(e.target.value)}
                    placeholder="e.g. Email"
                    style={{ width: '100%', padding: '6px 10px', fontSize: 13 }}
                  />
                )}
              </Field>
              <Field label="CoreFlux column (defaults will apply if blank)">
                <input
                  type="text"
                  data-testid="airtable-promote-int-col"
                  value={intCol}
                  onChange={(e) => setIntCol(e.target.value)}
                  placeholder="e.g. email_primary"
                  style={{ width: '100%', padding: '6px 10px', fontSize: 13 }}
                />
              </Field>
            </>
          )}

          <Field label="When a vault row doesn't match">
            <select
              data-testid="airtable-promote-unmatched"
              value={unmatched}
              onChange={(e) => setUnmatched(e.target.value)}
              style={{ width: '100%', padding: '6px 10px', fontSize: 13 }}>
              <option value="park">Park (leave in the unmatched queue)</option>
              <option value="skip">Skip (do not store the row at all)</option>
              <option value="create_stub">Create stub (auto-create the entity row)</option>
            </select>
          </Field>

          <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13 }}>
            <input
              type="checkbox"
              data-testid="airtable-promote-stubs"
              checked={createStubs}
              onChange={(e) => setCreateStubs(e.target.checked)}
            />
            Also create stubs <em style={{ color: '#64748b' }}>(for rows still unmatched after re-linking)</em>
          </label>

          {error && (
            <div data-testid="airtable-promote-error"
                 style={{ padding: '8px 10px', background: '#fef2f2',
                          color: '#991b1b', borderRadius: 4, fontSize: 12 }}>
              <AlertTriangle size={12} style={{ verticalAlign: 'middle', marginRight: 4 }} />
              {error}
            </div>
          )}

          {rollup && (
            <div data-testid="airtable-promote-rollup"
                 style={{ padding: 10, background: '#ecfdf5',
                          color: '#065f46', borderRadius: 4, fontSize: 13,
                          border: '1px solid #a7f3d0' }}>
              Promoted <strong>{rollup.scanned}</strong> vault row(s):{' '}
              <span data-testid="airtable-promote-linked">{rollup.linked} linked</span>,{' '}
              <span data-testid="airtable-promote-stubs-created">{rollup.stubs_created} stubs created</span>,{' '}
              <span data-testid="airtable-promote-still-unmatched">{rollup.still_unmatched} still unmatched</span>,{' '}
              <span data-testid="airtable-promote-still-ambiguous">{rollup.still_ambiguous} ambiguous</span>.
            </div>
          )}
        </div>

        <footer style={{ padding: '12px 20px', borderTop: '1px solid #e5e7eb',
                         display: 'flex', justifyContent: 'flex-end', gap: 6 }}>
          <button type="button" className="btn"
                  data-testid="airtable-promote-cancel"
                  onClick={onClose} disabled={submitting}>
            {rollup ? 'Close' : 'Cancel'}
          </button>
          {!rollup && (
            <button type="button" className="btn btn--primary"
                    data-testid="airtable-promote-submit"
                    onClick={submit}
                    disabled={submitting || (strategy === 'match_column' && !atField.trim())}>
              {submitting ? 'Promoting…' : 'Promote vault'}
            </button>
          )}
        </footer>
      </div>
    </div>
  );
}

function Field({ label, children }) {
  return (
    <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, fontWeight: 600 }}>
      <span>{label}</span>
      {children}
    </label>
  );
}
