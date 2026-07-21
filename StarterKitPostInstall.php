<?php

class StarterKitPostInstall
{
    public function handle($console)
    {
        $console->info('Content Agent installed.');
        $console->line('Next steps:');
        $console->line('  1. Run:  php artisan agentic:setup   (stamps your site name, preview URL, maintainer, branches)');
        $console->line('  2. Read: SETUP.md   (create the GitHub repo, staging/main branches, branch protection, deploy host)');
        $console->line('  3. Generate the agent catalog:  php artisan content:catalog');
        $console->line('  4. Point your AI agent at content/AGENTS.md and start editing.');
    }
}
