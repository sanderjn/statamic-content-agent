# Agentic

A Statamic starter kit that makes your site safely editable by an AI agent (Claude Code or
similar). It ships a page-builder scaffold, a content-catalogue command that turns your fieldsets
into an agent-readable reference, a `content:validate` guard, and a CI workflow that keeps client
commits inside the content allowlist and production behind a maintainer-only branch.

## Install

    statamic starter-kit:install sanderjn/statamic-agentic

## Get started

After install, read `SETUP.md` at the project root — it walks through `php artisan agentic:setup`,
creating the GitHub repo and branches, branch protection, and deploying.
