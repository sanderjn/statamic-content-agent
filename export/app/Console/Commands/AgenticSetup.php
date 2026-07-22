<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\text;

class AgenticSetup extends Command
{
    protected $signature = 'agentic:setup
        {--site-name=} {--site-description=} {--preview-url=} {--repo-url=}
        {--maintainer=} {--maintainer-emails=}
        {--work-branch=} {--release-branch=}';

    protected $description = 'Stamp this project\'s details into the agentic content-editing files (idempotent).';

    public function handle(): int
    {
        $agentsFile = config('agentic.agents_file');
        $ciFile = '.github/workflows/content-guardrails.yml';
        $onboardingFile = config('agentic.onboarding_file');
        $agentsPath = base_path($agentsFile);
        $ciPath = base_path($ciFile);
        $onboardingPath = base_path($onboardingFile);

        foreach ([$agentsPath, $ciPath] as $path) {
            if (! File::exists($path)) {
                $this->error("{$path} not found — run agentic:setup from the project root of a site created from this kit.");

                return self::FAILURE;
            }
        }

        // Read the current files once so each prompt can default to whatever is
        // already stamped. That makes an interactive re-run safe: pressing Enter
        // keeps the current value instead of blanking it.
        $agentsDoc = File::get($agentsPath);
        $ciDoc = File::get($ciPath);

        $siteName = $this->answer('site-name', 'Site name', $this->currentMarker($agentsDoc, 'site_name') ?? 'My Website');
        $siteDescription = $this->answer('site-description', 'One or two sentences describing the site', $this->currentMarker($agentsDoc, 'site_description') ?? 'A website.');
        $previewUrl = $this->answer('preview-url', 'Preview site URL (where the work branch deploys; leave empty to keep the current value)', '');
        $repoUrl = $this->answer('repo-url', 'Repository URL for the editor onboarding prompt (leave empty to keep the current value)', '');
        $maintainer = $this->answer('maintainer', 'Maintainer GitHub username (only they may land on the release branch)', $this->currentCiValue($ciDoc, 'MAINTAINERS', 'agentic:maintainers') ?? '');
        $maintainerEmails = $this->answer('maintainer-emails', 'Maintainer git emails (space-separated) — commits from anyone else are held to content-only', $this->currentCiValue($ciDoc, 'MAINTAINER_EMAILS', 'agentic:maintainer_emails') ?? '');
        $work = $this->answer('work-branch', 'Work branch (day-to-day edits)', $this->currentMarker($agentsDoc, 'work_branch') ?? config('agentic.branches.work'));
        $release = $this->answer('release-branch', 'Release branch (production)', $this->currentMarker($agentsDoc, 'release_branch') ?? config('agentic.branches.release'));

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

        // The editor onboarding prompt is optional — some tiers don't ship it.
        // Stamp it when present; otherwise warn and carry on. Empty answers keep
        // the current marker content, like preview_url above.
        $onboardingTotal = 0;
        if (File::exists($onboardingPath)) {
            $onboardingMarkers = [
                'repo_url' => $repoUrl,
                'maintainer_emails' => $maintainerEmails,
            ];
            foreach ($onboardingMarkers as $key => $value) {
                if ($value === '') {
                    unset($onboardingMarkers[$key]);
                }
            }

            $onboardingCounts = $this->stampMarkers($onboardingPath, $onboardingMarkers);
            foreach ($onboardingCounts as $key => $count) {
                if ($count === 0) {
                    $missed[] = $key;
                    $this->warn("Could not find marker '{$key}' in {$onboardingFile} — it was not filled.");
                }
            }
            $onboardingTotal = count($onboardingCounts);
        } else {
            $this->warn('ONBOARDING.md not found — onboarding prompt not stamped.');
        }

        $total = count($markerCounts) + count($ciVars) + $onboardingTotal;
        $missedCount = count($missed);

        if ($missedCount === 0) {
            $this->info('Agentic setup complete. Review the changes, then commit.');
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
     * The content currently stamped between a marker pair in a doc, or null when
     * the marker is missing or empty. Lets a re-run default to the live value.
     */
    private function currentMarker(string $doc, string $key): ?string
    {
        if (preg_match('/<!-- agentic:'.preg_quote($key, '/').' -->(.*?)<!-- \/agentic:'.preg_quote($key, '/').' -->/s', $doc, $m)) {
            return trim($m[1]) !== '' ? $m[1] : null;
        }

        return null;
    }

    /**
     * The value currently between the quotes on a marked CI env line, or null
     * when the line is missing or the value is empty. Mirrors stampCiEnv's shape.
     */
    private function currentCiValue(string $yml, string $var, string $marker): ?string
    {
        if (preg_match('/^\s*'.preg_quote($var, '/').': "(.*?)" #\s*'.preg_quote($marker, '/').'/m', $yml, $m)) {
            return $m[1] !== '' ? $m[1] : null;
        }

        return null;
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
