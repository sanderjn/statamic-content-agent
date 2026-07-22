# Setting up Agentic

Agentic makes this Statamic site safely editable by an AI agent (Claude Code or similar). Follow
these once, in order.

## 1. Fill in your project details

    php artisan agentic:setup

This stamps your site name, description, maintainer (GitHub username + git emails), and branch names
into `content/AGENTS.md` and the CI workflow. The maintainer emails matter: CI holds every commit that
*isn't* a maintainer's to the content-only allowlist, so the agent's edits are guarded whatever git
identity they carry. Re-run it any time; it is idempotent. Commit the result.

## 2. Create the GitHub repo + branches

First install the front-end dependencies and build once — this generates `package-lock.json`, which
CI uses (`npm ci`) for reproducible builds, so commit it along with everything else:

    npm install && npm run build

Then create the repo and branches:

    git init && git add -A && git commit -m "Initial commit"
    gh repo create <you>/<site> --private --source=. --push
    git branch staging && git push -u origin staging

Day-to-day agent edits go on `staging`; `main` is production.

> If you change the branch names in `agentic:setup`, also update
> `.github/workflows/content-guardrails.yml` (the `on: push: branches` list and the job `if:`
> conditions reference `staging`/`main` literally).

## 3. Branch protection (GitHub → Settings → Branches → Add rule for `main`)

- Require a pull request before merging; require 1 approval.
- Restrict who can push to `main` to the maintainer.
- Require the `validate-and-test` status check to pass.

The shipped CI (`.github/workflows/content-guardrails.yml`) enforces content-only client commits and
maintainer-only `main` as a backstop, but GitHub branch protection is the real gate.

An honest caveat: GitHub only offers branch protection on private repos with a paid plan (Pro or an
organization); on a free personal account it needs a public repo. Without protection, nothing
enforces the required check or blocks direct pushes to `main`: the CI `guard-release` job plus your
own review discipline are the only gate.

## 4. Deploy (your choice)

Agentic does not configure a host. Point your platform at the branches:

- **Ploi / Forge:** create a site, connect the repo, set the deploy branch to `staging` for a preview
  environment and `main` for production. Enable auto-deploy.
- **Vapor:** environments `staging` and `production` tracking those branches.
- **SSG (Netlify/static):** build on push to each branch.

**Whatever the host, your deploy must build the front-end assets and rebuild the content index.**
The CSS/JS is compiled by Vite and is *not* committed, so a deploy that skips the build serves an
unstyled site. And Statamic caches a flat-file index (the "Stache") that goes stale after a deploy, so
new pages and menu changes won't show up until it's rebuilt. A deploy hook, after the new code is in
place:

    composer install --no-dev
    npm ci && npm run build
    php artisan statamic:stache:refresh

**`staging` deploy speed matters.** In the lightest editor setup (see step 6) the editor keeps no local
runtime and reviews every change on the `staging` preview — so that round-trip has to be quick. Enable
auto-deploy; and if your platform lets you, skip the asset rebuild when a push only touched `content/`
(nothing under `resources/`), so content edits redeploy in seconds instead of waiting on a full build.

## 5. Start editing

    php artisan content:catalog        # regenerate the block catalogue
    # then run your agent from the project root and let content/AGENTS.md guide it

> **Note for developers:** the local edit-guard (a Claude Code PreToolUse hook) applies to *every*
> Claude Code session in this repo, including yours. When you're building the site yourself, lift it
> by setting `AGENTIC_DEVELOPER=1` — the cleanest way is an `env` block in a local, untracked
> `.claude/settings.local.json`. Your maintainer commits are exempt from the content guardrails
> anyway.

## 6. Hand it over to your editor

Decide how much to install on the editor's machine. The content is flat files, so the **recommended
default** is to install as little as possible and let the editor review everything on the `staging`
preview: you own the deployment, so the front-end build and index refresh happen there, not on their
machine. A local setup is possible too, but it puts more on their machine and usually needs a hand from
you to wire up. Three tiers:

- **Lite (recommended)** — `staging` auto-deploys fast. Install: git, Claude Code, `gh`. No PHP, no
  Node. The agent edits files and pushes; CI validates on push; the editor reviews on the `staging` URL.
- **+ Validate** — as Lite, plus PHP 8.3 and `composer install`, so the agent runs `content:validate`
  before it pushes and catches mistakes before they reach the preview. Still no Node.
- **Full local** — as + Validate, plus Node and `npm install && npm run build` once, so the site renders
  on localhost and the editor never waits on a deploy. Note: they must rebuild after any design change
  you ship, which is why this tier is usually *more* upkeep for a non-technical client, not less.

The account steps are yours in every tier (an agent can't do these for you):

- Install Claude Code and sign it in **on the client's own account**, not yours. On a Mac,
  [Laravel Herd](https://herd.laravel.com) is the easy way to get PHP (and Node) for the local tiers.
- Set their git identity to the **client's own name and email** — *not* a maintainer email. Maintainer
  commits are exempt from the content guardrails; the client's commits must be held to them, so their
  email must not be on the maintainer list.
- Give them push access to the repo, and run `gh auth login` so the agent can open the publish PR.

The command steps — clone the repo, `composer install`, `cp .env.example .env`,
`php artisan key:generate`, and (Full local only) `npm install && npm run build` — are ordinary commands
you can run yourself, or ask the editor's own agent to run once Claude Code is up.

Then, in a terminal in the project folder, run:

    claude

The shipped brief (`content/AGENTS.md`) takes over from there. On the first session the agent offers
to fill in the editor notes (tone of voice, writing preferences) together — a few friendly questions,
and every later edit sounds the way they want.

## Adding your own blocks

1. Add a `set` to `resources/fieldsets/page_builder.yaml`.
2. Add `resources/views/blocks/<set-handle>.antlers.html` (the filename MUST equal the set handle).
3. Run `php artisan content:catalog` and `php artisan content:validate`.

That is the whole contract — the agent, validation, and rendering all derive from the fieldset.
