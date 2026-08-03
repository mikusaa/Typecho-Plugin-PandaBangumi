<?php

declare(strict_types=1);

namespace Typecho\Plugin {
    class Exception extends \Exception
    {
    }
}

namespace Widget {
    interface ActionInterface
    {
    }
}

namespace Widget\Base {
    class Contents
    {
        public object $response;
        public object $request;
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
    use TypechoPlugin\PandaBangumi\Action;
    use TypechoPlugin\PandaBangumi\CacheStore;
    use TypechoPlugin\PandaBangumi\CalendarService;
    use TypechoPlugin\PandaBangumi\CollectionService;
    use TypechoPlugin\PandaBangumi\CoverService;
    use TypechoPlugin\PandaBangumi\HttpTransport;
    use TypechoPlugin\PandaBangumi\PluginConfig;
    use TypechoPlugin\PandaBangumi\RateLimitExceeded;
    use TypechoPlugin\PandaBangumi\RateLimiter;
    use TypechoPlugin\PandaBangumi\RequestParameters;
    use TypechoPlugin\PandaBangumi\SubjectService;
    use Utils\Helper;

    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }

    define('PandaBangumi_Plugin_VERSION', 'test');
    define('PANDABANGUMI_TESTING', true);
    define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));
    require dirname(__DIR__) . '/BangumiAPI.php';
    require dirname(__DIR__) . '/Action.php';

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

    final class TestResponse
    {
        public int $status = 200;
        public array $headers = array();

        public function setStatus(int $status): void
        {
            $this->status = $status;
        }

        public function setHeader(string $name, string $value): void
        {
            $this->headers[$name] = $value;
        }

        public function throwFile(string $file): void
        {
        }
    }

    final class TestRequest
    {
        public function getHeader(string $name, string $default = ''): string
        {
            return $default;
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

    function assertRateLimited(callable $callback, int $retryAfter = 1): void
    {
        try {
            $callback();
        } catch (RateLimitExceeded $error) {
            assertSameValue($retryAfter, $error->retryAfter());
            return;
        }
        throw new TestFailure('expected RateLimitExceeded');
    }

    function setBangumiApiService(string $property, mixed $value): void
    {
        $reflection = new ReflectionProperty(BangumiAPI::class, $property);
        $reflection->setValue(null, $value);
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

    function coverService(
        PluginConfig $config,
        CacheStore $cacheStore,
        ?RateLimiter $rateLimiter = null,
        int $maxEntries = 2048,
        int $maxBytes = 536870912
    ): CoverService
    {
        return new CoverService(
            $config,
            $cacheStore,
            $rateLimiter ?? new RateLimiter($cacheStore),
            SUBJECT_TYPES,
            LIST_TYPES,
            COLLECTION_VARIANT,
            $maxEntries,
            $maxBytes
        );
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

    $test('Action returns rate limit response contracts', static function (): void {
        $directory = testDirectory();
        $originalGet = $_GET;
        try {
            $cacheStore = new CacheStore($directory, static fn(): int => 1000);
            $rateLimiter = new RateLimiter($cacheStore);
            $pluginConfig = config(array('ImageMode' => 'cache'));
            $cover = coverService($pluginConfig, $cacheStore, $rateLimiter);
            $subject = new SubjectService(
                $pluginConfig,
                new FakeHttpTransport(array()),
                $cacheStore,
                $cover,
                $rateLimiter
            );
            $cacheStore->write($cacheStore->statePath('rate-limit.php'), array(
                'version' => 1,
                'buckets' => array(
                    'subject' => array('tokens' => 0, 'updated_at' => 1000),
                    'cover' => array('tokens' => 0, 'updated_at' => 1000)
                )
            ));

            setBangumiApiService('config', $pluginConfig);
            setBangumiApiService('cacheStore', $cacheStore);
            setBangumiApiService('rateLimiter', $rateLimiter);
            setBangumiApiService('coverService', $cover);
            setBangumiApiService('subjectService', $subject);
            Helper::$pluginOptions = (object)array('ImageMode' => 'cache', 'ValidTimeSpan' => 60);

            $_GET = array('type' => 'subject', 'id' => 1000);
            $subjectAction = new Action();
            $subjectAction->response = new TestResponse();
            $subjectAction->request = new TestRequest();
            ob_start();
            $subjectAction->action();
            $body = (string)ob_get_clean();
            assertSameValue(429, http_response_code());
            assertSameValue(array('error' => 'rate_limited', 'retry_after' => 1), json_decode($body, true));

            $source = 'https://1.1.1.1/cover.png';
            $descriptor = $cover->describeSource($source);
            $cacheStore->write($cacheStore->subjectPath(1001), array(
                'time' => 1000,
                'subject_id' => 1001,
                'data' => array('id' => 1001, 'images' => array('large' => $source))
            ));
            $_GET = array(
                'type' => 'cover',
                'scope' => 'subject',
                'id' => 1001,
                'v' => $descriptor['version']
            );
            $coverAction = new Action();
            $coverAction->response = new TestResponse();
            $coverAction->request = new TestRequest();
            $coverAction->action();
            assertSameValue(429, http_response_code());
        } finally {
            http_response_code(200);
            $_GET = $originalGet;
            foreach (array('config', 'cacheStore', 'rateLimiter', 'coverService', 'subjectService') as $property) {
                setBangumiApiService($property, null);
            }
            removeTestDirectory($directory);
        }
    });

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
            $cacheStore->write($cacheStore->dataPath('calendar.php'), array(
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
            $file = $cacheStore->dataPath('cache.php');
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

    $test('Protected cache format rejects unguarded data', static function (): void {
        $directory = testDirectory();
        try {
            $cacheStore = new CacheStore($directory);
            $file = $cacheStore->dataPath('protected.php');
            $cache = array('time' => 1, 'data' => array('title' => 'test'));
            assertTrueValue($cacheStore->write($file, $cache));
            $raw = (string)file_get_contents($file);
            assertTrueValue(str_starts_with($raw, CacheStore::PHP_PREFIX));
            assertSameValue($cache, $cacheStore->read($file));

            file_put_contents($file, json_encode($cache));
            assertSameValue(array(), $cacheStore->read($file));
            file_put_contents($file, "<?php exit; ?>\n" . json_encode($cache));
            assertSameValue(array(), $cacheStore->read($file));
        } finally {
            removeTestDirectory($directory);
        }
    });

    $test('Cache initialization deletes legacy data once', static function (): void {
        $pluginDirectory = testDirectory();
        $cacheDirectory = $pluginDirectory . '/cache';
        try {
            mkdir($cacheDirectory . '/data', 0700, true);
            mkdir($cacheDirectory . '/subjects', 0700, true);
            mkdir($cacheDirectory . '/covers', 0700, true);
            mkdir($pluginDirectory . '/json/nested', 0700, true);
            file_put_contents($cacheDirectory . '/data/calendar.json', '{}');
            file_put_contents($cacheDirectory . '/subjects/1.json', '{}');
            file_put_contents($cacheDirectory . '/subjects/1.json.refresh.lock', '');
            file_put_contents($cacheDirectory . '/covers/1-test.lock', '');
            file_put_contents($cacheDirectory . '/covers/1-test.jpg', 'cover');
            file_put_contents($pluginDirectory . '/json/nested/cache.json', '{}');

            $cacheStore = new CacheStore($cacheDirectory, static fn(): int => 1000);
            assertSameValue(false, is_file($cacheDirectory . '/data/calendar.json'));
            assertSameValue(false, is_file($cacheDirectory . '/subjects/1.json'));
            assertSameValue(false, is_file($cacheDirectory . '/subjects/1.json.refresh.lock'));
            assertSameValue(false, is_file($cacheDirectory . '/covers/1-test.lock'));
            assertTrueValue(is_file($cacheDirectory . '/covers/1-test.jpg'));
            assertSameValue(false, is_dir($pluginDirectory . '/json'));
            assertSameValue(1, $cacheStore->read($cacheStore->statePath('layout.php'))['version']);

            $current = $cacheStore->dataPath('calendar.php');
            $cacheStore->write($current, array('time' => 1000, 'data' => array()));
            assertTrueValue((new CacheStore($cacheDirectory, static fn(): int => 1001))->initialize());
            assertTrueValue(is_file($current));
        } finally {
            removeTestDirectory($pluginDirectory);
        }
    });

    $test('Rate limiter refills and fails closed', static function (): void {
        $directory = testDirectory();
        try {
            $now = 1000;
            $cacheStore = new CacheStore($directory, static function () use (&$now): int {
                return $now;
            });
            $limiter = new RateLimiter($cacheStore);
            $limiter->consume('test', 2, 1.0);
            $limiter->consume('test', 2, 1.0);
            assertRateLimited(static fn() => $limiter->consume('test', 2, 1.0));

            $now = 1001;
            $limiter->consume('test', 2, 1.0);
            assertRateLimited(static fn() => $limiter->consume('test', 2, 1.0));

            file_put_contents($cacheStore->statePath('rate-limit.php'), 'invalid');
            assertRateLimited(static fn() => $limiter->consume('test', 2, 1.0));

            $cacheStore->write($cacheStore->statePath('rate-limit.php'), array(
                'version' => 1,
                'buckets' => array('test' => array('tokens' => 'broken'))
            ));
            assertRateLimited(static fn() => $limiter->consume('test', 2, 1.0));
        } finally {
            removeTestDirectory($directory);
        }
    });

    $test('Shard locks use at most 64 files per scope', static function (): void {
        $directory = testDirectory();
        try {
            $cacheStore = new CacheStore($directory);
            for ($id = 1; $id <= 512; $id++) {
                $lock = $cacheStore->acquireShardLock('subject', (string)$id);
                assertTrueValue(is_resource($lock));
                $cacheStore->releaseRefreshLock($lock);
            }
            $locks = glob($cacheStore->directory('locks') . '/subject-*.lock');
            assertTrueValue(is_array($locks) && count($locks) <= 64);
            foreach ($locks as $lock) {
                assertTrueValue((bool)preg_match('/subject-[0-9]{2}\.lock$/', $lock));
            }
        } finally {
            removeTestDirectory($directory);
        }
    });

    $test('Subject cache pruning preserves current entry', static function (): void {
        $directory = testDirectory();
        try {
            $cacheStore = new CacheStore($directory);
            for ($id = 1; $id <= 258; $id++) {
                $path = $cacheStore->subjectPath($id);
                $cacheStore->write($path, array('time' => $id, 'subject_id' => $id, 'data' => array('id' => $id)));
                touch($path, 1000 + $id);
            }
            $cacheStore->pruneSubjectCaches(258, 256);
            $files = glob($cacheStore->directory('subjects') . '/[0-9]*.php');
            assertSameValue(256, is_array($files) ? count($files) : 0);
            assertTrueValue(is_file($cacheStore->subjectPath(258)));
            assertSameValue(false, is_file($cacheStore->subjectPath(1)));
            assertSameValue(false, is_file($cacheStore->subjectPath(2)));
        } finally {
            removeTestDirectory($directory);
        }
    });

    $test('Cover quotas preserve referenced and current files', static function (): void {
        $directory = testDirectory();
        try {
            $cacheStore = new CacheStore($directory, static fn(): int => 10000);
            $service = coverService(config(array('ImageMode' => 'cache')), $cacheStore, null, 2, 20);
            $calendar = array(array('items' => array()));
            $paths = array();
            for ($id = 1; $id <= 3; $id++) {
                $source = 'https://example.com/' . $id . '.jpg';
                $descriptor = $service->describeSource($source);
                $paths[$id] = $cacheStore->directory('covers') . '/' . $id . '-' . $descriptor['version'] . '.jpg';
                file_put_contents($paths[$id], str_repeat((string)$id, 10));
                touch($paths[$id], 1000 + $id);
                if ($id === 1) {
                    $calendar[0]['items'][] = array('id' => 1, 'images' => array('large' => $source));
                }
            }

            $service->cleanup($calendar, $paths[3]);
            assertTrueValue(is_file($paths[1]));
            assertSameValue(false, is_file($paths[2]));
            assertTrueValue(is_file($paths[3]));
        } finally {
            removeTestDirectory($directory);
        }
    });

    $test('Subject cache hits do not consume rate tokens', static function (): void {
        $directory = testDirectory();
        try {
            $cacheStore = new CacheStore($directory, static fn(): int => 1000);
            $limiter = new RateLimiter($cacheStore);
            $pluginConfig = config(array('ImageMode' => 'direct'));
            $cover = coverService($pluginConfig, $cacheStore, $limiter);
            $service = new SubjectService(
                $pluginConfig,
                new FakeHttpTransport(array()),
                $cacheStore,
                $cover,
                $limiter
            );
            $cacheStore->write($cacheStore->statePath('rate-limit.php'), array(
                'version' => 1,
                'buckets' => array('subject' => array('tokens' => 0, 'updated_at' => 1000))
            ));
            $cacheStore->write($cacheStore->subjectPath(101), array(
                'time' => 1000,
                'subject_id' => 101,
                'data' => json_decode(fixture('subject-response.json'), true)
            ));

            assertSameValue(101, json_decode($service->update(101, 60), true)['id']);
            assertRateLimited(static fn() => $service->update(102, 60));
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
            assertTrueValue(is_file($cacheStore->dataPath('listening-music.php')));

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
            assertTrueValue(is_file($cacheStore->dataPath('listened-music.php')));
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
                $cover,
                new RateLimiter($cacheStore)
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
