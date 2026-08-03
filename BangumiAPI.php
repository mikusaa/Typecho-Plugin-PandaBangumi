<?php

namespace TypechoPlugin\PandaBangumi;

require_once __DIR__ . '/src/PluginConfig.php';
require_once __DIR__ . '/src/HttpTransport.php';
require_once __DIR__ . '/src/HttpClient.php';
require_once __DIR__ . '/src/CacheStore.php';
require_once __DIR__ . '/src/CoverService.php';
require_once __DIR__ . '/src/RequestParameters.php';
require_once __DIR__ . '/src/CollectionService.php';
require_once __DIR__ . '/src/SubjectService.php';
require_once __DIR__ . '/src/CalendarService.php';

class BangumiAPI
{
    private const CALENDAR_IMAGE_VARIANT = 'images-v2';
    private const COLLECTION_CACHE_VARIANT = 'category-v3';
    private const COLLECTION_SUBJECT_TYPES = array(
        'book' => 1,
        'anime' => 2,
        'music' => 3,
        'game' => 4,
        'real' => 6
    );

    private static ?PluginConfig $config = null;
    private static ?HttpClient $httpClient = null;
    private static ?CacheStore $cacheStore = null;
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
        return self::$httpClient ??= new HttpClient(self::config());
    }

    private static function cacheStore(): CacheStore
    {
        return self::$cacheStore ??= new CacheStore(__DIR__ . '/cache');
    }

    private static function coverService(): CoverService
    {
        return self::$coverService ??= new CoverService(
            self::config(),
            self::cacheStore(),
            self::COLLECTION_SUBJECT_TYPES,
            self::COLLECTION_CACHE_VARIANT
        );
    }

    private static function requestParameters(): RequestParameters
    {
        return self::$requestParameters ??= new RequestParameters(self::COLLECTION_SUBJECT_TYPES);
    }

    private static function collectionService(): CollectionService
    {
        return self::$collectionService ??= new CollectionService(
            self::config(),
            self::httpClient(),
            self::cacheStore(),
            self::coverService(),
            self::COLLECTION_SUBJECT_TYPES,
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

    public static function buildApiUrl(string $path): string
    {
        return self::config()->buildApiUrl($path);
    }

    public static function encodeJson(mixed $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        return $json === false ? '[]' : $json;
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
        return self::subjectService()->update($subjectId, $validTimeSpan);
    }

    public static function updateWatchedCacheAndReturn(
        string $userId,
        int $pageSize,
        int $from,
        int $validTimeSpan
    ): string {
        return self::collectionService()->update(
            $userId,
            'watched',
            self::requestParameters()->category($_GET),
            $pageSize,
            $from,
            $validTimeSpan
        );
    }

    public static function updateWatchingCacheAndReturn(
        string $userId,
        int $pageSize,
        int $from,
        int $validTimeSpan
    ): string {
        return self::collectionService()->update(
            $userId,
            'watching',
            self::requestParameters()->category($_GET),
            $pageSize,
            $from,
            $validTimeSpan
        );
    }

    public static function updateCalendarCacheAndReturn(string $userId, int $validTimeSpan): string
    {
        return self::calendarService()->update(
            $userId,
            self::requestParameters()->calendarFilter($_GET),
            $validTimeSpan
        );
    }
}
