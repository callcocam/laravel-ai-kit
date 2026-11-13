<?php

namespace Callcocam\AiKit;

use Callcocam\AiKit\Console\CheckCommand;
use Callcocam\AiKit\Console\CompareCommand;
use Callcocam\AiKit\Console\UsageCommand;
use Callcocam\AiKit\Contracts\AiPolicy;
use Callcocam\AiKit\Contracts\RouteResolver;
use Illuminate\Support\ServiceProvider;

class AiKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-kit.php', 'ai-kit');

        // Lidas da config a cada resolução: o projeto troca em config/ai-kit.php
        // (ou faz bind no próprio provider, que vence este).
        $this->app->bindIf(AiPolicy::class, fn ($app) => $app->make((string) config('ai-kit.policy')));
        $this->app->bindIf(RouteResolver::class, fn ($app) => $app->make((string) config('ai-kit.route_resolver')));
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'ai-kit');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([__DIR__.'/../config/ai-kit.php' => config_path('ai-kit.php')], 'ai-kit-config');
        $this->publishes([__DIR__.'/../lang' => $this->app->langPath('vendor/ai-kit')], 'ai-kit-lang');
        // Só publicada, nunca carregada: os projetos que já têm `ai_runs` mantêm a sua.
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'ai-kit-migrations');

        $this->commands([CheckCommand::class, CompareCommand::class, UsageCommand::class]);
    }
}
