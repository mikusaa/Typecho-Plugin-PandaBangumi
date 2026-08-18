<?php

namespace TypechoPlugin\PandaBangumi;

final class CoverService
{
    private const COVER_SIZE = 'large';
    private const COVER_RESIZE_WIDTH = 600;
    private const IMAGE_SIZES = array('small', 'grid', 'common', 'medium', 'large');
    private const MAX_BYTES = 5242880;
    private const CACHE_MAX_BYTES = 536870912;
    private const CACHE_MAX_ENTRIES = 2048;
    private const RETENTION_SECONDS = 7776000;
    private const MAINTENANCE_INTERVAL = 86400;
    private const TEMP_MAX_AGE = 3600;
    private const MIME_TYPES = array(
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif'
    );

    public function __construct(
        private PluginConfig $config,
        private CacheStore $cacheStore,
        private UpstreamGate $upstreamGate,
        private array $collectionSubjectTypes,
        private array $collectionListTypes,
        private string $collectionCacheVariant,
        private int $maxCacheEntries = self::CACHE_MAX_ENTRIES,
        private int $maxCacheBytes = self::CACHE_MAX_BYTES
    ) {
    }

    public function extractImages(mixed $images): array
    {
        if (!is_array($images)) {
            return array();
        }

        $result = array();
        foreach (self::IMAGE_SIZES as $size) {
            $url = trim((string)($images[$size] ?? ''));
            if ($url !== '') {
                $result[$size] = $url;
            }
        }
        return $result;
    }

    public function selectUrl(mixed $images): string
    {
        return is_array($images) ? trim((string)($images[self::COVER_SIZE] ?? '')) : '';
    }

    public function describeSource(string $source): ?array
    {
        $source = trim($source);
        if ($source === '' || preg_match('/[\x00-\x20\x7F]/', $source)) {
            return null;
        }

        $parts = parse_url($source);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
        $port = isset($parts['port']) ? (int)$parts['port'] : null;
        if (
            !in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || ($port !== null && ($port < 1 || $port > 65535))
        ) {
            return null;
        }

        $normalizedScheme = $scheme;
        if ($scheme === 'http' && $host === 'lain.bgm.tv' && $port === null) {
            $normalizedScheme = 'https';
        }

        $path = (string)($parts['path'] ?? '');
        $fallbackUrl = $this->buildSourceUrl($parts, $normalizedScheme, $path);
        $coverPath = $path;
        if (preg_match('#^/(?:r/\d+/)?pic/cover/(.+)$#', $path, $matches)) {
            $coverPath = '/r/' . self::COVER_RESIZE_WIDTH . '/pic/cover/' . $matches[1];
        }
        $coverUrl = $this->buildSourceUrl($parts, $normalizedScheme, $coverPath);
        $hasFallback = $coverUrl !== $fallbackUrl;

        return array(
            'source_url' => $coverUrl,
            'fallback_url' => $hasFallback ? $fallbackUrl : '',
            'fetch_url' => $coverUrl,
            'fallback_fetch_url' => $hasFallback ? $fallbackUrl : '',
            'version' => substr(hash('sha256', 'r' . self::COVER_RESIZE_WIDTH . "\n" . $coverUrl), 0, 16)
        );
    }

    private function buildSourceUrl(array $parts, string $scheme, string $path): string
    {
        $host = (string)$parts['host'];
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        $url = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $url .= ':' . (int)$parts['port'];
        }
        $url .= $path;
        if (isset($parts['query'])) {
            $url .= '?' . $parts['query'];
        }
        return $url;
    }

    public function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function resolveFetchTarget(string $fetchUrl): ?array
    {
        $parts = parse_url($fetchUrl);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
        $port = isset($parts['port']) ? (int)$parts['port'] : 443;
        if ($scheme !== 'https' || $host === '' || $port !== 443) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicIp($host)
                ? array('url' => $fetchUrl, 'resolve' => array())
                : null;
        }
        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records) || count($records) === 0) {
            return null;
        }

        $addresses = array();
        foreach ($records as $record) {
            $ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
            if ($ip === '' || !$this->isPublicIp($ip)) {
                return null;
            }
            $addresses[] = $ip;
        }
        if (count($addresses) === 0) {
            return null;
        }

        $ip = $addresses[0];
        $resolvedIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        return array(
            'url' => $fetchUrl,
            'resolve' => array($host . ':443:' . $resolvedIp)
        );
    }

    private function collectionFileName(string $list, string $category): string
    {
        return $list . '-' . $category . '.php';
    }

    private function findCalendarSource(int $subjectId): string
    {
        $cache = $this->cacheStore->read($this->cacheStore->dataPath('calendar.php'));
        foreach (($cache['data'] ?? array()) as $day) {
            foreach (($day['items'] ?? array()) as $item) {
                if ((int)($item['id'] ?? 0) === $subjectId) {
                    return $this->selectUrl($item['images'] ?? array());
                }
            }
        }
        return '';
    }

    private function findCollectionSource(int $subjectId, string $list, string $category): string
    {
        $cache = $this->cacheStore->read(
            $this->cacheStore->dataPath($this->collectionFileName($list, $category))
        );
        if (
            ($cache['data_variant'] ?? '') !== $this->collectionCacheVariant
            || (string)($cache['cate'] ?? '') !== $category
        ) {
            return '';
        }

        foreach (($cache['data'] ?? array()) as $item) {
            if ((int)($item['id'] ?? 0) === $subjectId) {
                return $this->selectUrl($item['images'] ?? array());
            }
        }
        return '';
    }

    private function findSubjectSource(int $subjectId): string
    {
        $cache = $this->cacheStore->read(
            $this->cacheStore->subjectPath($subjectId)
        );
        $data = $cache['data'] ?? array();
        if (!is_array($data) || (int)($data['id'] ?? 0) !== $subjectId) {
            return '';
        }
        return $this->selectUrl($data['images'] ?? array());
    }

    private function basePath(int $subjectId, array $cover): string
    {
        return $this->cacheStore->directory('covers') . '/' . $subjectId . '-' . $cover['version'];
    }

    private function cachedMime(string $filePath): string
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return '';
        }

        $size = filesize($filePath);
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
            return '';
        }

        $imageInfo = @getimagesize($filePath);
        $mime = strtolower((string)($imageInfo['mime'] ?? ''));
        return array_key_exists($mime, self::MIME_TYPES) ? $mime : '';
    }

    private function findCached(string $basePath): array
    {
        foreach (array_unique(array_values(self::MIME_TYPES)) as $extension) {
            $filePath = $basePath . '.' . $extension;
            $mime = $this->cachedMime($filePath);
            if ($mime !== '' && self::MIME_TYPES[$mime] === $extension) {
                return array('file' => $filePath, 'mime' => $mime);
            }
        }
        return array();
    }

    private function download(array $cover, string $basePath): bool
    {
        $directory = dirname($basePath);
        if (!$this->cacheStore->ensureDirectory($directory)) {
            return false;
        }

        $lockHandle = $this->cacheStore->acquireShardLock('cover', basename($basePath));
        if ($lockHandle === false) {
            throw new RateLimitExceeded(1);
        }

        try {
            if (count($this->findCached($basePath)) > 0) {
                return true;
            }

            $targets = array();
            foreach (array_unique(array_filter(array(
                (string)($cover['fetch_url'] ?? ''),
                (string)($cover['fallback_fetch_url'] ?? '')
            ))) as $fetchUrl) {
                $target = $this->resolveFetchTarget($fetchUrl);
                if ($target !== null) {
                    $targets[] = $target;
                }
            }
            if (count($targets) === 0) {
                return false;
            }

            return $this->upstreamGate->cover(function () use ($directory, $targets, $basePath): bool {
                foreach ($targets as $target) {
                    if ($this->downloadTarget($directory, $target, $basePath)) {
                        return true;
                    }
                }
                return false;
            });
        } finally {
            $this->cacheStore->releaseRefreshLock($lockHandle);
        }
    }

    private function downloadTarget(string $directory, array $target, string $basePath): bool
    {
        $tmpFile = tempnam($directory, 'pb_cover_');
        $fileHandle = $tmpFile !== false ? @fopen($tmpFile, 'wb') : false;
        $curl = $fileHandle !== false ? curl_init($target['url']) : false;
        if ($tmpFile === false || $fileHandle === false || $curl === false) {
            if (is_resource($fileHandle)) {
                fclose($fileHandle);
            }
            if ($tmpFile !== false) {
                @unlink($tmpFile);
            }
            return false;
        }

        $bytes = 0;
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curl, CURLOPT_TIMEOUT, 15);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if (count($target['resolve']) > 0) {
            curl_setopt($curl, CURLOPT_RESOLVE, $target['resolve']);
        }
        curl_setopt($curl, CURLOPT_REFERER, 'https://bgm.tv/');
        curl_setopt($curl, CURLOPT_USERAGENT, $this->config->userAgent());
        curl_setopt($curl, CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use ($fileHandle, &$bytes): int {
            $length = strlen($chunk);
            if ($bytes + $length > self::MAX_BYTES) {
                return 0;
            }

            $written = fwrite($fileHandle, $chunk);
            if ($written === false) {
                return 0;
            }
            $bytes += $written;
            return $written;
        });

        $result = curl_exec($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);
        fclose($fileHandle);

        $mime = $this->cachedMime($tmpFile);
        $valid = $result !== false
            && $httpCode >= 200
            && $httpCode < 300
            && $bytes > 0
            && $mime !== '';

        if ($valid) {
            $cachePath = $basePath . '.' . self::MIME_TYPES[$mime];
            $valid = @rename($tmpFile, $cachePath);
            if ($valid) {
                @chmod($cachePath, 0644);
                $this->enforceQuota($cachePath);
            }
        }
        if (!$valid) {
            @unlink($tmpFile);
            error_log('PandaBangumi cover request failed: HTTP ' . $httpCode . ' ' . $curlError);
        }
        return $valid;
    }

    private function response(int $subjectId, string $version, string $source): array
    {
        if ($subjectId <= 0 || !preg_match('/^[a-f0-9]{16}$/', $version) || !$this->config->cacheImages()) {
            return array('status' => 404);
        }

        $cover = $this->describeSource($source);
        if ($cover === null || !hash_equals($cover['version'], $version)) {
            return array('status' => 404);
        }

        $basePath = $this->basePath($subjectId, $cover);
        $cached = $this->findCached($basePath);
        if (count($cached) === 0 && $this->download($cover, $basePath)) {
            $cached = $this->findCached($basePath);
        }
        if (count($cached) === 0) {
            return array('status' => 404);
        }

        $this->maybeRunMaintenance(array(), $cached['file']);
        return array('status' => 200, 'file' => $cached['file'], 'mime' => $cached['mime']);
    }

    public function getCalendarCover(int $subjectId, string $version): array
    {
        return $this->response($subjectId, $version, $this->findCalendarSource($subjectId));
    }

    public function getCollectionCover(int $subjectId, string $version, string $list, string $category): array
    {
        if (
            !array_key_exists($category, $this->collectionSubjectTypes)
            || !in_array($list, $this->collectionListTypes[$category] ?? array(), true)
        ) {
            return array('status' => 404);
        }

        return $this->response(
            $subjectId,
            $version,
            $this->findCollectionSource($subjectId, $list, $category)
        );
    }

    public function getSubjectCover(int $subjectId, string $version): array
    {
        return $this->response($subjectId, $version, $this->findSubjectSource($subjectId));
    }

    public function prepareItem(array $item): array
    {
        $source = $this->selectUrl($item['images'] ?? array());
        $cover = $this->describeSource($source);
        unset($item['images'], $item['img'], $item['img_fallback'], $item['cover_version']);

        if ($this->config->cacheImages()) {
            $item['cover_version'] = $cover['version'] ?? '';
        } else {
            $item['img'] = $cover['source_url'] ?? '';
            $item['img_fallback'] = $cover['fallback_url'] ?? '';
        }
        return $item;
    }

    public function prepareCollectionPage(array $page): array
    {
        if (!isset($page['items']) || !is_array($page['items'])) {
            return $page;
        }

        foreach ($page['items'] as &$item) {
            $item = $this->prepareItem(is_array($item) ? $item : array());
        }
        unset($item);
        return $page;
    }

    public function prepareCalendar(array $calendar): array
    {
        foreach ($calendar as &$day) {
            if (!isset($day['items']) || !is_array($day['items'])) {
                $day['items'] = array();
                continue;
            }
            foreach ($day['items'] as &$item) {
                $item = $this->prepareItem(is_array($item) ? $item : array());
            }
            unset($item);
        }
        unset($day);
        return $calendar;
    }

    private function addReferenced(array &$referenced, array $item): void
    {
        $subjectId = (int)($item['id'] ?? 0);
        $cover = $this->describeSource($this->selectUrl($item['images'] ?? array()));
        if ($subjectId <= 0 || $cover === null) {
            return;
        }

        $name = basename($this->basePath($subjectId, $cover));
        foreach (array_unique(array_values(self::MIME_TYPES)) as $extension) {
            $referenced[$name . '.' . $extension] = true;
        }
    }

    private function referencedCovers(array $calendar = array(), string $currentFile = ''): array
    {
        $referenced = array();
        if ($currentFile !== '') {
            $referenced[basename($currentFile)] = true;
        }
        foreach ($calendar as $day) {
            foreach (($day['items'] ?? array()) as $item) {
                $this->addReferenced($referenced, $item);
            }
        }

        $calendarCache = $this->cacheStore->read($this->cacheStore->dataPath('calendar.php'));
        foreach (($calendarCache['data'] ?? array()) as $day) {
            foreach (($day['items'] ?? array()) as $item) {
                $this->addReferenced($referenced, $item);
            }
        }

        foreach (array_keys($this->collectionSubjectTypes) as $category) {
            foreach ($this->collectionListTypes[$category] ?? array() as $list) {
                $cache = $this->cacheStore->read(
                    $this->cacheStore->dataPath($this->collectionFileName($list, $category))
                );
                foreach (($cache['data'] ?? array()) as $item) {
                    $this->addReferenced($referenced, $item);
                }
            }
        }

        $subjectDirectory = $this->cacheStore->directory('subjects');
        $subjectEntries = is_readable($subjectDirectory) ? scandir($subjectDirectory) : false;
        $recentCutoff = $this->cacheStore->now() - self::RETENTION_SECONDS;
        foreach (is_array($subjectEntries) ? $subjectEntries : array() as $entry) {
            if (!preg_match('/^[1-9][0-9]*\.php$/', $entry)) {
                continue;
            }
            $path = $subjectDirectory . '/' . $entry;
            $modified = @filemtime($path);
            if ($modified === false || $modified < $recentCutoff) {
                continue;
            }
            $cache = $this->cacheStore->read($path);
            if (isset($cache['data']) && is_array($cache['data'])) {
                $this->addReferenced($referenced, $cache['data']);
            }
        }
        return $referenced;
    }

    private function coverFiles(array $referenced, bool $removeOldTemporary): array
    {
        $directory = $this->cacheStore->directory('covers');
        if (!is_dir($directory) || !is_readable($directory)) {
            return array();
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return array();
        }
        $files = array();
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            $modified = is_file($path) ? filemtime($path) : false;
            if ($modified === false) {
                continue;
            }
            if (str_starts_with($entry, 'pb_cover_')) {
                if ($removeOldTemporary && $modified < $this->cacheStore->now() - self::TEMP_MAX_AGE) {
                    @unlink($path);
                }
                continue;
            }
            if (!preg_match('/^[1-9][0-9]*-[a-f0-9]{16}\.(?:jpg|png|webp|gif)$/', $entry)) {
                continue;
            }
            $size = @filesize($path);
            $files[] = array(
                'name' => $entry,
                'path' => $path,
                'modified' => $modified,
                'size' => $size === false ? 0 : max(0, $size),
                'protected' => isset($referenced[$entry])
            );
        }

        usort($files, static fn(array $left, array $right): int => $left['modified'] <=> $right['modified']);
        return $files;
    }

    private function pruneQuota(array $files): void
    {
        $count = count($files);
        $bytes = array_sum(array_column($files, 'size'));
        foreach ($files as $file) {
            if ($file['protected']) {
                continue;
            }
            if ($count <= max(0, $this->maxCacheEntries) && $bytes <= max(0, $this->maxCacheBytes)) {
                break;
            }
            if (@unlink($file['path'])) {
                $count--;
                $bytes -= $file['size'];
            }
        }

        if ($count > max(0, $this->maxCacheEntries) || $bytes > max(0, $this->maxCacheBytes)) {
            error_log(
                'PandaBangumi protected cover cache exceeds quota: '
                . $count . ' files, ' . $bytes . ' bytes'
            );
        }
    }

    public function enforceQuota(string $currentFile = ''): void
    {
        $referenced = $this->referencedCovers(array(), $currentFile);
        $this->pruneQuota($this->coverFiles($referenced, false));
    }

    public function maybeRunMaintenance(
        array $calendar = array(),
        string $currentFile = '',
        bool $force = false
    ): void {
        $statePath = $this->cacheStore->statePath('maintenance.php');
        $state = $this->cacheStore->read($statePath);
        $lastRun = (int)($state['last_run'] ?? 0);
        if (!$force && $lastRun > 0 && $this->cacheStore->now() - $lastRun < self::MAINTENANCE_INTERVAL) {
            return;
        }

        $lockHandle = $this->cacheStore->acquireShardLock('maintenance', 'covers');
        if ($lockHandle === false) {
            return;
        }

        try {
            $state = $this->cacheStore->read($statePath);
            $lastRun = (int)($state['last_run'] ?? 0);
            if (!$force && $lastRun > 0 && $this->cacheStore->now() - $lastRun < self::MAINTENANCE_INTERVAL) {
                return;
            }

            $referenced = $this->referencedCovers($calendar, $currentFile);
            $files = $this->coverFiles($referenced, true);
            $retentionCutoff = $this->cacheStore->now() - self::RETENTION_SECONDS;
            foreach ($files as $index => $file) {
                if (!$file['protected'] && $file['modified'] < $retentionCutoff && @unlink($file['path'])) {
                    unset($files[$index]);
                }
            }

            $this->pruneQuota(array_values($files));
            $this->cacheStore->write($statePath, array(
                'version' => 1,
                'last_run' => $this->cacheStore->now()
            ));
        } finally {
            $this->cacheStore->releaseRefreshLock($lockHandle);
        }
    }

    public function cleanup(array $calendar = array(), string $currentFile = ''): void
    {
        $this->maybeRunMaintenance($calendar, $currentFile, true);
    }
}
