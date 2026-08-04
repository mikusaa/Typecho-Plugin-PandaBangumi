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
                        && in_array(
                            (string)($cache['outcome'] ?? ''),
                            array('not_found', 'refresh_failed'),
                            true
                        )
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
                        $failure = null;
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
                        } catch (RefreshFailure $error) {
                            if ($isCompatible($stored) && $error->upstreamStatus() !== 404) {
                                $cache = $this->cacheStore->deferRefresh(
                                    $filePath,
                                    $stored,
                                    $error->retryAfter()
                                );
                            } else {
                                $failure = $error->upstreamStatus() === 404
                                    ? RefreshFailure::notFound()
                                    : $error;
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
                                $failure ??= RefreshFailure::transient(
                                    'PandaBangumi Subject response was invalid'
                                );
                                if ($isCompatible($stored) && $failure->status() !== 404) {
                                    $cache = $this->cacheStore->deferRefresh(
                                        $filePath,
                                        $stored,
                                        $failure->retryAfter()
                                    );
                                } elseif ($failure->status() === 404) {
                                    $cache = array(
                                        'time' => $this->cacheStore->now(),
                                        'subject_id' => $subjectId,
                                        'outcome' => 'not_found',
                                        'data' => array()
                                    );
                                    $this->cacheStore->write($filePath, $cache);
                                } else {
                                    $cache = $this->cacheStore->deferRefresh($filePath, array(
                                        'time' => 1,
                                        'subject_id' => $subjectId,
                                        'outcome' => 'refresh_failed',
                                        'data' => array()
                                    ), $failure->retryAfter());
                                }
                                $this->cacheStore->pruneSubjectCaches($subjectId);
                            }
                        }
                    }
                } finally {
                    $this->cacheStore->releaseRefreshLock($lockHandle);
                }
            }
        }

        $cache = $this->normalize($cache);
        $outcome = (string)($cache['outcome'] ?? '');
        if ($outcome === 'not_found') {
            throw RefreshFailure::notFound();
        }
        if ($outcome === 'refresh_failed') {
            throw RefreshFailure::transient(
                'PandaBangumi Subject refresh is deferred',
                0,
                max(1, (int)($cache['retry_after'] ?? 0) - $this->cacheStore->now())
            );
        }
        $data = $cache['data'];
        if (!is_array($data) || (int)($data['id'] ?? 0) !== $subjectId) {
            throw RefreshFailure::transient('PandaBangumi Subject response was invalid');
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
