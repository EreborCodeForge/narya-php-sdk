<?php

declare(strict_types=1);

namespace Narya\SDK\Runtime;

/**
 * Command-line arguments that the Narya Runtime (Go) passes when starting the worker.
 * Contract: php worker.php --sock /path/to.sock [--max-requests N]
 */
readonly final class WorkerRunArgs
{
    public function __construct(
        public ?string $sockPath,
        public int $maxRequests,
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

        for ($i = 1; $i < count($argv); $i++) {
            if ($argv[$i] === '--sock' && isset($argv[$i + 1])) {
                $sockPath = $argv[++$i];
            } elseif (str_starts_with($argv[$i], '--sock=')) {
                $sockPath = substr($argv[$i], 7);
            } elseif ($argv[$i] === '--max-requests' && isset($argv[$i + 1])) {
                $maxRequests = (int) $argv[$i + 1];
            }
        }

        return new self($sockPath, $maxRequests, $argv[0] ?? 'worker');
    }

    public function exitWithUsage(): never
    {
        fwrite(STDERR, "Usage: php {$this->scriptName} --sock /path/to/socket.sock [--max-requests N]\n");
        exit(1);
    }
}
