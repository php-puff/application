<?php

/*
 * PHP Fiber Framework
 * https://github.com/php-puff/application
 * https://github.com/php-puff/application/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Application;

use Puff\Async\EventLoop;
use Puff\Async\Runtime;
use Puff\Di\Container;
use Puff\Di\ServiceProvider;

final class Application
{
    /** @var array<string, Contract> */
    private array $apps = [];

    /** @var list<ServiceProvider> */
    private array $providers = [];

    private readonly Container $container;

    /**
     * @param null|list<class-string> $apps
     * @param null|list<class-string> $providers
     */
    public function __construct(?Container $container = null, ?array $apps = null, ?array $providers = null)
    {
        $previousContainer = Container::getInstance();
        $exceptionWasRegistered = Exception::registered();
        Exception::register();

        try {
            $this->container = $container ?? $previousContainer ?? new Container();
            $this->container->instance(self::class, $this);
            $this->container->instance('application', $this);
            Container::setInstance($this->container);

            $this->services($providers ?? Discovery::providers());
            foreach ($apps ?? Discovery::apps() as $app) {
                $this->mount($app);
            }
            foreach ($this->apps as $app) {
                $app->boot($this);
            }

            $scopeContainer = $this->container;
            Runtime::onCleanup(static function () use ($scopeContainer): void {
                $scopeContainer->clearScope();
            }, 'puff.application.scope');
            $this->registerException();
        } catch (\Throwable $exception) {
            Container::setInstance($previousContainer);
            if (!$exceptionWasRegistered) {
                Exception::restore();
            }
            throw $exception;
        }
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function run(): void
    {
        try {
            (new Process())->run(
                $this->apps,
                fn (Contract $app) => $this->bootWorker($app),
                fn (array $pids, array $info) => $this->runtime($pids, $info),
            );
        } finally {
            $this->stop();
        }
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
        $name = \trim($app->name());
        if ($name === '') {
            throw new \InvalidArgumentException("Application [{$class}] must have a non-empty name.");
        }
        if (isset($this->apps[$name])) {
            throw new \InvalidArgumentException("Application name [{$name}] is already registered.");
        }
        $this->apps[$name] = $app;
        $this->container->instance($class, $app);
    }

    public function register(string $provider): void
    {
        $service = new $provider($this->container);
        if (!$service instanceof ServiceProvider) {
            throw new \InvalidArgumentException("Provider [{$provider}] must extend " . ServiceProvider::class . '.');
        }
        $service->register();
        $this->providers[] = $service;
        if ($service->isDeferred()) {
            $abstract = $this->container->lastBinding();
            if ($abstract === null) {
                return;
            }
            $booted = false;
            foreach ($service->getBootingCallbacks() as $callback) {
                $this->container->beforeResolving($abstract, $callback);
            }
            if (\method_exists($service, 'boot')) {
                $this->container->resolving($abstract, function () use ($service, &$booted): void {
                    if ($booted) {
                        return;
                    }
                    $booted = true;
                    $this->container->call([$service, 'boot']);
                });
            }
            foreach ($service->getBootedCallbacks() as $callback) {
                $this->container->afterResolving($abstract, $callback);
            }
            return;
        }
        $this->bootProvider($service);
    }

    /** @param list<class-string> $providers */
    private function services(array $providers): void
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function version(): string
    {
        return Discovery::version('puff/application') ?? 'dev-main';
    }

    /**
     * @param array<string, list<int>>                                                                           $workerPids
     * @param array<string, array{name: string, addr?: string, url?: string, workers?: int, ...<string, mixed>}> $workerInfo
     */
    private function runtime(array $workerPids = [], array $workerInfo = []): void
    {
        if (!\defined('STDOUT')) {
            return;
        }
        $info = [
            'php' => PHP_VERSION,
            'pid' => \getmypid(),
            'apps' => $workerInfo !== []
                ? \array_values($workerInfo)
                : \array_map(static fn (Contract $app): array => $app->info(), $this->apps),
        ];
        $lines = [''];
        $lines[] = \sprintf('Puff · PHP Unison Fiber Framework (PHP-%s · Master %s)', $info['php'], $info['pid']);
        $lines[] = \str_repeat('─', 78);
        foreach ($info['apps'] as $app) {
            $name = $app['name'];
            $appPids = $workerPids[$name] ?? [(int) $info['pid']];
            $pids = \implode(', ', $appPids);
            $address = (string) ($app['url'] ?? $app['addr'] ?? 'ready');
            $lines[] = \sprintf('%s · %s', $name, $address);
            $lines[] = \sprintf('Workers · %s', $pids);
            $lines[] = \str_repeat('─', 78);
        }
        $lines[] = '';
        \fwrite(STDOUT, \implode(PHP_EOL, $lines));
    }

    private function bootWorker(Contract $app): void
    {
        foreach ($this->providers as $provider) {
            if ($provider->isDeferred()) {
                continue;
            }
            $this->bootProvider($provider);
        }
        $app->boot($this);
        $this->registerException();
    }

    private function bootProvider(ServiceProvider $provider): void
    {
        $provider->callBootingCallbacks();
        if (\method_exists($provider, 'boot')) {
            $this->container->call([$provider, 'boot']);
        }
        $provider->callBootedCallbacks();
    }

    private function registerException(): void
    {
        foreach (['Psr\\Log\\LoggerInterface', 'log'] as $service) {
            if (!$this->container->bound($service)) {
                continue;
            }
            $logger = $this->container->make($service);
            if (!\is_object($logger) || !\method_exists($logger, 'error')) {
                continue;
            }
            Exception::register(static fn (\Throwable $exception) => $logger->error(
                $exception->getMessage(),
                ['exception' => $exception],
            ));
            return;
        }
    }

}
