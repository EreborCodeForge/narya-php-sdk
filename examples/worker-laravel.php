<?php

declare(strict_types=1);

/**
 * Laravel worker entry point for Narya Runtime Engine.
 *
 * Copy to your Laravel app root and set in nry.yaml:
 *   php.worker_script: worker-laravel.php
 *
 * Requires your app's LaravelNaryaWorker (implements ApplicationWorker).
 */

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
        \Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
        \Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
        \Illuminate\Foundation\Bootstrap\HandleExceptions::class,
        \Illuminate\Foundation\Bootstrap\RegisterFacades::class,
        \Illuminate\Foundation\Bootstrap\RegisterProviders::class,
        \Illuminate\Foundation\Bootstrap\BootProviders::class,
    ]);

    $kernel = $laravel->make(\Illuminate\Contracts\Http\Kernel::class);

    // Replace with your app's LaravelNaryaWorker class:
    // $worker->setApplication(new LaravelNaryaWorker($kernel, enableTerminate: true));
});

$worker->run();
