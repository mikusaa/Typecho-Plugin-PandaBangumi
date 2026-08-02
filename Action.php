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

    /**
     * 返回请求的 HTML
     * @access public
     */
    public function action(): void
    {
        $type = strtolower((string)($_GET['type'] ?? ''));
        if (!in_array($type, ['watching', 'watched', 'calendar', 'subject', 'cover'], true)) {
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
            $cover = BangumiAPI::getCalendarCover(
                $subjectId,
                $version
            );

            if (($cover['status'] ?? 404) === 404) {
                $this->response->setStatus(404);
                return;
            }
            if (isset($cover['redirect'])) {
                $this->response->setHeader('Cache-Control', 'no-store');
                $this->response->redirect($cover['redirect']);
                return;
            }

            $etag = '"pb-cover-' . $subjectId . '-' . $version . '"';
            $modifiedTime = @filemtime($cover['file']);
            $this->response->setHeader('Cache-Control', 'public, max-age=31536000, immutable');
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
            echo BangumiAPI::updateSubjectCacheAndReturn((int)($_GET['id'] ?? 0), $ValidTimeSpan);
            return;
        }

        $pageSize = $From <= 0 ? self::COLLECTION_FIRST_PAGE_SIZE : self::COLLECTION_PAGE_SIZE;
        if ($type == 'watching')
            echo BangumiAPI::updateWatchingCacheAndReturn($ID, $pageSize, $From, $ValidTimeSpan);
        elseif ($type == 'watched')
            echo BangumiAPI::updateWatchedCacheAndReturn($ID, $pageSize, $From, $ValidTimeSpan);
        elseif ($type == 'calendar')
            echo BangumiAPI::updateCalendarCacheAndReturn($ID, $ValidTimeSpan);
    }
}
