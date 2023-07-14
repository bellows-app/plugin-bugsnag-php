<?php

namespace Bellows\Plugins;

use Bellows\PluginSdk\Contracts\Deployable;
use Bellows\PluginSdk\Contracts\HttpClient;
use Bellows\PluginSdk\Contracts\Installable;
use Bellows\PluginSdk\Data\AddApiCredentialsPrompt;
use Bellows\PluginSdk\Facades\Composer;
use Bellows\PluginSdk\Facades\Console;
use Bellows\PluginSdk\Facades\Deployment;
use Bellows\PluginSdk\Facades\Entity;
use Bellows\PluginSdk\Facades\Project;
use Bellows\PluginSdk\Plugin;
use Bellows\PluginSdk\PluginResults\CanBeDeployed;
use Bellows\PluginSdk\PluginResults\CanBeInstalled;
use Bellows\PluginSdk\PluginResults\DeploymentResult;
use Bellows\PluginSdk\PluginResults\InstallationResult;
use Illuminate\Http\Client\PendingRequest;

class BugsnagPHP extends Plugin implements Deployable, Installable
{
    use CanBeDeployed, CanBeInstalled;

    protected ?string $bugsnagKey;

    protected string $organizationId;

    public function __construct(
        protected HttpClient $http,
    ) {
    }

    public function install(): ?InstallationResult
    {
        $result = InstallationResult::create();

        if (Console::confirm('Setup Bugsnag PHP project now?', false)) {
            $this->setupProject();

            $result->environmentVariables($this->environmentVariables());
        }

        return $result
            ->serviceProvider('Bugsnag\\BugsnagLaravel\\BugsnagServiceProvider')
            ->alias('Bugsnag', 'Bugsnag\\BugsnagLaravel\\Facades\\Bugsnag')
            ->updateConfigs([
                'logging.channels.stack.driver'   => 'stack',
                'logging.channels.stack.channels' => "['single', 'bugsnag']",
                'logging.channels.bugsnag.driver' => 'bugsnag',
            ]);
    }

    public function deploy(): ?DeploymentResult
    {
        $this->setupProject();

        return DeploymentResult::create()->environmentVariables($this->environmentVariables());
    }

    public function anyRequiredComposerPackages(): array
    {
        return  [
            'bugsnag/bugsnag-laravel',
            'bugsnag/bugsnag',
        ];
    }

    public function shouldDeploy(): bool
    {
        return !Deployment::site()->env()->has('BUGSNAG_API_KEY');
    }

    public function setupProject(): void
    {
        $this->bugsnagKey = Project::env()->get('BUGSNAG_API_KEY');

        if ($this->bugsnagKey) {
            Console::miniTask('Using existing Bugsnag PHP key from', '.env');

            return;
        }

        $type = Composer::packageIsInstalled('laravel/framework') ? 'laravel' : 'php';

        $this->bugsnagKey = $this->getProjectKey($type);
    }

    protected function getProjectKey(string $type)
    {
        $this->setupClient();

        $projects = $this->http->client()->get("organizations/{$this->organizationId}/projects", [
            'per_page' => 100,
        ])->json();

        $projectsOfType = collect($projects)->where('type', $type);

        return Entity::from($projectsOfType)
            ->selectFromExisting(
                'Select a Bugsnag project',
                'name',
                Project::appName(),
                'Create new project',
            )
            ->createNew(
                'Create new Bugsnag project?',
                fn () => $this->createProject($type),
            )
            ->prompt()['api_key'];
    }

    protected function setupClient(): void
    {
        $this->http->createJsonClient(
            'https://api.bugsnag.com/',
            fn (PendingRequest $request, array $credentials) => $request->withToken($credentials['token'], 'token'),
            new AddApiCredentialsPrompt(
                url: 'https://app.bugsnag.com/settings/my-account',
                credentials: ['token'],
                displayName: 'Bugsnag',
            ),
            fn (PendingRequest $request) => $request->get('user/organizations', ['per_page' => 1]),
            true,
        );

        $this->organizationId = $this->http->client()->get('user/organizations')->json()[0]['id'];
    }

    protected function createProject(string $type): array
    {
        return $this->http->client()->post("organizations/{$this->organizationId}/projects", [
            'name' => Console::ask('Project name', Project::appName()),
            'type' => $type,
        ])->json();
    }

    protected function environmentVariables(): array
    {
        return [
            'BUGSNAG_API_KEY'               => $this->bugsnagKey,
            'BUGSNAG_NOTIFY_RELEASE_STAGES' => 'production,development,staging',
        ];
    }
}
