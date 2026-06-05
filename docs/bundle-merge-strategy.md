# Build-bundle merge strategy

## Why merges kept conflicting

CoreFlux deploys by committing the **pre-built** React bundle and serving it
directly (`spa.php` serves the newest `spa-assets/index-*.js`; `update.php` runs
`git pull --ff-only` on the server — it never runs `yarn build`). That keeps the
production host (Cloudways / droplet) free of a Node build toolchain.

The tradeoff: every `yarn --cwd dashboard build` regenerates content-hashed
files and rewrites a few tracked paths:

- `spa-assets/**` (new `index-<hash>.js` / `.css`)
- `index.html` (points at the new hash)
- `.deploy-version` (`expected_bundle:` block, stamped by `scripts/sync_bundle.sh`)
- `dashboard/dist/**`, `app/assets/**`, `dashboard/assets/**`

When two branches both rebuild, they collide on these generated paths → merge
conflicts on artifacts no human should hand-edit.

## The fix (Option 1: release-only bundle)

1. **`.gitattributes`** marks the generated paths `merge=ours`.
2. **`scripts/setup-git.sh`** registers the `ours` merge driver (`driver = true`)
   in each clone's git config.

Result: during a **local** `git merge`, the generated files auto-resolve to the
current branch's copy instead of conflicting.

### Release flow

Treat the bundle as a release artifact — rebuild it on `main`, not on every
feature branch:

```bash
# on a clean main, after merging feature work:
git checkout main && git pull --ff-only
yarn --cwd dashboard build      # regenerates spa-assets/ + stamps .deploy-version
git add spa-assets index.html .deploy-version dashboard/dist app/assets dashboard/assets
git commit -m "build: refresh dashboard bundle"
```

### One-time setup per clone

```bash
bash scripts/setup-git.sh
```

## Limitations

- Merge drivers run only on **local** merges. GitHub's web-UI "Resolve
  conflicts" and PR auto-merge **ignore** them. To benefit, pull the PR branch
  locally and merge there (the artifact conflicts then auto-resolve), then push.
- The bundle stays committed on purpose — do **not** untrack `spa-assets/`
  unless the deploy host is changed to build the SPA itself (`update.php` +
  Cloudways would need Node/Yarn and a multi-minute build per deploy).
