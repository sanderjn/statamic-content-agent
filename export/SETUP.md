# Setting up Agentic

Agentic makes this Statamic site safely editable by an AI agent (Claude Code or similar). Follow
these once, in order.

## 1. Fill in your project details

    php artisan agentic:setup

This stamps your site name, description, editor git emails, maintainer, and branch names into
`content/AGENTS.md` and the CI workflow. Re-run it any time; it is idempotent. Commit the result.

## 2. Create the GitHub repo + branches

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

## 4. Deploy (your choice)

Agentic does not configure a host. Point your platform at the branches:

- **Ploi / Forge:** create a site, connect the repo, set the deploy branch to `staging` for a preview
  environment and `main` for production. Enable auto-deploy.
- **Vapor:** environments `staging` and `production` tracking those branches.
- **SSG (Netlify/static):** build on push to each branch.

## 5. Start editing

    php artisan content:catalog        # regenerate the block catalogue
    # then run your agent from the project root and let content/AGENTS.md guide it

## Adding your own blocks

1. Add a `set` to `resources/fieldsets/page_builder.yaml`.
2. Add `resources/views/blocks/<set-handle>.antlers.html` (the filename MUST equal the set handle).
3. Run `php artisan content:catalog` and `php artisan content:validate`.

That is the whole contract — the agent, validation, and rendering all derive from the fieldset.
