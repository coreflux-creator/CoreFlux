import React, { useEffect, useState } from 'react';
import { CheckCircle2, XCircle, Loader2, CircleSlash, GitBranch } from 'lucide-react';
import { api } from '../lib/api';

/**
 * CIStatusBadge — read-only badge showing the latest GitHub Actions run.
 *
 * Fetches /api/ci_status.php (which caches GitHub API responses 5 min on
 * the server). Renders one of four states:
 *
 *   - green  ✓  "CI green"     — conclusion === 'success'
 *   - red    ✗  "CI failing"   — conclusion === 'failure' | 'cancelled'
 *   - blue   ⟳  "CI running"   — status === 'in_progress' | 'queued'
 *   - gray   —  "CI not configured" — GITHUB_REPO env not set
 *
 * Clicking the badge opens the run in a new tab. Tooltip shows the
 * branch, workflow name, short SHA, and commit subject.
 *
 * Mount it anywhere; the CFO Dashboard header is the canonical place.
 */
export default function CIStatusBadge() {
  const [data, setData] = useState(null);
  const [err, setErr]   = useState(null);

  useEffect(() => {
    let cancelled = false;
    const load = async () => {
      try {
        const res = await api.get('/api/ci_status.php');
        if (!cancelled) setData(res);
      } catch (e) {
        if (!cancelled) setErr(e?.message || 'failed to load');
      }
    };
    load();
    // Re-poll every 5 minutes (matches server cache TTL)
    const t = setInterval(load, 5 * 60 * 1000);
    return () => { cancelled = true; clearInterval(t); };
  }, []);

  if (err) return null; // silent fail — never breaks the dashboard
  if (!data) {
    return (
      <span data-testid="ci-status-badge" data-state="loading" style={badgeStyle('#94a3b8', '#f1f5f9')}>
        <Loader2 size={12} className="cf-spin" /> CI
      </span>
    );
  }

  if (!data.configured) {
    return (
      <span data-testid="ci-status-badge" data-state="unconfigured"
            title={data.reason || 'CI badge not configured'}
            style={badgeStyle('#64748b', '#f1f5f9')}>
        <CircleSlash size={12}/> CI not configured
      </span>
    );
  }

  if (data.error) {
    return (
      <span data-testid="ci-status-badge" data-state="error"
            title={data.error + (data.hint ? '\n' + data.hint : '')}
            style={badgeStyle('#b45309', '#fef3c7')}>
        <CircleSlash size={12}/> CI unreachable
      </span>
    );
  }

  const { conclusion, status, html_url, workflow_name, branch, commit_sha, commit_msg } = data;
  const running = status === 'in_progress' || status === 'queued';

  let label, fg, bg, Icon;
  if (running) {
    label = 'CI running';
    fg = '#1d4ed8'; bg = '#dbeafe';
    Icon = Loader2;
  } else if (conclusion === 'success') {
    label = 'CI green';
    fg = '#15803d'; bg = '#dcfce7';
    Icon = CheckCircle2;
  } else if (conclusion === 'failure' || conclusion === 'cancelled' || conclusion === 'timed_out') {
    label = conclusion === 'failure' ? 'CI failing' : `CI ${conclusion}`;
    fg = '#b91c1c'; bg = '#fee2e2';
    Icon = XCircle;
  } else {
    // skipped, neutral, action_required, stale, null
    label = `CI ${conclusion || 'unknown'}`;
    fg = '#64748b'; bg = '#f1f5f9';
    Icon = CircleSlash;
  }

  const tooltip = [
    workflow_name,
    branch ? `branch: ${branch}` : null,
    commit_sha ? `${commit_sha} — ${(commit_msg || '').slice(0, 60)}` : null,
  ].filter(Boolean).join('\n');

  const inner = (
    <>
      <Icon size={12} className={running ? 'cf-spin' : ''}/>
      {label}
      {branch && (
        <span style={{ display:'inline-flex', alignItems:'center', gap:2, opacity:0.7, marginLeft:4, fontWeight:400 }}>
          <GitBranch size={10}/> {branch}
        </span>
      )}
    </>
  );

  if (html_url) {
    return (
      <a href={html_url} target="_blank" rel="noopener noreferrer"
         data-testid="ci-status-badge" data-state={running ? 'running' : conclusion}
         data-conclusion={conclusion || ''}
         title={tooltip}
         style={{ ...badgeStyle(fg, bg), textDecoration:'none' }}>
        {inner}
      </a>
    );
  }
  return (
    <span data-testid="ci-status-badge" data-state={running ? 'running' : conclusion}
          title={tooltip} style={badgeStyle(fg, bg)}>
      {inner}
    </span>
  );
}

function badgeStyle(fg, bg) {
  return {
    display:'inline-flex',
    alignItems:'center',
    gap:6,
    padding:'4px 10px',
    borderRadius:999,
    fontSize:12,
    fontWeight:600,
    color: fg,
    background: bg,
    border: `1px solid ${fg}33`,
    whiteSpace:'nowrap',
    cursor:'inherit',
  };
}
