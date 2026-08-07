# Puff Application

Protocol-independent application lifecycle for PHP Unison Fiber Framework. It owns one shared DI container, configuration binding, scoped Fiber cleanup, and Composer-discovered providers and protocol applications.

```php
$app = new Puff\Application\Application()
$app->run();
```

Installed protocol packages are mounted through Composer discovery:

- `puff/webserver` for HTTP routing and PSR-15 handling.
- `puff/websocket` for WebSocket connection and frame dispatch.

Every installed application runs in its own supervised worker process. Configure `<app>.workers` to increase its worker count; the default is one worker per application.
