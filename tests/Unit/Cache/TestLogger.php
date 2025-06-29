<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Cache;

use Psr\Log\LoggerInterface;
use Stringable;

/**
 * Simple test logger.
 */
final class TestLogger implements LoggerInterface
{
    private array $logs = [];

    public function alert(string | Stringable $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string | Stringable $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function debug(string | Stringable $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function emergency(string | Stringable $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function error(string | Stringable $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function getLogs(): array
    {
        return $this->logs;
    }

    public function hasLog(string $level, string $message): bool
    {
        foreach ($this->logs as $log) {
            if ($log['level'] === $level && str_contains((string) $log['message'], $message)) {
                return true;
            }
        }

        return false;
    }

    public function info(string | Stringable $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function log($level, string | Stringable $message, array $context = []): void
    {
        $this->logs[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }

    public function notice(string | Stringable $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function warning(string | Stringable $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }
}
