<?php

namespace TypechoPlugin\PandaBangumi;

final class CollectionService
{
    private const FETCH_PAGE_SIZE = 30;
    private const EMPTY_CACHE = array('time' => 1, 'data' => array());

    public function __construct(
        private PluginConfig $config,
        private HttpTransport $http,
        private CacheStore $cacheStore,
        private CoverService $coverService,
        private array $subjectTypes,
        private array $listTypes,
        private string $cacheVariant
    ) {
    }

    private function encode(mixed $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        return $json === false ? '[]' : $json;
    }

    private function fetchRaw(string $userId, int $status, int $subjectType, int $userLimit): array
    {
        if ($userId === '' || $userLimit <= 0) {
            return array('data' => array(), 'complete' => true);
        }

        $offset = 0;
        $collections = array();
        $complete = true;
        do {
            $url = $this->config->buildApiUrl('/v0/users/' . rawurlencode($userId) . '/collections')
                . '?subject_type=' . $subjectType
                . '&type=' . $status
                . '&limit=' . self::FETCH_PAGE_SIZE
                . '&offset=' . $offset;
            $json = $this->http->get($url);
            if ($json === false || $json === 'null') {
                $complete = false;
                break;
            }

            $data = json_decode($json, true);
            if (!is_array($data) || !isset($data['total'], $data['data']) || !is_array($data['data'])) {
                $complete = false;
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
                    'images' => $this->coverService->extractImages($subject['images'] ?? array()),
                    'score' => (float)($subject['score'] ?? 0),
                    'id' => $subjectId,
                );
                if (count($collections) >= $userLimit) {
                    break 2;
                }
            }

            $responseLimit = max(1, (int)($data['limit'] ?? self::FETCH_PAGE_SIZE));
            $offset = max($offset + $responseLimit, (int)($data['offset'] ?? $offset) + $responseLimit);
            $hasMore = $offset < (int)$data['total'] && count($data['data']) > 0;
        } while ($hasMore);

        return array('data' => array_slice($collections, 0, $userLimit), 'complete' => $complete);
    }

    private function normalize(mixed $cache): array
    {
        if (!is_array($cache) || !isset($cache['data']) || !is_array($cache['data'])) {
            return self::EMPTY_CACHE;
        }
        return $cache;
    }

    private function cacheFileName(string $list, string $category): string
    {
        return $list . '-' . $category . '.php';
    }

    private function categoryCache(
        string $userId,
        int $status,
        string $list,
        string $category,
        int $validTimeSpan
    ): array {
        if (!array_key_exists($category, $this->subjectTypes)) {
            return self::EMPTY_CACHE;
        }

        $filePath = $this->cacheStore->dataPath($this->cacheFileName($list, $category));
        $userLimit = $this->config->int('Limit', 30, 0, 300);
        $userKey = hash('sha256', $userId);
        $isCompatible = function (array $cache) use ($userLimit, $userKey, $category): bool {
            return isset($cache['data'])
                && is_array($cache['data'])
                && ($cache['data_variant'] ?? '') === $this->cacheVariant
                && (int)($cache['limit'] ?? -1) === $userLimit
                && (string)($cache['user_key'] ?? '') === $userKey
                && (string)($cache['cate'] ?? '') === $category;
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

            try {
                $result = $this->fetchRaw($userId, $status, $this->subjectTypes[$category], $userLimit);
            } catch (RateLimitExceeded $error) {
                if ($isCompatible($stored)) {
                    return $this->normalize($this->cacheStore->deferRefresh(
                        $filePath,
                        $stored,
                        max(30, $error->retryAfter())
                    ));
                }
                throw $error;
            }
            $newCache = array(
                'time' => $this->cacheStore->now(),
                'data_variant' => $this->cacheVariant,
                'limit' => $userLimit,
                'user_key' => $userKey,
                'cate' => $category,
                'data' => $result['data']
            );

            if (!$result['complete']) {
                $fallback = $isCompatible($stored) ? $stored : array_merge($newCache, array('time' => 1));
                return $this->normalize($this->cacheStore->deferRefresh($filePath, $fallback));
            }

            $this->cacheStore->write($filePath, $newCache);
            return $newCache;
        } finally {
            $this->cacheStore->releaseRefreshLock($lockHandle);
        }
    }

    public function moreUrl(string $userId, string $type, string $category): string
    {
        if ($userId === '' || !array_key_exists($category, $this->subjectTypes)) {
            return '';
        }
        $status = ($this->listTypes[$category][1] ?? '') === $type ? 'collect' : 'do';
        return 'https://bgm.tv/' . $category . '/list/' . rawurlencode($userId) . '/' . $status;
    }

    public function page(array $data, int $pageSize, int $from, string $moreUrl): array
    {
        $from = max(0, $from);
        $pageSize = max(0, $pageSize);
        $items = array_slice($data, $from, $pageSize);
        $nextOffset = $from + count($items);

        return array(
            'items' => $items,
            'next_offset' => $nextOffset,
            'has_more' => $nextOffset < count($data),
            'more_url' => $moreUrl
        );
    }

    public function update(
        string $userId,
        string $type,
        string $category,
        int $pageSize,
        int $from,
        int $validTimeSpan
    ): string {
        $status = ($this->listTypes[$category][1] ?? '') === $type ? 2 : 3;
        $cache = $this->categoryCache($userId, $status, $type, $category, $validTimeSpan);
        $this->coverService->maybeRunMaintenance();
        $page = $this->page(
            $cache['data'],
            $pageSize,
            $from,
            $this->moreUrl($userId, $type, $category)
        );
        return $this->encode($this->coverService->prepareCollectionPage($page));
    }
}
