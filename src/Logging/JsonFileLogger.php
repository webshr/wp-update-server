<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Logging;

final class JsonFileLogger implements LoggerInterface
{
    public function __construct(
        private readonly string $logDir,
        private readonly int $maxBytes = 10485760
    ) {
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0700, true);
        }
        @chmod($this->logDir, 0700);
    }

    public function log(string $event, array $context = []): void
    {
        $line = json_encode([
            'timestamp' => gmdate('c'),
            'event' => $event,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL;

        $path = $this->pathForLine($line);
        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        @chmod($path, 0600);
    }

    private function pathForLine(string $line): string
    {
        $basePath = $this->logDir . DIRECTORY_SEPARATOR . 'update-server-' . gmdate('Y-m-d') . '.log';
        if (!is_file($basePath) || (int) filesize($basePath) + strlen($line) <= $this->maxBytes) {
            return $basePath;
        }

        for ($index = 1; $index < 1000; $index++) {
            $path = $this->logDir . DIRECTORY_SEPARATOR . sprintf('update-server-%s.%d.log', gmdate('Y-m-d'), $index);
            if (!is_file($path) || (int) filesize($path) + strlen($line) <= $this->maxBytes) {
                return $path;
            }
        }

        return $this->logDir . DIRECTORY_SEPARATOR . sprintf('update-server-%s.overflow.log', gmdate('Y-m-d'));
    }
}
