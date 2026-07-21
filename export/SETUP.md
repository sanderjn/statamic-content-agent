# Setting up Content Agent

Content Agent makes this Statamic site safely editable by an AI agent (Claude Code or similar).
Follow these once, in order.

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

Content Agent does not configure a host. Point your platform at the branches:

- **Ploi / Forge:** create a site, connect the repo, set the deploy branch to `staging` for a preview
  environment and `main` for production. Enable auto-deploy.
- **Vapor:** environments `staging` and `production` tracking those branches.
- **SSG (Netlify/static):** build on push to each branch.

**Whatever the host, rebuild the content index on every deploy.** Statamic caches a flat-file index
(the "Stache"); after a deploy it can be stale, so new pages and menu changes won't show up until it's
rebuilt. Add this to your deploy hook, after the new code is in place:

    php artisan statamic:stache:refresh

## 5. Start editing

    php artisan content:catalog        # regenerate the block catalogue
    # then run your agent from the project root and let content/AGENTS.md guide it

> **Note for developers:** the local edit-guard (a Claude Code PreToolUse hook) applies to *every*
> Claude Code session in this repo, including yours. When you're building the site yourself, lift it
> by setting `AGENTIC_DEVELOPER=1` — the cleanest way is an `env` block in a local, untracked
> `.claude/settings.local.json`. Your maintainer commits are exempt from the content guardrails
> anyway.

## 6. Hand it over to your editor

Setting up the editor's (client's) machine is a developer task — do it *with* or *for* them, don't
assume they'll manage it alone. An honest checklist:

- Clone the repo onto their machine.
- Install the toolchain: PHP 8.3, Composer, and Node. On a Mac, [Laravel Herd](https://herd.laravel.com)
  is the easy path (it bundles PHP and a local server).
- In the project folder: `composer install`, `cp .env.example .env`, `php artisan key:generate`,
  `npm install`.
- Install Claude Code and sign it in **on the client's own account**, not yours.
- Set their git identity to the **client's own name and email** — *not* a maintainer email. Maintainer
  commits are exempt from the content guardrails; the client's commits must be held to them, so their
  email must not be on the maintainer list.
- Give them push access to the repo.
- Run `gh auth login` so the agent can open the publish pull request.

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
