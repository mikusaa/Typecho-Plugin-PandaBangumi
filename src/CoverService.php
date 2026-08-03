<?php

namespace TypechoPlugin\PandaBangumi;

final class CoverService
{
    private const COVER_SIZE = 'large';
    private const IMAGE_SIZES = array('small', 'grid', 'common', 'medium', 'large');
    private const MAX_BYTES = 5242880;
    private const RETENTION = 7776000;
    private const MIME_TYPES = array(
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif'
    );

    public function __construct(
        private PluginConfig $config,
        private CacheStore $cacheStore,
        private array $collectionSubjectTypes,
        private array $collectionListTypes,
        private string $collectionCacheVariant
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

        $fetchUrl = $source;
        if ($scheme === 'http' && $host === 'lain.bgm.tv' && $port === null) {
            $fetchUrl = 'https://' . substr($source, strlen('http://'));
        }

        return array(
            'source_url' => $source,
            'fetch_url' => $fetchUrl,
            'version' => substr(hash('sha256', self::COVER_SIZE . "\n" . $source), 0, 16)
        );
    }

    public function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function resolveFetchTarget(array $cover): ?array
    {
        $parts = parse_url((string)($cover['fetch_url'] ?? ''));
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
                ? array('url' => $cover['fetch_url'], 'resolve' => array())
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
            'url' => $cover['fetch_url'],
            'resolve' => array($host . ':443:' . $resolvedIp)
        );
    }

    private function collectionFileName(string $list, string $category): string
    {
        return $list . '-' . $category . '.json';
    }

    private function findCalendarSource(int $subjectId): string
    {
        $cache = $this->cacheStore->read($this->cacheStore->dataPath('calendar.json'));
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
            $this->cacheStore->directory('subjects') . '/' . $subjectId . '.json'
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

        $lockHandle = @fopen($basePath . '.lock', 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX)) {
            if (is_resource($lockHandle)) {
                fclose($lockHandle);
            }
            return false;
        }

        if (count($this->findCached($basePath)) > 0) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            return true;
        }

        $target = $this->resolveFetchTarget($cover);
        $tmpFile = tempnam($directory, 'pb_cover_');
        $fileHandle = $tmpFile !== false ? @fopen($tmpFile, 'wb') : false;
        $curl = $fileHandle !== false && $target !== null ? curl_init($target['url']) : false;
        if ($tmpFile === false || $fileHandle === false || $curl === false || $target === null) {
            if (is_resource($fileHandle)) {
                fclose($fileHandle);
            }
            if ($tmpFile !== false) {
                @unlink($tmpFile);
            }
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
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
            }
        }
        if (!$valid) {
            @unlink($tmpFile);
            error_log('PandaBangumi cover request failed: HTTP ' . $httpCode . ' ' . $curlError);
        }

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
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
        unset($item['images'], $item['img'], $item['cover_version']);

        if ($this->config->cacheImages()) {
            $item['cover_version'] = $cover['version'] ?? '';
        } else {
            $item['img'] = $cover['source_url'] ?? '';
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
        $referenced[$name . '.lock'] = true;
        foreach (array_unique(array_values(self::MIME_TYPES)) as $extension) {
            $referenced[$name . '.' . $extension] = true;
        }
    }

    public function cleanup(array $calendar): void
    {
        $directory = $this->cacheStore->directory('covers');
        if (!is_dir($directory) || !is_readable($directory)) {
            return;
        }

        $referenced = array();
        foreach ($calendar as $day) {
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

        $cutoff = time() - self::RETENTION;
        $subjectDirectory = $this->cacheStore->directory('subjects');
        $subjectEntries = is_readable($subjectDirectory) ? scandir($subjectDirectory) : false;
        if (is_array($subjectEntries)) {
            foreach ($subjectEntries as $entry) {
                $subjectPath = $subjectDirectory . '/' . $entry;
                $modified = is_file($subjectPath) ? filemtime($subjectPath) : false;
                if ($modified === false || $modified < $cutoff || pathinfo($entry, PATHINFO_EXTENSION) !== 'json') {
                    continue;
                }

                $subjectCache = $this->cacheStore->read($subjectPath);
                $subject = $subjectCache['data'] ?? array();
                if (is_array($subject)) {
                    $this->addReferenced($referenced, $subject);
                }
            }
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || isset($referenced[$entry])) {
                continue;
            }

            $path = $directory . '/' . $entry;
            $modified = is_file($path) ? filemtime($path) : false;
            if ($modified !== false && $modified < $cutoff) {
                @unlink($path);
            }
        }
    }
}
