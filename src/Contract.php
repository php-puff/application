<?php

declare(strict_types=1);
/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/application
 * https://github.com/php-puff/application/issues
 * Copyright (c) Puff
 */

namespace Puff\Application;

interface Contract
{
    public function name(): string;

    public function boot(Application $app): void;

    /** @return array{name: string, addr?: string, url?: string, workers?: int, ...<string, mixed>} */
    public function info(): array;

    public function stop(): void;

    public function start(): void;

    public function workers(): int;
}
