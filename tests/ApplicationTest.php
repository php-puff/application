<?php

/*
 * PHP Fiber Framework
 * https://github.com/php-puff/application
 * https://github.com/php-puff/application/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Application\Tests;

use PHPUnit\Framework\TestCase;
use Puff\Application\Application;
use Puff\Application\Contract;
use Puff\Application\Discovery;
use Puff\Application\Exception;
use Puff\Di\Container;
use Puff\Di\ServiceProvider;

final class ApplicationTest extends TestCase
{
    protected function tearDown(): void
    {
        Exception::restore();
        Container::setInstance(null);
    }

    public function testOwnsProtocolIndependentLifecycle(): void
    {
        $app = new Application();

        self::assertFalse($app->container()->bound('request'));
        self::assertFalse($app->container()->bound('router'));
    }

    public function testUsesCurrentContainerByDefault(): void
    {
        $container = new Container();
        Container::setInstance($container);

        self::assertSame($container, (new Application())->container());
    }

    public function testApplicationHelpersUseTheActiveContainer(): void
    {
        $container = new Container();
        $application = new Application($container);
        $resolved = false;
        $application->container()->bind(HelperService::class);
        $application->container()->beforeResolving(HelperService::class, static function () use (&$resolved): void {
            $resolved = true;
        });

        self::assertSame($application, \app());
        self::assertInstanceOf(HelperService::class, \make(HelperService::class));
        self::assertTrue($resolved);
    }

    public function testErrorHandlerConvertsPhpErrorsToExceptions(): void
    {
        new Application();
        $handler = Exception::register();

        $this->expectException(\ErrorException::class);
        $handler->onError(E_ERROR, 'application error', __FILE__, __LINE__);
    }

    public function testErrorHandlerSupportsCustomLogger(): void
    {
        Exception::restore();
        $logged = null;
        $handler = Exception::register(static function (\Throwable $exception) use (&$logged): void {
            $logged = $exception;
        });
        $exception = new \RuntimeException('logged');

        $handler->onException($exception);

        self::assertSame($exception, $logged);
    }

    public function testRegisterUpdatesTheLoggerOfTheActiveHandler(): void
    {
        $logged = null;
        $handler = Exception::register();
        self::assertSame($handler, Exception::register(static function (\Throwable $exception) use (&$logged): void {
            $logged = $exception;
        }));
        $exception = new \RuntimeException('updated logger');

        $handler->onException($exception);

        self::assertSame($exception, $logged);
    }

    public function testProvidersAreRegisteredBeforeApplicationsAreBooted(): void
    {
        $application = new Application(new Container(), [ProviderAwareApp::class], [TestProvider::class]);

        self::assertTrue($application->container()->make(ProviderAwareApp::class)->providerWasReady);
    }

    public function testDuplicateApplicationNamesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Application name [duplicate] is already registered.');

        new Application(new Container(), [FirstNamedApp::class, SecondNamedApp::class], []);
    }

    public function testReadsItsVersionFromComposerMetadata(): void
    {
        $application = new Application();

        self::assertSame(Discovery::version('puff/application') ?? 'dev-main', $application->version());
    }
}

final class HelperService
{
}

final class TestProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('test.ready', true);
    }
}

class ProviderAwareApp implements Contract
{
    public bool $providerWasReady = false;

    public function name(): string
    {
        return 'provider-aware';
    }

    public function boot(Application $app): void
    {
        $this->providerWasReady = $app->container()->bound('test.ready');
    }

    public function info(): array
    {
        return ['name' => $this->name(), 'addr' => 'test'];
    }

    public function stop(): void
    {
    }

    public function start(): void
    {
    }

    public function workers(): int
    {
        return 1;
    }
}

final class FirstNamedApp extends ProviderAwareApp
{
    public function name(): string
    {
        return 'duplicate';
    }
}

final class SecondNamedApp extends ProviderAwareApp
{
    public function name(): string
    {
        return 'duplicate';
    }
}
