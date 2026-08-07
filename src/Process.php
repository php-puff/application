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

final class Process
{
    /** @var array<int, Contract> */
    private array $workers = [];
    private bool $stopping = false;

    /** @param array<string, Contract> $apps */
    public function run(array $apps, callable $started): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_wait') || !\function_exists('posix_kill')) {
            throw new \RuntimeException('Puff process workers require the pcntl and posix extensions.');
        }
        foreach ($apps as $app) {
            $count = $app->workers();
            for ($worker = 0; $worker < $count; ++$worker) {
                $this->fork($app);
            }
        }
        $this->signal();
        $started($this->PIDs());

        while ($this->workers !== []) {
            $pid = \pcntl_wait($status);
            if ($pid <= 0) {
                continue;
            }
            $app = $this->workers[$pid] ?? null;
            unset($this->workers[$pid]);
            if (!$this->stopping && $app !== null) {
                $this->fork($app);
            }
        }
    }

    private function fork(Contract $app): void
    {
        $pid = \pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException("Unable to fork {$app->name()} worker.");
        }
        if ($pid > 0) {
            $this->workers[$pid] = $app;
            return;
        }

        $this->workers = [];
        $loop = EventLoop::get();
        $app->start();
        if (\function_exists('pcntl_async_signals')) {
            \pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM, SIGQUIT] as $signal) {
                \pcntl_signal($signal, function () use ($app): void {
                    $app->stop();
                    EventLoop::get()->stop();
                });
            }
        }
        $loop->setErrorHandler(static fn (\Throwable $exception): bool => (bool) \error_log((string) $exception));
        $loop->run();
        $app->stop();
        exit(0);
    }

    private function signal(): void
    {
        if (!\function_exists('pcntl_async_signals')) {
            return;
        }
        \pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM, SIGQUIT] as $signal) {
            \pcntl_signal($signal, function (int $signal): void {
                if ($this->stopping) {
                    return;
                }
                $this->stopping = true;
                foreach (\array_keys($this->workers) as $pid) {
                    @\posix_kill($pid, $signal);
                }
            });
        }
    }

    /** @return array<string, list<int>> */
    private function PIDs(): array
    {
        $ids = [];
        foreach ($this->workers as $pid => $app) {
            $ids[$app->name()][] = $pid;
        }
        return $ids;
    }
}
