# Content Agent

A Statamic **starter kit** (published as `sanderjn/statamic-content-agent`) that makes a Statamic site safely editable by an AI content-editing agent. This repo is the *kit source*, not a runnable site — the shippable files live under `export/` and get copied into a real Statamic project on install. There is no full Laravel/Statamic app here, so `php artisan …` commands do not run in this repo; they run in an installed target project.

## Stack
- Statamic v6, PHP 8.3+ (single-locale).
- Frontend build: Vite 8, Tailwind CSS 4 (`@tailwindcss/vite`), laravel-vite-plugin 3. No JS framework.
- No composer runtime deps — root `composer.json` is only kit metadata.

## Commands (npm scripts in `export/package.json`, run in an installed site)
- `npm run dev` / `npm run watch` — Vite dev server.
- `npm run build` — production asset build.
- Artisan commands the kit ships (`export/app/Console/Commands/`): `agentic:setup`, `content:catalog` (regenerates `content/agent-reference.md` from fieldsets), `content:validate` (validates flat-file content against blueprints), `assets:compress`.

There is no test runner or linter defined in this repo's manifests.

## Where things live (all under `export/`)
- `app/Console/Commands/` — the four artisan commands above; the real logic of the kit.
- `resources/fieldsets/page_builder.yaml` — **single source of truth** for page-builder blocks; catalog, validation, CP, and rendering all derive from it.
- `resources/views/blocks/<set-handle>.antlers.html` — one partial per block; **filename must equal the set handle**. Ships one example block `rich_text`.
- `config/agentic.php` — wiring (fieldset name, paths, branch topology `staging`/`main`).
- `content/AGENTS.md` — the content-agent brief; `content/agent-reference.md` — auto-generated block catalogue.
- `.claude/settings.json` — deny-rules blocking agent writes to `app/`, `config/`, `resources/`, CI, manifests.
- `.github/workflows/content-guardrails.yml` — CI enforcing content-only client commits + maintainer-only `main`.

`starter-kit.yaml` lists exported paths; `SETUP.md` (also exported) is the install checklist. Root `README.md` explains the whole design.

## Conventions
- To add a block: add a `set` to `page_builder.yaml`, add the matching `blocks/<handle>.antlers.html`, then run `content:catalog` + `content:validate`. No switch statement or second registry.
- Branch topology is literal `staging` (work) → `main` (release); changing names means updating both `config/agentic.php` and the CI workflow.
