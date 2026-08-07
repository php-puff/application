<?php
declare(strict_types=1);
/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/application
 * https://github.com/php-puff/application/issues
 * Copyright (c) Puff
 */

namespace Puff\Application\Tests;

use Puff\Application\Application;
use Puff\Application\Error\ErrorHandler;
use Puff\Di\Container;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    protected function tearDown(): void
    {
        ErrorHandler::restore();
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
        $handler = ErrorHandler::register();

        $this->expectException(\ErrorException::class);
        $handler->onError(E_ERROR, 'application error', __FILE__, __LINE__);
    }

    public function testErrorHandlerSupportsCustomLogger(): void
    {
        ErrorHandler::restore();
        $logged = null;
        $handler = ErrorHandler::register(static function (\Throwable $exception) use (&$logged): void {
            $logged = $exception;
        });
        $exception = new \RuntimeException('logged');

        $handler->onException($exception);

        self::assertSame($exception, $logged);
    }
}

final class HelperService
{
}
