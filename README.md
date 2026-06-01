# Narya PHP SDK

PHP library that integrates PHP userland with the **[Narya Runtime Engine](https://github.com/EreborCodeForge/NaryaRuntimeEngine)** (Go).  
Namespace: `Narya\SDK`.

Protocol: **UDS (Unix Domain Sockets)** + **MessagePack**, handshake `NARYA1`/`OK`, 4-byte BE length framing + payload.

## Components

| Component | Description |
|-----------|-------------|
| **Worker** (`Runtime\Worker`) | Orchestrates the loop: receives request from Go, calls application or handler, sends response. Resets state between requests. |
| **WorkerBridge** (`Runtime\WorkerBridge`) | UDS + MessagePack bridge: `connectAndHandshake()`, request loop (`serve()`), read/write frames. |
| **NaryaRequest** / **WorkerRequest** | Request contract and implementation (id, method, uri, path, query, headers, body, remote_addr, host, scheme, timeout_ms, meta, worker_id, runtime_version). |
| **NaryaResponse** / **WorkerResponse** | Response contract and implementation (status, headers, body, error). The Bridge adds `id` and `_meta`. |
| **ApplicationWorker** | Application (framework) contract: `handle(NaryaRequest): array|NaryaResponse` and `reset()`. |
| **LifecycleInterface** / **LifecycleManager** | Worker lifecycle: `boot()` **after** UDS handshake with Go, `shutdown()` on exit (max_requests or EOF). Passed as Worker’s 4th argument or via `setLifecycle()`. |

## Requirements

- PHP 8.2+
- **msgpack** extension (`pecl install msgpack`)
- Linux or WSL (UDS not supported on native Windows)

## Installation

```bash
composer require narya/php-sdk
```

## Basic usage

The Go runtime starts each process with: `php worker.php --sock /path/to.sock`.

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Narya\SDK\Runtime\Worker;
use Narya\SDK\Runtime\WorkerResponse;
use Narya\SDK\Contracts\ApplicationWorker;
use Narya\SDK\Contracts\NaryaRequest;

$app = new class () implements ApplicationWorker {
    public function handle(NaryaRequest $request): array|WorkerResponse
    {
        if ($request->getPath() === '/health' && $request->getMethod() === 'GET') {
            return WorkerResponse::create(200, ['Content-Type' => ['application/json']], '{"status":"ok"}', '');
        }
        return WorkerResponse::create(404, [], '', 'Not Found');
    }

    public function reset(): void
    {
        // Clear per-request state (superglobals, connections, etc.)
    }
};

(new Worker($app))->run();
```

With lifecycle (`boot()` after handshake, `shutdown()` on exit):

```php
use Narya\SDK\Lifecycle\LifecycleManager;

$lifecycle = new LifecycleManager();

// Run once after the UDS handshake (heavy bootstrap belongs here, not before connect)
$lifecycle->onBoot(function (): void {
    // e.g. open persistent DB connection, warm cache, load config
    // MyApp::connectDb();
    // MyApp::warmCache();
});

// Run when the loop ends (max_requests reached, EOF from Go, or exception)
$lifecycle->onShutdown(function (): void {
    // e.g. close connections, flush logs, cleanup
    // MyApp::closeDb();
    // MyApp::flushLogs();
});

(new Worker($app, null, 10000, $lifecycle))->run();
```

Example with a shared resource in the worker (e.g. connection opened in boot, closed in shutdown):

```php
// Simple container: boot() sets it, shutdown() clears it, app uses it in handle()
class WorkerContainer {
    public static ?PDO $db = null;
}

$lifecycle = new LifecycleManager();
$lifecycle->onBoot(function (): void {
    WorkerContainer::$db = new PDO('sqlite::memory:'); // or real DSN
});
$lifecycle->onShutdown(function (): void {
    WorkerContainer::$db = null;
});

$app = new class () implements ApplicationWorker {
    public function handle(NaryaRequest $request): array|WorkerResponse {
        $db = WorkerContainer::$db; // available for the whole loop (until shutdown)
        // use $db for queries...
        return WorkerResponse::create(200, ['Content-Type' => ['application/json']], '{"ok":true}', '');
    }
    public function reset(): void {}
};

(new Worker($app, null, 10000, $lifecycle))->run();
```

With a callable handler (no framework):

```php
$handler = function (array $request): array {
    return [
        'status' => 200,
        'headers' => ['Content-Type' => ['application/json']],
        'body' => '{"message":"Hello"}',
        'error' => '',
    ];
};

(new Worker(null, $handler))->run();
```

## Dependency injection container

If your application already uses a DI container:

- **boot()** — Configure the container once after the UDS handshake (bindings, persistent connections).
- **ApplicationWorker** — Receive the container in the constructor and use it in `handle()` to resolve services.
- **reset()** — **Here you clear the per-request context (ctx)**: request-scoped container state (e.g. `$container->resetRequestScope()`). The Worker calls `reset()` after **each** request.
- **shutdown()** — Cleanup when the worker exits (close connections, flush logs). Called **once** when leaving the loop.

So: **clearing context on each request** → inside **`reset()`**, not in shutdown. See a full example in `examples/worker_with_container.php`.

## Laravel Worker Safety

The worker connects and completes the `NARYA1`/`OK` handshake **before** `boot()` runs, so Laravel bootstrap does not block spawn. Use `LifecycleManager::onBoot()` for `bootstrap/app.php` and set the application on the worker inside that callback.

Per-request `timeout_ms` from Go is applied for that request only; the socket timeout is restored to `WorkerOptions.socketTimeoutSeconds` before the next frame.

Example entry script (`worker-laravel.php` in your app root; set `php.worker_script: worker-laravel.php` in `nry.yaml`):

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Narya\SDK\Lifecycle\LifecycleManager;
use Narya\SDK\Runtime\Worker;
use Narya\SDK\Runtime\WorkerOptions;

$options = new WorkerOptions(
    maxRequests: (int) (getenv('NARYA_MAX_REQUESTS') ?: 300),
    memoryLimitMb: (int) (getenv('NARYA_MEMORY_LIMIT_MB') ?: 256),
    socketTimeoutSeconds: (int) (getenv('NARYA_SOCKET_TIMEOUT') ?: 30),
    gcInterval: (int) (getenv('NARYA_GC_INTERVAL') ?: 10),
);

$lifecycle = new LifecycleManager();
$worker = new Worker(null, null, $options->maxRequests, $lifecycle, $options);

$lifecycle->onBoot(static function () use ($worker): void {
    $laravel = require __DIR__ . '/bootstrap/app.php';
    $laravel->bootstrapWith([
        // same bootstrappers as public/index.php or Octane
    ]);
    $kernel = $laravel->make(\Illuminate\Contracts\Http\Kernel::class);
    $worker->setApplication(new LaravelNaryaWorker($kernel, enableTerminate: true));
});

$worker->run();
```

See `examples/worker-laravel.php` for a copy-paste template.

For Laravel persistent workers, use conservative limits first:

```php
use Narya\SDK\Runtime\Worker;
use Narya\SDK\Runtime\WorkerOptions;

$options = new WorkerOptions(
    maxRequests: (int) getenv('NARYA_MAX_REQUESTS') ?: 300,
    memoryLimitMb: (int) getenv('NARYA_MEMORY_LIMIT_MB') ?: 256,
    socketTimeoutSeconds: 30,
    gcInterval: 10,
);

$lifecycle = new LifecycleManager();
$worker = new Worker(null, null, $options->maxRequests, $lifecycle, $options);

$lifecycle->onBoot(static function () use ($worker): void {
  // bootstrap Laravel and $worker->setApplication(...)
});

$worker->run();
```

Recommended Laravel reset checklist in `ApplicationWorker::reset()`:

- forget current request instance;
- clear scoped container instances;
- clear resolved Facades;
- optionally call `Kernel::terminate`;
- clear output buffers (handled by SDK when `resetOutputBuffersAfterRequest` is true);
- run GC periodically via `gcInterval`.

Suggested env vars for tuning:

```env
NARYA_MAX_REQUESTS=300
NARYA_MEMORY_LIMIT_MB=512
NARYA_GC_INTERVAL=10
NARYA_SOCKET_TIMEOUT=30
```

## Tests

```bash
composer install
./vendor/bin/phpunit
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit --testsuite Integration
```

## License

MIT
