import React, { useState } from 'react';
import { api, useApi } from '../lib/api';
import AISuggestion from '../components/AISuggestion';
import { Sparkles, Bot, Building2, Wallet, BookOpen, FileText, Mail, CalendarClock } from 'lucide-react';

/**
 * AI Agents — Sprint 7g.
 * Five purpose-built advisory agents. Each runs on demand and produces a
 * narrative the operator reviews via the standard <AISuggestion /> control.
 */
const AGENT_ICONS = {
  bookkeeper:       BookOpen,
  reconciliation:   FileText,
  treasury_analyst: Wallet,
  cfo:              Building2,
  tax:              Bot,
};

export default function AIAgents() {
  const { data, loading, error, reload } = useApi('/api/ai_agents.php?action=list');
  const [runs, setRuns] = useState({}); // { [agentKey]: envelope }
  const [busy, setBusy] = useState({});
  const [agentErr, setAgentErr] = useState({});
  const [digestNote, setDigestNote] = useState(null);

  if (loading) return <p data-testid="ai-agents-loading">Loading…</p>;
  if (error)   return <p className="error" data-testid="ai-agents-error">{error.message}</p>;

  const agents = data?.agents ?? [];
  const digest = data?.digest ?? { enabled: false, recipients: null, send_dow: 1, last_sent_at: null };

  const runAgent = async (key) => {
    setBusy(b => ({ ...b, [key]: true }));
    setAgentErr(e => ({ ...e, [key]: null }));
    try {
      const r = await api.post(`/api/ai_agents.php?action=run&agent=${encodeURIComponent(key)}`);
      setRuns(s => ({ ...s, [key]: r.envelope }));
    } catch (e) {
      setAgentErr(s => ({ ...s, [key]: e.message }));
    } finally {
      setBusy(b => ({ ...b, [key]: false }));
    }
  };

  const setMode = async (key, mode) => {
    setBusy(b => ({ ...b, [`mode-${key}`]: true }));
    try {
      await api.post('/api/ai_agents.php?action=mode_set', { agent: key, mode });
      if (reload) reload();
    } catch (e) {
      setAgentErr(s => ({ ...s, [key]: e.message }));
    } finally {
      setBusy(b => ({ ...b, [`mode-${key}`]: false }));
    }
  };

  const setDigest = async (patch) => {
    setBusy(b => ({ ...b, digest: true }));
    setDigestNote(null);
    try {
      await api.post('/api/ai_agents.php?action=digest_settings_set', patch);
      if (reload) reload();
    } catch (e) {
      setDigestNote({ kind: 'error', text: e.message });
    } finally {
      setBusy(b => ({ ...b, digest: false }));
    }
  };

  const sendDigestNow = async () => {
    setBusy(b => ({ ...b, sendDigest: true }));
    setDigestNote(null);
    try {
      const r = await api.post('/api/ai_agents.php?action=digest_send_now');
      setDigestNote({ kind: 'ok', text: `Digest sent to ${(r.recipients || []).join(', ')}.` });
      if (reload) reload();
    } catch (e) {
      setDigestNote({ kind: 'error', text: e.message });
    } finally {
      setBusy(b => ({ ...b, sendDigest: false }));
    }
  };

  return (
    <section data-testid="ai-agents-page" style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
      <header>
        <h2 style={{ margin: 0, display: 'flex', alignItems: 'center', gap: 8 }}>
          <Sparkles size={20} color="#7c3aed" /> AI Agents
        </h2>
        <p style={{ color: '#64748b', margin: '4px 0 0', fontSize: 13 }}>
          Each agent reads qualitative signals from your books and produces an advisory narrative for human review. Nothing posts, nothing decides — they recommend, you accept or edit.
        </p>
      </header>

      {/* Digest controls — Slice 3. On-demand "Email me the digest now" plus
          weekly schedule toggle. Recipients fall back to tenant master_admin. */}
      <div data-testid="ai-agents-digest-card"
           style={{ padding: 16, background: 'linear-gradient(135deg,#f5f3ff,#ecfeff)',
                    border: '1px solid #c4b5fd', borderRadius: 12 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
          <Mail size={16} color="#5b21b6" />
          <strong style={{ fontSize: 14, color: '#5b21b6' }}>Weekly digest</strong>
        </div>
        <p style={{ color: '#64748b', fontSize: 12, margin: '0 0 12px' }}>
          One email stitching all five agents into a single narrative. Send it to yourself on demand, or schedule a weekly auto-send.
        </p>

        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, alignItems: 'center' }}>
          <button data-testid="ai-agents-digest-send-now"
                  onClick={sendDigestNow} disabled={busy.sendDigest}
                  className="btn btn--primary"
                  style={{ fontSize: 12, background: '#7c3aed', color: '#fff' }}>
            <Mail size={12} style={{ marginRight: 4, verticalAlign: 'middle' }} />
            {busy.sendDigest ? 'Sending…' : 'Email me a digest now'}
          </button>

          <label data-testid="ai-agents-digest-enabled-label"
                 style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 13, color: '#1e293b' }}>
            <input type="checkbox"
                   data-testid="ai-agents-digest-enabled"
                   checked={!!digest.enabled}
                   disabled={busy.digest}
                   onChange={e => setDigest({ enabled: e.target.checked })} />
            Auto-send weekly
          </label>

          {digest.enabled && (
            <select data-testid="ai-agents-digest-dow"
                    value={digest.send_dow} disabled={busy.digest}
                    onChange={e => setDigest({ send_dow: parseInt(e.target.value, 10) })}
                    className="input" style={{ fontSize: 12, padding: '4px 8px' }}>
              <option value={1}>Mondays</option>
              <option value={2}>Tuesdays</option>
              <option value={3}>Wednesdays</option>
              <option value={4}>Thursdays</option>
              <option value={5}>Fridays</option>
              <option value={6}>Saturdays</option>
              <option value={7}>Sundays</option>
            </select>
          )}

          <input data-testid="ai-agents-digest-recipients"
                 type="text" placeholder="recipient@you.com (default: master admin)"
                 defaultValue={digest.recipients || ''}
                 onBlur={e => {
                   const v = e.target.value.trim();
                   if (v !== (digest.recipients || '')) setDigest({ recipients: v });
                 }}
                 className="input"
                 style={{ flex: 1, minWidth: 220, fontSize: 12, padding: '4px 8px' }} />
        </div>

        {digest.last_sent_at && (
          <p data-testid="ai-agents-digest-last-sent"
             style={{ fontSize: 11, color: '#64748b', margin: '8px 0 0', display: 'flex', alignItems: 'center', gap: 4 }}>
            <CalendarClock size={11} /> Last sent: {digest.last_sent_at}
          </p>
        )}
        {digest.last_send_error && (
          <p data-testid="ai-agents-digest-last-error" style={{ fontSize: 11, color: '#b91c1c', margin: '4px 0 0' }}>
            Last error: {digest.last_send_error}
          </p>
        )}
        {digestNote && (
          <p data-testid="ai-agents-digest-note"
             style={{ fontSize: 12, margin: '8px 0 0', color: digestNote.kind === 'ok' ? '#065f46' : '#b91c1c' }}>
            {digestNote.text}
          </p>
        )}
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(360px,1fr))', gap: 16 }}>
        {agents.map(agent => {
          const Icon = AGENT_ICONS[agent.key] || Sparkles;
          return (
            <div key={agent.key}
                 data-testid={`ai-agents-card-${agent.key}`}
                 style={{ padding: 18, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12, display: 'flex', flexDirection: 'column', gap: 12 }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <div style={{ width: 38, height: 38, borderRadius: 10, background: '#f5f3ff', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                  <Icon size={18} color="#7c3aed" />
                </div>
                <div>
                  <strong style={{ fontSize: 14 }}>{agent.label}</strong>
                  <div style={{ fontSize: 12, color: '#64748b' }}>{agent.description}</div>
                </div>
              </div>

              <button data-testid={`ai-agents-run-${agent.key}`}
                      onClick={() => runAgent(agent.key)}
                      disabled={busy[agent.key]}
                      className="btn btn--primary"
                      style={{ alignSelf: 'flex-start', fontSize: 12 }}>
                <Sparkles size={12} style={{ marginRight: 4, verticalAlign: 'middle' }} />
                {busy[agent.key] ? 'Running…' : (runs[agent.key] ? 'Re-run' : 'Run agent')}
              </button>

              <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 11, color: '#64748b' }}>
                Mode:
                <select data-testid={`ai-agents-mode-${agent.key}`}
                        value={agent.mode || 'advisory'}
                        disabled={busy[`mode-${agent.key}`]}
                        onChange={e => setMode(agent.key, e.target.value)}
                        className="input"
                        style={{ fontSize: 11, padding: '2px 6px' }}>
                  <option value="advisory">Advisory (review each)</option>
                  <option value="auto_log">Auto-log (file without review)</option>
                </select>
              </label>

              {agentErr[agent.key] && (
                <p data-testid={`ai-agents-err-${agent.key}`} className="error" style={{ fontSize: 12 }}>
                  {agentErr[agent.key]}
                </p>
              )}

              {runs[agent.key] && (
                <div data-testid={`ai-agents-result-${agent.key}`}>
                  <AISuggestion envelope={runs[agent.key]}
                                featureKey={agent.feature_key}
                                editable={true} />
                </div>
              )}
            </div>
          );
        })}
      </div>
    </section>
  );
}
