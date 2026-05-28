/**
 * AssignmentSchemaPreview — auto-built JobDiva Assignment-clone screen.
 *
 * Renders the live indexed schema (every field JobDiva sent us during
 * the last sync/backfill) grouped into Assignment / Placement / Job /
 * Person / End-client / Contact sections — mirroring the JobDiva
 * Assignment edit screen so operators get a CoreFlux-native view of
 * everything they're already mapping in the Studio.
 *
 * Read-only first pass. Operators who want to TRANSFORM the data into
 * CoreFlux columns still use the Field Mapping Studio next door.
 *
 * Empty-state behaviour: when a section has zero indexed paths, we
 * render a hint pointing the operator at the Studio's Re-index button
 * so the JobDiva enrichment endpoints get a chance to populate the
 * missing data before this page can render meaningfully.
 */
import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';

export default function AssignmentSchemaPreview() {
  const [integration, setIntegration] = useState('jobdiva');
  const [sections, setSections] = useState([]);
  const [loading,  setLoading]  = useState(true);
  const [error,    setError]    = useState(null);
  const [openSections, setOpenSections] = useState({});

  const load = async () => {
    setLoading(true); setError(null);
    try {
      const r = await api.get(`/api/admin/integrations/placement_schema.php?integration=${integration}`);
      const ss = Array.isArray(r.sections) ? r.sections : [];
      setSections(ss);
      // Default-open every section with at least one field.
      const op = {};
      ss.forEach(s => { op[s.key] = (s.field_count > 0); });
      setOpenSections(op);
    } catch (e) {
      setError(e.message || 'Failed to load schema');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); /* eslint-disable-next-line */ }, [integration]);

  const totalFields = sections.reduce((a, s) => a + (s.field_count || 0), 0);

  return (
    <section data-testid="assignment-schema-page" style={{ padding: 20, maxWidth: 1200, margin: '0 auto' }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 16 }}>
        <div>
          <h1 style={{ margin: 0, fontSize: 22 }}>Assignment detail (auto-built from indexed fields)</h1>
          <p style={{ margin: '6px 0 0', fontSize: 13, color: '#475569', maxWidth: 720 }}>
            This screen is generated directly from your <code>{integration}</code> indexed schema. Every field
            JobDiva (or any integration) returned during the last sync / backfill shows up here, grouped by
            entity. Use this as the canonical "what data do we actually have" view — and use the{' '}
            <Link to="/admin/integrations/field-map/studio" data-testid="assignment-schema-studio-link">
              Field Mapping Studio
            </Link>{' '}
            to route any of these fields into CoreFlux columns.
          </p>
          <p style={{ margin: '6px 0 0', fontSize: 12, color: '#64748b' }}>
            <strong data-testid="assignment-schema-total-fields">{totalFields}</strong> indexed fields across{' '}
            <strong>{sections.length}</strong> section(s).{' '}
            {totalFields === 0 && (
              <Link to="/admin/integrations/field-map/studio" style={{ color: '#dc2626' }}>
                No fields indexed yet — open Studio and click Re-index →
              </Link>
            )}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <select
            data-testid="assignment-schema-integration"
            value={integration}
            onChange={e => setIntegration(e.target.value)}
            className="input"
            style={{ fontSize: 13 }}
          >
            <option value="jobdiva">jobdiva</option>
            <option value="quickbooks">quickbooks</option>
            <option value="zoho_books">zoho_books</option>
            <option value="airtable">airtable</option>
          </select>
          <button data-testid="assignment-schema-reload"
                  onClick={load} disabled={loading}
                  className="btn btn--ghost"
                  style={{ fontSize: 13 }}>
            {loading ? 'Loading…' : 'Reload'}
          </button>
        </div>
      </header>

      {error && (
        <div data-testid="assignment-schema-error"
             style={{ padding: 10, background: '#fef2f2', border: '1px solid #fecaca', borderRadius: 8, color: '#b91c1c', fontSize: 13 }}>
          {error}
        </div>
      )}

      {!loading && sections.map(s => {
        const isOpen = openSections[s.key];
        const empty  = (s.field_count || 0) === 0;
        return (
          <div key={s.key}
               data-testid={`assignment-schema-section-${s.key}`}
               data-entity-type={s.entity_type}
               data-field-count={s.field_count || 0}
               style={{ marginBottom: 14, border: '1px solid #e2e8f0', borderRadius: 10, overflow: 'hidden' }}>
            <button
              type="button"
              data-testid={`assignment-schema-toggle-${s.key}`}
              onClick={() => setOpenSections(o => ({ ...o, [s.key]: !isOpen }))}
              style={{
                width: '100%', textAlign: 'left',
                padding: '12px 14px',
                background: isOpen ? '#eff6ff' : '#f8fafc',
                border: 0, cursor: 'pointer',
                display: 'flex', alignItems: 'center', gap: 10,
                fontSize: 14, fontWeight: 600, color: '#0f172a',
              }}>
              <span style={{ fontSize: 18 }}>{s.icon}</span>
              <span style={{ flex: 1 }}>{s.label}</span>
              <span style={{ fontSize: 11, color: '#64748b', fontWeight: 400 }}>
                {s.field_count} {s.field_count === 1 ? 'field' : 'fields'} · entity_type={s.entity_type}
              </span>
              <span style={{ fontSize: 12, color: '#64748b' }}>{isOpen ? '▾' : '▸'}</span>
            </button>
            {isOpen && (
              <div style={{ padding: 0 }}>
                {empty && (
                  <p data-testid={`assignment-schema-empty-${s.key}`}
                     style={{ padding: 14, fontSize: 12, color: '#64748b' }}>
                    No paths indexed for <code>{integration}/{s.entity_type}</code> yet. Open the{' '}
                    <Link to="/admin/integrations/field-map/studio">Field Mapping Studio</Link> and click{' '}
                    <strong>Re-index again</strong> — if the JobDiva search endpoints are reachable on your
                    tenant, this section will populate automatically.
                  </p>
                )}
                {!empty && (
                  <div style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))',
                    gap: 0,
                  }}>
                    {s.fields.map(f => (
                      <div key={f.path}
                           data-testid={`assignment-schema-field-${s.key}-${f.path}`}
                           style={{ padding: '10px 14px', borderTop: '1px solid #e2e8f0',
                                    borderLeft: '1px solid #f1f5f9' }}>
                        <div style={{ fontSize: 11, color: '#64748b', textTransform: 'uppercase',
                                      letterSpacing: 0.2 }}>
                          {f.path}
                        </div>
                        <div style={{ marginTop: 4, fontSize: 13, color: '#0f172a',
                                      whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}
                             title={f.sample_value || ''}>
                          {f.sample_value !== null && f.sample_value !== '' ? (
                            <span>{f.sample_value}</span>
                          ) : (
                            <em style={{ color: '#cbd5e1' }}>—</em>
                          )}
                        </div>
                        <div style={{ marginTop: 2, fontSize: 10, color: '#94a3b8' }}>
                          {f.value_type} · ×{f.occurrence_count}
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )}
          </div>
        );
      })}
    </section>
  );
}
