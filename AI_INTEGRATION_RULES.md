# CoreFlux AI Integration Rules

**Read this before writing any AI-touching code. The rules below are non-negotiable and enforced by the platform.**

## The Hard Rule

> **AI produces advisory narrative for humans. AI never produces values, formulas, decisions, or tasks that the application consumes.**

Every AI output is **human-review-gated**. If your feature needs a number the
business logic will use — a tax rate, an amount, an hour count, a GL entry
figure, a task to auto-execute — the application's deterministic code must
produce that number. AI may describe it, explain it, or suggest what the
human should investigate, but the system of record never reads a value
straight out of an LLM response.

## What AI can do in CoreFlux

| Allowed | Example |
|---|---|
| Summarize deterministic data | "This pay period had 14 employees paid across 3 departments." |
| Narrate / explain | "Headcount is up compared to last quarter; the largest increase is in engineering." |
| Draft text a human will edit | Employee comms, job descriptions, journal entry memos |
| Classify with a label | "Transaction category: travel — routine business trip pattern." |
| Ask clarifying questions | "Do you want to include contractor time in the next export?" |
| Flag anomalies for review | "Three timesheets exceeded 60 hours — worth a second look." |

## What AI must never do

| Forbidden | Why |
|---|---|
| Output a number the system stores or calculates with | Deterministic logic owns values, period. |
| Output a formula or algorithm the system executes | Same — and audit would be impossible. |
| Output JSON/structured fields for business logic | The envelope enforces narrative-only output. |
| Auto-execute tasks (create records, run payroll, send email) | Humans commit actions after reviewing the draft. |
| Be called directly from a module (bypassing `aiAsk`) | One chokepoint = one policy = one audit trail. |

## How to use AI correctly

### 1. Backend — call `aiAsk()` (never the sidecar directly)

```php
require_once __DIR__ . '/../../../core/ai_service.php';
$ctx = api_require_auth();

$envelope = aiAsk([
    'feature_class' => 'summary',                      // model routing + per-feature toggle
    'kind'          => 'summary',
    'feature_key'   => 'payroll.pay_period_summary',   // audit + toggle identifier
    'system'        => 'You are a payroll domain assistant.',
    'prompt'        => 'Summarize the pay period for pay_run_id=' . $payRunId,
    'context'       => $deterministic_facts,           // send DATA; it comes back as TEXT
]);
api_ok(['ai' => $envelope]);
```

You never parse `$envelope['content']` for numbers. If you need numbers back
out, compute them yourself and let the AI describe them.

### 2. Frontend — render with `<AISuggestion />` (never raw string rendering)

```jsx
import AISuggestion from '../../components/AISuggestion';

<AISuggestion
  envelope={aiEnvelope}
  featureKey="payroll.pay_period_summary"
  subjectType="pay_run"
  subjectId={payRunId}
  onAccepted={(finalText, suggestionId) => refreshPayRun()}
/>
```

The component:
- Displays the "AI draft · human review required" badge
- Shows an Edit control so the human can revise wording
- Sends Accept / Reject to `/core/api/ai_suggestions.php` and persists the
  human's decision as the source of truth
- Emits `data-testid` hooks on every interactive element for testing

### 3. Feature classes → models (all configurable in `/app/backend/.env`)

| `feature_class` | Default model | Intended use |
|---|---|---|
| `summary` | `gpt-5.4-mini` | High-volume, low-stakes summaries (dashboards, "what's new") |
| `narrative` | `gpt-5.4` | Variance analysis, anomaly explanations, coaching |
| `draft` | `gpt-5.4` | Drafts a human will edit + send (comms, JDs, journal memos) |
| `classification` | `gpt-5.4-mini` | Single-label + short rationale |
| `deep_reasoning` | `gpt-5.4-thinking` | Compliance, multi-month root-causing, rare complex cases |

### 4. Feature-class toggles per tenant

Every call is gated by:
- `tenants.ai_enabled` (master toggle per tenant)
- `ai_tenant_features.enabled` for the feature class (default ON when tenant is on)

When disabled, `aiAsk()` throws `AIDisabledException` — your module must
handle this gracefully (render a non-AI fallback, hide the suggestion UI).

## The commit workflow

```
┌────────┐   aiAsk()   ┌─────────┐   <AISuggestion />   ┌────────┐
│ module ├────────────►│ sidecar ├─────────────────────►│ human  │
└────────┘             └─────────┘                      └───┬────┘
                                                            │
                                        accept / edit /     │
                                             reject         │
                                                            ▼
                                                    ┌───────────────┐
                                                    │ai_suggestions │  ← source of truth
                                                    └───────┬───────┘
                                                            │
                                           deterministic logic
                                           reads final_content
                                           (never draft_content)
```

Rule of thumb: **`draft_content` is AI. `final_content` is human.**
Your module code only ever reads `final_content` and only after
`status = 'approved'`.

## Audit & observability

Every `aiAsk()` call writes a row to `ai_interactions`:
- **Always:** tenant, user, feature_key, kind, status, model, latency, prompt+response hashes
- **Only if tenant has `ai_full_content_logging=1`:** full prompt and response text

Failures and contract-violation rejections are logged the same way. This
gives you a provable boundary between "AI said" and "human decided".

## Common mistakes to avoid

```php
// ❌ NEVER — parsing AI output for a value
$envelope = aiAsk([...]);
$guess = (int) preg_match('/\$(\d+)/', $envelope['content'], $m);
updatePayroll($guess);   // this is exactly what the rule forbids

// ❌ NEVER — passing AI output as a calculation input
$envelope = aiAsk([...]);
$total = $baseline * floatval($envelope['content']);   // no

// ❌ NEVER — calling the sidecar directly
$body = curl_get(AI_SIDECAR_URL, [...]);               // bypasses gating + audit

// ❌ NEVER — rendering AI text without the review component
<div>{aiEnvelope.content}</div>                        // missing badge, disclaimer, review

// ✅ ALWAYS — advisory display with review controls
<AISuggestion envelope={aiEnvelope} featureKey="x.y" />
```

## When the rule feels in the way

If you catch yourself designing "the AI will return the right answer and we'll
just use it" — stop. The pattern you want is:
1. Compute the answer deterministically.
2. Ask the AI to *explain* that answer.
3. Show the explanation through `<AISuggestion />` so the human can accept the
   wording.

That's the shape every CoreFlux AI feature follows.
