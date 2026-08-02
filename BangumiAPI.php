<?php

namespace TypechoPlugin\PandaBangumi;

use Typecho\Plugin\Exception;
use Utils\Helper;

class BangumiAPI
{
    private const DEFAULT_API_BASE = 'https://api.bgm.tv';
    private const COVER_SIZE = 'large';
    private const COVER_IMAGE_SIZES = array('small', 'grid', 'common', 'medium', 'large');
    private const CALENDAR_IMAGE_VARIANT = 'images-v2';
    private const COLLECTION_CACHE_VARIANT = 'category-v3';
    private const COLLECTION_FETCH_PAGE_SIZE = 30;
    private const COLLECTION_SUBJECT_TYPES = array(
        'book' => 1,
        'anime' => 2,
        'game' => 4,
        'real' => 6
    );
    private const COVER_CACHE_MAX_BYTES = 5242880;
    private const COVER_CACHE_RETENTION = 7776000;
    private const COVER_MIME_TYPES = array(
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif'
    );
    private const EMPTY_COLLECTION_CACHE = array('time' => 1, 'data' => array());
    private static array $legacyCacheDirectoriesMigrated = array();

    /**
     * 获取 Bangumi API 基础地址
     *
     * @access public
     * @return string
     */
    public static function getApiBase(): string
    {
        $apiBase = '';

        try {
            $pluginOptions = Helper::options()->plugin('PandaBangumi');
            $apiBase = isset($pluginOptions->ApiBase) ? trim((string)$pluginOptions->ApiBase) : '';
        } catch (\Throwable $e) {
            $apiBase = '';
        }

        if ($apiBase === '') {
            return self::DEFAULT_API_BASE;
        }

        $parts = parse_url($apiBase);
        if (
            !is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || preg_match('/[\x00-\x1F\x7F]/', $apiBase)
        ) {
            return self::DEFAULT_API_BASE;
        }

        $origin = 'https://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . (int)$parts['port'];
        }

        return $origin;
    }

    /**
     * 构造 Bangumi API 请求地址
     *
     * @access public
     * @param string $path
     * @return string
     */
    public static function buildApiUrl(string $path): string
    {
        $apiBase = self::getApiBase();
        $path = '/' . ltrim($path, '/');

        return $apiBase . $path;
    }

    /**
     * 获取符合 Bangumi API 要求的应用标识
     */
    private static function getUserAgent(): string
    {
        $version = defined('PandaBangumi_Plugin_VERSION')
            ? (string)constant('PandaBangumi_Plugin_VERSION')
            : 'dev';

        return 'mikusa/PandaBangumi/' . $version
            . ' (https://github.com/mikusaa/Typecho-Plugin-PandaBangumi)';
    }

    /**
     * JSON 编码
     *
     * @access public
     * @param mixed $data
     * @return string
     */
    public static function encodeJson(mixed $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        return $json === false ? '[]' : $json;
    }

    /**
     * 获取配置的整数值
     *
     * @access private
     * @param string $name
     * @param int $default
     * @param int $min
     * @param int $max
     * @return int
     */
    private static function getIntOption(string $name, int $default, int $min, int $max): int
    {
        try {
            $pluginOptions = Helper::options()->plugin('PandaBangumi');
            $value = isset($pluginOptions->{$name}) ? (int)$pluginOptions->{$name} : $default;
        } catch (\Throwable $e) {
            $value = $default;
        }

        return max($min, min($value, $max));
    }

    /**
     * 获取封面加载方式，并兼容一个版本的旧配置
     */
    private static function getImageMode(): string
    {
        try {
            $pluginOptions = Helper::options()->plugin('PandaBangumi');
            $mode = strtolower(trim((string)($pluginOptions->ImageMode ?? '')));
            if (in_array($mode, ['direct', 'cache'], true)) {
                return $mode;
            }

            $legacyCache = (string)($pluginOptions->ProxyImages ?? '0') === '1'
                || (string)($pluginOptions->CacheCollectionImages ?? '0') === '1';
            return $legacyCache ? 'cache' : 'direct';
        } catch (\Throwable $e) {
            return 'direct';
        }
    }

    /**
     * 是否将封面缓存到本站
     */
    private static function cacheImages(): bool
    {
        return self::getImageMode() === 'cache';
    }

    /**
     * 保留 API 提供的已知尺寸封面地址
     */
    private static function extractCoverImages(mixed $images): array
    {
        if (!is_array($images)) {
            return array();
        }

        $result = array();
        foreach (self::COVER_IMAGE_SIZES as $size) {
            $url = trim((string)($images[$size] ?? ''));
            if ($url !== '') {
                $result[$size] = $url;
            }
        }

        return $result;
    }

    /**
     * 选择当前使用的封面尺寸，不回退到其他尺寸
     */
    private static function selectCoverUrl(mixed $images): string
    {
        return is_array($images) ? trim((string)($images[self::COVER_SIZE] ?? '')) : '';
    }

    /**
     * 获取请求分类
     *
     * @access private
     * @return string
     */
    private static function getCate(): string
    {
        $cate = strtolower((string)($_GET['cate'] ?? 'anime'));
        return array_key_exists($cate, self::COLLECTION_SUBJECT_TYPES) ? $cate : '';
    }

    /**
     * 获取日历过滤器
     *
     * @access private
     * @return string
     */
    private static function getCalendarFilter(): string
    {
        $filter = strtolower((string)($_GET['filter'] ?? 'watching'));
        return $filter === 'watching' ? 'watching' : 'all';
    }

    /**
     * 使用 curl 代替 file_get_contents()
     *
     * @access public
     * @param string $_url
     * @return bool|string
     */
    public static function curlFileGetContents(string $_url): bool|string
    {
        $myCurl = curl_init($_url);
        if ($myCurl === false) {
            return false;
        }

        curl_setopt($myCurl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($myCurl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($myCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($myCurl, CURLOPT_HEADER, false);
        curl_setopt($myCurl, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($myCurl, CURLOPT_TIMEOUT, 12);
        curl_setopt($myCurl, CURLOPT_REFERER, 'https://bgm.tv/');
        curl_setopt($myCurl, CURLOPT_USERAGENT, self::getUserAgent());
        $content = curl_exec($myCurl);
        $httpCode = (int)curl_getinfo($myCurl, CURLINFO_RESPONSE_CODE);
        if ($content === false || $httpCode < 200 || $httpCode >= 300) {
            error_log('PandaBangumi API request failed: ' . $_url . ' HTTP ' . $httpCode . ' ' . curl_error($myCurl));
            curl_close($myCurl);
            return false;
        }

        curl_close($myCurl);
        return $content;
    }

    /**
     * 获取收藏数据并格式化返回
     *
     * @param string $ID
     * @param int $status 1:想看 2:看过 3:在看 4:搁置 5:抛弃
     * @param int $subject_type 1:book 2:anime 3:music 4:game 6:real
     * @param int $userLimit
     * @return array
     * @throws Exception
     */
    private static function __getCollectionRawData(string $ID, int $status, int $subject_type, int $userLimit): array
    {
        if ($ID === '' || $userLimit <= 0) {
            return array();
        }

        $offset = 0;
        $collections = array();
        do {
            $apiUrl = self::buildApiUrl('/v0/users/' . rawurlencode($ID) . '/collections')
                . '?subject_type=' . $subject_type
                . '&type=' . $status
                . '&limit=' . self::COLLECTION_FETCH_PAGE_SIZE
                . '&offset=' . $offset;
            $json = self::curlFileGetContents($apiUrl);
            if ($json === false || $json === 'null') {
                break;
            }

            $data = json_decode($json, true);
            if (!is_array($data) || !isset($data['total'], $data['data']) || !is_array($data['data'])) {
                break;
            }

            foreach ($data['data'] as $item) {
                $subject = $item['subject'] ?? array();
                $subjectId = (int)($subject['id'] ?? 0);
                if ($subjectId <= 0) {
                    continue;
                }

                $collections[] = array(
                    'name' => (string)($subject['name'] ?? ''),
                    'name_cn' => (string)($subject['name_cn'] ?? ''),
                    'url' => 'https://bgm.tv/subject/' . $subjectId,
                    'status' => (int)($item['ep_status'] ?? 0),
                    'vol_status' => (int)($item['vol_status'] ?? 0),
                    'count' => (int)($subject['eps'] ?? 0),
                    'vol_count' => (int)($subject['volumes'] ?? 0),
                    'air_date' => (string)($subject['date'] ?? ''),
                    'images' => self::extractCoverImages($subject['images'] ?? array()),
                    'score' => (float)($subject['score'] ?? 0),
                    'id' => $subjectId,
                );
                if (count($collections) >= $userLimit) {
                    break 2;
                }
            }

            $responseLimit = max(1, (int)($data['limit'] ?? self::COLLECTION_FETCH_PAGE_SIZE));
            $offset = max($offset + $responseLimit, (int)($data['offset'] ?? $offset) + $responseLimit);
            $hasMore = $offset < (int)$data['total'] && count($data['data']) > 0;
        } while ($hasMore);

        return array_slice($collections, 0, $userLimit);
    }

    /**
     * 获取日历数据并格式化返回
     *
     * @return array
     * @throws Exception
     */
    private static function __getCalendarRawData(): array
    {
        $apiUrl = self::buildApiUrl('/calendar');
        $json = self::curlFileGetContents($apiUrl);
        if ($json === false) {
            return array();
        }

        if ($json == 'null') {
            return array();
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return array();
        }

        $calendar = array();

        foreach ($data as $day) {
            $items = array_map(function ($item) {
                $id = (int)($item['id'] ?? 0);
                return [
                    'id' => $id,
                    'name' => (string)($item['name'] ?? ''),
                    'name_cn' => (string)($item['name_cn'] ?? ''),
                    'url' => $id > 0 ? 'https://bgm.tv/subject/' . $id : '',
                    'images' => self::extractCoverImages($item['images'] ?? array())
                ];
            }, $day['items'] ?? array());
            $calendar[] = array(
                'id' => (int)($day['weekday']['id'] ?? 0),
                'date_en' => (string)($day['weekday']['en'] ?? ''),
                'date_cn' => (string)($day['weekday']['cn'] ?? ''),
                'items' => $items
            );
        }

        return $calendar;
    }

    /**
     * 检查缓存是否过期
     *
     * @access  private
     * @param string $FilePath 缓存路径
     * @param int $ValidTimeSpan 有效时间，Unix 时间戳，s
     * @return  mixed     正常数据: 未过期; 1:已过期; -1：无缓存或缓存无效
     */
    private static function __isCacheExpired(string $FilePath, int $ValidTimeSpan): mixed
    {
        if (!is_file($FilePath) || !is_readable($FilePath)) {
            return -1;
        }

        $raw = file_get_contents($FilePath);
        if ($raw === false) {
            return -1;
        }

        $content = json_decode($raw, true);
        if (!is_array($content) || !array_key_exists('time', $content) || $content['time'] < 1) {
            return -1;
        }

        if (time() - $content['time'] > $ValidTimeSpan) {
            return 1;
        }

        return $content;
    }

    /**
     * 写入 JSON 缓存
     *
     * @access private
     * @param string $FilePath
     * @param array $cache
     * @return bool
     */
    private static function __writeCache(string $FilePath, array $cache): bool
    {
        $json = self::encodeJson($cache);
        $dir = dirname($FilePath);
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }

        $tmpFile = tempnam($dir, 'pb_');
        if ($tmpFile === false) {
            return false;
        }

        if (file_put_contents($tmpFile, $json, LOCK_EX) === false) {
            @unlink($tmpFile);
            return false;
        }

        if (!@rename($tmpFile, $FilePath)) {
            @unlink($tmpFile);
            return false;
        }

        return true;
    }

    /**
     * 创建插件缓存目录
     */
    private static function ensureCacheDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            return is_writable($directory);
        }

        return @mkdir($directory, 0755, true) && is_writable($directory);
    }

    /**
     * 获取新的缓存子目录
     */
    private static function getCacheDirectory(string $name): string
    {
        $directory = __DIR__ . '/cache/' . trim($name, '/');
        self::ensureCacheDirectory($directory);
        return $directory;
    }

    /**
     * 将旧缓存文件原子迁移到新位置
     */
    private static function migrateLegacyCacheFile(string $legacyPath, string $targetPath): bool
    {
        if (is_file($targetPath)) {
            return true;
        }
        if (!is_file($legacyPath) || !is_readable($legacyPath)) {
            return false;
        }

        $directory = dirname($targetPath);
        if (!self::ensureCacheDirectory($directory)) {
            return false;
        }
        if (@rename($legacyPath, $targetPath)) {
            return true;
        }

        $tmpFile = tempnam($directory, 'pb_migrate_');
        if ($tmpFile === false || !@copy($legacyPath, $tmpFile)) {
            if ($tmpFile !== false) {
                @unlink($tmpFile);
            }
            return false;
        }

        $legacySize = filesize($legacyPath);
        $tmpSize = filesize($tmpFile);
        $legacyHash = hash_file('sha256', $legacyPath);
        $tmpHash = hash_file('sha256', $tmpFile);
        $valid = $legacySize !== false
            && $tmpSize === $legacySize
            && is_string($legacyHash)
            && is_string($tmpHash)
            && hash_equals($legacyHash, $tmpHash);

        if ($valid && @rename($tmpFile, $targetPath)) {
            @unlink($legacyPath);
            return true;
        }

        @unlink($tmpFile);
        return is_file($targetPath);
    }

    /**
     * 迁移旧 json 目录中的分类缓存
     */
    private static function migrateLegacyCacheDirectory(string $name, string $targetDirectory): void
    {
        if (isset(self::$legacyCacheDirectoriesMigrated[$name])) {
            return;
        }
        self::$legacyCacheDirectoriesMigrated[$name] = true;

        $legacyDirectory = __DIR__ . '/json/' . $name;
        if (!is_dir($legacyDirectory) || !is_readable($legacyDirectory)) {
            return;
        }

        $entries = scandir($legacyDirectory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $legacyPath = $legacyDirectory . '/' . $entry;
            if (is_file($legacyPath)) {
                self::migrateLegacyCacheFile($legacyPath, $targetDirectory . '/' . $entry);
            }
        }
    }

    /**
     * 获取固定 JSON 数据缓存路径
     */
    private static function getDataCachePath(string $fileName): string
    {
        $fileName = basename($fileName);
        $targetPath = self::getCacheDirectory('data') . '/' . $fileName;
        self::migrateLegacyCacheFile(__DIR__ . '/json/' . $fileName, $targetPath);
        return $targetPath;
    }

    /**
     * 获取指定收藏状态和分类的缓存文件名
     */
    private static function getCollectionCacheFileName(string $list, string $cate): string
    {
        return $list . '-' . $cate . '.json';
    }

    /**
     * 获取 Bangumi 条目卡片缓存目录
     */
    private static function getSubjectCacheDirectory(): string
    {
        $directory = self::getCacheDirectory('subjects');
        self::migrateLegacyCacheDirectory('subjects', $directory);
        return $directory;
    }

    /**
     * 获取日历封面缓存目录
     */
    private static function getCoverCacheDirectory(): string
    {
        $directory = self::getCacheDirectory('covers');
        self::migrateLegacyCacheDirectory('covers', $directory);
        return $directory;
    }

    /**
     * 读取缓存文件，不检查有效期
     */
    private static function readCacheFile(string $filePath): array
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

    /**
     * 校验 API 返回的封面地址，不改写浏览器使用的 URL
     */
    private static function describeCoverSource(string $source): ?array
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

    /**
     * 检查 IP 是否为公网地址
     */
    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * 解析并固定公网 HTTPS 下载目标，避免 DNS 重绑定访问内网
     */
    private static function resolveCoverFetchTarget(array $cover): ?array
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
            return self::isPublicIp($host)
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
            if ($ip === '' || !self::isPublicIp($ip)) {
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

    /**
     * 获取日历缓存中的封面源地址
     */
    private static function findCalendarCoverSource(int $subjectId): string
    {
        $cache = self::readCacheFile(self::getDataCachePath('calendar.json'));
        foreach (($cache['data'] ?? array()) as $day) {
            foreach (($day['items'] ?? array()) as $item) {
                if ((int)($item['id'] ?? 0) === $subjectId) {
                    return self::selectCoverUrl($item['images'] ?? array());
                }
            }
        }

        return '';
    }

    /**
     * 获取收藏缓存中的封面源地址
     */
    private static function findCollectionCoverSource(int $subjectId, string $list, string $cate): string
    {
        $cache = self::readCacheFile(self::getDataCachePath(self::getCollectionCacheFileName($list, $cate)));
        if (
            ($cache['data_variant'] ?? '') !== self::COLLECTION_CACHE_VARIANT
            || (string)($cache['cate'] ?? '') !== $cate
        ) {
            return '';
        }

        foreach (($cache['data'] ?? array()) as $item) {
            if ((int)($item['id'] ?? 0) === $subjectId) {
                return self::selectCoverUrl($item['images'] ?? array());
            }
        }

        return '';
    }

    /**
     * 获取 Subject 缓存中的封面源地址
     */
    private static function findSubjectCoverSource(int $subjectId): string
    {
        $cache = self::readCacheFile(self::getSubjectCacheDirectory() . '/' . $subjectId . '.json');
        $data = $cache['data'] ?? array();
        if (!is_array($data) || (int)($data['id'] ?? 0) !== $subjectId) {
            return '';
        }

        return self::selectCoverUrl($data['images'] ?? array());
    }

    /**
     * 获取不带扩展名的封面缓存路径
     */
    private static function getCoverCacheBasePath(int $subjectId, array $cover): string
    {
        return self::getCoverCacheDirectory() . '/' . $subjectId . '-' . $cover['version'];
    }

    /**
     * 校验本地封面并返回 MIME
     */
    private static function getCachedCoverMime(string $filePath): string
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return '';
        }

        $size = filesize($filePath);
        if ($size === false || $size < 1 || $size > self::COVER_CACHE_MAX_BYTES) {
            return '';
        }

        $imageInfo = @getimagesize($filePath);
        $mime = strtolower((string)($imageInfo['mime'] ?? ''));
        return array_key_exists($mime, self::COVER_MIME_TYPES) ? $mime : '';
    }

    /**
     * 查找与版本匹配且内容有效的本地封面
     */
    private static function findCachedCover(string $basePath): array
    {
        foreach (array_unique(array_values(self::COVER_MIME_TYPES)) as $extension) {
            $filePath = $basePath . '.' . $extension;
            $mime = self::getCachedCoverMime($filePath);
            if ($mime !== '' && self::COVER_MIME_TYPES[$mime] === $extension) {
                return array('file' => $filePath, 'mime' => $mime);
            }
        }

        return array();
    }

    /**
     * 下载封面到临时文件并原子写入缓存
     */
    private static function downloadCover(array $cover, string $basePath): bool
    {
        $directory = dirname($basePath);
        if (!self::ensureCacheDirectory($directory)) {
            return false;
        }

        $lockHandle = @fopen($basePath . '.lock', 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX)) {
            if (is_resource($lockHandle)) {
                fclose($lockHandle);
            }
            return false;
        }

        if (count(self::findCachedCover($basePath)) > 0) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            return true;
        }

        $target = self::resolveCoverFetchTarget($cover);
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
        curl_setopt($curl, CURLOPT_USERAGENT, self::getUserAgent());
        curl_setopt($curl, CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use ($fileHandle, &$bytes): int {
            $length = strlen($chunk);
            if ($bytes + $length > self::COVER_CACHE_MAX_BYTES) {
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

        $mime = self::getCachedCoverMime($tmpFile);
        $valid = $result !== false
            && $httpCode >= 200
            && $httpCode < 300
            && $bytes > 0
            && $mime !== '';

        if ($valid) {
            $cachePath = $basePath . '.' . self::COVER_MIME_TYPES[$mime];
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

    /**
     * 获取封面响应
     */
    private static function getCoverResponse(int $subjectId, string $version, string $source): array
    {
        if ($subjectId <= 0 || !preg_match('/^[a-f0-9]{16}$/', $version)) {
            return array('status' => 404);
        }

        if (!self::cacheImages()) {
            return array('status' => 404);
        }

        $cover = self::describeCoverSource($source);
        if ($cover === null || !hash_equals($cover['version'], $version)) {
            return array('status' => 404);
        }

        $basePath = self::getCoverCacheBasePath($subjectId, $cover);
        $cached = self::findCachedCover($basePath);
        if (count($cached) === 0 && self::downloadCover($cover, $basePath)) {
            $cached = self::findCachedCover($basePath);
        }

        if (count($cached) === 0) {
            return array('status' => 404);
        }

        return array(
            'status' => 200,
            'file' => $cached['file'],
            'mime' => $cached['mime']
        );
    }

    /**
     * 获取日历封面响应
     */
    public static function getCalendarCover(int $subjectId, string $version): array
    {
        return self::getCoverResponse($subjectId, $version, self::findCalendarCoverSource($subjectId));
    }

    /**
     * 获取收藏列表封面响应
     */
    public static function getCollectionCover(
        int $subjectId,
        string $version,
        string $list,
        string $cate
    ): array
    {
        if (
            !in_array($list, ['watching', 'watched'], true)
            || !array_key_exists($cate, self::COLLECTION_SUBJECT_TYPES)
        ) {
            return array('status' => 404);
        }

        return self::getCoverResponse(
            $subjectId,
            $version,
            self::findCollectionCoverSource($subjectId, $list, $cate)
        );
    }

    /**
     * 获取单部 Subject 卡片封面响应
     */
    public static function getSubjectCover(int $subjectId, string $version): array
    {
        return self::getCoverResponse($subjectId, $version, self::findSubjectCoverSource($subjectId));
    }

    /**
     * 标准化列表缓存结构
     *
     * @access private
     * @param mixed $cache
     * @return array
     */
    private static function __normalizeCollectionCache(mixed $cache): array
    {
        if (!is_array($cache) || !isset($cache['data']) || !is_array($cache['data'])) {
            return self::EMPTY_COLLECTION_CACHE;
        }

        return $cache;
    }

    /**
     * 读取或刷新指定收藏状态的分类缓存
     */
    private static function getCategoryCollectionCache(
        string $ID,
        int $status,
        string $fileName,
        string $cate,
        int $ValidTimeSpan
    ): array
    {
        if (!array_key_exists($cate, self::COLLECTION_SUBJECT_TYPES)) {
            return self::EMPTY_COLLECTION_CACHE;
        }

        $list = pathinfo($fileName, PATHINFO_FILENAME);
        $cacheFileName = self::getCollectionCacheFileName($list, $cate);
        $filePath = self::getDataCachePath($cacheFileName);
        $userLimit = self::getIntOption('Limit', 30, 0, 300);
        $userKey = hash('sha256', $ID);
        $cache = self::__isCacheExpired($filePath, $ValidTimeSpan);

        if (is_array($cache) && (
            ($cache['data_variant'] ?? '') !== self::COLLECTION_CACHE_VARIANT
            || (int)($cache['limit'] ?? -1) !== $userLimit
            || (string)($cache['user_key'] ?? '') !== $userKey
            || (string)($cache['cate'] ?? '') !== $cate
        )) {
            $cache = 1;
        }

        if ($cache == -1 || $cache == 1) {
            $data = self::__getCollectionRawData(
                $ID,
                $status,
                self::COLLECTION_SUBJECT_TYPES[$cate],
                $userLimit
            );
            $cache = array(
                'time' => time(),
                'data_variant' => self::COLLECTION_CACHE_VARIANT,
                'limit' => $userLimit,
                'user_key' => $userKey,
                'cate' => $cate,
                'data' => $data
            );
            if ($userLimit > 0 && !count($data)) {
                $cache['time'] = 1;
            }
            self::__writeCache($filePath, $cache);
            $calendar = self::readCacheFile(self::getDataCachePath('calendar.json'));
            self::cleanupCoverCache($calendar['data'] ?? array());
        }

        return self::__normalizeCollectionCache($cache);
    }

    /**
     * 构造对应收藏分类的 Bangumi 页面地址
     */
    private static function buildCollectionMoreUrl(string $ID, string $type, string $cate): string
    {
        if ($ID === '' || !array_key_exists($cate, self::COLLECTION_SUBJECT_TYPES)) {
            return '';
        }

        $status = $type === 'watched' ? 'collect' : 'do';
        return 'https://bgm.tv/' . $cate . '/list/' . rawurlencode($ID) . '/' . $status;
    }

    /**
     * 构造前端分页对象
     */
    private static function buildCollectionPage(array $data, int $PageSize, int $From, string $moreUrl): array
    {
        $from = max(0, $From);
        $pageSize = max(0, $PageSize);
        $items = array_slice($data, $from, $pageSize);
        $nextOffset = $from + count($items);

        return array(
            'items' => $items,
            'next_offset' => $nextOffset,
            'has_more' => $nextOffset < count($data),
            'more_url' => $moreUrl
        );
    }

    /**
     * 按当前加载方式准备一条封面数据
     */
    private static function prepareCoverItemForOutput(array $item): array
    {
        $source = self::selectCoverUrl($item['images'] ?? array());
        $cover = self::describeCoverSource($source);
        unset($item['images'], $item['img'], $item['cover_version']);

        if (self::cacheImages()) {
            $item['cover_version'] = $cover['version'] ?? '';
        } else {
            $item['img'] = $cover['source_url'] ?? '';
        }

        return $item;
    }

    /**
     * 准备列表分页中的封面输出
     */
    private static function prepareCollectionPageForOutput(array $page): array
    {
        if (!isset($page['items']) || !is_array($page['items'])) {
            return $page;
        }

        foreach ($page['items'] as &$item) {
            $item = self::prepareCoverItemForOutput(is_array($item) ? $item : array());
        }
        unset($item);

        return $page;
    }

    /**
     * 准备日历封面输出
     */
    private static function prepareCalendarForOutput(array $calendar): array
    {
        foreach ($calendar as &$day) {
            if (!isset($day['items']) || !is_array($day['items'])) {
                $day['items'] = array();
                continue;
            }

            foreach ($day['items'] as &$item) {
                $item = self::prepareCoverItemForOutput(is_array($item) ? $item : array());
            }
            unset($item);
        }
        unset($day);

        return $calendar;
    }

    /**
     * 将条目使用的封面加入保留集合
     */
    private static function addReferencedCover(array &$referenced, array $item): void
    {
        $subjectId = (int)($item['id'] ?? 0);
        $cover = self::describeCoverSource(self::selectCoverUrl($item['images'] ?? array()));
        if ($subjectId <= 0 || $cover === null) {
            return;
        }

        $name = basename(self::getCoverCacheBasePath($subjectId, $cover));
        $referenced[$name . '.lock'] = true;
        foreach (array_unique(array_values(self::COVER_MIME_TYPES)) as $extension) {
            $referenced[$name . '.' . $extension] = true;
        }
    }

    /**
     * 清理长期未引用的封面缓存
     */
    private static function cleanupCoverCache(array $calendar): void
    {
        $directory = self::getCoverCacheDirectory();
        if (!is_dir($directory) || !is_readable($directory)) {
            return;
        }

        $referenced = array();
        foreach ($calendar as $day) {
            foreach (($day['items'] ?? array()) as $item) {
                self::addReferencedCover($referenced, $item);
            }
        }

        foreach (['watching', 'watched'] as $list) {
            foreach (array_keys(self::COLLECTION_SUBJECT_TYPES) as $cate) {
                $cache = self::readCacheFile(
                    self::getDataCachePath(self::getCollectionCacheFileName($list, $cate))
                );
                foreach (($cache['data'] ?? array()) as $item) {
                    self::addReferencedCover($referenced, $item);
                }
            }
        }

        $cutoff = time() - self::COVER_CACHE_RETENTION;
        $subjectDirectory = self::getSubjectCacheDirectory();
        $subjectEntries = is_readable($subjectDirectory) ? scandir($subjectDirectory) : false;
        if (is_array($subjectEntries)) {
            foreach ($subjectEntries as $entry) {
                $subjectPath = $subjectDirectory . '/' . $entry;
                $modified = is_file($subjectPath) ? filemtime($subjectPath) : false;
                if ($modified === false || $modified < $cutoff || pathinfo($entry, PATHINFO_EXTENSION) !== 'json') {
                    continue;
                }

                $subjectCache = self::readCacheFile($subjectPath);
                $subject = $subjectCache['data'] ?? array();
                if (is_array($subject)) {
                    self::addReferencedCover($referenced, $subject);
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

    /**
     * 读取并更新 Bangumi 条目卡片数据缓存
     */
    public static function updateSubjectCacheAndReturn(int $subjectId, int $ValidTimeSpan): string
    {
        if ($subjectId <= 0) {
            return self::encodeJson(array());
        }

        $directory = self::getSubjectCacheDirectory();
        if (!is_writable($directory)) {
            return self::encodeJson(array());
        }

        $filePath = $directory . '/' . $subjectId . '.json';
        $cache = self::__isCacheExpired($filePath, $ValidTimeSpan);
        if ($cache == -1 || $cache == 1) {
            $json = self::curlFileGetContents(self::buildApiUrl('/v0/subjects/' . $subjectId));
            $data = $json !== false ? json_decode($json, true) : null;
            if (!is_array($data) || (int)($data['id'] ?? 0) !== $subjectId) {
                return self::encodeJson(array());
            }

            $cache = array('time' => time(), 'data' => $data);
            self::__writeCache($filePath, $cache);
        }

        $cache = self::__normalizeCollectionCache($cache);
        $data = $cache['data'];
        if (!is_array($data)) {
            return self::encodeJson(array());
        }

        $images = self::extractCoverImages($data['images'] ?? array());
        $source = self::selectCoverUrl($images);
        $cover = self::describeCoverSource($source);
        if (self::cacheImages()) {
            unset($data['images']);
            $data['cover_version'] = $cover['version'] ?? '';
        } else {
            $data['images'] = $images;
            unset($data['cover_version']);
        }

        return self::encodeJson($data);
    }


    /**
     * 读取与更新本地已看缓存，格式化返回已看数据
     *
     * @access public
     * @param string $ID
     * @param int $PageSize
     * @param int $From
     * @param int $ValidTimeSpan
     * @return string
     * @throws Exception
     */
    public static function updateWatchedCacheAndReturn(string $ID, int $PageSize, int $From, int $ValidTimeSpan): string
    {
        $cate = self::getCate();
        $cache = self::getCategoryCollectionCache($ID, 2, 'watched.json', $cate, $ValidTimeSpan);
        $data = $cache['data'];
        $page = self::buildCollectionPage(
            $data,
            $PageSize,
            $From,
            self::buildCollectionMoreUrl($ID, 'watched', $cate)
        );
        return self::encodeJson(self::prepareCollectionPageForOutput($page));
    }

    /**
     * 读取与更新本地缓存，格式化返回数据
     *
     * @access public
     * @param string $ID
     * @param int $PageSize
     * @param int $From
     * @param int $ValidTimeSpan
     * @return string
     * @throws Exception
     */
    public static function updateWatchingCacheAndReturn(string $ID, int $PageSize, int $From, int $ValidTimeSpan): string
    {
        $cate = self::getCate();
        $cache = self::getCategoryCollectionCache($ID, 3, 'watching.json', $cate, $ValidTimeSpan);
        $data = $cache['data'];
        $page = self::buildCollectionPage(
            $data,
            $PageSize,
            $From,
            self::buildCollectionMoreUrl($ID, 'watching', $cate)
        );
        return self::encodeJson(self::prepareCollectionPageForOutput($page));
    }

    /**
     * 读取与更新本地日历缓存，格式化返回日历数据
     *
     * @access public
     * @param string $ID
     * @param int $ValidTimeSpan
     * @return string
     * @throws Exception
     */
    public static function updateCalendarCacheAndReturn(string $ID, int $ValidTimeSpan): string
    {
        $filePath = self::getDataCachePath('calendar.json');
        $cache = self::__isCacheExpired($filePath, $ValidTimeSpan);

        if (is_array($cache) && ($cache['image_variant'] ?? '') !== self::CALENDAR_IMAGE_VARIANT) {
            $cache = 1;
        }

        if ($cache == -1 || $cache == 1) {
            // 缓存无效，重新请求，数据写入
            $raw = self::__getCalendarRawData();
            if ($raw == -1 || count($raw) == 0) {
                // 请求数据为空
                $cache = array('time' => 1, 'data' => array());
            } else {
                $cache = array(
                    'time' => time(),
                    'image_variant' => self::CALENDAR_IMAGE_VARIANT,
                    'data' => $raw
                );
                self::cleanupCoverCache($raw);
            }
            self::__writeCache($filePath, $cache);
        }

        $cache = self::__normalizeCollectionCache($cache);
        $filter = self::getCalendarFilter();
        if ($filter !== 'watching') {
            return self::encodeJson(self::prepareCalendarForOutput($cache['data']));
        }

        $watchingPage = json_decode(self::updateWatchingCacheAndReturn($ID, 1000, 0, $ValidTimeSpan), true);
        if (!is_array($watchingPage) || !isset($watchingPage['items']) || !is_array($watchingPage['items'])) {
            return self::encodeJson(array());
        }
        $watchingAnimes = $watchingPage['items'];
        $watchingAnimeIds = array_column($watchingAnimes, 'id');

        $cal = array();
        foreach ($cache['data'] as $day) {
            $items = array_filter($day['items'], function ($item) use ($watchingAnimeIds) {
                return in_array($item['id'], $watchingAnimeIds);
            });
            $cal[] = array(
                'id' => $day['id'],
                'date_en' => $day['date_en'],
                'date_cn' => $day['date_cn'],
                'items' => $items
            );
        }
        return self::encodeJson(self::prepareCalendarForOutput($cal));
    }
}
