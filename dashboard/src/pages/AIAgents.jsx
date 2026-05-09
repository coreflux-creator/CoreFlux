import React, { useState } from 'react';
import { api, useApi } from '../lib/api';
import AISuggestion from '../components/AISuggestion';
import { Sparkles, Bot, Building2, Wallet, BookOpen, FileText } from 'lucide-react';

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
  const { data, loading, error } = useApi('/api/ai_agents.php?action=list');
  const [runs, setRuns] = useState({}); // { [agentKey]: envelope }
  const [busy, setBusy] = useState({});
  const [agentErr, setAgentErr] = useState({});

  if (loading) return <p data-testid="ai-agents-loading">Loading…</p>;
  if (error)   return <p className="error" data-testid="ai-agents-error">{error.message}</p>;

  const agents = data?.agents ?? [];

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
