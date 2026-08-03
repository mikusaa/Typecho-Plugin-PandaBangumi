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
    use Utils\Helper;

    define('PandaBangumi_Plugin_VERSION', 'test');
    require dirname(__DIR__) . '/BangumiAPI.php';

    final class TestFailure extends RuntimeException
    {
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

    function callPrivate(string $method, mixed ...$arguments): mixed
    {
        static $methods = array();
        if (!isset($methods[$method])) {
            $methods[$method] = new ReflectionMethod(BangumiAPI::class, $method);
            $methods[$method]->setAccessible(true);
        }

        return $methods[$method]->invokeArgs(null, $arguments);
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

    $tests = array();
    $test = static function (string $name, callable $callback) use (&$tests): void {
        $tests[$name] = $callback;
    };

    $test('API base normalization', static function (): void {
        Helper::$pluginOptions = (object)array('ApiBase' => '');
        assertSameValue('https://api.bgm.tv', BangumiAPI::getApiBase());

        Helper::$pluginOptions = (object)array('ApiBase' => 'https://mirror.example.com/v0/path');
        assertSameValue('https://mirror.example.com', BangumiAPI::getApiBase());
        assertSameValue('https://mirror.example.com/calendar', BangumiAPI::buildApiUrl('/calendar'));

        Helper::$pluginOptions = (object)array('ApiBase' => 'http://mirror.example.com');
        assertSameValue('https://api.bgm.tv', BangumiAPI::getApiBase());
    });

    $test('JSON encoding fallback', static function (): void {
        assertSameValue('{"title":"test"}', BangumiAPI::encodeJson(array('title' => 'test')));
        $handle = fopen('php://memory', 'r');
        assertSameValue('[]', BangumiAPI::encodeJson($handle));
        fclose($handle);
    });

    $test('Request category and calendar filter normalization', static function (): void {
        $_GET = array('cate' => 'GAME', 'filter' => 'watching');
        assertSameValue('game', callPrivate('getCate'));
        assertSameValue('watching', callPrivate('getCalendarFilter'));

        $_GET = array('cate' => 'music', 'filter' => 'unexpected');
        assertSameValue('', callPrivate('getCate'));
        assertSameValue('all', callPrivate('getCalendarFilter'));
    });

    $test('Cover image selection uses large only', static function (): void {
        $images = callPrivate('extractCoverImages', array(
            'small' => 'https://example.com/s.jpg',
            'large' => 'https://example.com/l.jpg',
            'unknown' => 'https://example.com/x.jpg'
        ));
        assertSameValue(array(
            'small' => 'https://example.com/s.jpg',
            'large' => 'https://example.com/l.jpg'
        ), $images);
        assertSameValue('https://example.com/l.jpg', callPrivate('selectCoverUrl', $images));
        assertSameValue('', callPrivate('selectCoverUrl', array('medium' => 'https://example.com/m.jpg')));
    });

    $test('Cover source validation and versioning', static function (): void {
        $source = 'http://lain.bgm.tv/pic/cover/l/example.jpg';
        $cover = callPrivate('describeCoverSource', $source);
        assertSameValue($source, $cover['source_url']);
        assertSameValue('https://lain.bgm.tv/pic/cover/l/example.jpg', $cover['fetch_url']);
        assertSameValue(substr(hash('sha256', "large\n" . $source), 0, 16), $cover['version']);

        assertSameValue(null, callPrivate('describeCoverSource', 'https://user@example.com/image.jpg'));
        assertSameValue(null, callPrivate('describeCoverSource', "https://example.com/im\nage.jpg"));
        assertSameValue(null, callPrivate('describeCoverSource', 'file:///tmp/image.jpg'));
    });

    $test('Public IP validation rejects private and reserved ranges', static function (): void {
        assertTrueValue(callPrivate('isPublicIp', '1.1.1.1'));
        assertSameValue(false, callPrivate('isPublicIp', '127.0.0.1'));
        assertSameValue(false, callPrivate('isPublicIp', '192.168.1.1'));
        assertSameValue(false, callPrivate('isPublicIp', '::1'));
    });

    $test('Collection pagination contract', static function (): void {
        $fixture = json_decode((string)file_get_contents(__DIR__ . '/fixtures/collection-items.json'), true);
        $page = callPrivate('buildCollectionPage', $fixture, 2, 0, 'https://bgm.tv/anime/list/test/do');
        assertSameValue(array(101, 102), array_column($page['items'], 'id'));
        assertSameValue(2, $page['next_offset']);
        assertSameValue(true, $page['has_more']);

        $lastPage = callPrivate('buildCollectionPage', $fixture, 12, 2, 'more');
        assertSameValue(array(103), array_column($lastPage['items'], 'id'));
        assertSameValue(3, $lastPage['next_offset']);
        assertSameValue(false, $lastPage['has_more']);
    });

    $test('Collection more URL contract', static function (): void {
        assertSameValue(
            'https://bgm.tv/anime/list/test%20user/do',
            callPrivate('buildCollectionMoreUrl', 'test user', 'watching', 'anime')
        );
        assertSameValue(
            'https://bgm.tv/book/list/test/collect',
            callPrivate('buildCollectionMoreUrl', 'test', 'watched', 'book')
        );
        assertSameValue('', callPrivate('buildCollectionMoreUrl', 'test', 'watching', 'music'));
    });

    $test('Cover output honors direct and cache modes', static function (): void {
        $item = array(
            'id' => 101,
            'images' => array('large' => 'https://example.com/cover.jpg'),
            'img' => 'old',
            'cover_version' => 'old'
        );

        Helper::$pluginOptions = (object)array('ImageMode' => 'direct');
        $direct = callPrivate('prepareCoverItemForOutput', $item);
        assertSameValue('https://example.com/cover.jpg', $direct['img']);
        assertSameValue(false, array_key_exists('images', $direct));
        assertSameValue(false, array_key_exists('cover_version', $direct));

        Helper::$pluginOptions = (object)array('ImageMode' => 'cache');
        $cached = callPrivate('prepareCoverItemForOutput', $item);
        assertSameValue(false, array_key_exists('images', $cached));
        assertSameValue(false, array_key_exists('img', $cached));
        assertSameValue(
            substr(hash('sha256', "large\nhttps://example.com/cover.jpg"), 0, 16),
            $cached['cover_version']
        );
    });

    $test('Cache writes are readable and expire predictably', static function (): void {
        $directory = sys_get_temp_dir() . '/pandabangumi-tests-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $file = $directory . '/cache.json';

        try {
            $cache = array('time' => time(), 'data' => array('ok' => true));
            assertTrueValue(callPrivate('__writeCache', $file, $cache));
            assertSameValue($cache, callPrivate('readCacheFile', $file));
            assertSameValue($cache, callPrivate('__isCacheExpired', $file, 60));

            $expired = array('time' => time() - 120, 'data' => array());
            file_put_contents($file, BangumiAPI::encodeJson($expired));
            assertSameValue(1, callPrivate('__isCacheExpired', $file, 60));

            $deferred = callPrivate('deferCacheRefresh', $file, $expired);
            assertTrueValue((int)$deferred['retry_after'] > time());
            assertSameValue($deferred, callPrivate('readCacheFile', $file));

            $lock = callPrivate('acquireCacheRefreshLock', $file);
            assertTrueValue(is_resource($lock));
            callPrivate('releaseCacheRefreshLock', $lock);
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
