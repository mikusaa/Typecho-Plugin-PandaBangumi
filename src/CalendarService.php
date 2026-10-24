<?php

namespace TypechoPlugin\PandaBangumi;

final class CalendarService
{
    private const WATCHING_LIMIT = 120;
    // 缓存结构变化时递增，旧缓存只作为刷新失败时的兜底
    private const DATA_VARIANT = 'fields-v1';
    private const MIN_RATING_COUNT = 10;
    private const EMPTY_CACHE = array('time' => 1, 'data' => array());

    public function __construct(
        private PluginConfig $config,
        private HttpTransport $http,
        private CacheStore $cacheStore,
        private CoverService $coverService,
        private CollectionService $collectionService,
        private string $imageVariant
    ) {
    }

    private function encode(mixed $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        return $json === false ? '[]' : $json;
    }

    private function normalize(mixed $cache): array
    {
        if (!is_array($cache) || !isset($cache['data']) || !is_array($cache['data'])) {
            return self::EMPTY_CACHE;
        }
        return $cache;
    }

    private function fetchRaw(): array
    {
        $json = $this->http->get($this->config->buildApiUrl('/calendar'));
        if ($json === false || $json === 'null') {
            throw RefreshFailure::transient('PandaBangumi calendar request failed');
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw RefreshFailure::transient('PandaBangumi calendar response was invalid');
        }

        $calendar = array();
        foreach ($data as $day) {
            $items = array_map(function ($item): array {
                $id = (int)($item['id'] ?? 0);
                $rating = is_array($item['rating'] ?? null) ? $item['rating'] : array();
                $collection = is_array($item['collection'] ?? null) ? $item['collection'] : array();
                $airDate = (string)($item['air_date'] ?? '');
                return array(
                    'id' => $id,
                    'name' => (string)($item['name'] ?? ''),
                    'name_cn' => (string)($item['name_cn'] ?? ''),
                    'url' => $id > 0 ? 'https://bgm.tv/subject/' . $id : '',
                    'images' => $this->coverService->extractImages($item['images'] ?? array()),
                    // 评分人数过少时分数没有参考价值，不展示
                    'score' => (int)($rating['total'] ?? 0) >= self::MIN_RATING_COUNT
                        ? round((float)($rating['score'] ?? 0), 1)
                        : 0.0,
                    'doing' => max(0, (int)($collection['doing'] ?? 0)),
                    'air_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $airDate) === 1 ? $airDate : ''
                );
            }, $day['items'] ?? array());
            // 按在看人数从高到低排列，同热度保持接口原顺序
            usort($items, static fn(array $left, array $right): int => $right['doing'] <=> $left['doing']);
            $calendar[] = array(
                'id' => (int)($day['weekday']['id'] ?? 0),
                'date_en' => (string)($day['weekday']['en'] ?? ''),
                'date_cn' => (string)($day['weekday']['cn'] ?? ''),
                'items' => $items
            );
        }
        return $calendar;
    }

    private function calendarCache(int $validTimeSpan): array
    {
        $filePath = $this->cacheStore->dataPath('calendar.php');
        $isServable = function (array $cache): bool {
            return isset($cache['data'])
                && is_array($cache['data'])
                && ($cache['image_variant'] ?? '') === $this->imageVariant;
        };
        $isCompatible = static function (array $cache) use ($isServable): bool {
            return $isServable($cache) && ($cache['data_variant'] ?? '') === self::DATA_VARIANT;
        };

        $stored = $this->cacheStore->read($filePath);
        $cache = $this->cacheStore->usable($filePath, $validTimeSpan, $stored, $isCompatible);
        if ($cache !== null) {
            return $this->normalize($cache);
        }

        $lockHandle = $this->cacheStore->acquireRefreshLock($filePath);
        if ($lockHandle === false) {
            if ($isServable($stored)) {
                return $stored;
            }
            throw new RateLimitExceeded(1);
        }

        try {
            $stored = $this->cacheStore->read($filePath);
            $cache = $this->cacheStore->usable($filePath, $validTimeSpan, $stored, $isCompatible);
            if ($cache !== null) {
                return $this->normalize($cache);
            }

            try {
                $raw = $this->fetchRaw();
            } catch (RateLimitExceeded $error) {
                if ($isServable($stored)) {
                    return $this->normalize($this->cacheStore->deferRefresh(
                        $filePath,
                        $stored,
                        max(30, $error->retryAfter())
                    ));
                }
                throw $error;
            } catch (RefreshFailure $error) {
                if ($isServable($stored)) {
                    return $this->normalize($this->cacheStore->deferRefresh(
                        $filePath,
                        $stored,
                        $error->retryAfter()
                    ));
                }
                throw $error;
            }
            $cache = array(
                'time' => $this->cacheStore->now(),
                'image_variant' => $this->imageVariant,
                'data_variant' => self::DATA_VARIANT,
                'data' => $raw
            );
            if (!$this->cacheStore->write($filePath, $cache)) {
                $error = RefreshFailure::transient('PandaBangumi could not commit calendar cache');
                if ($isServable($stored)) {
                    return $this->normalize($this->cacheStore->deferRefresh(
                        $filePath,
                        $stored,
                        $error->retryAfter()
                    ));
                }
                throw $error;
            }
            return $cache;
        } finally {
            $this->cacheStore->releaseRefreshLock($lockHandle);
        }
    }

    public function update(
        string $userId,
        string $filter,
        int $validTimeSpan
    ): string {
        $cache = $this->normalize($this->calendarCache($validTimeSpan));
        $this->coverService->maybeRunMaintenance();
        if ($filter !== 'watching') {
            return $this->encode($this->coverService->prepareCalendar($cache['data']));
        }

        $progress = $this->collectionService->subjectProgress(
            $userId,
            'watching',
            'anime',
            self::WATCHING_LIMIT,
            $validTimeSpan
        );

        $calendar = array();
        foreach ($cache['data'] as $day) {
            $items = array();
            foreach ($day['items'] as $item) {
                $subjectId = (int)($item['id'] ?? 0);
                if (isset($progress[$subjectId])) {
                    // 附带收视进度，前端据此显示进度条
                    $items[] = array_merge($item, $progress[$subjectId]);
                }
            }
            $calendar[] = array(
                'id' => $day['id'],
                'date_en' => $day['date_en'],
                'date_cn' => $day['date_cn'],
                'items' => $items
            );
        }
        return $this->encode($this->coverService->prepareCalendar($calendar));
    }
}
