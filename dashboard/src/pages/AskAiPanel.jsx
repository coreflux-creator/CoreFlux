import React, { useState, useCallback } from 'react';
import { api } from '../lib/api';

/**
 * <AskAiPanel /> — minimal Ask-AI shell for the AI Tool Gateway
 * (Slice 1).
 *
 * Spec §15 calls for a contextual Ask AI panel inside every module.
 * Slice 1 ships only the *shell*: it creates an ai_runs row, invokes
 * a single read-only tool by name (no LLM planner yet), and renders
 * the structured envelope back to the user. Slice 2 wires the real
 * model and replaces this deterministic dispatch with the LLM's
 * planned tool calls.
 *
 * Visibility (Slice 1): admin-only / feature-flagged. Mount this
 * inside the admin shell. When Slice 2 lands we'll lift the gate via
 * `ai.use`.
 *
 * Props:
 *   agent      — string, defaults to 'orchestrator'.
 *   defaultTool — string, defaults to 'coreflux.get_tenant_context'.
 *
 * The panel exposes data-testids on every interactive element for
 * the custom PHP smoke suite.
 */
export default function AskAiPanel({ agent = 'orchestrator', defaultTool = 'coreflux.get_tenant_context' }) {
  const [intent, setIntent]     = useState('');
  const [toolName, setToolName] = useState(defaultTool);
  const [toolArgs, setToolArgs] = useState('{}');
  const [run, setRun]           = useState(null);
  const [busy, setBusy]         = useState(false);
  const [error, setError]       = useState(null);

  const submit = useCallback(async (e) => {
    e?.preventDefault?.();
    setBusy(true); setError(null); setRun(null);
    let args = {};
    try { args = JSON.parse(toolArgs || '{}'); }
    catch { setError('args must be valid JSON'); setBusy(false); return; }
    try {
      const r = await api.post('/api/ai/runs.php', {
        agent,
        input_summary: intent,
        tools: toolName ? [{ name: toolName, args }] : [],
      });
      setRun(r);
    } catch (err) { setError(err.message || 'Run failed'); }
    finally { setBusy(false); }
  }, [agent, intent, toolName, toolArgs]);

  return (
    <section data-testid="ask-ai-panel" style={{ padding: 'var(--cf-space-3, 1rem)', maxWidth: 760 }}>
      <header style={{ marginBottom: 16 }}>
        <h2 style={{ margin: 0, fontSize: 20, fontWeight: 600 }}>Ask AI <span style={{ fontSize: 12, color: '#92400e', background: '#fffbeb', padding: '2px 6px', borderRadius: 4, marginLeft: 8 }}>Slice 1 · plumbing only</span></h2>
        <p style={{ margin: '4px 0 0', fontSize: 13, color: '#64748b' }}>
          Sends a deterministic tool call through the gateway. The LLM planner ships in Slice 2.
          Every run lands in <code>/admin/ai-gateway</code> with full audit.
        </p>
      </header>

      {error && <div className="error" data-testid="ask-ai-error" style={{ marginBottom: 12 }}>{error}</div>}

      <form onSubmit={submit} style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
        <label style={{ fontSize: 12, fontWeight: 600 }}>
          What do you need? (input_summary)
          <input className="input" value={intent}
                 onChange={(e) => setIntent(e.target.value)}
                 placeholder="e.g. show me recent bank transactions"
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
        <button type="submit" className="btn btn--primary"
                disabled={busy}
                data-testid="ask-ai-submit"
                style={{ alignSelf: 'flex-start' }}>
          {busy ? 'Running…' : 'Run'}
        </button>
      </form>

      {run && (
        <div data-testid="ask-ai-result" style={{ marginTop: 16 }}>
          <p style={{ fontSize: 13, margin: '0 0 4px' }}>
            Run <code>{run.ai_run_id}</code> · <strong>{run.status}</strong>
          </p>
          <pre data-testid="ask-ai-result-json"
               style={{ background: '#f8fafc', padding: 8, borderRadius: 4,
                        fontSize: 11, fontFamily: 'ui-monospace, monospace',
                        maxHeight: 360, overflow: 'auto' }}>
            {JSON.stringify(run.tool_calls, null, 2)}
          </pre>
        </div>
      )}
    </section>
  );
}
