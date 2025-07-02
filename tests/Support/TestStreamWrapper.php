<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Support;

use function strlen;

/**
 * Test stream wrapper for mocking stdin.
 *
 * @internal
 */
final class TestStreamWrapper
{
    private static array $data = [];

    private string $content = '';

    private int $position = 0;

    public static function reset(): void
    {
        self::$data = [];
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(string: $this->content);
    }

    public function stream_open($path, $mode, $options, &$opened_path): bool
    {
        if ('php://stdin' === $path) {
            $this->content = self::$data['stdin'] ?? '';
            $this->position = 0;

            return true;
        }

        return false;
    }

    public function stream_read($count): string
    {
        $ret = substr(string: $this->content, offset: $this->position, length: $count);
        $this->position += strlen(string: $ret);

        return $ret;
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_write(string $data): int
    {
        if (! isset(self::$data['stdin'])) {
            self::$data['stdin'] = '';
        }
        self::$data['stdin'] .= $data;

        return strlen(string: $data);
    }

    public function url_stat($path, $flags): array
    {
        return [];
    }
}
