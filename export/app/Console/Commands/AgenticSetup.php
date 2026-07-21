<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\text;

class AgenticSetup extends Command
{
    protected $signature = 'agentic:setup
        {--site-name=} {--site-description=} {--preview-url=}
        {--maintainer=} {--maintainer-emails=}
        {--work-branch=} {--release-branch=}';

    protected $description = 'Stamp this project\'s details into the agentic content-editing files (idempotent).';

    public function handle(): int
    {
        $agentsFile = config('agentic.agents_file');
        $ciFile = '.github/workflows/content-guardrails.yml';
        $agentsPath = base_path($agentsFile);
        $ciPath = base_path($ciFile);

        foreach ([$agentsPath, $ciPath] as $path) {
            if (! File::exists($path)) {
                $this->error("{$path} not found — run agentic:setup from the project root of a site created from this kit.");

                return self::FAILURE;
            }
        }

        $siteName = $this->answer('site-name', 'Site name', 'My Website');
        $siteDescription = $this->answer('site-description', 'One or two sentences describing the site', 'A website.');
        $previewUrl = $this->answer('preview-url', 'Preview site URL (where the work branch deploys; leave empty to keep the current value)', '');
        $maintainer = $this->answer('maintainer', 'Maintainer GitHub username (only they may land on the release branch)', '');
        $maintainerEmails = $this->answer('maintainer-emails', 'Maintainer git emails (space-separated) — commits from anyone else are held to content-only', '');
        $work = $this->answer('work-branch', 'Work branch (day-to-day edits)', config('agentic.branches.work'));
        $release = $this->answer('release-branch', 'Release branch (production)', config('agentic.branches.release'));

        $missed = [];

        $markers = [
            'site_name' => $siteName,
            'site_description' => $siteDescription,
            'preview_url' => $previewUrl,
            'work_branch' => $work,
            'release_branch' => $release,
        ];

        // An empty preview URL means "keep whatever the marker already says" —
        // skip it entirely rather than blanking the sentence in AGENTS.md.
        if ($previewUrl === '') {
            unset($markers['preview_url']);
        }

        $markerCounts = $this->stampMarkers($agentsPath, $markers);
        foreach ($markerCounts as $key => $count) {
            if ($count === 0) {
                $missed[] = $key;
                $this->warn("Could not find marker '{$key}' in {$agentsFile} — it was not filled.");
            }
        }

        $ciVars = [
            'MAINTAINER_EMAILS' => [$maintainerEmails, 'agentic:maintainer_emails'],
            'MAINTAINERS' => [$maintainer, 'agentic:maintainers'],
        ];
        foreach ($ciVars as $var => [$value, $marker]) {
            if ($this->stampCiEnv($ciPath, $var, $value, $marker) === 0) {
                $missed[] = $var;
                $this->warn("Could not find marker '{$var}' in {$ciFile} — it was not filled.");
            }
        }

        $total = count($markerCounts) + count($ciVars);
        $missedCount = count($missed);

        if ($missedCount === 0) {
            $this->info('Setup complete. Review the changes, then commit.');
        } else {
            $stampedCount = $total - $missedCount;
            $this->warn("Stamped {$stampedCount} of {$total} values; {$missedCount} could not be found (see warnings above).");
        }

        return self::SUCCESS;
    }

    private function answer(string $option, string $label, string $default): string
    {
        if ($this->option($option) !== null) {
            return (string) $this->option($option);
        }

        return text(label: $label, default: $default);
    }

    /**
     * Replace the text between each `<!-- agentic:key -->…<!-- /agentic:key -->`
     * marker pair. Re-runnable because it targets the markers, not the value.
     * Returns the number of replacements made per key (0 means the marker was
     * missing, so nothing was filled).
     *
     * @return array<string, int>
     */
    private function stampMarkers(string $path, array $values): array
    {
        $doc = File::get($path);
        $counts = [];
        foreach ($values as $key => $value) {
            $doc = preg_replace(
                '/(<!-- agentic:'.preg_quote($key, '/').' -->).*?(<!-- \/agentic:'.preg_quote($key, '/').' -->)/s',
                '$1'.addcslashes($value, '\\$').'$2',
                $doc,
                -1,
                $count
            );
            $counts[$key] = $count;
        }
        File::put($path, $doc);

        return $counts;
    }

    /**
     * Replace the value on the CI env line tagged with the given marker comment.
     * Returns the number of replacements made (0 means the marked line was not
     * found, so nothing was filled).
     */
    private function stampCiEnv(string $path, string $var, string $value, string $marker): int
    {
        $yml = File::get($path);
        $yml = preg_replace(
            '/^(\s*'.preg_quote($var, '/').': ").*?(" #\s*'.preg_quote($marker, '/').'.*)$/m',
            '${1}'.addcslashes($value, '\\$').'${2}',
            $yml,
            -1,
            $count
        );
        File::put($path, $yml);

        return $count;
    }
}
