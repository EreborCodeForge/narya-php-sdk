<?php

declare(strict_types=1);

namespace Narya\SDK\Runtime;

use Narya\SDK\Contracts\ApplicationWorker;

final readonly class WorkerResetter
{
    public function __construct(
        private WorkerOptions $options
    ) {
    }

    public function reset(?ApplicationWorker $application = null): void
    {
        $this->resetSuperglobals();

        if ($this->options->clearHeadersAfterRequest && function_exists('header_remove') && !headers_sent()) {
            header_remove();
        }

        if ($application !== null) {
            $application->reset();
        }

        if ($this->options->resetOutputBuffersAfterRequest) {
            $this->clearOutputBuffers();
        }

        $this->runGc();
    }

    private function resetSuperglobals(): void
    {
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_COOKIE = [];
        $_FILES = [];

        $_SERVER = array_filter(
            $_SERVER,
            static fn ($key): bool => is_string($key) && str_starts_with($key, 'PHP_'),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function clearOutputBuffers(): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
    }

    private function runGc(): void
    {
        gc_collect_cycles();

        if ($this->options->enableGcMemCaches && function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }
    }
}
