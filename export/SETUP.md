# Setting up Agentic

Agentic makes this Statamic site safely editable by an AI agent (Claude Code or similar).

**This file is for the developer** — the one-time setup of repo, CI, host, and hand-over. Follow the
steps once, in order. If you're the site's editor (or its content agent), this file is not for you:
your instructions live in `content/AGENTS.md`.

## 1. Fill in your project details

    php artisan agentic:setup

This stamps your site name, description, preview URL, repository URL, maintainer (GitHub username +
git emails), and branch names into `content/AGENTS.md`, the CI workflow, and the hand-over prompt
(`ONBOARDING.md`). The maintainer emails matter: CI holds every commit that *isn't* a maintainer's to
the content-only allowlist, so the agent's edits are guarded whatever git identity they carry. Re-run
it any time; it is idempotent.

You won't know the repository URL until step 2, or the preview URL until step 4 — leave both empty
for now; the re-run in step 4 fills them in. (Committing happens in step 2, once the repo exists.)

> If you change the branch names here, also update `.github/workflows/content-guardrails.yml` — the
> `on: push: branches` list and the job `if:` conditions reference `staging`/`main` literally.

## 2. Create the GitHub repo + branches

First install the front-end dependencies and build once — this generates `package-lock.json`, which
CI uses (`npm ci`) for reproducible builds, so commit it along with everything else:

    npm install && npm run build

Then create the repo and branches. One decision first: **private or public?** Branch protection
(step 3) is only available on private repos on a paid GitHub plan — on a free personal account, make
the repo public if you want protection. And the `-b main` matters: a bare `git init` still defaults
to `master` on most machines, while this checklist and the CI workflow assume `main` literally.

    git init -b main && git add -A && git commit -m "Initial commit"
    gh repo create <you>/<site> --private --source=. --push
    git branch staging && git push -u origin staging

Day-to-day agent edits go on `staging`; `main` is production.

> CI runs on PHP 8.4. If your local PHP is newer, raise `php-version` in
> `.github/workflows/content-guardrails.yml` to match — `composer install` refuses a lock file
> resolved on a newer PHP.

## 3. Branch protection (GitHub → Settings → Branches → Add rule for `main`)

- Require a pull request before merging; require 1 approval.
- Restrict who can push to `main` to the maintainer.
- Require the `validate-and-test` status check to pass.

The shipped CI (`.github/workflows/content-guardrails.yml`) enforces content-only client commits and
maintainer-only `main` as a backstop, but GitHub branch protection is the real gate.

An honest caveat: without protection (free plan + private repo — see step 2), nothing enforces the
required check or blocks direct pushes to `main`: the CI `guard-release` job plus your own review
discipline are the only gate.

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

**Now you know the preview URL** — re-run `php artisan agentic:setup`, fill in the preview URL and
the repository URL this time (they land in the agent's brief and the hand-over prompt; your other
answers keep their values), and commit. Until then the agent can only tell the editor "ask the
developer for the preview link".

## 5. Build the site

Build the site the way you always would — layout, styles, collections. The kit's only opinion is that
pages are built from blocks defined in `resources/fieldsets/page_builder.yaml`: delete the example
`rich_text` block and add your own (see "Adding your own blocks" below). After any block change, run:

    php artisan content:catalog        # regenerate the block catalogue
    php artisan content:validate

CI fails the push if the committed catalogue is stale, so `content:catalog` is part of shipping a
block, not optional.

Do your work on `staging`, the same flow the agent uses: push, check the preview, open a PR to `main`
when it's ready. Your maintainer commits are exempt from the content-only allowlist, so code changes
pass CI on `staging`. Two habits keep the branches healthy once the editor is active:

- **Merge PRs to `main` with a merge commit, never squash.** Squashing makes `staging` and `main`
  diverge, and every later publish PR drags already-released commits back in.
- **Pull `staging` before you start.** The editor's content commits land there day to day; your code
  changes ride along in the same history.

> **The local edit-guard applies to you too:** the Claude Code PreToolUse hook blocks writes outside
> content in *every* session in this repo, including yours. When you're building the site yourself,
> lift it by setting `AGENTIC_DEVELOPER=1` — the cleanest way is an `env` block in a local, untracked
> `.claude/settings.local.json`. Your maintainer commits are exempt from the content guardrails
> anyway.

## 6. Hand it over to your editor

Two prerequisites — neither is Agentic's job, just make sure they exist before hand-over:

- The editor has a **GitHub account**, and you've added it as a **collaborator with push access**.
- **Claude Code is installed and signed in on their machine**, on the **client's own account**. That's
  a paid Claude plan — a real monthly cost on their side; put it in your quote or their budget up front.

From there the hand-over is automated: **email the editor the prompt from `ONBOARDING.md`** (stamped
in step 4). Their own agent does the machine setup — installs `gh`, walks them through the GitHub
sign-in, clones the repo, sets their git identity, and verifies push access. The prompt makes sure
their git email is the **client's own**, not a maintainer address — maintainer commits are exempt
from the content guardrails, so the client's must stay off that list. If the editor gets stuck alone
with a terminal, do the same steps together on a call — ONBOARDING.md doubles as your script.

Decide how much runs on their machine. The content is flat files, so the **recommended default** is
as little as possible: you own the deployment, so the front-end build and index refresh happen
there, not on their machine. Three tiers:

- **Lite (recommended)** — nothing beyond the onboarding prompt. No PHP, no Node. The agent edits
  files and pushes; CI validates on push; the editor reviews on the `staging` URL (which is why
  `staging` deploy speed matters — step 4).
- **+ Validate** — plus PHP 8.3 (on a Mac, [Laravel Herd](https://herd.laravel.com) is the easy way)
  and `composer install`, `cp .env.example .env`, `php artisan key:generate`, so the agent runs
  `content:validate` before it pushes and catches mistakes before they reach the preview. Still no
  Node.
- **Full local** — as + Validate, plus Node and `npm install && npm run build` once, so the site
  renders on localhost and the editor never waits on a deploy. Note: they must rebuild after any
  design change you ship, which is why this tier is usually *more* upkeep for a non-technical
  client, not less.

For the local tiers, add one line to your onboarding email telling the editor's agent what else to
install — ONBOARDING.md shows an example.

Then, in a terminal in the project folder, the editor runs:

    claude

The shipped brief (`content/AGENTS.md`) takes over from there. On the first session the agent offers
to fill in the editor notes (tone of voice, writing preferences) together — a few friendly questions,
and every later edit sounds the way they want.

## Adding your own blocks

1. Add a `set` to `resources/fieldsets/page_builder.yaml`.
2. Add `resources/views/blocks/<set-handle>.antlers.html` (the filename MUST equal the set handle).
3. Run `php artisan content:catalog` and `php artisan content:validate`.

That is the whole contract — the agent, validation, and rendering all derive from the fieldset.
