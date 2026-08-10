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
    private const READY_TIMEOUT = 10.0;
    private const STABLE_SECONDS = 30.0;
    private const RESTART_LIMIT = 5;
    private const RESTART_BASE_DELAY = 0.1;
    private const RESTART_MAX_DELAY = 5.0;

    /** @var array<int, array{app: Contract, started: float, failures: int}> */
    private array $workers = [];

    /** @var array<int, array{app: Contract, at: float, failures: int}> */
    private array $pending = [];

    /** @var array<string, array{name: string, addr?: string, url?: string, workers?: int, ...<string, mixed>}> */
    private array $workerInfo = [];

    private bool $stopping = false;

    /**
     * @param array<string, Contract>                                                                                                                      $apps
     * @param callable(Contract): void                                                                                                                     $initializer
     * @param callable(array<string, list<int>>, array<string, array{name: string, addr?: string, url?: string, workers?: int, ...<string, mixed>}>): void $started
     */
    public function run(array $apps, callable $initializer, callable $started): void
    {
        $this->assertSupported();
        $this->signal();

        try {
            foreach ($apps as $app) {
                $count = $app->workers();
                if ($count < 1) {
                    throw new \InvalidArgumentException("Application [{$app->name()}] must configure at least one worker.");
                }
                for ($worker = 0; $worker < $count; ++$worker) {
                    $this->spawn($app, $initializer, 0);
                }
            }
            $started($this->PIDs(), $this->workerInfo);
            $this->supervise($initializer);
        } catch (\Throwable $exception) {
            $this->stopping = true;
            $this->terminateWorkers(SIGTERM);
            $this->reapWorkers();
            throw $exception;
        }
    }

    /** @param callable(Contract): void $initializer */
    private function spawn(Contract $app, callable $initializer, int $failures): void
    {
        $sockets = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new \RuntimeException('Unable to create the worker readiness channel.');
        }

        [$parent, $child] = $sockets;
        $pid = \pcntl_fork();
        if ($pid === -1) {
            \fclose($parent);
            \fclose($child);
            throw new \RuntimeException("Unable to fork {$app->name()} worker.");
        }
        if ($pid === 0) {
            \fclose($parent);
            $this->worker($app, $initializer, $child);
        }

        \fclose($child);
        $this->workers[$pid] = [
            'app' => $app,
            'started' => \microtime(true),
            'failures' => $failures,
        ];

        try {
            $info = $this->awaitReady($pid, $app, $parent);
            $this->workerInfo[$app->name()] = $info;
        } catch (\Throwable $exception) {
            @\posix_kill($pid, SIGTERM);
            \pcntl_waitpid($pid, $status);
            unset($this->workers[$pid]);
            throw $exception;
        } finally {
            \fclose($parent);
        }
    }

    /**
     * @param callable(Contract): void $initializer
     * @param resource                 $ready
     */
    private function worker(Contract $app, callable $initializer, mixed $ready): never
    {
        $this->workers = [];
        $this->pending = [];
        EventLoop::reset();
        $loop = EventLoop::get();
        $stopped = false;
        $stopRequested = false;
        $stop = static function () use ($app, $loop, &$stopped): void {
            if ($stopped) {
                return;
            }
            $stopped = true;
            $app->stop();
            $loop->stop();
        };
        $requestStop = static function () use ($loop, &$stopRequested): void {
            $stopRequested = true;
            $loop->stop();
        };

        if (\function_exists('pcntl_async_signals')) {
            \pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM, SIGQUIT] as $signal) {
                \pcntl_signal($signal, static fn () => $requestStop());
            }
        }
        $loop->setErrorHandler(static function (\Throwable $exception): bool {
            \error_log((string) $exception);
            return true;
        });

        try {
            $initializer($app);
            if ($stopRequested) {
                $stop();
                \fclose($ready);
                exit(0);
            }
            $app->start();
            $info = $app->info();
            $this->validateInfo($app, $info);
            $payload = \json_encode(['ready' => true, 'info' => $info], JSON_THROW_ON_ERROR) . "\n";
            \fwrite($ready, $payload);
            \fclose($ready);
            $loop->run();
            $stop();
            exit(0);
        } catch (\Throwable $exception) {
            if (\is_resource($ready)) {
                $payload = \json_encode([
                    'ready' => false,
                    'error' => $exception->getMessage(),
                ], JSON_THROW_ON_ERROR) . "\n";
                @\fwrite($ready, $payload);
                @\fclose($ready);
            }
            \error_log((string) $exception);
            $stop();
            exit(1);
        }
    }

    /** @param callable(Contract): void $initializer */
    private function supervise(callable $initializer): void
    {
        while ($this->workers !== [] || $this->pending !== []) {
            $pid = \pcntl_waitpid(-1, $status, WNOHANG);
            if ($pid > 0) {
                $worker = $this->workers[$pid] ?? null;
                unset($this->workers[$pid]);
                if (!$this->stopping && $worker !== null) {
                    $this->scheduleRestart($worker);
                }
                continue;
            }

            if (!$this->stopping) {
                $this->startPending($initializer);
            }
            \usleep(50_000);
        }
    }

    /** @param array{app: Contract, started: float, failures: int} $worker */
    private function scheduleRestart(array $worker): void
    {
        $stable = \microtime(true) - $worker['started'] >= self::STABLE_SECONDS;
        $failures = $stable ? 1 : $worker['failures'] + 1;
        if ($failures > self::RESTART_LIMIT) {
            \error_log("Application [{$worker['app']->name()}] exceeded the worker restart limit.");
            return;
        }
        $delay = \min(self::RESTART_BASE_DELAY * (2 ** ($failures - 1)), self::RESTART_MAX_DELAY);
        $this->pending[] = [
            'app' => $worker['app'],
            'at' => \microtime(true) + $delay,
            'failures' => $failures,
        ];
    }

    /** @param callable(Contract): void $initializer */
    private function startPending(callable $initializer): void
    {
        $now = \microtime(true);
        foreach ($this->pending as $index => $pending) {
            if ($pending['at'] > $now) {
                continue;
            }
            unset($this->pending[$index]);
            try {
                $this->spawn($pending['app'], $initializer, $pending['failures']);
            } catch (\Throwable $exception) {
                \error_log((string) $exception);
                $this->scheduleRestart([
                    'app' => $pending['app'],
                    'started' => $now,
                    'failures' => $pending['failures'],
                ]);
            }
        }
    }

    /**
     * @param  resource                                                                            $socket
     * @return array{name: string, addr?: string, url?: string, workers?: int, ...<string, mixed>}
     */
    private function awaitReady(int $pid, Contract $app, mixed $socket): array
    {
        \stream_set_blocking($socket, false);
        $deadline = \microtime(true) + self::READY_TIMEOUT;
        $buffer = '';
        while (\microtime(true) < $deadline) {
            $read = [$socket];
            $write = null;
            $except = null;
            $seconds = (int) \max(0, \floor($deadline - \microtime(true)));
            $microseconds = 100_000;
            $selected = @\stream_select($read, $write, $except, $seconds, $microseconds);
            if ($selected === false) {
                throw new \RuntimeException("Unable to read readiness from {$app->name()} worker {$pid}.");
            }
            if ($selected === 0) {
                continue;
            }
            $chunk = \fread($socket, 8192);
            if ($chunk !== false) {
                $buffer .= $chunk;
            }
            if (!\str_contains($buffer, "\n") && !\feof($socket)) {
                continue;
            }
            $payload = \json_decode(\trim($buffer), true);
            if (!\is_array($payload) || ($payload['ready'] ?? false) !== true) {
                $message = \is_string($payload['error'] ?? null) ? $payload['error'] : 'unknown startup error';
                throw new \RuntimeException("Application [{$app->name()}] worker failed to start: {$message}");
            }
            $info = $payload['info'] ?? null;
            if (!\is_array($info)) {
                throw new \RuntimeException("Application [{$app->name()}] returned invalid worker information.");
            }
            /** @var array{name: string, addr?: string, url?: string, workers?: int, ...<string, mixed>} $info */
            return $info;
        }
        throw new \RuntimeException("Application [{$app->name()}] worker {$pid} did not become ready within 10 seconds.");
    }

    /** @param array<string, mixed> $info */
    private function validateInfo(Contract $app, array $info): void
    {
        if (($info['name'] ?? null) !== $app->name()) {
            throw new \UnexpectedValueException("Application [{$app->name()}] info must contain its name.");
        }
        if (!isset($info['url']) && !isset($info['addr'])) {
            throw new \UnexpectedValueException("Application [{$app->name()}] info must contain url or addr.");
        }
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
                $this->pending = [];
                $this->terminateWorkers($signal);
            });
        }
    }

    private function terminateWorkers(int $signal): void
    {
        foreach (\array_keys($this->workers) as $pid) {
            @\posix_kill($pid, $signal);
        }
    }

    private function reapWorkers(): void
    {
        $deadline = \microtime(true) + 5.0;
        while ($this->workers !== [] && \microtime(true) < $deadline) {
            $pid = \pcntl_waitpid(-1, $status, WNOHANG);
            if ($pid > 0) {
                unset($this->workers[$pid]);
                continue;
            }
            \usleep(50_000);
        }
        if ($this->workers !== []) {
            $this->terminateWorkers(SIGKILL);
            while (($pid = \pcntl_wait($status)) > 0) {
                unset($this->workers[$pid]);
            }
        }
    }

    private function assertSupported(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid') || !\function_exists('posix_kill')) {
            throw new \RuntimeException('Puff process workers require the pcntl and posix extensions.');
        }
    }

    /** @return array<string, list<int>> */
    private function PIDs(): array
    {
        $ids = [];
        foreach ($this->workers as $pid => $worker) {
            $ids[$worker['app']->name()][] = $pid;
        }
        return $ids;
    }
}
