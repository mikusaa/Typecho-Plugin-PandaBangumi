<?php

declare(strict_types=1);

namespace Typecho\Plugin {
    class Exception extends \Exception
    {
    }
}

namespace Utils {
    final class TestOptions
    {
        public function plugin(string $name): object
        {
            return Helper::$pluginOptions;
        }
    }

    final class Helper
    {
        public static object $pluginOptions;

        public static function options(): TestOptions
        {
            return new TestOptions();
        }
    }
}

namespace {
    use TypechoPlugin\PandaBangumi\BangumiAPI;
    use TypechoPlugin\PandaBangumi\CacheStore;
    use TypechoPlugin\PandaBangumi\CalendarService;
    use TypechoPlugin\PandaBangumi\CollectionService;
    use TypechoPlugin\PandaBangumi\CoverService;
    use TypechoPlugin\PandaBangumi\HttpTransport;
    use TypechoPlugin\PandaBangumi\PluginConfig;
    use TypechoPlugin\PandaBangumi\RequestParameters;
    use TypechoPlugin\PandaBangumi\SubjectService;
    use Utils\Helper;

    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }

    define('PandaBangumi_Plugin_VERSION', 'test');
    require dirname(__DIR__) . '/BangumiAPI.php';

    const SUBJECT_TYPES = array('book' => 1, 'anime' => 2, 'music' => 3, 'game' => 4, 'real' => 6);
    const LIST_TYPES = array(
        'anime' => array('watching', 'watched'),
        'real' => array('watching', 'watched'),
        'book' => array('reading', 'read'),
        'game' => array('playing', 'played'),
        'music' => array('listening', 'listened')
    );
    const COLLECTION_VARIANT = 'category-v3';
    const CALENDAR_VARIANT = 'images-v2';

    final class TestFailure extends RuntimeException
    {
    }

    final class FakeHttpTransport implements HttpTransport
    {
        public array $urls = array();

        public function __construct(private array $responses)
        {
        }

        public function get(string $url): bool|string
        {
            $this->urls[] = $url;
            return count($this->responses) > 0 ? array_shift($this->responses) : false;
        }
    }

    function assertSameValue(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new TestFailure(($message !== '' ? $message . ': ' : '')
                . 'expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true));
        }
    }

    function assertTrueValue(bool $actual, string $message = ''): void
    {
        if (!$actual) {
            throw new TestFailure($message !== '' ? $message : 'expected true');
        }
    }

    function fixture(string $name): string
    {
        return (string)file_get_contents(__DIR__ . '/fixtures/' . $name);
    }

    function testDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/pandabangumi-tests-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        return $directory;
    }

    function removeTestDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $entries = scandir($directory);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $path = $directory . '/' . $entry;
                    is_dir($path) ? removeTestDirectory($path) : unlink($path);
                }
            }
        }
        rmdir($directory);
    }

    function config(array $options): PluginConfig
    {
        return new PluginConfig(static fn(): object => (object)$options);
    }

    function coverService(PluginConfig $config, CacheStore $cacheStore): CoverService
    {
        return new CoverService($config, $cacheStore, SUBJECT_TYPES, LIST_TYPES, COLLECTION_VARIANT);
    }

    function collectionService(
        PluginConfig $config,
        HttpTransport $http,
        CacheStore $cacheStore,
        CoverService $coverService
    ): CollectionService {
        return new CollectionService(
            $config,
            $http,
            $cacheStore,
            $coverService,
            SUBJECT_TYPES,
            LIST_TYPES,
            COLLECTION_VARIANT
        );
    }

    $tests = array();
    $test = static function (string $name, callable $callback) use (&$tests): void {
        $tests[$name] = $callback;
    };

    $test('API base normalization', static function (): void {
        Helper::$pluginOptions = (object)array('ApiBase' => '');
        assertSameValue('https://api.bgm.tv', BangumiAPI::getApiBase());

        $mirror = config(array('ApiBase' => 'https://mirror.example.com/v0/path'));
        assertSameValue('https://mirror.example.com', $mirror->apiBase());
        assertSameValue('https://mirror.example.com/calendar', $mirror->buildApiUrl('/calendar'));
        assertSameValue('https://api.bgm.tv', config(array('ApiBase' => 'http://mirror.example.com'))->apiBase());
    });

    $test('JSON encoding fallback', static function (): void {
        assertSameValue('{"title":"test"}', BangumiAPI::encodeJson(array('title' => 'test')));
        $handle = fopen('php://memory', 'r');
        assertSameValue('[]', BangumiAPI::encodeJson($handle));
        fclose($handle);
    });

    $test('Request parameter normalization', static function (): void {
        $parameters = new RequestParameters(SUBJECT_TYPES, LIST_TYPES);
        foreach (array_merge(...array_values(LIST_TYPES)) as $type) {
            assertSameValue(true, BangumiAPI::isCollectionType($type));
        }
        assertSameValue(false, BangumiAPI::isCollectionType('completed'));
        assertSameValue('game', $parameters->category(array('cate' => 'GAME')));
        assertSameValue('music', $parameters->category(array('cate' => 'music')));
        assertSameValue('', $parameters->category(array('cate' => 'podcast')));
        assertSameValue('watching', $parameters->collectionList(array('type' => 'watching', 'cate' => 'anime')));
        assertSameValue('watched', $parameters->collectionList(array('type' => 'watched', 'cate' => 'real')));
        assertSameValue('reading', $parameters->collectionList(array('type' => 'reading', 'cate' => 'book')));
        assertSameValue('read', $parameters->collectionList(array('type' => 'read', 'cate' => 'book')));
        assertSameValue('playing', $parameters->collectionList(array('type' => 'playing', 'cate' => 'game')));
        assertSameValue('played', $parameters->collectionList(array('type' => 'played', 'cate' => 'game')));
        assertSameValue('listening', $parameters->collectionList(array('type' => 'listening', 'cate' => 'music')));
        assertSameValue('listened', $parameters->collectionList(array('type' => 'listened', 'cate' => 'music')));
        assertSameValue('', $parameters->collectionList(array('type' => 'watching', 'cate' => 'music')));
        assertSameValue('', $parameters->collectionList(array('type' => 'watched', 'cate' => 'book')));
        assertSameValue('watching', $parameters->calendarFilter(array('filter' => 'watching')));
        assertSameValue('all', $parameters->calendarFilter(array('filter' => 'unexpected')));
    });

    $test('Cover selection and validation', static function (): void {
        $directory = testDirectory();
        try {
            $service = coverService(config(array('ImageMode' => 'direct')), new CacheStore($directory));
            $images = $service->extractImages(array(
                'small' => 'https://example.com/s.jpg',
                'large' => 'https://example.com/l.jpg',
                'unknown' => 'https://example.com/x.jpg'
            ));
            assertSameValue(array(
                'small' => 'https://example.com/s.jpg',
                'large' => 'https://example.com/l.jpg'
            ), $images);
            assertSameValue('https://example.com/l.jpg', $service->selectUrl($images));
            assertSameValue('', $service->selectUrl(array('medium' => 'https://example.com/m.jpg')));

            $source = 'http://lain.bgm.tv/pic/cover/l/example.jpg';
            $cover = $service->describeSource($source);
            assertSameValue('https://lain.bgm.tv/pic/cover/l/example.jpg', $cover['fetch_url']);
            assertSameValue(substr(hash('sha256', "large\n" . $source), 0, 16), $cover['version']);
            assertSameValue(null, $service->describeSource('https://user@example.com/image.jpg'));
            assertSameValue(null, $service->describeSource("https://example.com/im\nage.jpg"));
            assertSameValue(null, $service->describeSource('file:///tmp/image.jpg'));
        } finally {
            removeTestDirectory($directory);
        }
    });

    $test('Public IP validation', static function (): void {
        $directory = testDirectory();
        try {
            $service = coverService(config(array()), new CacheStore($directory));
            assertTrueValue($service->isPublicIp('1.1.1.1'));
            assertSameValue(false, $service->isPublicIp('127.0.0.1'));
            assertSameValue(false, $service->isPublicIp('192.168.1.1'));
            assertSameValue(false, $service->isPublicIp('::1'));
        } finally {
            removeTestDirectory($directory);
        }
    });

    $test('Collection pagination and more URL', static function (): void {
        $directory = testDirectory();
        try {
            $pluginConfig = config(array());
            $cacheStore = new CacheStore($directory);
            $service = collectionService(
                $pluginConfig,
                new FakeHttpTransport(array()),
                $cacheStore,
                coverService($pluginConfig, $cacheStore)
            );
            $items = json_decode(fixture('collection-items.json'), true);
            $page = $service->page($items, 2, 0, 'more');
            assertSameValue(array(101, 102), array_column($page['items'], 'id'));
            assertSameValue(2, $page['next_offset']);
            assertSameValue(true, $page['has_more']);
            assertSameValue('https://bgm.tv/anime/list/test%20user/do', $service->moreUrl('test user', 'watching', 'anime'));
            assertSameValue('https://bgm.tv/book/list/test/do', $service->moreUrl('test', 'reading', 'book'));
            assertSameValue('https://bgm.tv/game/list/test/collect', $service->moreUrl('test', 'played', 'game'));
            assertSameValue('https://bgm.tv/music/list/test/do', $service->moreUrl('test', 'listening', 'music'));
            assertSameValue('https://bgm.tv/music/list/test/collect', $service->moreUrl('test', 'listened', 'music'));
            assertSameValue('', $service->moreUrl('test', 'listening', 'podcast'));
        } finally {
            removeTestDirectory($directory);
        }
    });

    $test('Cover output modes', static function (): void {
        $directory = testDirectory();
        try {
            $item = array('id' => 101, 'images' => array('large' => 'https://example.com/cover.jpg'));
            $direct = coverService(config(array('ImageMode' => 'direct')), new CacheStore($directory))->prepareItem($item);
            assertSameValue('https://example.com/cover.jpg', $direct['img']);
            assertSameValue(false, array_key_exists('images', $direct));

            $cached = coverService(config(array('ImageMode' => 'cache')), new CacheStore($directory))->prepareItem($item);
            assertSameValue(false, array_key_exists('img', $cached));
            assertSameValue(
                substr(hash('sha256', "large\nhttps://example.com/cover.jpg"), 0, 16),
                $cached['cover_version']
            );
        } finally {
            removeTestDirectory($directory);
        }
    });

    $test('Cover response binds version to cached source', static function (): void {
        $directory = testDirectory();
        try {
            $pluginConfig = config(array('ImageMode' => 'cache'));
            $cacheStore = new CacheStore($directory);
            $service = coverService($pluginConfig, $cacheStore);
            $source = 'https://lain.bgm.tv/pic/cover/l/test-101.jpg';
            $descriptor = $service->describeSource($source);
            $cacheStore->write($cacheStore->dataPath('calendar.json'), array(
                'time' => time(),
                'data' => array(array('items' => array(array(
                    'id' => 101,
                    'images' => array('large' => $source)
                ))))
            ));

            $coverPath = $cacheStore->directory('covers')
                . '/101-' . $descriptor['version'] . '.png';
            file_put_contents(
                $coverPath,
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
            );

            $response = $service->getCalendarCover(101, $descriptor['version']);
            assertSameValue(200, $response['status']);
            assertSameValue('image/png', $response['mime']);
            assertSameValue(404, $service->getCalendarCover(101, '0000000000000000')['status']);
        } finally {
            removeTestDirectory($directory);
        }
    });

    $test('Cache lifecycle with injected clock', static function (): void {
        $directory = testDirectory();
        try {
            $cacheStore = new CacheStore($directory, static fn(): int => 1000);
            $file = $cacheStore->dataPath('cache.json');
            $cache = array('time' => 1000, 'data' => array('ok' => true));
            assertTrueValue($cacheStore->write($file, $cache));
            assertSameValue($cache, $cacheStore->read($file));
            assertSameValue($cache, $cacheStore->freshness($file, 60));

            $expired = array('time' => 800, 'data' => array());
            $cacheStore->write($file, $expired);
            assertSameValue(1, $cacheStore->freshness($file, 60));
            $deferred = $cacheStore->deferRefresh($file, $expired);
            assertSameValue(1300, $deferred['retry_after']);

            $lock = $cacheStore->acquireRefreshLock($file);
            assertTrueValue(is_resource($lock));
            $cacheStore->releaseRefreshLock($lock);
        } finally {
            removeTestDirectory($directory);
        }
    });

    $test('Collection refresh uses fixture and then cache', static function (): void {
        $directory = testDirectory();
        try {
            $pluginConfig = config(array('Limit' => 2, 'ImageMode' => 'direct'));
            $cacheStore = new CacheStore($directory, static fn(): int => 1000);
            $http = new FakeHttpTransport(array(fixture('collection-response.json')));
            $service = collectionService(
                $pluginConfig,
                $http,
                $cacheStore,
                coverService($pluginConfig, $cacheStore)
            );

            $page = json_decode($service->update('tester', 'watching', 'anime', 1, 0, 60), true);
            assertSameValue(array(101), array_column($page['items'], 'id'));
            assertSameValue(true, $page['has_more']);
            assertSameValue(1, count($http->urls));

            $cached = json_decode($service->update('tester', 'watching', 'anime', 12, 0, 60), true);
            assertSameValue(array(101, 102), array_column($cached['items'], 'id'));
            assertSameValue(1, count($http->urls));

            $musicHttp = new FakeHttpTransport(array(fixture('collection-response.json')));
            $musicService = collectionService(
                $pluginConfig,
                $musicHttp,
                $cacheStore,
                coverService($pluginConfig, $cacheStore)
            );
            $musicService->update('tester', 'listening', 'music', 1, 0, 60);
            assertSameValue(
                'https://api.bgm.tv/v0/users/tester/collections?subject_type=3&type=3&limit=30&offset=0',
                $musicHttp->urls[0]
            );
            assertTrueValue(is_file($cacheStore->dataPath('listening-music.json')));

            $listenedHttp = new FakeHttpTransport(array(fixture('collection-response.json')));
            $listenedService = collectionService(
                $pluginConfig,
                $listenedHttp,
                $cacheStore,
                coverService($pluginConfig, $cacheStore)
            );
            $listenedService->update('tester', 'listened', 'music', 1, 0, 60);
            assertSameValue(
                'https://api.bgm.tv/v0/users/tester/collections?subject_type=3&type=2&limit=30&offset=0',
                $listenedHttp->urls[0]
            );
            assertTrueValue(is_file($cacheStore->dataPath('listened-music.json')));
        } finally {
            removeTestDirectory($directory);
        }
    });

    $test('Subject refresh uses fixture', static function (): void {
        $directory = testDirectory();
        try {
            $pluginConfig = config(array('ImageMode' => 'cache'));
            $cacheStore = new CacheStore($directory, static fn(): int => 1000);
            $cover = coverService($pluginConfig, $cacheStore);
            $service = new SubjectService(
                $pluginConfig,
                new FakeHttpTransport(array(fixture('subject-response.json'))),
                $cacheStore,
                $cover
            );

            $subject = json_decode($service->update(101, 60), true);
            assertSameValue(101, $subject['id']);
            assertSameValue(false, array_key_exists('images', $subject));
            assertTrueValue((bool)preg_match('/^[a-f0-9]{16}$/', $subject['cover_version']));
        } finally {
            removeTestDirectory($directory);
        }
    });

    $test('Calendar watching filter uses anime collection', static function (): void {
        $directory = testDirectory();
        try {
            $pluginConfig = config(array('Limit' => 2, 'ImageMode' => 'direct'));
            $cacheStore = new CacheStore($directory, static fn(): int => 1000);
            $http = new FakeHttpTransport(array(
                fixture('calendar-response.json'),
                fixture('collection-response.json')
            ));
            $cover = coverService($pluginConfig, $cacheStore);
            $collection = collectionService($pluginConfig, $http, $cacheStore, $cover);
            $calendar = new CalendarService(
                $pluginConfig,
                $http,
                $cacheStore,
                $cover,
                $collection,
                CALENDAR_VARIANT
            );

            $result = json_decode($calendar->update('tester', 'watching', 60), true);
            assertSameValue(array(101), array_column($result[0]['items'], 'id'));
            assertSameValue(2, count($http->urls));
        } finally {
            removeTestDirectory($directory);
        }
    });

    $passed = 0;
    foreach ($tests as $name => $callback) {
        try {
            $callback();
            $passed++;
            fwrite(STDOUT, "PASS {$name}\n");
        } catch (Throwable $error) {
            fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
            exit(1);
        }
    }
    fwrite(STDOUT, "{$passed} tests passed\n");
}
