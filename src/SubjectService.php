<?php

namespace TypechoPlugin\PandaBangumi;

final class SubjectService
{
    private const EMPTY_CACHE = array('time' => 1, 'data' => array());

    public function __construct(
        private PluginConfig $config,
        private HttpTransport $http,
        private CacheStore $cacheStore,
        private CoverService $coverService
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

    public function update(int $subjectId, int $validTimeSpan): string
    {
        if ($subjectId <= 0) {
            return $this->encode(array());
        }

        $filePath = $this->cacheStore->subjectPath($subjectId);
        $isCompatible = static function (array $cache) use ($subjectId): bool {
            return isset($cache['data'])
                && is_array($cache['data'])
                && (
                    (int)($cache['data']['id'] ?? 0) === $subjectId
                    || (
                        count($cache['data']) === 0
                        && (int)($cache['subject_id'] ?? 0) === $subjectId
                    )
                );
        };

        $stored = $this->cacheStore->read($filePath);
        $cache = $this->cacheStore->usable($filePath, $validTimeSpan, $stored, $isCompatible);
        if ($cache === null) {
            $lockHandle = $this->cacheStore->acquireShardLock('subject', (string)$subjectId);
            if ($lockHandle === false) {
                if ($isCompatible($stored)) {
                    $cache = $stored;
                } else {
                    throw new RateLimitExceeded(1);
                }
            } else {
                try {
                    $stored = $this->cacheStore->read($filePath);
                    $cache = $this->cacheStore->usable($filePath, $validTimeSpan, $stored, $isCompatible);
                    if ($cache === null) {
                        try {
                            $json = $this->http->get($this->config->buildApiUrl('/v0/subjects/' . $subjectId));
                        } catch (RateLimitExceeded $error) {
                            if ($isCompatible($stored)) {
                                $cache = $this->cacheStore->deferRefresh(
                                    $filePath,
                                    $stored,
                                    max(30, $error->retryAfter())
                                );
                            } else {
                                throw $error;
                            }
                            $json = false;
                        }
                        if ($cache === null) {
                            $data = $json !== false ? json_decode($json, true) : null;
                            if (is_array($data) && (int)($data['id'] ?? 0) === $subjectId) {
                                $cache = array(
                                    'time' => $this->cacheStore->now(),
                                    'subject_id' => $subjectId,
                                    'data' => $data
                                );
                                if ($this->cacheStore->write($filePath, $cache)) {
                                    $this->cacheStore->pruneSubjectCaches($subjectId);
                                }
                            } else {
                                $fallback = $isCompatible($stored)
                                    ? $stored
                                    : array('time' => 1, 'subject_id' => $subjectId, 'data' => array());
                                $cache = $this->cacheStore->deferRefresh($filePath, $fallback);
                            }
                        }
                    }
                } finally {
                    $this->cacheStore->releaseRefreshLock($lockHandle);
                }
            }
        }

        $cache = $this->normalize($cache);
        $data = $cache['data'];
        if (!is_array($data) || (int)($data['id'] ?? 0) !== $subjectId) {
            return $this->encode(array());
        }

        $this->coverService->maybeRunMaintenance();

        $images = $this->coverService->extractImages($data['images'] ?? array());
        $cover = $this->coverService->describeSource($this->coverService->selectUrl($images));
        if ($this->config->cacheImages()) {
            unset($data['images']);
            $data['cover_version'] = $cover['version'] ?? '';
        } else {
            $data['images'] = $images;
            unset($data['cover_version']);
        }
        return $this->encode($data);
    }
}
