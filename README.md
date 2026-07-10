# Agentic

A Statamic starter kit that lets a non-developer edit the site by **talking to an AI agent**
(Claude Code or similar) — and makes it so the agent *can't break the site*: not the code, not the
schema, not production.

Instead of learning the Control Panel, your client says "add a quote from Jane to the about page"
and the agent edits the underlying content. Agentic supplies the guardrails, the validation, and the
publish flow that make that safe.

---

## How it works

Statamic content is **flat files** — YAML and Markdown under `content/`. An LLM is good at rewriting
plain text, so an agent can edit that content directly. Two things make it trustworthy:

1. The agent knows exactly what it's allowed to do, and
2. Nothing it produces can reach production without passing automated checks.

### What the agent reads

- **`content/AGENTS.md`** — the agent's brief, in plain language. It says: you are a content editor,
  not a developer; only edit content, never code; here is what you may and may not touch; run
  `content:validate` before you commit; here's how "publish" works. The person being helped never
  sees YAML, a branch, or a blueprint.
- **`content/agent-reference.md`** — an **auto-generated** catalogue of every page-builder block and
  its fields, types, and allowed options. The agent reads this before adding content, so it never
  invents a block or field that doesn't exist. It's regenerated from your fieldsets by
  `php artisan content:catalog`, so it can never drift from what the site actually supports.

### What catches mistakes

Statamic does **not** validate flat-file content when it loads — a malformed edit renders wrong or
blank, silently. That's the gap Agentic closes:

- **`php artisan content:validate`** walks every entry, term, and global against its blueprint and
  fails on anything the Control Panel would never allow: an unknown block type, an invalid select
  option, a reference to a missing image, or a block with no matching template. The agent runs it
  before every commit; CI runs it again.

### What stops it going off the rails

Guardrails at two levels — one for smooth UX, one that's the actual guarantee:

- **Local (UX):** `.claude/settings.json` deny-rules stop the agent from writing to `app/`,
  `config/`, `resources/`, dependencies, and CI at all, so it self-corrects instead of trying a
  workaround.
- **CI (the guarantee):** `.github/workflows/content-guardrails.yml` enforces, on GitHub's side:
  - client commits may touch **only** content and assets (a path allowlist keyed to the editor's git
    identity — your own commits are never restricted);
  - only the **maintainer** can land changes on the production branch;
  - every push runs `content:validate`, a catalogue-freshness check, a build, and the test suite.

  So even if the agent goes rogue or someone hand-edits a file, a code-touching or schema-invalid
  change can't land cleanly on the working branch and can't reach production.

### How changes go live

- **Day to day:** the agent commits to the `staging` branch, which redeploys to a preview URL. Many
  small edits, no ceremony — the client reviews on the preview site.
- **Publishing:** when the client says "publish" / "make it live", the agent opens **one** pull
  request from `staging` to `main` for the maintainer to review and merge. Production only ever
  changes through a reviewed PR.

### A typical exchange

> **Client:** "Add a testimonial from Jane Doe to the about page."

The agent pulls the latest `staging`, checks `content/agent-reference.md` to confirm a testimonials
block exists and what fields it takes, edits the about page's content file, runs
`content:validate`, and commits to `staging`. The client sees it on the preview URL. Later they say
"looks good, publish it" and the agent opens the PR.

---

## One source of truth: the page builder

Blocks are defined in one place — the `page_builder` fieldset — and everything else derives from it.
To add a block:

1. Add a `set` to `resources/fieldsets/page_builder.yaml`.
2. Add `resources/views/blocks/<set-handle>.antlers.html` — **the filename must equal the set
   handle**.
3. Run `php artisan content:catalog` and `php artisan content:validate`.

That's the whole contract. The agent's catalogue, the validation rules, the Control Panel, and the
front-end rendering all update automatically from the fieldset — no switch statement, no second
registry to keep in sync.

The kit ships with a single example block (`rich_text`) to demonstrate the pattern. Delete it and
add your own.

---

## Install

Create a new site from the kit:

    statamic new my-site sanderjn/statamic-agentic

…or add it to an existing Statamic project:

    php please starter-kit:install sanderjn/statamic-agentic

## Set up

After install, follow **`SETUP.md`** at the project root. It's a short, ordered checklist:
run `php artisan agentic:setup` (stamps your site name, editors, maintainer, and branches into the
docs and CI), create the GitHub repo with `staging`/`main` branches, turn on branch protection, and
point your host at the branches. Then run your agent from the project root and let
`content/AGENTS.md` guide it.

## Requirements & notes

- Statamic v6, PHP 8.3+.
- Single-locale. Multilingual sites, optional modules (SEO Pro, blog), and a richer block library are
  on the roadmap.
- Deployment is your choice (Ploi, Forge, Vapor, SSG) — Agentic sets up the repo + branches + CI, not
  the host.
