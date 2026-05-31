<?php

declare(strict_types=1);

namespace Narya\SDK\Runtime;

/**
 * Command-line arguments that the Narya Runtime (Go) passes when starting the worker.
 * Contract: php worker.php --sock /path/to.sock [--max-requests N] [--memory-limit-mb N] [--socket-timeout N]
 */
readonly final class WorkerRunArgs
{
    public function __construct(
        public ?string $sockPath,
        public int $maxRequests,
        public int $memoryLimitMb,
        public int $socketTimeoutSeconds,
        private string $scriptName,
    ) {
    }

    /**
     * Parse $argv (e.g. $GLOBALS['argv']). Returns sockPath null if --sock is not passed.
     *
     * @param list<string> $argv
     */
    public static function fromArgv(array $argv, int $defaultMaxRequests = 10000): self
    {
        $sockPath = null;
        $maxRequests = $defaultMaxRequests;
        $memoryLimitMb = 0;
        $socketTimeoutSeconds = 0;

        for ($i = 1; $i < count($argv); $i++) {
            if ($argv[$i] === '--sock' && isset($argv[$i + 1])) {
                $sockPath = $argv[++$i];
            } elseif (str_starts_with($argv[$i], '--sock=')) {
                $sockPath = substr($argv[$i], 7);
            } elseif ($argv[$i] === '--max-requests' && isset($argv[$i + 1])) {
                $maxRequests = (int) $argv[$i + 1];
            } elseif (str_starts_with($argv[$i], '--max-requests=')) {
                $maxRequests = (int) substr($argv[$i], 15);
            } elseif ($argv[$i] === '--memory-limit-mb' && isset($argv[$i + 1])) {
                $memoryLimitMb = max(0, (int) $argv[++$i]);
            } elseif (str_starts_with($argv[$i], '--memory-limit-mb=')) {
                $memoryLimitMb = max(0, (int) substr($argv[$i], 18));
            } elseif ($argv[$i] === '--socket-timeout' && isset($argv[$i + 1])) {
                $socketTimeoutSeconds = max(1, (int) $argv[++$i]);
            } elseif (str_starts_with($argv[$i], '--socket-timeout=')) {
                $socketTimeoutSeconds = max(1, (int) substr($argv[$i], 17));
            }
        }

        return new self(
            $sockPath,
            $maxRequests,
            $memoryLimitMb,
            $socketTimeoutSeconds,
            $argv[0] ?? 'worker',
        );
    }

    public function exitWithUsage(): never
    {
        fwrite(
            STDERR,
            "Usage: php {$this->scriptName} --sock /path/to/socket.sock"
            . " [--max-requests N] [--memory-limit-mb N] [--socket-timeout N]\n"
        );
        exit(1);
    }
}
