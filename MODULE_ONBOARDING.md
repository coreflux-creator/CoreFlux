# CoreFlux Module Onboarding — 30-Minute Quickstart

Ship your first CoreFlux module end-to-end. This is the hands-on companion to
[`MODULE_SKELETON.md`](./MODULE_SKELETON.md) — follow the steps in order, you'll
have a working module wired to the SPA, database, and sidebar in half an hour.

> ⚠️ **If your module uses AI, also read [`AI_INTEGRATION_RULES.md`](./AI_INTEGRATION_RULES.md) before coding.**
> Hard rule: AI outputs advisory narrative for humans, never values the app
> computes with. Use `aiAsk()` (backend) and `<AISuggestion />` (frontend); a
> skeleton AI feature example is included in Step 9 below.

We'll build a toy **Notes** module (a tenant-scoped list of notes) to
demonstrate every primitive. Swap `notes` for your module's id when building
the real thing.

---

## 0. Prerequisites (2 min)

- You can run the repo locally or on Cloudways
- MySQL access (credentials in `core/config.php`)
- Node 18+ for the Vite SPA build (`cd dashboard && yarn`)
- On Git: create a feature branch

```bash
git checkout main
git pull
git checkout -b feature/notes
```

---

## 1. Scaffold from the template (1 min)

```bash
cp -r modules/_template modules/notes
```

You now have:
```
modules/notes/
├── manifest.php
├── api/records.php
├── migrations/001_init.sql
└── ui/TemplateModule.jsx
```

---

## 2. Fill the manifest (2 min)

Edit `modules/notes/manifest.php`:

```php
return [
    'id'          => 'notes',
    'name'        => 'Notes',
    'icon'        => '/assets/icons/icon-notes.png',
    'description' => 'Tenant-scoped notes',
    'version'     => '0.1.0',
    'actions' => [
        ['name' => 'Overview', 'route' => 'overview', 'permission' => 'notes.view'],
        ['name' => 'All Notes', 'route' => 'list',    'permission' => 'notes.view'],
    ],
    'permissions' => ['notes.view', 'notes.manage'],
    'default_roles' => ['master_admin', 'tenant_admin', 'admin'],
];
```

---

## 3. Write the migration (3 min)

Rename + edit `modules/notes/migrations/001_init.sql`:

```sql
CREATE TABLE IF NOT EXISTS notes_entries (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT UNSIGNED NOT NULL,
    title       VARCHAR(255) NOT NULL,
    body        TEXT,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_notes_tenant (tenant_id),
    INDEX idx_notes_tenant_title (tenant_id, title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Run it against the DB (manual for now):

```bash
mysql -h $DB_HOST -u $DB_USER -p$DB_PASS $DB_NAME < modules/notes/migrations/001_init.sql
```

> Rule: `tenant_id` is always NOT NULL and always the first column indexed.

---

## 4. Build the API (5 min)

Rename `modules/notes/api/records.php` → `modules/notes/api/entries.php`:

```php
<?php
require_once __DIR__ . '/../../../core/api_bootstrap.php';

$ctx = api_require_auth();

switch (api_method()) {
    case 'GET': {
        $rows = scopedQuery(
            'SELECT id, title, body, created_at
             FROM notes_entries
             WHERE tenant_id = :tenant_id
             ORDER BY id DESC'
        );
        api_ok(['entries' => $rows]);
    }
    case 'POST': {
        $body = api_json_body();
        api_require_fields($body, ['title']);
        $id = scopedInsert('notes_entries', [
            'title' => $body['title'],
            'body'  => $body['body'] ?? null,
        ]);
        api_ok(['id' => $id], 201);
    }
    case 'DELETE': {
        $id = (int) (api_query('id') ?? 0);
        if (!$id) api_error('Missing id', 422);
        scopedDelete('notes_entries', $id);
        api_ok(['ok' => true]);
    }
}
api_error('Method not allowed', 405);
```

**What you did NOT have to write:** session init, auth guards, tenant lookup,
JSON parsing, SQL-injection-safe identifier handling, error response shaping,
CORS, OPTIONS preflight. The bootstrap owns all of it.

---

## 5. Build the React view (5 min)

Rename `modules/notes/ui/TemplateModule.jsx` → `modules/notes/ui/NotesModule.jsx`:

```jsx
import React, { useState } from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';

function NotesList() {
  const { data, loading, error, reload } = useApi('/modules/notes/api/entries.php');
  const [title, setTitle] = useState('');

  const add = async (e) => {
    e.preventDefault();
    if (!title.trim()) return;
    await api.post('/modules/notes/api/entries.php', { title });
    setTitle('');
    reload();
  };

  const remove = async (id) => {
    await api.delete(`/modules/notes/api/entries.php?id=${id}`);
    reload();
  };

  if (loading) return <p>Loading…</p>;
  if (error)   return <p>Error: {error.message}</p>;

  return (
    <div className="module-view">
      <h2>Notes</h2>
      <form onSubmit={add}>
        <input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="New note title" />
        <button type="submit">Add</button>
      </form>
      <ul>
        {(data?.entries ?? []).map((n) => (
          <li key={n.id}>
            {n.title} <button onClick={() => remove(n.id)}>delete</button>
          </li>
        ))}
      </ul>
    </div>
  );
}

export default function NotesModule() {
  return (
    <Routes>
      <Route index element={<Navigate to="overview" replace />} />
      <Route path="overview" element={<NotesList />} />
      <Route path="list"     element={<NotesList />} />
    </Routes>
  );
}
```

**What you did NOT have to write:** fetch wrapper, credentials handling, JSON
parsing, error normalization, loading state boilerplate. `api` + `useApi` own it.

---

## 6. Register the module (3 min, temporary until auto-discovery ships)

Two edits in core — these go away once the manifest auto-loader lands.

**a) Navigation** — add to `core/modules.php` → `getModuleDefinitions()`:

```php
'notes' => [
    'id'          => 'notes',
    'name'        => 'Notes',
    'icon'        => '/assets/icons/icon-notes.png',
    'description' => 'Tenant-scoped notes',
    'actions' => [
        ['name' => 'Overview',  'route' => 'overview', 'permission' => 'notes.view'],
        ['name' => 'All Notes', 'route' => 'list',     'permission' => 'notes.view'],
    ],
],
```

And add `'notes'` to the `tenant_admin` / `admin` entries in `getUserModules()`.

**b) React route** — in `dashboard/src/App.jsx`:

```jsx
import NotesModule from '../../modules/notes/ui/NotesModule';
// ...
<Route path="/modules/notes/*" element={<NotesModule session={session} />} />
```

**c) Icon** — drop a 24×24 PNG at `public_html/assets/icons/icon-notes.png`
(or copy the template icon as a placeholder).

---

## 7. Build + verify (3 min)

```bash
cd dashboard && yarn build && cd ..
# copy build output wherever spa.php serves from (e.g. /app/spa-assets/)
```

Smoke test the backend helpers from CLI:

```bash
php -d zend.assertions=1 tests/core_platform_smoke.php
```

Then in the browser:
1. Log in → you land on the SPA
2. Sidebar now lists **Notes**
3. Click **Notes → Overview** → the list page loads (empty)
4. Add a note → it appears → refresh the page → still there (DB round-trip OK)
5. Delete → it disappears
6. Log in as a user from a **different tenant** → their list is independent
   (tenant isolation works automatically)

---

## 8. Commit + ship (2 min)

```bash
git add modules/notes dashboard/src core/modules.php
git commit -m "feat(notes): initial notes module MVP"
git push -u origin feature/notes
```

Open a PR into `main`. Done.

---

## 9. (Optional) Add an AI feature (5 min)

Suppose we want a one-paragraph "What's in my notes this week?" summary on the
Notes overview. Follow the rule: AI describes, human accepts, deterministic
code only reads approved text.

**Backend** — add `modules/notes/api/ai_summary.php`:

```php
<?php
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/ai_service.php';

$ctx = api_require_auth();

// Gather deterministic facts from YOUR DB. Send data to AI; get narrative back.
$recent = scopedQuery(
    'SELECT title, created_at FROM notes_entries
     WHERE tenant_id = :tenant_id AND created_at >= (NOW() - INTERVAL 7 DAY)
     ORDER BY created_at DESC LIMIT 20'
);

try {
    $envelope = aiAsk([
        'feature_class' => 'summary',
        'kind'          => 'summary',
        'feature_key'   => 'notes.weekly_summary',
        'system'        => 'You are a brief workspace assistant.',
        'prompt'        => 'Summarize the themes across these recent notes for a busy reader.',
        'context'       => ['notes' => $recent],
    ]);
    api_ok(['ai' => $envelope]);
} catch (AIDisabledException $e) {
    api_ok(['ai' => null]);                       // graceful fallback
}
```

**Frontend** — in `NotesModule.jsx`:

```jsx
import AISuggestion from '../../../dashboard/src/components/AISuggestion';
import { api } from '../../../dashboard/src/lib/api';

const [aiEnvelope, setAi] = useState(null);
const loadSummary = async () => {
  const res = await api.post('/modules/notes/api/ai_summary.php');
  setAi(res.ai);
};

return (
  <>
    <button onClick={loadSummary}>Summarize my week</button>
    {aiEnvelope && (
      <AISuggestion
        envelope={aiEnvelope}
        featureKey="notes.weekly_summary"
        subjectType="notes_week"
      />
    )}
  </>
);
```

**That's it.** The draft is badged, editable, human-reviewed, audited, and
contract-locked. You did not write any LLM plumbing, auth, audit, or review
UI — the platform owns all of it.

---

## What you just exercised

| Layer          | Primitive used                                       | File                           |
|----------------|------------------------------------------------------|--------------------------------|
| Request entry  | `api_require_auth`, `api_method`, `api_json_body`    | `core/api_bootstrap.php`       |
| Persistence    | `scopedQuery`, `scopedInsert`, `scopedDelete`        | `core/tenant_scope.php`        |
| Frontend HTTP  | `api.get/post/delete`, `useApi`                      | `dashboard/src/lib/api.js`     |
| Module shape   | manifest + `api/` + `migrations/` + `ui/`            | `modules/_template/`           |

If you had to reinvent any of those in your module, **stop and use the primitive instead.**
If a primitive is missing something you need, upgrade the primitive — don't work around it.

---

## Common gotchas

- **"No tenant context" 400** → the user has no active tenant. Either log in
  through a tenant, or pass `api_require_auth(false)` for endpoints that are
  legitimately tenant-less (rare).
- **`401` on `/session.php`** → SPA will fall back to demo mode; you're not
  logged in to PHP. Check cookies + same-origin.
- **Queries returning zero rows that should have data** → you forgot
  `WHERE tenant_id = :tenant_id` in the SQL you passed to `scopedQuery`.
  The helper binds the param but does not rewrite your SQL.
- **`InvalidArgumentException: Unsafe SQL identifier`** → table/column name
  failed the allowlist. Use `snake_case` ASCII only.
- **Built SPA doesn't see your new route** → did you rebuild (`yarn build`) and
  redeploy the `spa-assets/`? `App.jsx` is compiled into the bundle.

---

## When to reach for what's not here yet

Some conveniences are deliberately deferred until the first real module
validates the shape. If you hit a need, flag it:

- Manifest auto-discovery (dropping `core/modules.php` edits)
- Migration runner (dropping manual `mysql <` steps)
- `can($perm)` enforcing manifest permissions
- `React.lazy()` loading of module UI

Each one gets tracked in `memory/PRD.md` → Backlog (P2).

---

*Ship the module. Iterate on the platform after.*
