<?php

namespace TypechoPlugin\PandaBangumi;

final class CollectionService
{
    public const MAX_FETCH_LIMIT = 300;

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

    private function fetchRaw(string $userId, int $status, int $subjectType, int $fetchLimit): array
    {
        if ($userId === '') {
            return array('data' => array(), 'complete' => true);
        }
        if ($fetchLimit <= 0) {
            return array('data' => array(), 'complete' => false);
        }

        $offset = 0;
        $collections = array();
        $complete = false;
        $pages = 0;
        $maxPages = (int)ceil($fetchLimit / self::FETCH_PAGE_SIZE);
        do {
            if ($pages >= $maxPages) {
                throw RefreshFailure::budgetExceeded();
            }
            $url = $this->config->buildApiUrl('/v0/users/' . rawurlencode($userId) . '/collections')
                . '?subject_type=' . $subjectType
                . '&type=' . $status
                . '&limit=' . self::FETCH_PAGE_SIZE
                . '&offset=' . $offset;
            $json = $this->http->get($url);
            if ($json === false || $json === 'null') {
                throw RefreshFailure::transient('PandaBangumi collection request failed');
            }

            $data = json_decode($json, true);
            if (!is_array($data)
                || !isset($data['total'], $data['limit'], $data['offset'], $data['data'])
                || !is_int($data['total'])
                || !is_int($data['limit'])
                || !is_int($data['offset'])
                || !is_array($data['data'])
                || $data['total'] < 0
                || $data['offset'] < 0
            ) {
                throw RefreshFailure::transient('PandaBangumi collection response was invalid');
            }

            $responseTotal = $data['total'];
            $pageOffset = $data['offset'];
            if ($pageOffset !== $offset) {
                throw RefreshFailure::transient('PandaBangumi collection response offset did not match');
            }
            $responseLimit = $data['limit'];
            $pageCount = count($data['data']);
            if ($responseLimit < 1
                || $responseLimit > self::FETCH_PAGE_SIZE
                || $pageCount > $responseLimit
                || ($pageCount < $responseLimit && $pageOffset + $pageCount < $responseTotal)
            ) {
                throw RefreshFailure::transient('PandaBangumi collection response pagination was invalid');
            }
            $processed = 0;
            foreach ($data['data'] as $item) {
                $processed++;
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
                if (count($collections) >= $fetchLimit) {
                    $complete = $pageOffset + $processed >= $responseTotal;
                    break;
                }
            }

            $pages++;
            if (count($collections) >= $fetchLimit) {
                break;
            }
            $offset = $pageOffset + $pageCount;
            if ($offset >= $responseTotal) {
                $complete = true;
            }
            if (count($data['data']) === 0 && !$complete) {
                throw RefreshFailure::transient('PandaBangumi collection response stopped before total');
            }

            if ($complete) {
                break;
            }
        } while (true);

        return array(
            'data' => array_slice($collections, 0, $fetchLimit),
            'complete' => $complete
        );
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
        int $requiredLimit,
        int $validTimeSpan
    ): array {
        if (!array_key_exists($category, $this->subjectTypes)) {
            return self::EMPTY_CACHE;
        }

        $filePath = $this->cacheStore->dataPath($this->cacheFileName($list, $category));
        $requiredLimit = max(0, min(self::MAX_FETCH_LIMIT, $requiredLimit));
        $userKey = hash('sha256', $userId);
        $isCompatible = function (array $cache) use ($userKey, $category): bool {
            return isset($cache['data'])
                && is_array($cache['data'])
                && ($cache['data_variant'] ?? '') === $this->cacheVariant
                && (string)($cache['user_key'] ?? '') === $userKey
                && (string)($cache['cate'] ?? '') === $category
                && array_key_exists('complete', $cache)
                && is_bool($cache['complete']);
        };
        $hasCoverage = static function (array $cache) use ($isCompatible, $requiredLimit): bool {
            return $isCompatible($cache)
                && ((bool)$cache['complete'] || count($cache['data']) >= $requiredLimit);
        };

        $stored = $this->cacheStore->read($filePath);
        if ($requiredLimit === 0) {
            return $isCompatible($stored) ? $stored : self::EMPTY_CACHE;
        }
        $cache = $this->cacheStore->usable($filePath, $validTimeSpan, $stored, $hasCoverage);
        if ($cache !== null) {
            return $this->normalize($cache);
        }

        $lockHandle = $this->cacheStore->acquireRefreshLock($filePath);
        if ($lockHandle === false) {
            if ($hasCoverage($stored)) {
                return $stored;
            }
            throw new RateLimitExceeded(1);
        }

        try {
            $stored = $this->cacheStore->read($filePath);
            $cache = $this->cacheStore->usable($filePath, $validTimeSpan, $stored, $hasCoverage);
            if ($cache !== null) {
                return $this->normalize($cache);
            }

            try {
                $result = $this->fetchRaw($userId, $status, $this->subjectTypes[$category], $requiredLimit);
            } catch (RateLimitExceeded $error) {
                if ($hasCoverage($stored)) {
                    return $this->normalize($this->cacheStore->deferRefresh(
                        $filePath,
                        $stored,
                        max(30, $error->retryAfter())
                    ));
                }
                throw $error;
            } catch (RefreshFailure $error) {
                if ($hasCoverage($stored)) {
                    return $this->normalize($this->cacheStore->deferRefresh(
                        $filePath,
                        $stored,
                        $error->retryAfter()
                    ));
                }
                throw $error;
            }
            $newCache = array(
                'time' => $this->cacheStore->now(),
                'data_variant' => $this->cacheVariant,
                'requested_limit' => $requiredLimit,
                'complete' => $result['complete'],
                'user_key' => $userKey,
                'cate' => $category,
                'data' => $result['data']
            );

            if (!$this->cacheStore->write($filePath, $newCache)) {
                $error = RefreshFailure::transient('PandaBangumi could not commit collection cache');
                if ($hasCoverage($stored)) {
                    return $this->normalize($this->cacheStore->deferRefresh(
                        $filePath,
                        $stored,
                        $error->retryAfter()
                    ));
                }
                throw $error;
            }
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
        $displayLimit = $this->config->int('Limit', 30, 0, 300);
        $cache = $this->categoryCache(
            $userId,
            $status,
            $type,
            $category,
            $displayLimit,
            $validTimeSpan
        );
        $this->coverService->maybeRunMaintenance();
        $page = $this->page(
            array_slice($cache['data'], 0, $displayLimit),
            $pageSize,
            $from,
            $this->moreUrl($userId, $type, $category)
        );
        return $this->encode($this->coverService->prepareCollectionPage($page));
    }

    public function subjectIds(
        string $userId,
        string $type,
        string $category,
        int $requiredLimit,
        int $validTimeSpan
    ): array {
        if (!array_key_exists($category, $this->subjectTypes)) {
            return array();
        }

        $status = ($this->listTypes[$category][1] ?? '') === $type ? 2 : 3;
        $cache = $this->categoryCache(
            $userId,
            $status,
            $type,
            $category,
            $requiredLimit,
            $validTimeSpan
        );
        $ids = array_map('intval', array_column($cache['data'], 'id'));
        return array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
    }
}
