# Agentic

A Statamic starter kit that lets a non-developer edit the site by **talking to an AI agent**
(Claude Code or similar), and makes it so the agent *can't break the site*: not the code, not the
schema, not production.

For a one-line tweak, the Control Panel is already a fine editing experience. Agentic isn't trying to
replace it there. Where it earns its keep is **volume and structure**: redoing a whole page,
duplicating a page and adapting it, drafting a batch of blog posts, running a site-wide tone pass.
Your client says "here are three new team members, add them all to the team page in our usual tone"
and the agent does the repetitive work across the underlying content. Agentic supplies the guardrails,
the validation, and the publish flow that make that safe.

---

## How it works

Statamic content is **flat files**: YAML and Markdown under `content/`. An LLM is good at rewriting
plain text, so an agent can edit that content directly. Two things make it trustworthy:

1. The agent knows exactly what it's allowed to do, and
2. Nothing it produces can reach production without passing automated checks.

### What the agent reads

- **`content/AGENTS.md`**: the agent's brief, in plain language. It says: you are a content editor,
  not a developer; only edit content, never code; here is what you may and may not touch; run
  `content:validate` before you commit; here's how "publish" works. The person being helped never
  sees YAML, a branch, or a blueprint.
- **`content/agent-reference.md`**: an **auto-generated** catalogue of every page-builder block and
  its fields, types, and allowed options. The agent reads this before adding content, so it never
  invents a block or field that doesn't exist. It's regenerated from your fieldsets by
  `php artisan content:catalog`, so it can never drift from what the site actually supports.
- **`content/editor-notes.md`**: the editor's **own** space for tone of voice, writing do's and
  don'ts, recurring page structures, and sign-off. The first time an editor works with the agent it offers to fill
  this in (a short interview, or run `/setup` in Claude Code), then reads it before every edit so the
  copy always sounds the way they want. Unlike the brief and the catalogue, this file *is* editable by
  the agent: it's the client's preferences, not the rules. The agent can never rewrite its own brief.

### What catches mistakes

Statamic does **not** validate flat-file content when it loads: a malformed edit renders wrong or
blank, silently. That's the gap Agentic closes:

- **`php artisan content:validate`** walks every entry, term, and global against its blueprint and
  fails on anything the Control Panel would never allow: a missing required field, a value past its
  length limit, an unknown block type, an invalid select option, a reference to a missing image, or a
  block with no matching template. (It runs the blueprint's real validation rules, exactly as a
  Control Panel save would.) The agent runs it before every commit; CI runs it again.

### What stops it going off the rails

![A man in a 90s office, on the phone, looking worried](art/client-on-phone.jpg)

*Your client, ten minutes after you gave them full repo access and an agent without guardrails.
Agentic exists so this call never happens.*

Guardrails at two levels, one for smooth UX and one that's the actual guarantee:

- **Local (UX):** a Claude Code **PreToolUse hook** runs every edit through the *same* path-allowlist
  script CI uses. If the agent tries to write outside content (`app/`, `config/`, `resources/`,
  dependencies, CI), the edit is blocked and the reason is handed back to the agent, so it self-corrects
  instead of trying a workaround. `settings.json` also allow-lists the handful of commands the brief
  needs (validate, catalogue, the git steps), so the editor isn't spammed with permission prompts.
- **CI (the guarantee):** `.github/workflows/content-guardrails.yml` enforces, on GitHub's side:
  - every commit on the work branch may touch **only** content and assets, *unless* it's authored by
    a maintainer. It's default-deny: the agent's edits are held to the content allowlist whatever git
    identity they carry, and only the maintainer's own commits are exempt;
  - only the **maintainer** can land changes on the production branch;
  - every push runs `content:validate`, a catalogue-freshness check, a build, and a code-style check.

  So even if the agent goes rogue or someone hand-edits a file, a code-touching or schema-invalid
  change can't land cleanly on the working branch and can't reach production.

The guardrail machinery itself (the commands, the validator, the path allowlist) is covered by the
kit's own test suite. See `tests/` and `.github/workflows/ci.yml`.

### How changes go live

- **Day to day:** the agent commits and pushes to the `staging` branch, which redeploys to a preview
  URL. Many edits, no ceremony. The client reviews on the preview site.
- **Publishing:** when the client says "publish" / "make it live", the agent opens **one** pull
  request from `staging` to `main` for the maintainer to review and merge. Production only ever
  changes through a reviewed PR.

### A typical exchange

> **Client:** "Here's an overview doc for our six services. Set up a page for each one, in our tone."

The agent switches to `staging` and pulls the latest, checks `content/agent-reference.md` to see which
blocks exist and what fields they take, then builds all six pages from the existing blocks: copying an
existing page as a starting point, wiring each into the page tree, writing the copy in the site's
voice. It runs `content:validate`, commits, and **pushes** to `staging`, then shares the preview link
so the client can review the whole set at once. Later they say "looks good, publish it" and the agent
opens one PR.

---

## One source of truth: the page builder

Blocks are defined in one place (the `page_builder` fieldset) and everything else derives from it.
To add a block:

1. Add a `set` to `resources/fieldsets/page_builder.yaml`.
2. Add `resources/views/blocks/<set-handle>.antlers.html`: **the filename must equal the set
   handle**.
3. Run `php artisan content:catalog` and `php artisan content:validate`.

That's the whole contract. The agent's catalogue, the validation rules, the Control Panel, and the
front-end rendering all update automatically from the fieldset: no switch statement, no second
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

After install, follow **`SETUP.md`** in your new site's root (the kit ships it there; in this repo
it lives at `export/SETUP.md`). It's a short, ordered checklist:

1. Run `php artisan agentic:setup` (stamps your site name, preview URL, maintainer, and branches
   into the docs and CI).
2. Create the GitHub repo with `staging`/`main` branches.
3. Turn on branch protection.
4. Point your host at the branches.
5. Hand the project over to your editor (SETUP.md walks through setting up their machine).

Then run your agent from the project root: the shipped root `CLAUDE.md` imports
`content/AGENTS.md`, so Claude Code picks up the content-editor brief automatically (other agents:
point them at `content/AGENTS.md` yourself).

## Requirements & notes

- Statamic v6, PHP 8.3+.
- Single-locale. Multilingual support is on the roadmap.
- Deployment is your choice (Ploi, Forge, Vapor, SSG). Agentic sets up the repo + branches + CI, not
  the host.

## For developers

The kit has exactly one opinion: pages are built from blocks defined in a single fieldset
(`page_builder.yaml`). That structure is what makes agent editing safe: the catalogue, the
validation, the Control Panel, and the rendering all derive from that one definition, so the agent
can never invent a block the site doesn't support. Everything else is yours. The kit ships one
example block (`rich_text`) and a minimal layout. **Build the site the way you always would** and
the agentic layer rides along automatically.

The local edit-guard also applies to your own Claude Code sessions. Set `AGENTIC_DEVELOPER=1` (via
`env` in an untracked `.claude/settings.local.json`) to lift it while you build.

## License & contributing

MIT: use it, strip it down, rebuild it however you like. If you improve the experience, or learn
something worth sharing from deploying it for a client, open an issue or PR so the improvement reaches
everyone.
