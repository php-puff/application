<?php

declare(strict_types=1);
/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/application
 * https://github.com/php-puff/application/issues
 * Copyright (c) Puff
 */

namespace Puff\Application;

use Puff\Async\EventLoop;
use Puff\Async\Runtime;
use Puff\Di\Container;
use Puff\Di\ServiceProvider;

final class Application
{
    /** @var array<string, Contract> */
    private array $apps = [];
    private readonly Container $container;

    public function __construct(?Container $container = null)
    {
        Exception::register();
        $this->container = $container ?? Container::getInstance() ?? new Container();
        $this->container->instance(self::class, $this);
        $this->container->instance('application', $this);
        Container::setInstance($this->container);

        foreach (Discovery::apps() as $app) {
            $this->mount($app);
        }

        Runtime::onCleanup(
            function (): void {
                $this->container->clearScope();
            },
            'puff.application.scope.' . \spl_object_id($this),
        );

        $this->services();
        foreach ($this->apps as $app) {
            $app->boot($this);
        }
    }

    public function container(): Container
    {
        return $this->container;
    }


    public function run(): void
    {
        (new Process())->run(
            $this->apps,
            fn (array $pids) => $this->runtime($pids),
        );
    }

    public function stop(): void
    {
        foreach (\array_reverse($this->apps) as $app) {
            $app->stop();
        }
        EventLoop::get()->stop();
    }

    private function mount(string $class): void
    {
        $app = $this->container->make($class);
        if (!$app instanceof Contract) {
            throw new \InvalidArgumentException("Application [{$class}] must implement " . Contract::class . '.');
        }
        $this->apps[$app->name()] = $app;
        $this->container->instance($class, $app);
    }

    public function register(string $provider): void
    {
        $service = new $provider($this->container);
        if (!$service instanceof ServiceProvider) {
            throw new \InvalidArgumentException("Provider [{$provider}] must extend " . ServiceProvider::class . '.');
        }
        $service->register();
        if ($service->isDeferred()) {
            $abstract = $this->container->lastBinding();
            if ($abstract === null) {
                return;
            }
            foreach ($service->getBootingCallbacks() as $callback) {
                $this->container->beforeResolving($abstract, $callback);
            }
            if (\method_exists($service, 'boot')) {
                $this->container->resolving($abstract, fn () => $this->container->call([$service, 'boot']));
            }
            foreach ($service->getBootedCallbacks() as $callback) {
                $this->container->afterResolving($abstract, $callback);
            }
            return;
        }
        $service->callBootingCallbacks();
        if (\method_exists($service, 'boot')) {
            $this->container->call([$service, 'boot']);
        }
        $service->callBootedCallbacks();
    }

    private function services(): void
    {
        foreach (Discovery::providers() as $provider) {
            $this->register($provider);
        }
    }

    public function version(): string
    {
        return \Composer\InstalledVersions::getPrettyVersion('puff/application');
    }

    private function runtime(array $workerPids = []): void
    {
        if (!\defined('STDOUT')) {
            return;
        }
        $info = [
            'php' => PHP_VERSION,
            'pid' => \getmypid(),
            'apps' => \array_map(static fn (Contract $app): array => $app->info(), $this->apps),
        ];
        $lines = [''];
        $lines[] = \sprintf('Puff · PHP Unison Fiber Framework (PHP-%s · Master %s)', $info['php'], $info['pid']);
        $lines[] = \str_repeat('─', 78);
        foreach ($info['apps'] as $app) {
            $name = (string) ($app['name'] ?? 'app');
            $appPids = $workerPids[$name] ?? [(int) $info['pid']];
            $pids = \implode(', ', $appPids);
            $lines[] = \sprintf('%s · %s', $name, (string) ($app['url'] ?? $app['addr']));
            $lines[] = \sprintf('Workers · %s', $pids);
            $lines[] = \str_repeat('─', 78);
        }
        $lines[] = '';
        \fwrite(STDOUT, \implode(PHP_EOL, $lines));
    }

}
