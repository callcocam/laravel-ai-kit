<?php

namespace Callcocam\AiKit\Tests;

use Callcocam\AiKit\AiKitServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class, AiKitServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');

        // Os dois provedores de hoje, como os projetos configuram em config/ai.php.
        $app['config']->set('ai.default', 'cloudflare');
        $app['config']->set('ai.providers', [
            'cloudflare' => [
                'driver' => 'openai-compatible',
                'url' => 'https://api.cloudflare.test/ai/v1',
                'key' => 'cf-key',
                'models' => ['text' => ['default' => '@cf/google/gemma-4-26b-a4b-it']],
            ],
            'anthropic' => [
                'driver' => 'anthropic',
                'key' => 'anthropic-key',
                'url' => 'https://api.anthropic.test/v1',
                'models' => ['text' => ['default' => 'claude-sonnet-5']],
            ],
            'openai' => [
                'driver' => 'openai',
                'key' => 'openai-key',
                'models' => ['text' => ['default' => 'gpt-5-mini']],
            ],
        ]);

        $app['config']->set('ai-kit.enabled', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
