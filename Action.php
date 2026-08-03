<?php

namespace TypechoPlugin\PandaBangumi;

use Widget\ActionInterface;
use Widget\Base\Contents;

use Utils\Helper;

/**
 * Action.php
 *
 * API 获取、更新数据，处理前端 AJAX 请求
 *
 * @author 熊猫小A
 */
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Action extends Contents implements ActionInterface
{
    private const COLLECTION_FIRST_PAGE_SIZE = 11;
    private const COLLECTION_PAGE_SIZE = 12;

    /**
     * 使用弱比较规则匹配 If-None-Match 中的实体标签
     */
    private static function matchesEntityTag(string $headerValue, string $etag): bool
    {
        foreach (explode(',', $headerValue) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*') {
                return true;
            }
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            if (hash_equals($etag, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function sendRateLimitResponse(RateLimitExceeded $error, bool $withJson): void
    {
        $retryAfter = (string)$error->retryAfter();
        $body = $withJson ? BangumiAPI::encodeJson(array(
            'error' => 'rate_limited',
            'retry_after' => $error->retryAfter()
        )) : '';
        if (defined('PANDABANGUMI_TESTING') && PANDABANGUMI_TESTING === true) {
            http_response_code(429);
            echo $body;
            return;
        }

        // Typecho 1.3 lacks a reason phrase for 429 but still sends the correct status.
        set_error_handler(static function (int $severity, string $message, string $file): bool {
            return $severity === E_WARNING
                && str_contains($message, 'Undefined array key 429')
                && basename($file) === 'Response.php';
        });
        $this->response->setStatus(429);
        $this->response->setHeader('Retry-After', $retryAfter);
        $this->response->setHeader('Content-Length', (string)strlen($body));
        $this->response->throwContent($body, $withJson ? 'application/json' : 'text/plain');
    }

    /**
     * 返回请求的 HTML
     * @access public
     */
    public function action(): void
    {
        $type = strtolower((string)($_GET['type'] ?? ''));
        $isCollection = BangumiAPI::isCollectionType($type);
        if (!$isCollection && !in_array($type, ['calendar', 'subject', 'cover'], true)) {
            header("Content-Type: application/json; charset=UTF-8");
            echo BangumiAPI::encodeJson(array());
            exit;
        }

        $options = Helper::options();
        $pluginOptions = $options->plugin('PandaBangumi');
        $ID = trim((string)($pluginOptions->ID ?? ''));
        $ValidTimeSpan = max(0, (int)($pluginOptions->ValidTimeSpan ?? 86400));
        $From = (int)($_GET['from'] ?? 0);

        if ($type === 'cover') {
            $subjectId = (int)($_GET['id'] ?? 0);
            $version = strtolower((string)($_GET['v'] ?? ''));
            $scope = strtolower((string)($_GET['scope'] ?? 'calendar'));
            try {
                if ($scope === 'collection') {
                    $cover = BangumiAPI::getCollectionCover(
                        $subjectId,
                        $version,
                        strtolower((string)($_GET['list'] ?? '')),
                        strtolower((string)($_GET['cate'] ?? ''))
                    );
                } elseif ($scope === 'subject') {
                    $cover = BangumiAPI::getSubjectCover($subjectId, $version);
                } elseif ($scope === 'calendar') {
                    $cover = BangumiAPI::getCalendarCover($subjectId, $version);
                } else {
                    $cover = array('status' => 404);
                }
            } catch (RateLimitExceeded $error) {
                $this->sendRateLimitResponse($error, false);
                return;
            }

            if (($cover['status'] ?? 404) === 404) {
                $this->response->setStatus(404);
                return;
            }
            $etag = '"pb-cover-' . $subjectId . '-' . $version . '"';
            $modifiedTime = @filemtime($cover['file']);

            // Logged-in Typecho sessions install no-cache headers before the route runs.
            header_remove('Cache-Control');
            header_remove('Expires');
            header_remove('Pragma');
            $this->response->setHeader('Cache-Control', 'public, max-age=31536000, immutable');
            $this->response->setHeader('Expires', gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
            $this->response->setHeader('ETag', $etag);
            if ($modifiedTime !== false) {
                $this->response->setHeader('Last-Modified', gmdate('D, d M Y H:i:s', $modifiedTime) . ' GMT');
            }

            $ifNoneMatch = trim((string)$this->request->getHeader('If-None-Match', ''));
            $notModified = $ifNoneMatch !== ''
                ? self::matchesEntityTag($ifNoneMatch, $etag)
                : false;
            if (!$notModified && $ifNoneMatch === '' && $modifiedTime !== false) {
                $ifModifiedSince = trim((string)$this->request->getHeader('If-Modified-Since', ''));
                $modifiedSince = $ifModifiedSince !== '' ? strtotime($ifModifiedSince) : false;
                $notModified = $modifiedSince !== false && $modifiedTime <= $modifiedSince;
            }

            if ($notModified) {
                $this->response->setStatus(304);
                return;
            }

            $this->response->setHeader('Content-Type', $cover['mime']);
            $this->response->setHeader('X-Content-Type-Options', 'nosniff');
            $this->response->throwFile($cover['file']);
            return;
        }

        header("Content-Type: application/json; charset=UTF-8");

        if ($type === 'subject') {
            try {
                echo BangumiAPI::updateSubjectCacheAndReturn((int)($_GET['id'] ?? 0), $ValidTimeSpan);
            } catch (RateLimitExceeded $error) {
                $this->sendRateLimitResponse($error, true);
            }
            return;
        }

        $pageSize = $From <= 0 ? self::COLLECTION_FIRST_PAGE_SIZE : self::COLLECTION_PAGE_SIZE;
        if ($isCollection)
            echo BangumiAPI::updateCollectionCacheAndReturn($ID, $pageSize, $From, $ValidTimeSpan);
        elseif ($type == 'calendar')
            echo BangumiAPI::updateCalendarCacheAndReturn($ID, $ValidTimeSpan);
    }
}
