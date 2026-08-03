<?php

namespace TypechoPlugin\PandaBangumi;

final class CalendarService
{
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

    private function fetchRaw(): ?array
    {
        $json = $this->http->get($this->config->buildApiUrl('/calendar'));
        if ($json === false || $json === 'null') {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        $calendar = array();
        foreach ($data as $day) {
            $items = array_map(function ($item): array {
                $id = (int)($item['id'] ?? 0);
                return array(
                    'id' => $id,
                    'name' => (string)($item['name'] ?? ''),
                    'name_cn' => (string)($item['name_cn'] ?? ''),
                    'url' => $id > 0 ? 'https://bgm.tv/subject/' . $id : '',
                    'images' => $this->coverService->extractImages($item['images'] ?? array())
                );
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

    private function calendarCache(int $validTimeSpan): array
    {
        $filePath = $this->cacheStore->dataPath('calendar.php');
        $isCompatible = function (array $cache): bool {
            return isset($cache['data'])
                && is_array($cache['data'])
                && ($cache['image_variant'] ?? '') === $this->imageVariant;
        };

        $stored = $this->cacheStore->read($filePath);
        $cache = $this->cacheStore->usable($filePath, $validTimeSpan, $stored, $isCompatible);
        if ($cache !== null) {
            return $this->normalize($cache);
        }

        $lockHandle = $this->cacheStore->acquireRefreshLock($filePath);
        if ($lockHandle === false) {
            if ($isCompatible($stored)) {
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

            $raw = $this->fetchRaw();
            if ($raw !== null) {
                $cache = array(
                    'time' => $this->cacheStore->now(),
                    'image_variant' => $this->imageVariant,
                    'data' => $raw
                );
                $this->cacheStore->write($filePath, $cache);
                return $cache;
            }

            $fallback = $isCompatible($stored)
                ? $stored
                : array('time' => 1, 'image_variant' => $this->imageVariant, 'data' => array());
            return $this->cacheStore->deferRefresh($filePath, $fallback);
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

        $watchingPage = json_decode(
            $this->collectionService->update($userId, 'watching', 'anime', 1000, 0, $validTimeSpan),
            true
        );
        if (!is_array($watchingPage) || !isset($watchingPage['items']) || !is_array($watchingPage['items'])) {
            return $this->encode(array());
        }
        $watchingIds = array_column($watchingPage['items'], 'id');

        $calendar = array();
        foreach ($cache['data'] as $day) {
            $items = array_filter($day['items'], static function ($item) use ($watchingIds): bool {
                return in_array($item['id'], $watchingIds);
            });
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
