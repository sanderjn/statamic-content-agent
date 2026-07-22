<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AgenticSetupTest extends TestCase
{
    private string $agentsPath;

    private string $ciPath;

    private string $onboardingPath;

    protected function setUp(): void
    {
        parent::setUp();

        // The command stamps base_path('content/AGENTS.md'), the CI workflow, and
        // the editor onboarding prompt; seed all three from the shipped templates.
        $this->agentsPath = base_path('content/AGENTS.md');
        $this->ciPath = base_path('.github/workflows/content-guardrails.yml');
        $this->onboardingPath = base_path('ONBOARDING.md');

        File::ensureDirectoryExists(dirname($this->agentsPath));
        File::ensureDirectoryExists(dirname($this->ciPath));
        File::copy($this->kitRoot.'/export/content/AGENTS.md', $this->agentsPath);
        File::copy($this->kitRoot.'/export/.github/workflows/content-guardrails.yml', $this->ciPath);
        File::copy($this->kitRoot.'/export/ONBOARDING.md', $this->onboardingPath);
    }

    protected function tearDown(): void
    {
        File::delete($this->agentsPath);
        File::delete($this->ciPath);
        File::delete($this->onboardingPath);

        parent::tearDown();
    }

    private function stamp(string $name, string $maintainer, string $emails, string $previewUrl = 'https://preview.test', string $repoUrl = 'https://github.com/acme/site'): void
    {
        $this->artisan('agentic:setup', [
            '--site-name' => $name,
            '--site-description' => 'A test site.',
            '--preview-url' => $previewUrl,
            '--repo-url' => $repoUrl,
            '--maintainer' => $maintainer,
            '--maintainer-emails' => $emails,
            '--work-branch' => 'staging',
            '--release-branch' => 'main',
        ])->assertSuccessful();
    }

    public function test_it_stamps_the_docs_and_ci(): void
    {
        $this->stamp('Acme Co', 'octocat', 'dev@acme.test', 'https://preview.acme.test');

        $agents = File::get($this->agentsPath);
        $ci = File::get($this->ciPath);

        $this->assertStringContainsString('<!-- agentic:site_name -->Acme Co<!-- /agentic:site_name -->', $agents);
        $this->assertStringContainsString('<!-- agentic:preview_url -->https://preview.acme.test<!-- /agentic:preview_url -->', $agents);
        $this->assertStringContainsString('MAINTAINER_EMAILS: "dev@acme.test"', $ci);
        $this->assertStringContainsString('MAINTAINERS: "octocat"', $ci);
    }

    public function test_it_is_idempotent(): void
    {
        $this->stamp('First Name', 'octocat', 'dev@acme.test', 'https://first.test');
        $this->stamp('Second Name', 'hubot', 'ops@acme.test', 'https://second.test');

        $agents = File::get($this->agentsPath);
        $ci = File::get($this->ciPath);

        // Re-running replaces the value rather than duplicating markers.
        $this->assertStringContainsString('<!-- agentic:site_name -->Second Name<!-- /agentic:site_name -->', $agents);
        $this->assertStringNotContainsString('First Name', $agents);
        $this->assertStringContainsString('<!-- agentic:preview_url -->https://second.test<!-- /agentic:preview_url -->', $agents);
        $this->assertStringNotContainsString('https://first.test', $agents);
        $this->assertSame(1, substr_count($agents, '<!-- agentic:site_name -->'));
        $this->assertStringContainsString('MAINTAINER_EMAILS: "ops@acme.test"', $ci);
        $this->assertStringContainsString('MAINTAINERS: "hubot"', $ci);
    }

    public function test_an_empty_preview_url_keeps_the_existing_value(): void
    {
        $this->stamp('Acme Co', 'octocat', 'dev@acme.test', 'https://preview.acme.test');
        $this->stamp('Acme Co', 'octocat', 'dev@acme.test', '');

        $agents = File::get($this->agentsPath);

        $this->assertStringContainsString('<!-- agentic:preview_url -->https://preview.acme.test<!-- /agentic:preview_url -->', $agents);
    }

    public function test_it_stamps_the_onboarding_prompt(): void
    {
        $this->stamp('Acme Co', 'octocat', 'dev@acme.test', 'https://preview.acme.test', 'https://github.com/acme/website');

        $onboarding = File::get($this->onboardingPath);

        $this->assertStringContainsString('<!-- agentic:repo_url -->https://github.com/acme/website<!-- /agentic:repo_url -->', $onboarding);
        $this->assertStringContainsString('<!-- agentic:maintainer_emails -->dev@acme.test<!-- /agentic:maintainer_emails -->', $onboarding);
    }

    public function test_an_empty_repo_url_keeps_the_existing_value(): void
    {
        $this->stamp('Acme Co', 'octocat', 'dev@acme.test', 'https://preview.acme.test', 'https://github.com/acme/website');
        $this->stamp('Acme Co', 'octocat', 'dev@acme.test', 'https://preview.acme.test', '');

        $onboarding = File::get($this->onboardingPath);

        $this->assertStringContainsString('<!-- agentic:repo_url -->https://github.com/acme/website<!-- /agentic:repo_url -->', $onboarding);
    }

    public function test_it_succeeds_without_an_onboarding_file(): void
    {
        File::delete($this->onboardingPath);

        $this->stamp('Acme Co', 'octocat', 'dev@acme.test', 'https://preview.acme.test');

        $agents = File::get($this->agentsPath);
        $ci = File::get($this->ciPath);

        $this->assertFalse(File::exists($this->onboardingPath));
        $this->assertStringContainsString('<!-- agentic:site_name -->Acme Co<!-- /agentic:site_name -->', $agents);
        $this->assertStringContainsString('MAINTAINER_EMAILS: "dev@acme.test"', $ci);
    }
}
