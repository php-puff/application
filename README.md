# Puff Application

Protocol-independent application lifecycle for PHP Unison Fiber Framework. It owns one shared DI container, configuration binding, scoped Fiber cleanup, and Composer-discovered providers and protocol applications.

```php
$app = new Puff\Application\Application();
$app->run();
```

Installed protocol packages are mounted through Composer discovery:

- `puff/http-server` for HTTP routing and PSR-15 handling.
- `puff/websocket-server` for WebSocket connection and frame dispatch.

Every installed application runs in its own supervised worker process. Configure `<app>.workers` to increase its worker count; the default is one worker per application.

Providers and applications are initialized again after `fork()`, so sockets and other worker resources are created in the child process. A worker is announced only after `boot()`, `start()`, and `info()` succeed.

Unexpected worker exits use bounded exponential backoff. A worker that repeatedly fails during startup or exits within 30 seconds is restarted at most five times. A process signal stops accepting restarts, forwards the signal to every child, and waits for them to exit.

The master process requires the `pcntl` and `posix` extensions at runtime. They are Composer suggestions rather than installation requirements, allowing the package to be used for protocol-independent testing on platforms without process control.

Application names must be non-empty and unique. `info()` must return the same `name` and either an `url` or `addr` value so readiness and startup output remain deterministic.
