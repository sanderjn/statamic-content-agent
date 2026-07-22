# Editor onboarding (for the developer)

This file is yours, not the editor's. It automates the machine setup in SETUP.md step 6: instead of
driving the editor's computer yourself, you email them a prompt and **their own agent does the
setup** — installs `gh`, walks them through GitHub sign-in, clones the repo, sets a safe git
identity, and verifies push access.

Two prerequisites the prompt can't cover — name them in your email:

- The editor has a **GitHub account**, and you've added it as a **collaborator with push access**.
- **Claude Code is installed and signed in** on their machine, on **their own account** (a paid
  Claude plan — their cost, flag it up front).

Run `php artisan agentic:setup` first so the repository URL and maintainer emails below are filled
in. Then email the editor the whole prompt block — the `<!-- -->` tags are stamping markers; they're
fine to leave in, the agent ignores them. Tell the editor: open Claude Code (any folder is fine) and
paste the entire block as one message.

For the **+ Validate** or **Full local** tiers (see SETUP.md step 6), add one line to your email
telling their agent what else to set up — e.g. "also install PHP 8.3 via Laravel Herd and run
`composer install`, `cp .env.example .env`, `php artisan key:generate` in the project folder".

---

You are helping a non-technical person set up their computer so they can edit their website by
talking to an AI agent. Work one step at a time, explain everything in plain words (no jargon), and
verify each step worked before moving to the next.

Some steps need the person to type a password or click around in a browser (installing tools,
signing in to GitHub). You can't do those for them: open the Terminal app for them, tell them
exactly what to paste, narrate what they'll see, and verify the result yourself afterwards.

1. Check that `git` and the GitHub CLI (`gh`) are installed. Install what's missing — on a Mac via
   Homebrew (install Homebrew first if needed; macOS may also ask to install its command line
   developer tools — that's normal, let it).
2. Sign in to GitHub: have them run `gh auth login` in the Terminal app (choose GitHub.com, HTTPS,
   login with a web browser) using **their own GitHub account** — the one the site's developer
   invited. If GitHub shows a pending repository invitation, have them accept it. Verify with
   `gh auth status`.
3. Clone the website's repository:
   <!-- agentic:repo_url -->(run agentic:setup to fill the repository URL in)<!-- /agentic:repo_url -->
   into a sensible place such as a `Sites` folder in their home folder.
4. Set their git identity inside the cloned folder (`git config user.name` and `git config
   user.email`): ask for their name and their own email address. Their email must NOT be one of the
   developer's: <!-- agentic:maintainer_emails -->(run agentic:setup to fill the maintainer emails in)<!-- /agentic:maintainer_emails -->.
   If they offer one of those, use a personal address instead — this matters for the site's safety
   checks.
5. Verify everything works: in the project folder, run `git checkout staging` and then
   `git push --dry-run origin staging`. Both must pass without errors. If push access is denied,
   the developer still needs to add them as a collaborator — stop and tell them to ask.
6. Wrap up: tell them setup is done, and that from now on they edit the site by opening Claude Code
   **inside this project folder** — that's where the website's own editing instructions take over.
   This setup conversation is not for editing: have them close it and start a fresh session in the
   project folder.

If a step fails twice, stop and have them send the developer the exact error message.
