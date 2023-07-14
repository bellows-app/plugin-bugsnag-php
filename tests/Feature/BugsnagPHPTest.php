<?php

use Bellows\Plugins\BugsnagPHP;
use Bellows\PluginSdk\Facades\Composer;
use Bellows\PluginSdk\Facades\Project;
use Illuminate\Support\Facades\Http;

it('can choose an app from the list', function () {
    Composer::require('laravel/framework');

    Http::fake([
        'user/organizations' => Http::response([
            [
                'id'   => '123',
                'name' => 'Bellows',
            ],
        ]),
        'organizations/123/projects?per_page=100' => Http::response([
            [
                'id'      => '456',
                'name'    => Project::appName(),
                'type'    => 'laravel',
                'api_key' => 'test-api-key',
            ],
        ]),
    ]);

    $result = $this->plugin(BugsnagPHP::class)
        ->expectsQuestion('Select a Bugsnag project', Project::appName())
        ->deploy();

    expect($result->getEnvironmentVariables())->toBe([
        'BUGSNAG_API_KEY'               => 'test-api-key',
        'BUGSNAG_NOTIFY_RELEASE_STAGES' => 'production,development,staging',
    ]);
});

it('can create a new app', function ($package, $projectType) {
    if ($package) {
        Composer::require($package);
    }

    Http::fake([
        'user/organizations' => Http::response([
            [
                'id'   => '123',
                'name' => 'Bellows',
            ],
        ]),
        'organizations/123/projects?per_page=100' => Http::response([
            [
                'id'      => '456',
                'name'    => 'Random Project',
                'type'    => $projectType,
                'api_key' => 'test-api-key',
            ],
        ]),
        'projects' => Http::response([
            'id'      => '789',
            'name'    => 'Test App',
            'api_key' => 'test-api-key',
        ]),
    ]);

    $result = $this->plugin(BugsnagPHP::class)
        ->expectsConfirmation('Create new Bugsnag project?', 'yes')
        ->expectsQuestion('Project name', 'Test App')
        ->deploy();

    $this->assertRequestWasSent('POST', 'organizations/123/projects', [
        'name' => 'Test App',
        'type' => $projectType,
    ]);

    expect($result->getEnvironmentVariables())->toBe([
        'BUGSNAG_API_KEY'               => 'test-api-key',
        'BUGSNAG_NOTIFY_RELEASE_STAGES' => 'production,development,staging',
    ]);
})->with([
    ['laravel/framework', 'laravel'],
    [null, 'php'],
]);

it('will use the .env variable if there is one', function () {
    $this->setEnv(['BUGSNAG_API_KEY' => 'test-api-key']);

    $result = $this->plugin(BugsnagPHP::class)
        ->expectsOutputToContain('Using existing Bugsnag PHP key from')
        ->deploy();

    expect($result->getEnvironmentVariables())->toBe([
        'BUGSNAG_API_KEY'               => 'test-api-key',
        'BUGSNAG_NOTIFY_RELEASE_STAGES' => 'production,development,staging',
    ]);
});
