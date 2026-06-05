import React, { useState, useEffect, useCallback } from 'react';
import { api } from '../lib/api';

/**
 * <KnowledgeGraphExplorer /> — Slice 7B Knowledge Graph browser.
 *
 * Two tabs:
 *   "Search"   — FULLTEXT over knowledge_documents
 *   "Entities" — list entities (filter by type) + drill-in panel with
 *                1-hop neighbours
 *
 * Mounted at /admin/ai/knowledge.
 */
export default function KnowledgeGraphExplorer() {
  const [tab, setTab]                       = useState('search');
  const [query, setQuery]                   = useState('');
  const [results, setResults]               = useState([]);
  const [entities, setEntities]             = useState([]);
  const [entityType, setEntityType]         = useState('');
  const [selectedEntity, setSelectedEntity] = useState(null);
  const [neighbours, setNeighbours]         = useState(null);
  const [error, setError]                   = useState(null);
  const [busy, setBusy]                     = useState(false);

  const runSearch = useCallback(async () => {
    if (!query.trim()) return;
    setBusy(true); setError(null);
    try {
      const r = await api.get(`/api/ai/knowledge.php?action=search&q=${encodeURIComponent(query)}`);
      setResults(r.results || []);
    } catch (e) { setError(e.message || String(e)); }
    finally { setBusy(false); }
  }, [query]);

  const loadEntities = useCallback(async () => {
    setBusy(true); setError(null);
    try {
      const r = await api.get(`/api/ai/knowledge.php?action=entities${entityType ? `&entity_type=${encodeURIComponent(entityType)}` : ''}`);
      setEntities(r.entities || []);
    } catch (e) { setError(e.message || String(e)); }
    finally { setBusy(false); }
  }, [entityType]);

  const loadNeighbours = useCallback(async (id) => {
    if (!id) { setNeighbours(null); return; }
    setBusy(true); setError(null);
    try {
      const r = await api.get(`/api/ai/knowledge.php?action=entity&id=${id}`);
      setNeighbours(r);
    } catch (e) { setError(e.message || String(e)); }
    finally { setBusy(false); }
  }, []);

  useEffect(() => { if (tab === 'entities') loadEntities(); }, [tab, loadEntities]);
  useEffect(() => { loadNeighbours(selectedEntity); }, [selectedEntity, loadNeighbours]);

  return (
    <div data-testid="knowledge-page" style={{ padding: '0 8px' }}>
      <header style={{ marginBottom: 16 }}>
        <h2 style={{ fontSize: 'var(--cf-text-xl)', margin: 0 }}
            data-testid="knowledge-title">
          Knowledge graph
        </h2>
        <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13, margin: '4px 0 0' }}>
          Documents (FULLTEXT-indexed) + entity / edge graph the LLM can cite back to.
          {' '}<em>Vector retrieval via pgvector is deferred</em> — current search is MySQL FULLTEXT.
        </p>
      </header>

      <div role="tablist" style={{ display: 'flex', gap: 8, marginBottom: 12 }}>
        {['search', 'entities'].map(t => (
          <button key={t} type="button"
                  onClick={() => setTab(t)}
                  data-testid={`knowledge-tab-${t}`}
                  className={tab === t ? 'btn btn--primary' : 'btn btn--ghost'}
                  style={{ fontSize: 12 }}>
            {t === 'search' ? 'Search' : 'Entities'}
          </button>
        ))}
      </div>

      {error && (
        <div className="alert alert--error"
             data-testid="knowledge-error"
             style={{ marginBottom: 12 }}>{error}</div>
      )}

      {tab === 'search' && (
        <section data-testid="knowledge-search-panel">
          <div style={{ display: 'flex', gap: 8, marginBottom: 12 }}>
            <input className="input" type="search" placeholder="e.g. vendor onboarding policy"
                   value={query}
                   onChange={e => setQuery(e.target.value)}
                   onKeyDown={e => { if (e.key === 'Enter') runSearch(); }}
                   data-testid="knowledge-search-input"
                   style={{ flex: 1 }} />
            <button type="button" className="btn btn--primary"
                    disabled={busy || !query.trim()}
                    onClick={runSearch}
                    data-testid="knowledge-search-run">Search</button>
          </div>

          {results.length === 0
            ? <p data-testid="knowledge-search-empty" style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>
                {query.trim() ? 'No matches yet.' : 'Type a query and press Enter.'}
              </p>
            : (
                <ul data-testid="knowledge-search-results"
                    style={{ listStyle: 'none', padding: 0, margin: 0 }}>
                  {results.map(r => (
                    <li key={r.id} data-testid={`knowledge-search-result-${r.id}`}
                        style={{ borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)', padding: '10px 0' }}>
                      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
                        <span style={{ fontWeight: 600, fontSize: 14 }}>{r.title}</span>
                        <span style={{ fontFamily: 'ui-monospace, monospace', fontSize: 11, color: '#475569' }}>
                          {r.doc_type} {r.score !== null && <>· score {r.score}</>}
                        </span>
                      </div>
                      <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)', marginTop: 2 }}>
                        <code>{r.doc_uri}</code>
                      </div>
                      {r.snippet && (
                        <div style={{ marginTop: 4, fontSize: 12, color: '#475569', whiteSpace: 'pre-wrap' }}>
                          {r.snippet}
                          {r.snippet && r.snippet.length >= 280 ? '…' : ''}
                        </div>
                      )}
                    </li>
                  ))}
                </ul>
              )}
        </section>
      )}

      {tab === 'entities' && (
        <section data-testid="knowledge-entities-panel"
                 style={{ display: 'grid', gridTemplateColumns: 'minmax(360px, 1fr) 2fr', gap: 16 }}>
          <div>
            <label style={{ fontSize: 12 }}>Type filter
              <input className="input" type="text" placeholder="vendor / customer / account / …"
                     value={entityType}
                     onChange={e => setEntityType(e.target.value)}
                     data-testid="knowledge-entities-type-input"
                     style={{ marginLeft: 6 }} />
            </label>

            {entities.length === 0
              ? <p data-testid="knowledge-entities-empty" style={{ color: 'var(--cf-text-secondary)', fontSize: 13, marginTop: 12 }}>
                  No entities yet — record one via the AI gateway or
                  {' '}<code>coreflux.record_knowledge</code>.
                </p>
              : (
                  <ul data-testid="knowledge-entities-list"
                      style={{ listStyle: 'none', padding: 0, margin: '12px 0 0',
                               border: '1px solid var(--cf-border)', borderRadius: 6, overflow: 'hidden' }}>
                    {entities.map(e => (
                      <li key={e.id}>
                        <button type="button"
                                onClick={() => setSelectedEntity(e.id)}
                                data-testid={`knowledge-entity-row-${e.id}`}
                                style={{
                                  display: 'block', width: '100%', textAlign: 'left',
                                  padding: '8px 10px', cursor: 'pointer', border: 'none',
                                  background: selectedEntity === e.id ? 'var(--cf-bg-selected, #eff6ff)' : 'transparent',
                                  borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)',
                                  fontSize: 12,
                                }}>
                          <span style={{ fontWeight: 600 }}>{e.label}</span>
                          {' '}<span style={{ color: '#475569' }}>· {e.entity_type}</span>
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
          </div>

          <div data-testid="knowledge-entity-detail">
            {!selectedEntity
              ? <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>
                  Select an entity to see neighbours.
                </p>
              : !neighbours
                  ? <p data-testid="knowledge-entity-detail-loading">Loading…</p>
                  : (
                      <div>
                        <header style={{ marginBottom: 10 }}>
                          <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>
                            #<code>{neighbours.entity.id}</code> · {neighbours.entity.entity_type}
                          </div>
                          <h3 data-testid="knowledge-entity-detail-label"
                              style={{ margin: '4px 0 0', fontSize: 16, fontWeight: 600 }}>
                            {neighbours.entity.label}
                          </h3>
                        </header>

                        <h4 style={{ fontSize: 12, fontWeight: 600, marginTop: 12 }}>Outgoing edges</h4>
                        <EdgeList edges={neighbours.edges_out} dir="out" />

                        <h4 style={{ fontSize: 12, fontWeight: 600, marginTop: 12 }}>Incoming edges</h4>
                        <EdgeList edges={neighbours.edges_in} dir="in" />
                      </div>
                    )}
          </div>
        </section>
      )}
    </div>
  );
}

function EdgeList({ edges, dir }) {
  if (!edges?.length) {
    return <p data-testid={`knowledge-edges-${dir}-empty`} style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>None.</p>;
  }
  return (
    <ul data-testid={`knowledge-edges-${dir}`} style={{ margin: 0, padding: 0, listStyle: 'none' }}>
      {edges.map(e => (
        <li key={e.edge_id} data-testid={`knowledge-edge-${dir}-${e.edge_id}`}
            style={{ padding: '4px 0', fontSize: 12 }}>
          <code style={{ color: '#7e22ce' }}>{e.relation}</code>
          {' → '}
          <span style={{ fontWeight: 600 }}>{e.label}</span>
          <span style={{ color: '#475569' }}> · {e.entity_type}</span>
          {e.weight !== null && <span style={{ color: '#94a3b8' }}> · w={e.weight}</span>}
        </li>
      ))}
    </ul>
  );
}
