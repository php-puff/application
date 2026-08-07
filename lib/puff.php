<?php

declare(strict_types=1);
/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/application
 * https://github.com/php-puff/application/issues
 * Copyright (c) Puff
 */

use Puff\Application\Application;
use Puff\Di\Container;

if (!\function_exists('app')) {
    function app(?string $abstract = null, array $parameters = [], bool $events = true): mixed
    {
        $container = Container::getInstance();
        if ($container === null) {
            throw new LogicException('The Puff application has not been booted.');
        }
        return $abstract === null
            ? $container->get(Application::class)
            : $container->make($abstract, $parameters, $events);
    }
}

if (!\function_exists('make')) {
    function make(string $abstract, array $parameters = [], bool $events = true): mixed
    {
        return app($abstract, $parameters, $events);
    }
}
