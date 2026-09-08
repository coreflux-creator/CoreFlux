import React, { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  AlertCircle, ChevronLeft, Clock3, KeyRound, Link2,
  RefreshCw, Search, ShieldCheck, Unlink, Unplug, UserPlus, UsersRound, X,
} from 'lucide-react';
import { api, useApi } from '../lib/api';

const STATE_META = {
  available: { label: 'Available', color: '#047857', bg: '#d1fae5' },
  not_in_plan: { label: 'Plan restricted', color: '#92400e', bg: '#fef3c7' },
  not_authorized: { label: 'Not authorized', color: '#92400e', bg: '#fef3c7' },
  rate_limited: { label: 'Rate limited', color: '#9a3412', bg: '#ffedd5' },
  error: { label: 'Error', color: '#991b1b', bg: '#fee2e2' },
};

const MATCH_META = {
  exact: { label: 'Exact ID', color: '#047857', bg: '#d1fae5' },
  suggested: { label: 'Review match', color: '#1d4ed8', bg: '#dbeafe' },
  ambiguous: { label: 'Ambiguous', color: '#92400e', bg: '#fef3c7' },
  unmatched: { label: 'Unmatched', color: '#991b1b', bg: '#fee2e2' },
};

function Badge({ meta }) {
  return (
    <span style={{
      display: 'inline-flex', alignItems: 'center', minHeight: 22,
      padding: '2px 8px', borderRadius: 4, fontSize: 12, fontWeight: 600,
      color: meta.color, background: meta.bg, whiteSpace: 'nowrap',
    }}>
      {meta.label}
    </span>
  );
}

function Notice({ kind = 'info', children }) {
  const danger = kind === 'error';
  return (
    <div role={danger ? 'alert' : 'status'} style={{
      display: 'flex', alignItems: 'flex-start', gap: 8, padding: '10px 12px',
      border: `1px solid ${danger ? '#fecaca' : '#bfdbfe'}`,
      background: danger ? '#fef2f2' : '#eff6ff',
      color: danger ? '#991b1b' : '#1e3a8a', borderRadius: 6,
    }}>
      {danger ? <AlertCircle size={17} /> : <ShieldCheck size={17} />}
      <span>{children}</span>
    </div>
  );
}

function SummaryCell({ label, value, tone = 'default' }) {
  const color = tone === 'good' ? '#047857' : tone === 'warn' ? '#92400e' : tone === 'bad' ? '#991b1b' : 'var(--cf-text-primary)';
  return (
    <div style={{ minWidth: 130 }}>
      <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)', marginBottom: 3 }}>{label}</div>
      <div style={{ fontSize: 22, lineHeight: 1.2, fontWeight: 700, color }}>{value}</div>
    </div>
  );
}

function CapabilitiesTable({ probe }) {
  const rows = Object.values(probe?.capabilities || {});
  const inventory = probe?.inventory || {};
  if (!rows.length) return null;
  return (
    <div style={{ overflowX: 'auto', border: '1px solid var(--cf-border)', borderRadius: 6 }}>
      <table style={{ width: '100%', minWidth: 900, borderCollapse: 'collapse' }} data-testid="connecteam-capabilities-table">
        <thead>
          <tr style={{ background: 'var(--cf-surface-subtle, #f8fafc)' }}>
            {['FEATURE', 'ACCESS', 'VISIBLE', 'COREFLUX DESTINATION', 'DIRECTION'].map(label => (
              <th key={label} style={{ padding: '10px 12px', textAlign: 'left', fontSize: 12, color: 'var(--cf-text-secondary)', borderBottom: '1px solid var(--cf-border)' }}>{label}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map(row => {
            const meta = STATE_META[row.state] || STATE_META.error;
            const inv = inventory[row.key];
            const count = inv?.reported_total ?? inv?.visible_count;
            return (
              <tr key={row.key}>
                <td style={cellStyle}>
                  <div style={{ fontWeight: 600 }}>{row.label}</div>
                  <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>{row.group}</div>
                </td>
                <td style={cellStyle} title={row.detail || ''}><Badge meta={meta} /></td>
                <td style={cellStyle}>{row.state === 'available' ? (count ?? 0) : '—'}</td>
                <td style={cellStyle}>{row.destination}</td>
                <td style={cellStyle}>{row.direction}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

const cellStyle = {
  padding: '10px 12px', borderBottom: '1px solid var(--cf-border)',
  verticalAlign: 'top', fontSize: 13,
};

function InventorySamples({ probe }) {
  const sections = Object.entries(probe?.inventory || {}).filter(([, item]) => item?.sample?.length);
  if (!sections.length) return null;
  return (
    <details style={{ marginTop: 12 }}>
      <summary style={{ cursor: 'pointer', fontWeight: 600 }}>Inventory sample</summary>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: 12, marginTop: 12 }}>
        {sections.map(([key, item]) => (
          <div key={key} style={{ borderLeft: '3px solid #93c5fd', padding: '4px 10px' }}>
            <div style={{ fontSize: 12, fontWeight: 700, textTransform: 'uppercase', color: 'var(--cf-text-secondary)', marginBottom: 6 }}>
              {key.replaceAll('_', ' ')}
            </div>
            {item.sample.map((sample, index) => (
              <div key={`${sample.id || index}`} style={{ fontSize: 13, marginBottom: 5, overflowWrap: 'anywhere' }}>
                <strong>{sample.name || sample.id || 'Unnamed'}</strong>
                {sample.code ? ` · ${sample.code}` : ''}
                {sample.email ? <div style={{ color: 'var(--cf-text-secondary)' }}>{sample.email}</div> : null}
              </div>
            ))}
          </div>
        ))}
      </div>
    </details>
  );
}

function identityLabel(identity) {
  const source = String(identity?.source || '').replaceAll('_', ' ');
  return `${source || 'source'}: ${identity?.external_id || '—'}`;
}

function PersonResolver({ row, busy, onLink, onUnlink, onCreate }) {
  const [mode, setMode] = useState('');
  const [query, setQuery] = useState('');
  const [results, setResults] = useState([]);
  const [selectedId, setSelectedId] = useState(null);
  const [searching, setSearching] = useState(false);
  const [localError, setLocalError] = useState('');
  const [draft, setDraft] = useState({
    first_name: row.source_first_name || '',
    last_name: row.source_last_name || '',
    email_primary: row.source_email || '',
    phone_primary: row.source_phone || '',
    classification: '',
  });

  useEffect(() => {
    if (mode !== 'search') return undefined;
    const trimmed = query.trim();
    if (trimmed.length < 2 && !/^P?-?\d+$/i.test(trimmed)) {
      setResults([]);
      return undefined;
    }
    let active = true;
    const timer = window.setTimeout(async () => {
      setSearching(true);
      setLocalError('');
      try {
        const response = await api.get(`/api/connecteam/people_search.php?action=people_search&q=${encodeURIComponent(trimmed)}`);
        if (active) setResults(response.people || []);
      } catch (error) {
        if (active) setLocalError(error.message || 'Could not search the People directory.');
      } finally {
        if (active) setSearching(false);
      }
    }, 250);
    return () => { active = false; window.clearTimeout(timer); };
  }, [mode, query]);

  const openSearch = () => {
    setMode('search');
    setQuery(row.source_email || row.source_name || '');
    setSelectedId(row.coreflux_id || null);
    setLocalError('');
  };

  const chosen = results.find(person => Number(person.id) === Number(selectedId));
  const commitLink = async (personId) => {
    setLocalError('');
    const ok = await onLink(row, Number(personId));
    if (ok) setMode('');
  };

  if (!mode) {
    if (row.state === 'exact') {
      return (
        <button className="btn btn-secondary" type="button" disabled={busy} onClick={async () => {
          if (!window.confirm(`Remove the Connecteam identity link for ${row.source_name || row.source_id}? The CoreFlux person will not be deleted.`)) return;
          await onUnlink(row);
        }} title="Remove only this Connecteam identity link">
          <Unlink size={15} /> Unlink
        </button>
      );
    }
    return (
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
        {row.state === 'suggested' && row.coreflux_id ? (
          <button className="btn btn-primary" type="button" disabled={busy} onClick={() => commitLink(row.coreflux_id)}>
            <Link2 size={15} /> Link P-{row.coreflux_id}
          </button>
        ) : null}
        <button className="btn btn-secondary" type="button" disabled={busy} onClick={openSearch}>
          <Search size={15} /> {row.coreflux_id ? 'Choose another' : 'Find person'}
        </button>
        <button className="btn btn-secondary" type="button" disabled={busy} onClick={() => setMode('create')}>
          <UserPlus size={15} /> Create person
        </button>
      </div>
    );
  }

  if (mode === 'create') {
    return (
      <div data-testid={`connecteam-create-person-${row.source_id}`} style={{ display: 'grid', gap: 7, minWidth: 360 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <strong>Create and link</strong>
          <button type="button" className="btn btn-secondary" onClick={() => setMode('')} title="Close"><X size={15} /></button>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 7 }}>
          <input aria-label="First name" placeholder="First name" value={draft.first_name} onChange={event => setDraft({ ...draft, first_name: event.target.value })} />
          <input aria-label="Last name" placeholder="Last name" value={draft.last_name} onChange={event => setDraft({ ...draft, last_name: event.target.value })} />
        </div>
        <input aria-label="Email" type="email" placeholder="Email" value={draft.email_primary} onChange={event => setDraft({ ...draft, email_primary: event.target.value })} />
        <input aria-label="Phone" type="tel" placeholder="Phone (optional)" value={draft.phone_primary} onChange={event => setDraft({ ...draft, phone_primary: event.target.value })} />
        <select aria-label="Worker classification" value={draft.classification} onChange={event => setDraft({ ...draft, classification: event.target.value })}>
          <option value="">Choose worker classification</option>
          <option value="w2">W-2 employee</option>
          <option value="1099">1099 contractor</option>
          <option value="c2c">C2C contractor</option>
          <option value="temp">Temporary worker</option>
          <option value="perm">Permanent placement</option>
          <option value="candidate">Candidate</option>
        </select>
        <button className="btn btn-primary" type="button" disabled={busy || !draft.classification} onClick={async () => {
          const ok = await onCreate(row, draft);
          if (ok) setMode('');
        }}>
          <UserPlus size={15} /> Create and link
        </button>
      </div>
    );
  }

  return (
    <div data-testid={`connecteam-person-search-${row.source_id}`} style={{ display: 'grid', gap: 7, minWidth: 380 }}>
      <div style={{ display: 'flex', gap: 6 }}>
        <div style={{ position: 'relative', flex: 1 }}>
          <Search size={15} style={{ position: 'absolute', left: 9, top: 10, color: 'var(--cf-text-secondary)' }} />
          <input aria-label="Find CoreFlux person" value={query} onChange={event => setQuery(event.target.value)} placeholder="Name, email, phone, or P-ID" style={{ width: '100%', paddingLeft: 30 }} />
        </div>
        <button type="button" className="btn btn-secondary" onClick={() => setMode('')} title="Close"><X size={15} /></button>
      </div>
      {localError ? <span style={{ color: '#991b1b', fontSize: 12 }}>{localError}</span> : null}
      {searching ? <span style={{ color: 'var(--cf-text-secondary)', fontSize: 12 }}>Searching…</span> : null}
      {!searching && query.trim().length >= 2 && results.length === 0 ? (
        <span style={{ color: 'var(--cf-text-secondary)', fontSize: 12 }}>No CoreFlux people found.</span>
      ) : null}
      {results.length ? (
        <div style={{ maxHeight: 190, overflowY: 'auto', border: '1px solid var(--cf-border)', borderRadius: 4 }}>
          {results.map(person => (
            <button key={person.id} type="button" onClick={() => setSelectedId(person.id)} style={{
              display: 'block', width: '100%', padding: '8px 10px', textAlign: 'left', border: 0,
              borderBottom: '1px solid var(--cf-border)', cursor: 'pointer', letterSpacing: 0,
              background: Number(selectedId) === Number(person.id) ? '#eff6ff' : 'white',
            }}>
              <strong>P-{person.id} · {person.name}</strong>
              <div style={{ marginTop: 2, color: 'var(--cf-text-secondary)', fontSize: 12 }}>{person.email_primary || person.email_secondary || 'No email'} · {person.classification}</div>
              {person.identities?.length ? <div style={{ marginTop: 2, color: '#475569', fontSize: 11 }}>{person.identities.map(identityLabel).join(' · ')}</div> : null}
            </button>
          ))}
        </div>
      ) : null}
      {chosen ? (
        <button className="btn btn-primary" type="button" disabled={busy} onClick={() => commitLink(chosen.id)}>
          <Link2 size={15} /> Link to P-{chosen.id}
        </button>
      ) : null}
    </div>
  );
}

function ReconciliationSection({ title, icon: Icon, result, kind, busy, onLink, onUnlink, onCreate }) {
  if (!result) return null;
  const counts = result.counts || {};
  const isPeople = kind === 'person';
  const headers = ['CONNECTEAM', 'RESULT', 'COREFLUX', 'BASIS'];
  if (isPeople) headers.push('ACTION');
  return (
    <section style={{ marginTop: 24 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
        <Icon size={18} />
        <h3 style={{ margin: 0, fontSize: 16 }}>{title}</h3>
        <span style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>{result.source_count} Connecteam · {result.coreflux_count} CoreFlux</span>
      </div>
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 24, marginBottom: 12 }}>
        <SummaryCell label="Exact IDs" value={counts.exact || 0} tone="good" />
        <SummaryCell label="Review matches" value={counts.suggested || 0} />
        <SummaryCell label="Ambiguous" value={counts.ambiguous || 0} tone="warn" />
        <SummaryCell label="Unmatched" value={counts.unmatched || 0} tone="bad" />
      </div>
      <div style={{ overflowX: 'auto', border: '1px solid var(--cf-border)', borderRadius: 6 }}>
        <table style={{ width: '100%', minWidth: isPeople ? 1120 : 760, borderCollapse: 'collapse' }}>
          <thead>
            <tr style={{ background: 'var(--cf-surface-subtle, #f8fafc)' }}>
              {headers.map(label => (
                <th key={label} style={{ padding: '9px 12px', textAlign: 'left', fontSize: 12, color: 'var(--cf-text-secondary)', borderBottom: '1px solid var(--cf-border)' }}>{label}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {(result.rows || []).map((row, index) => (
              <tr key={`${row.source_id}-${index}`}>
                <td style={cellStyle}>
                  <div style={{ fontWeight: 600 }}>{row.source_name || 'Unnamed'}</div>
                  <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>{isPeople ? `User ID ${row.source_id}` : (row.source_code || row.source_id)}</div>
                  {isPeople && row.source_email ? <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>{row.source_email}</div> : null}
                </td>
                <td style={cellStyle}><Badge meta={MATCH_META[row.state] || MATCH_META.unmatched} /></td>
                <td style={cellStyle}>{row.coreflux_name || '—'}{row.coreflux_id ? ` · ${title === 'People' ? 'P' : 'PL'}-${row.coreflux_id}` : ''}</td>
                <td style={cellStyle}>{row.method}</td>
                {isPeople ? (
                  <td style={{ ...cellStyle, width: 430 }}>
                    <PersonResolver row={row} busy={busy} onLink={onLink} onUnlink={onUnlink} onCreate={onCreate} />
                  </td>
                ) : null}
              </tr>
            ))}
            {!result.rows?.length ? (
              <tr><td colSpan={headers.length} style={{ ...cellStyle, textAlign: 'center', color: 'var(--cf-text-secondary)' }}>No rows returned.</td></tr>
            ) : null}
          </tbody>
        </table>
      </div>
    </section>
  );
}

export default function ConnecteamSettings({ session }) {
  const { data, error, loading, reload } = useApi('/api/connecteam/status.php?action=status');
  const [apiKey, setApiKey] = useState('');
  const [region, setRegion] = useState('us');
  const [busy, setBusy] = useState('');
  const [message, setMessage] = useState('');
  const [failure, setFailure] = useState('');
  const [liveProbe, setLiveProbe] = useState(null);
  const [preview, setPreview] = useState(null);

  useEffect(() => {
    if (data?.region) setRegion(data.region);
  }, [data?.region]);

  const probe = liveProbe || data?.probe;
  const connected = Boolean(data?.connected);
  const summary = probe?.summary;
  const accountName = probe?.account?.name || data?.account?.name || 'Connecteam account';
  const capabilityGroups = useMemo(() => Object.values(probe?.capabilities || {}).length, [probe]);
  const tenantContext = preview?.tenant_context || data?.tenant_context;
  const canPreview = probe?.capabilities?.users?.state === 'available'
    && probe?.capabilities?.jobs?.state === 'available';

  const clearMessages = () => { setMessage(''); setFailure(''); };

  const connect = async (event) => {
    event.preventDefault(); clearMessages(); setPreview(null);
    if (!apiKey.trim()) { setFailure('Enter a Connecteam API key.'); return; }
    setBusy('connect');
    try {
      const result = await api.post('/api/connecteam/connect.php?action=connect', { api_key: apiKey.trim(), region });
      setLiveProbe(result.probe);
      setApiKey('');
      setMessage(`Connected to ${result.probe?.account?.name || 'Connecteam'}. ${result.probe?.summary?.available || 0} features are available.`);
      await reload();
    } catch (e) {
      setFailure(e.message || 'Connecteam connection failed.');
    } finally { setBusy(''); }
  };

  const refreshProbe = async () => {
    clearMessages(); setBusy('probe');
    try {
      const result = await api.post('/api/connecteam/probe.php?action=probe', {});
      setLiveProbe(result.probe);
      setMessage(`Capability scan complete: ${result.probe?.summary?.available || 0} available, ${result.probe?.summary?.restricted || 0} restricted.`);
      await reload();
    } catch (e) { setFailure(e.message || 'Capability scan failed.'); }
    finally { setBusy(''); }
  };

  const runPreview = async () => {
    clearMessages(); setBusy('preview');
    try {
      const result = await api.post('/api/connecteam/preview.php?action=preview', {});
      setPreview(result.preview);
      setMessage('Dry run complete. No workforce or accounting records were changed.');
    } catch (e) { setFailure(e.message || 'Reconciliation preview failed.'); }
    finally { setBusy(''); }
  };

  const refreshPreviewAfterIdentityChange = async (successMessage) => {
    const result = await api.post('/api/connecteam/preview.php?action=preview', {});
    setPreview(result.preview);
    setMessage(successMessage);
  };

  const linkPerson = async (row, personId) => {
    clearMessages(); setBusy(`link:${row.source_id}`);
    try {
      await api.post('/api/connecteam/link_person.php?action=link_person', {
        source_user_id: row.source_id,
        person_id: personId,
      });
      await refreshPreviewAfterIdentityChange(`Linked ${row.source_name || 'Connecteam user'} to CoreFlux P-${personId}.`);
      return true;
    } catch (error) {
      setFailure(error.message || 'Could not save the Connecteam identity link.');
      return false;
    } finally { setBusy(''); }
  };

  const unlinkPerson = async (row) => {
    clearMessages(); setBusy(`unlink:${row.source_id}`);
    try {
      await api.post('/api/connecteam/unlink_person.php?action=unlink_person', { source_user_id: row.source_id });
      await refreshPreviewAfterIdentityChange(`Removed the Connecteam identity link for ${row.source_name || row.source_id}.`);
      return true;
    } catch (error) {
      setFailure(error.message || 'Could not remove the Connecteam identity link.');
      return false;
    } finally { setBusy(''); }
  };

  const createPerson = async (row, fields) => {
    clearMessages(); setBusy(`create:${row.source_id}`);
    try {
      const result = await api.post('/api/connecteam/create_person.php?action=create_person', {
        source_user_id: row.source_id,
        ...fields,
      });
      await refreshPreviewAfterIdentityChange(`Created ${result.person?.name || row.source_name} as CoreFlux P-${result.person?.id} and linked the Connecteam identity.`);
      return true;
    } catch (error) {
      setFailure(error.message || 'Could not create the CoreFlux person.');
      return false;
    } finally { setBusy(''); }
  };

  const disconnect = async () => {
    if (!window.confirm('Disconnect Connecteam and remove the stored API key? Discovery history will remain available in the audit log.')) return;
    clearMessages(); setBusy('disconnect');
    try {
      await api.post('/api/connecteam/disconnect.php?action=disconnect', {});
      setLiveProbe(null); setPreview(null); setMessage('Connecteam disconnected.');
      await reload();
    } catch (e) { setFailure(e.message || 'Disconnect failed.'); }
    finally { setBusy(''); }
  };

  return (
    <div data-testid="connecteam-settings" style={{ maxWidth: 1500 }}>
      <Link to="/admin/integrations" style={{ display: 'inline-flex', alignItems: 'center', gap: 4, marginBottom: 16, textDecoration: 'none' }}>
        <ChevronLeft size={16} /> Integrations
      </Link>
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16, marginBottom: 20 }}>
        <div>
          <h1 style={{ margin: 0, fontSize: 'var(--cf-text-2xl)', letterSpacing: 0 }}>Connecteam</h1>
          <p style={{ margin: '6px 0 0', color: 'var(--cf-text-secondary)' }}>Workforce identity, scheduling, approved time, leave, and operating policies.</p>
        </div>
        {connected ? <Badge meta={{ label: data?.status === 'error' ? 'Needs attention' : 'Connected', color: data?.status === 'error' ? '#991b1b' : '#047857', bg: data?.status === 'error' ? '#fee2e2' : '#d1fae5' }} /> : null}
      </div>

      {error ? <Notice kind="error">{error.message}</Notice> : null}
      {failure ? <Notice kind="error">{failure}</Notice> : null}
      {message ? <Notice>{message}</Notice> : null}
      {data?.migration_required ? <Notice kind="error">{data.message}</Notice> : null}
      {tenantContext ? (
        <div data-testid="connecteam-tenant-context" style={{ marginTop: 12, padding: '10px 12px', border: '1px solid var(--cf-border)', borderRadius: 6, background: 'var(--cf-surface-subtle, #f8fafc)', fontSize: 13 }}>
          <strong>CoreFlux workspace:</strong> {tenantContext.workspace?.name} (#{tenantContext.workspace?.id})
          <span style={{ margin: '0 8px', color: 'var(--cf-text-secondary)' }}>·</span>
          <strong>People directory used for matching:</strong> {tenantContext.people_catalog?.name} (#{tenantContext.people_catalog?.id})
          {session?.tenant_id && Number(session.tenant_id) !== Number(tenantContext.workspace?.id) ? (
            <span style={{ display: 'block', marginTop: 6, color: '#b91c1c', fontWeight: 600 }}>
              Workspace mismatch detected. Reload this page before running reconciliation.
            </span>
          ) : null}
        </div>
      ) : null}

      {!connected && !loading ? (
        <section data-testid="connecteam-not-connected" style={{ marginTop: 20, borderTop: '1px solid var(--cf-border)', paddingTop: 20 }}>
          <h2 style={{ fontSize: 17, margin: '0 0 14px' }}>Connect account</h2>
          <form onSubmit={connect} style={{ display: 'grid', gridTemplateColumns: 'minmax(280px, 1fr) 180px auto', alignItems: 'end', gap: 12, maxWidth: 900 }}>
            <label style={{ display: 'grid', gap: 6 }}>
              <span style={{ fontSize: 13, fontWeight: 600 }}>API key</span>
              <div style={{ position: 'relative' }}>
                <KeyRound size={16} style={{ position: 'absolute', left: 10, top: 11, color: 'var(--cf-text-secondary)' }} />
                <input data-testid="connecteam-api-key-input" type="password" value={apiKey} onChange={e => setApiKey(e.target.value)} autoComplete="new-password" style={{ width: '100%', height: 38, padding: '0 10px 0 34px' }} />
              </div>
            </label>
            <label style={{ display: 'grid', gap: 6 }}>
              <span style={{ fontSize: 13, fontWeight: 600 }}>Data region</span>
              <select data-testid="connecteam-region-select" value={region} onChange={e => setRegion(e.target.value)} style={{ height: 38 }}>
                <option value="us">United States</option>
                <option value="au">Australia</option>
              </select>
            </label>
            <button data-testid="connecteam-connect-btn" className="btn btn-primary" type="submit" disabled={busy === 'connect'} style={{ height: 38 }}>
              <Search size={16} /> {busy === 'connect' ? 'Inspecting…' : 'Connect and inspect'}
            </button>
          </form>
          <p style={{ maxWidth: 900, marginTop: 12, fontSize: 13, color: 'var(--cf-text-secondary)' }}>The key is encrypted at rest. This connection starts in discovery mode and cannot import or change workforce records.</p>
        </section>
      ) : null}

      {connected ? (
        <div data-testid="connecteam-connected" style={{ marginTop: 20 }}>
          <section style={{ borderTop: '1px solid var(--cf-border)', paddingTop: 18 }}>
            <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
              <div>
                <h2 style={{ margin: 0, fontSize: 18 }}>{accountName}</h2>
                <div style={{ marginTop: 4, color: 'var(--cf-text-secondary)', fontSize: 13 }}>
                  {data?.region === 'au' ? 'Australia' : 'United States'} region · key ending {data?.api_key_last4 || '—'} · last checked {data?.last_probe_at || '—'}
                </div>
              </div>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                <button data-testid="connecteam-probe-btn" className="btn btn-secondary" onClick={refreshProbe} disabled={Boolean(busy)}>
                  <RefreshCw size={16} /> {busy === 'probe' ? 'Checking…' : 'Refresh capabilities'}
                </button>
                <button data-testid="connecteam-preview-btn" className="btn btn-primary" onClick={runPreview} disabled={Boolean(busy) || !canPreview}>
                  <Search size={16} /> {busy === 'preview' ? 'Comparing…' : 'Run reconciliation dry run'}
                </button>
                <button data-testid="connecteam-disconnect-btn" className="btn btn-secondary" onClick={disconnect} disabled={Boolean(busy)} title="Disconnect Connecteam">
                  <Unplug size={16} /> Disconnect
                </button>
              </div>
            </div>
            {data?.last_probe_error ? <div style={{ marginTop: 10 }}><Notice kind="error">{data.last_probe_error}</Notice></div> : null}
          </section>

          {summary ? (
            <section style={{ marginTop: 24 }}>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 34, marginBottom: 14 }}>
                <SummaryCell label="Available features" value={summary.available} tone="good" />
                <SummaryCell label="Plan restricted" value={summary.restricted} tone={summary.restricted ? 'warn' : 'default'} />
                <SummaryCell label="Probe errors" value={summary.errors} tone={summary.errors ? 'bad' : 'default'} />
                <SummaryCell label="Capabilities checked" value={capabilityGroups} />
              </div>
              <CapabilitiesTable probe={probe} />
              <InventorySamples probe={probe} />
            </section>
          ) : null}

          <section style={{ marginTop: 28, borderTop: '1px solid var(--cf-border)', paddingTop: 20 }} data-testid="connecteam-reconciliation">
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <ShieldCheck size={18} color="#047857" />
              <h2 style={{ margin: 0, fontSize: 18 }}>Identity and placement reconciliation</h2>
            </div>
            <p style={{ margin: '7px 0 0', color: 'var(--cf-text-secondary)', maxWidth: 1100 }}>CoreFlux P-ID is the canonical person. JobDiva candidate IDs, Connecteam user IDs, and other source identities can all link to the same P-ID without replacing one another. Email, phone, name, and title matches remain review-only until you approve them.</p>
            {preview ? (
              <>
                <ReconciliationSection
                  title="People" icon={UsersRound} result={preview.people} kind="person"
                  busy={Boolean(busy)} onLink={linkPerson} onUnlink={unlinkPerson} onCreate={createPerson}
                />
                <ReconciliationSection title="Placement jobs" icon={Clock3} result={preview.jobs} kind="placement" busy={Boolean(busy)} />
              </>
            ) : (
              <div style={{ marginTop: 16, padding: '20px 0', color: 'var(--cf-text-secondary)' }}>No reconciliation preview has been run.</div>
            )}
          </section>
        </div>
      ) : null}
    </div>
  );
}
