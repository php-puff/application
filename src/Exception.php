<?php

declare(strict_types=1);
/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/application
 * https://github.com/php-puff/application/issues
 * Copyright (c) Puff
 */

namespace Puff\Application;

final class Exception
{
    private const FATAL_ERRORS = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    private static ?self $active = null;
    private static bool $shutdownRegistered = false;

    /** @var null|callable(\Throwable): void */
    private $logger;

    /** @param null|callable(\Throwable): void $logger */
    private function __construct(?callable $logger)
    {
        $this->logger = $logger;
    }

    /** @param null|callable(\Throwable): void $logger */
    public static function register(?callable $logger = null): self
    {
        if (self::$active !== null) {
            if ($logger !== null) {
                self::$active->logger = $logger;
            }
            return self::$active;
        }

        $handler = new self($logger);
        \set_error_handler($handler->onError(...));
        \set_exception_handler($handler->onException(...));
        if (!self::$shutdownRegistered) {
            \register_shutdown_function(self::onShutdown(...));
            self::$shutdownRegistered = true;
        }
        return self::$active = $handler;
    }

    public static function registered(): bool
    {
        return self::$active !== null;
    }

    public static function restore(): void
    {
        if (self::$active === null) {
            return;
        }
        \restore_error_handler();
        \restore_exception_handler();
        self::$active = null;
    }

    public function onError(int $severity, string $message, string $file, int $line): bool
    {
        if ((\error_reporting() & $severity) === 0) {
            return false;
        }
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    public function onException(\Throwable $exception): void
    {
        if ($this->logger !== null) {
            ($this->logger)($exception);
            return;
        }
        \error_log((string) $exception);
    }

    public static function onShutdown(): void
    {
        $error = \error_get_last();
        if (self::$active === null || $error === null || !\in_array($error['type'], self::FATAL_ERRORS, true)) {
            return;
        }
        self::$active->onException(new \ErrorException(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line'],
        ));
    }
}
