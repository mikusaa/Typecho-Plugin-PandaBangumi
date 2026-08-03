<?php

namespace TypechoPlugin\PandaBangumi;

final class CacheStore
{
    private const FAILURE_RETRY = 300;

    /** @var callable */
    private $clock;

    public function __construct(private string $root, ?callable $clock = null)
    {
        $this->root = rtrim($root, '/');
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function now(): int
    {
        return (int)($this->clock)();
    }

    public function ensureDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            return is_writable($directory);
        }
        return @mkdir($directory, 0755, true) && is_writable($directory);
    }

    public function directory(string $name): string
    {
        $directory = $this->root . '/' . trim($name, '/');
        $this->ensureDirectory($directory);
        return $directory;
    }

    public function dataPath(string $fileName): string
    {
        return $this->directory('data') . '/' . basename($fileName);
    }

    public function read(string $filePath): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return array();
        }

        $raw = file_get_contents($filePath);
        if ($raw === false) {
            return array();
        }

        $cache = json_decode($raw, true);
        return is_array($cache) ? $cache : array();
    }

    public function write(string $filePath, array $cache): bool
    {
        $json = json_encode($cache, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '[]';
        }

        $directory = dirname($filePath);
        if (!is_dir($directory) || !is_writable($directory)) {
            return false;
        }

        $tmpFile = tempnam($directory, 'pb_');
        if ($tmpFile === false) {
            return false;
        }

        if (file_put_contents($tmpFile, $json, LOCK_EX) === false) {
            @unlink($tmpFile);
            return false;
        }

        if (!@rename($tmpFile, $filePath)) {
            @unlink($tmpFile);
            return false;
        }
        return true;
    }

    public function freshness(string $filePath, int $validTimeSpan): mixed
    {
        $content = $this->read($filePath);
        if (!array_key_exists('time', $content) || $content['time'] < 1) {
            return -1;
        }
        if ($this->now() - $content['time'] > $validTimeSpan) {
            return 1;
        }
        return $content;
    }

    public function isRefreshDeferred(array $cache): bool
    {
        return (int)($cache['retry_after'] ?? 0) > $this->now();
    }

    public function deferRefresh(string $filePath, array $cache): array
    {
        if (!isset($cache['time'])) {
            $cache['time'] = 1;
        }
        $cache['retry_after'] = $this->now() + self::FAILURE_RETRY;
        $this->write($filePath, $cache);
        return $cache;
    }

    public function usable(
        string $filePath,
        int $validTimeSpan,
        array $stored,
        callable $isCompatible
    ): ?array {
        if (!$isCompatible($stored)) {
            return null;
        }

        $fresh = $this->freshness($filePath, $validTimeSpan);
        if (is_array($fresh)) {
            return $fresh;
        }
        return $this->isRefreshDeferred($stored) ? $stored : null;
    }

    /** @return resource|false */
    public function acquireRefreshLock(string $filePath)
    {
        $lockHandle = @fopen($filePath . '.refresh.lock', 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX)) {
            if (is_resource($lockHandle)) {
                fclose($lockHandle);
            }
            return false;
        }
        return $lockHandle;
    }

    /** @param resource $lockHandle */
    public function releaseRefreshLock($lockHandle): void
    {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
