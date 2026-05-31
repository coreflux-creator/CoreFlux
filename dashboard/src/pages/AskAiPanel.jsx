import React, { useState, useCallback } from 'react';
import { api } from '../lib/api';

/**
 * <AskAiPanel /> — Ask-AI shell for the AI Tool Gateway.
 *
 * Two modes:
 *   • LLM mode (Slice 2 default) — type natural-language intent, send
 *     it to the gateway as `{agent, intent}`. The gateway hands the
 *     request to the configured LLM (OpenAI) with the full tool
 *     registry as function specs and loops until the model returns a
 *     final assistant answer. Renders assistant_text + every tool
 *     call the planner made.
 *
 *   • Deterministic mode (Slice 1 fallback) — pick a tool, hand-fill
 *     args, single dispatch. Useful for debugging the tool itself.
 *
 * Visibility: admin-only / feature-flagged during Slice 1 was a
 * deliberate gate. Slice 2 keeps the admin mount so we can validate
 * the LLM loop in isolation. Lifting the gate to `ai.use` is a
 * separate config decision.
 *
 * Spec §15: Ask AI Panel + Suggested Actions Drawer + Confidence /
 * Reasoning Summary all eventually mount inside this component.
 */
export default function AskAiPanel({ agent = 'orchestrator', defaultTool = 'coreflux.get_tenant_context' }) {
  const [mode, setMode]         = useState('llm');         // 'llm' | 'tool'
  const [intent, setIntent]     = useState('');
  const [toolName, setToolName] = useState(defaultTool);
  const [toolArgs, setToolArgs] = useState('{}');
  const [run, setRun]           = useState(null);
  const [busy, setBusy]         = useState(false);
  const [error, setError]       = useState(null);

  const submit = useCallback(async (e) => {
    e?.preventDefault?.();
    setBusy(true); setError(null); setRun(null);

    let body;
    if (mode === 'llm') {
      if (!intent.trim()) { setError('please describe what you need'); setBusy(false); return; }
      body = { agent, intent, mode: 'llm' };
    } else {
      let args = {};
      try { args = JSON.parse(toolArgs || '{}'); }
      catch { setError('args must be valid JSON'); setBusy(false); return; }
      body = { agent, input_summary: intent, tools: toolName ? [{ name: toolName, args }] : [] };
    }

    try {
      const r = await api.post('/api/ai/runs.php', body);
      setRun(r);
    } catch (err) { setError(err.message || 'Run failed'); }
    finally { setBusy(false); }
  }, [mode, agent, intent, toolName, toolArgs]);

  return (
    <section data-testid="ask-ai-panel" style={{ padding: 'var(--cf-space-3, 1rem)', maxWidth: 760 }}>
      <header style={{ marginBottom: 16 }}>
        <h2 style={{ margin: 0, fontSize: 20, fontWeight: 600 }}>
          Ask AI
          <span style={{ fontSize: 12, color: '#166534', background: '#dcfce7', padding: '2px 6px', borderRadius: 4, marginLeft: 8 }}>
            Slice 2 · LLM planner live
          </span>
        </h2>
        <p style={{ margin: '4px 0 0', fontSize: 13, color: '#64748b' }}>
          Describe what you need in plain language — the orchestrator LLM picks the
          right tools, calls them with your permissions, and returns the answer.
          Every run lands in <code>/admin/ai-gateway</code> with full audit.
        </p>
      </header>

      {error && <div className="error" data-testid="ask-ai-error" style={{ marginBottom: 12 }}>{error}</div>}

      <div role="tablist" style={{ display: 'flex', gap: 4, marginBottom: 12 }} data-testid="ask-ai-mode-tabs">
        <button type="button"
                className={`btn ${mode === 'llm' ? 'btn--primary' : 'btn--ghost'}`}
                onClick={() => setMode('llm')}
                data-testid="ask-ai-mode-llm"
                style={{ fontSize: 12 }}>
          LLM planner
        </button>
        <button type="button"
                className={`btn ${mode === 'tool' ? 'btn--primary' : 'btn--ghost'}`}
                onClick={() => setMode('tool')}
                data-testid="ask-ai-mode-tool"
                style={{ fontSize: 12 }}>
          Deterministic tool
        </button>
      </div>

      <form onSubmit={submit} style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
        {mode === 'llm' ? (
          <label style={{ fontSize: 12, fontWeight: 600 }}>
            What do you need?
            <textarea className="input" value={intent}
                      onChange={(e) => setIntent(e.target.value)}
                      placeholder="e.g. show me Plaid + Mercury bank activity from the last week, and flag anything over $5,000"
                      data-testid="ask-ai-input"
                      rows={3}
                      style={{ display: 'block', width: '100%', marginTop: 4, fontSize: 13 }} />
          </label>
        ) : (
          <>
            <label style={{ fontSize: 12, fontWeight: 600 }}>
              input_summary (logged for audit)
              <input className="input" value={intent}
                     onChange={(e) => setIntent(e.target.value)}
                     placeholder="e.g. read recent bank transactions"
                     data-testid="ask-ai-input"
                     style={{ display: 'block', width: '100%', marginTop: 4 }} />
            </label>
            <label style={{ fontSize: 12, fontWeight: 600 }}>
              Tool
              <select className="input" value={toolName}
                      onChange={(e) => setToolName(e.target.value)}
                      data-testid="ask-ai-tool"
                      style={{ display: 'block', width: '100%', marginTop: 4 }}>
                <option value="coreflux.get_tenant_context">coreflux.get_tenant_context</option>
                <option value="coreflux.get_user_permissions">coreflux.get_user_permissions</option>
                <option value="coreflux.get_bank_transactions">coreflux.get_bank_transactions</option>
                <option value="coreflux.list_tools">coreflux.list_tools</option>
                <option value="coreflux.list_outbox">coreflux.list_outbox</option>
              </select>
            </label>
            <label style={{ fontSize: 12, fontWeight: 600 }}>
              Args (JSON)
              <textarea className="input" value={toolArgs}
                        onChange={(e) => setToolArgs(e.target.value)}
                        rows={3}
                        data-testid="ask-ai-args"
                        style={{ display: 'block', width: '100%', marginTop: 4, fontFamily: 'ui-monospace, monospace', fontSize: 12 }} />
            </label>
          </>
        )}
        <button type="submit" className="btn btn--primary"
                disabled={busy}
                data-testid="ask-ai-submit"
                style={{ alignSelf: 'flex-start' }}>
          {busy ? 'Running…' : (mode === 'llm' ? 'Ask AI' : 'Run tool')}
        </button>
      </form>

      {run && (
        <div data-testid="ask-ai-result" style={{ marginTop: 16 }}>
          <p style={{ fontSize: 13, margin: '0 0 4px' }}>
            Run <code>{run.ai_run_id}</code> · <strong>{run.status}</strong>
            {run.turns !== undefined && <> · {run.turns} LLM turns</>}
            {run.usage?.total_tokens > 0 && <> · {run.usage.total_tokens} tokens</>}
            {run.model && <> · {run.model}</>}
          </p>
          {run.assistant_text && (
            <div data-testid="ask-ai-assistant-text"
                 style={{ background: '#f0fdf4', padding: 12, borderRadius: 6,
                          fontSize: 13, lineHeight: 1.55, whiteSpace: 'pre-wrap',
                          marginBottom: 12 }}>
              {run.assistant_text}
            </div>
          )}
          <details>
            <summary style={{ cursor: 'pointer', fontSize: 12, fontWeight: 600, color: '#64748b' }}>
              Tool calls ({(run.tool_calls || []).length})
            </summary>
            <pre data-testid="ask-ai-result-json"
                 style={{ background: '#f8fafc', padding: 8, borderRadius: 4,
                          fontSize: 11, fontFamily: 'ui-monospace, monospace',
                          maxHeight: 360, overflow: 'auto' }}>
              {JSON.stringify(run.tool_calls, null, 2)}
            </pre>
          </details>
        </div>
      )}
    </section>
  );
}
