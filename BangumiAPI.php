<?php

namespace TypechoPlugin\PandaBangumi;

use Typecho\Plugin\Exception;
use Utils\Helper;

class BangumiAPI
{
    private const DEFAULT_API_BASE = 'https://api.bgm.tv';
    private const EMPTY_COLLECTION_CACHE = array('time' => 1, 'data' => array());
    private const EMPTY_TYPED_CACHE = array('time' => 1, 'data' => array('anime' => array(), 'real' => array()));

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
            || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || preg_match('/[\x00-\x1F\x7F]/', $apiBase)
        ) {
            return self::DEFAULT_API_BASE;
        }

        return rtrim($apiBase, '/');
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

        if (str_ends_with($apiBase, '/v0') && str_starts_with($path, '/v0/')) {
            $path = substr($path, 3);
        } elseif (str_ends_with($apiBase, '/v0') && $path === '/v0') {
            $path = '';
        }

        return $apiBase . $path;
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
     * 获取请求分类
     *
     * @access private
     * @return string
     */
    private static function getCate(): string
    {
        $cate = strtolower((string)($_GET['cate'] ?? 'anime'));
        return in_array($cate, ['anime', 'real'], true) ? $cate : '';
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
        curl_setopt($myCurl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36');
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
     * @param int $Offset
     * @param int $status 1:想看 2:看过 3:在看 4:搁置 5:抛弃
     * @param int $subject_type 1:book 2:anime 3:music 4:game 6:real
     * @return array
     * @throws Exception
     */
    private static function __getCollectionRawData(string $ID, int $Offset = 0, int $status = 3, int $subject_type = 2): array
    {
        $apiUrl = self::buildApiUrl('/v0/users/' . $ID . '/collections') . '?subject_type=' . $subject_type . '&type=' . $status . '&limit=30&offset=' . $Offset;
        $json = self::curlFileGetContents($apiUrl);
        if ($json === false) {
            return array();
        }

        if ($json == 'null') {
            return array(); // 没有标记数据
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['total'])) {
            return array();
        }

        $collections = array();

        $total = $data['total'];
        $limit = $data['limit'];
        $offset = $data['offset'];
        $list = $data['data'];

        foreach ($list as $item) {
            $subject = $item['subject'] ?? array();
            $subjectId = (int)($subject['id'] ?? 0);
            if ($subjectId <= 0) {
                continue;
            }

            $collect = array(
                'name' => (string)($subject['name'] ?? ''),
                'name_cn' => (string)($subject['name_cn'] ?? ''),
                'url' => 'https://bgm.tv/subject/' . $subjectId,
                'status' => (int)($item['ep_status'] ?? 0),
                'count' => (int)($subject['eps'] ?? 0),
                'air_date' => (string)($subject['date'] ?? ''),
                'img' => (string)($subject['images']['large'] ?? ''),
                'id' => $subjectId,
            );
            $collections[] = $collect;
        }

        $userLimit = self::getIntOption('Limit', 30, 0, 300);

        if ($total > $limit + $offset && $userLimit > $limit + $offset) {
            $collections = array_merge($collections, self::__getCollectionRawData($ID, $limit + $offset, $status, $subject_type));
        }

        return $collections;
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
                    'img' => (string)($item['images']['large'] ?? '')
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
     * 标准化分类缓存结构
     *
     * @access private
     * @param mixed $cache
     * @return array
     */
    private static function __normalizeTypedCache(mixed $cache): array
    {
        if (!is_array($cache) || !isset($cache['data']) || !is_array($cache['data'])) {
            return self::EMPTY_TYPED_CACHE;
        }

        foreach (['anime', 'real'] as $cate) {
            if (!isset($cache['data'][$cate]) || !is_array($cache['data'][$cate])) {
                $cache['data'][$cate] = array();
            }
        }

        return $cache;
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
     * 裁剪分页数据并返回 JSON
     *
     * @access private
     * @param array $data
     * @param int $PageSize
     * @param int $From
     * @return string
     */
    private static function __sliceData(array $data, int $PageSize, int $From): string
    {
        $total = count($data);
        if ($From < 0 || $From > $total) {
            return self::encodeJson(array());
        }

        return self::encodeJson(array_slice($data, $From, $PageSize));
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
        $cache = self::__isCacheExpired(__DIR__ . '/json/watched.json', $ValidTimeSpan);

        // 缓存过期或缓存无效
        if ($cache == -1 || $cache == 1) {
            // 缓存无效，重新请求，数据写入
            $watchedAnime = self::__getCollectionRawData($ID, 0, 2);
            $watchedReal = self::__getCollectionRawData($ID, 0, 2, 6);

            $cache = array('time' => time(), 'data' => array(
                'anime' => $watchedAnime,
                'real' => $watchedReal)
            );
            // 若全空，很可能是请求失败，则下次强制刷新
            if (!count($watchedAnime) && !count($watchedReal)) {
                $cache['time'] = 1;
            }
            self::__writeCache(__DIR__ . '/json/watched.json', $cache);
        }

        $cache = self::__normalizeTypedCache($cache);
        $cate = self::getCate();
        if (!array_key_exists($cate, $cache['data']))
            return self::encodeJson(array());

        return self::__sliceData($cache['data'][$cate], $PageSize, $From);
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
        $cache = self::__isCacheExpired(__DIR__ . '/json/watching.json', $ValidTimeSpan);

        if ($cache == -1 || $cache == 1) {
            // 缓存无效，重新请求，数据写入
            $watchingAnime = self::__getCollectionRawData($ID);
            $watchingReal = self::__getCollectionRawData($ID, 0, 3, 6);
            $cache = array('time' => time(), 'data' => array(
                'anime' => $watchingAnime,
                'real' => $watchingReal
            ));
            if (!count($watchingAnime) && !count($watchingReal)) {
                $cache['time'] = 1;
            }
            self::__writeCache(__DIR__ . '/json/watching.json', $cache);
        }

        $cache = self::__normalizeTypedCache($cache);
        $cate = self::getCate();
        if (!array_key_exists($cate, $cache['data']))
            return self::encodeJson(array());

        return self::__sliceData($cache['data'][$cate], $PageSize, $From);
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
        $cache = self::__isCacheExpired(__DIR__ . '/json/calendar.json', $ValidTimeSpan);

        if ($cache == -1 || $cache == 1) {
            // 缓存无效，重新请求，数据写入
            $raw = self::__getCalendarRawData();
            if ($raw == -1 || count($raw) == 0) {
                // 请求数据为空
                $cache = array('time' => 1, 'data' => array());
            } else {
                $cache = array('time' => time(), 'data' => $raw);
            }
            self::__writeCache(__DIR__ . '/json/calendar.json', $cache);
        }

        $cache = self::__normalizeCollectionCache($cache);
        $filter = self::getCalendarFilter();
        if ($filter !== 'watching') {
            return self::encodeJson($cache['data']);
        }

        $watchingAnimes = json_decode(self::updateWatchingCacheAndReturn($ID, 1000, 0, $ValidTimeSpan), true);
        if (!is_array($watchingAnimes)) {
            return self::encodeJson(array());
        }
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
        return self::encodeJson($cal);
    }
}
