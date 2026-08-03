<?php

namespace TypechoPlugin\PandaBangumi;

require_once __DIR__ . '/src/PluginConfig.php';
require_once __DIR__ . '/src/HttpTransport.php';
require_once __DIR__ . '/src/HttpClient.php';
require_once __DIR__ . '/src/CacheStore.php';
require_once __DIR__ . '/src/RateLimitExceeded.php';
require_once __DIR__ . '/src/RateLimiter.php';
require_once __DIR__ . '/src/UpstreamGate.php';
require_once __DIR__ . '/src/CoverService.php';
require_once __DIR__ . '/src/RequestParameters.php';
require_once __DIR__ . '/src/CollectionService.php';
require_once __DIR__ . '/src/SubjectService.php';
require_once __DIR__ . '/src/CalendarService.php';

class BangumiAPI
{
    private const CALENDAR_IMAGE_VARIANT = 'images-v2';
    private const COLLECTION_CACHE_VARIANT = 'category-v4';
    private const COLLECTION_SUBJECT_TYPES = array(
        'book' => 1,
        'anime' => 2,
        'music' => 3,
        'game' => 4,
        'real' => 6
    );
    private const COLLECTION_LIST_TYPES = array(
        'anime' => array('watching', 'watched'),
        'real' => array('watching', 'watched'),
        'book' => array('reading', 'read'),
        'game' => array('playing', 'played'),
        'music' => array('listening', 'listened')
    );

    private static ?PluginConfig $config = null;
    private static ?HttpClient $httpClient = null;
    private static ?CacheStore $cacheStore = null;
    private static ?RateLimiter $rateLimiter = null;
    private static ?UpstreamGate $upstreamGate = null;
    private static ?CoverService $coverService = null;
    private static ?RequestParameters $requestParameters = null;
    private static ?CollectionService $collectionService = null;
    private static ?SubjectService $subjectService = null;
    private static ?CalendarService $calendarService = null;

    private static function config(): PluginConfig
    {
        return self::$config ??= new PluginConfig();
    }

    private static function httpClient(): HttpClient
    {
        return self::$httpClient ??= new HttpClient(self::config(), self::upstreamGate());
    }

    private static function cacheStore(): CacheStore
    {
        return self::$cacheStore ??= new CacheStore(__DIR__ . '/.cache');
    }

    private static function rateLimiter(): RateLimiter
    {
        return self::$rateLimiter ??= new RateLimiter(self::cacheStore());
    }

    private static function upstreamGate(): UpstreamGate
    {
        return self::$upstreamGate ??= new UpstreamGate(self::cacheStore(), self::rateLimiter());
    }

    private static function coverService(): CoverService
    {
        if (self::$coverService !== null) {
            return self::$coverService;
        }
        self::$coverService = new CoverService(
            self::config(),
            self::cacheStore(),
            self::upstreamGate(),
            self::COLLECTION_SUBJECT_TYPES,
            self::COLLECTION_LIST_TYPES,
            self::COLLECTION_CACHE_VARIANT
        );
        return self::$coverService;
    }

    private static function requestParameters(): RequestParameters
    {
        return self::$requestParameters ??= new RequestParameters(
            self::COLLECTION_SUBJECT_TYPES,
            self::COLLECTION_LIST_TYPES
        );
    }

    private static function collectionService(): CollectionService
    {
        return self::$collectionService ??= new CollectionService(
            self::config(),
            self::httpClient(),
            self::cacheStore(),
            self::coverService(),
            self::COLLECTION_SUBJECT_TYPES,
            self::COLLECTION_LIST_TYPES,
            self::COLLECTION_CACHE_VARIANT
        );
    }

    private static function subjectService(): SubjectService
    {
        return self::$subjectService ??= new SubjectService(
            self::config(),
            self::httpClient(),
            self::cacheStore(),
            self::coverService()
        );
    }

    private static function calendarService(): CalendarService
    {
        return self::$calendarService ??= new CalendarService(
            self::config(),
            self::httpClient(),
            self::cacheStore(),
            self::coverService(),
            self::collectionService(),
            self::CALENDAR_IMAGE_VARIANT
        );
    }

    public static function getApiBase(): string
    {
        return self::config()->apiBase();
    }

    public static function initializeCache(): bool
    {
        $initialized = self::cacheStore()->initialize();
        if ($initialized) {
            self::coverService()->maybeRunMaintenance();
        }
        return $initialized;
    }

    public static function buildApiUrl(string $path): string
    {
        return self::config()->buildApiUrl($path);
    }

    public static function encodeJson(mixed $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        return $json === false ? '[]' : $json;
    }

    public static function isCollectionType(string $type): bool
    {
        foreach (self::COLLECTION_LIST_TYPES as $types) {
            if (in_array($type, $types, true)) {
                return true;
            }
        }
        return false;
    }

    public static function curlFileGetContents(string $url): bool|string
    {
        return self::httpClient()->get($url);
    }

    public static function getCalendarCover(int $subjectId, string $version): array
    {
        return self::coverService()->getCalendarCover($subjectId, $version);
    }

    public static function getCollectionCover(
        int $subjectId,
        string $version,
        string $list,
        string $category
    ): array {
        return self::coverService()->getCollectionCover($subjectId, $version, $list, $category);
    }

    public static function getSubjectCover(int $subjectId, string $version): array
    {
        return self::coverService()->getSubjectCover($subjectId, $version);
    }

    public static function updateSubjectCacheAndReturn(int $subjectId, int $validTimeSpan): string
    {
        return self::subjectService()->update(
            $subjectId,
            PluginConfig::normalizeRefreshInterval($validTimeSpan)
        );
    }

    public static function updateCollectionCacheAndReturn(
        string $userId,
        int $pageSize,
        int $from,
        int $validTimeSpan
    ): string {
        $parameters = self::requestParameters();
        $category = $parameters->category($_GET);
        $list = $parameters->collectionList($_GET);
        if ($category === '' || $list === '') {
            return self::encodeJson(array());
        }

        return self::collectionService()->update(
            $userId,
            $list,
            $category,
            $pageSize,
            $from,
            PluginConfig::normalizeRefreshInterval($validTimeSpan)
        );
    }

    public static function updateCalendarCacheAndReturn(string $userId, int $validTimeSpan): string
    {
        return self::calendarService()->update(
            $userId,
            self::requestParameters()->calendarFilter($_GET),
            PluginConfig::normalizeRefreshInterval($validTimeSpan)
        );
    }
}
