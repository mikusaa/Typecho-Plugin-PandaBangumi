<?php

namespace TypechoPlugin\PandaBangumi;

final class CacheStore
{
    public const PHP_PREFIX = "<?php http_response_code(404); exit; ?>\n";

    private const FAILURE_RETRY = 300;
    private const LAYOUT_VERSION = 2;
    private const SUBJECT_LIMIT = 256;
    private const LOCK_SLOTS = 64;
    private const INDEX_GUARD = "<?php\n\nhttp_response_code(404);\nexit;\n";
    private const APACHE_GUARD = "<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteRule ^ - [R=404,L]\n</IfModule>\n";

    /** @var callable */
    private $clock;

    public function __construct(private string $root, ?callable $clock = null)
    {
        $this->root = rtrim($root, '/');
        $this->clock = $clock ?? static fn(): int => time();
        if (!$this->initialize()) {
            throw new \RuntimeException('PandaBangumi cache security initialization failed');
        }
    }

    public function now(): int
    {
        return (int)($this->clock)();
    }

    public function root(): string
    {
        return $this->root;
    }

    public function ensureDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            return is_writable($directory);
        }
        return @mkdir($directory, 0755, true) && is_writable($directory);
    }

    private function ensureGuardFile(string $filePath, string $content): bool
    {
        if (is_file($filePath)) {
            return true;
        }

        return file_put_contents($filePath, $content, LOCK_EX) !== false;
    }

    private function ensureGuards(): bool
    {
        if (!$this->ensureGuardFile($this->root . '/.htaccess', self::APACHE_GUARD)) {
            return false;
        }
        if (!$this->ensureGuardFile($this->root . '/index.php', self::INDEX_GUARD)) {
            return false;
        }

        foreach (array('data', 'subjects', 'covers', 'state', 'locks') as $directory) {
            if (!$this->ensureGuardFile($this->root . '/' . $directory . '/index.php', self::INDEX_GUARD)) {
                return false;
            }
        }
        return true;
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

    public function subjectPath(int $subjectId): string
    {
        return $this->directory('subjects') . '/' . max(0, $subjectId) . '.php';
    }

    public function statePath(string $fileName): string
    {
        return $this->directory('state') . '/' . basename($fileName);
    }

    public function read(string $filePath): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return array();
        }

        $raw = file_get_contents($filePath);
        if ($raw === false || !str_starts_with($raw, self::PHP_PREFIX)) {
            return array();
        }

        $cache = json_decode(substr($raw, strlen(self::PHP_PREFIX)), true);
        return is_array($cache) ? $cache : array();
    }

    public function write(string $filePath, array $cache): bool
    {
        $json = json_encode($cache, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        $directory = dirname($filePath);
        if (!is_dir($directory) || !is_writable($directory)) {
            return false;
        }

        $tmpFile = tempnam($directory, 'pb_');
        if ($tmpFile === false) {
            return false;
        }

        $written = file_put_contents($tmpFile, self::PHP_PREFIX . $json, LOCK_EX);
        if ($written === false) {
            @unlink($tmpFile);
            return false;
        }

        if (!@rename($tmpFile, $filePath)) {
            @unlink($tmpFile);
            return false;
        }
        @chmod($filePath, 0644);
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
    public function acquireShardLock(string $scope, string $key)
    {
        $scope = preg_replace('/[^a-z0-9_-]/i', '', strtolower($scope)) ?: 'cache';
        $hash = hash('sha256', $key);
        $slot = hexdec(substr($hash, 0, 8)) % self::LOCK_SLOTS;
        $filePath = $this->directory('locks') . '/' . $scope . '-' . sprintf('%02d', $slot) . '.lock';
        $lockHandle = @fopen($filePath, 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if (is_resource($lockHandle)) {
                fclose($lockHandle);
            }
            return false;
        }
        return $lockHandle;
    }

    /** @return resource|false */
    public function acquireConcurrencySlot(string $scope, int $slots)
    {
        $scope = preg_replace('/[^a-z0-9_-]/i', '', strtolower($scope)) ?: 'upstream';
        $slots = max(1, min(16, $slots));
        for ($slot = 0; $slot < $slots; $slot++) {
            $filePath = $this->directory('locks') . '/concurrency-' . $scope . '-' . sprintf('%02d', $slot) . '.lock';
            $lockHandle = @fopen($filePath, 'c');
            if ($lockHandle !== false && flock($lockHandle, LOCK_EX | LOCK_NB)) {
                return $lockHandle;
            }
            if (is_resource($lockHandle)) {
                fclose($lockHandle);
            }
        }
        return false;
    }

    /** @return resource|false */
    public function acquireRefreshLock(string $filePath)
    {
        return $this->acquireShardLock('data', basename($filePath));
    }

    /** @param resource $lockHandle */
    public function releaseRefreshLock($lockHandle): void
    {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    public function pruneSubjectCaches(int $currentSubjectId = 0, int $limit = self::SUBJECT_LIMIT): void
    {
        $directory = $this->directory('subjects');
        $entries = is_readable($directory) ? scandir($directory) : false;
        if (!is_array($entries)) {
            return;
        }

        $files = array();
        foreach ($entries as $entry) {
            if (!preg_match('/^([1-9][0-9]*)\.php$/', $entry, $matches)) {
                continue;
            }
            $path = $directory . '/' . $entry;
            $modified = @filemtime($path);
            if (is_file($path) && $modified !== false) {
                $files[] = array('id' => (int)$matches[1], 'path' => $path, 'modified' => $modified);
            }
        }

        usort($files, static fn(array $left, array $right): int => $left['modified'] <=> $right['modified']);
        $remove = max(0, count($files) - max(0, $limit));
        foreach ($files as $file) {
            if ($remove <= 0) {
                break;
            }
            if ($file['id'] === $currentSubjectId) {
                continue;
            }
            if (@unlink($file['path'])) {
                $remove--;
            }
        }
    }

    public function initialize(): bool
    {
        if (!$this->ensureDirectory($this->root)) {
            return false;
        }
        foreach (array('data', 'subjects', 'covers', 'state', 'locks') as $directory) {
            if (!$this->ensureDirectory($this->root . '/' . $directory)) {
                return false;
            }
        }

        if (!$this->ensureGuards()) {
            return false;
        }

        $layoutPath = $this->statePath('layout.php');
        $layout = $this->read($layoutPath);
        if ((int)($layout['version'] ?? 0) === self::LAYOUT_VERSION) {
            return true;
        }

        $lockHandle = $this->acquireShardLock('initialize', 'layout');
        if ($lockHandle === false) {
            return true;
        }

        try {
            $layout = $this->read($layoutPath);
            if ((int)($layout['version'] ?? 0) === self::LAYOUT_VERSION) {
                return true;
            }
            return $this->write($layoutPath, array(
                'version' => self::LAYOUT_VERSION,
                'initialized_at' => $this->now()
            ));
        } finally {
            $this->releaseRefreshLock($lockHandle);
        }
    }
}
