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
    private const COLLECTION_PAGE_SIZE = 12;

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
            $cover = BangumiAPI::getCalendarCover(
                (int)($_GET['id'] ?? 0),
                strtolower((string)($_GET['v'] ?? ''))
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

            $this->response->setHeader('Content-Type', $cover['mime']);
            $this->response->setHeader('Cache-Control', 'public, max-age=31536000, immutable');
            $this->response->setHeader('X-Content-Type-Options', 'nosniff');
            $this->response->throwFile($cover['file']);
            return;
        }

        header("Content-Type: application/json; charset=UTF-8");

        if ($type === 'subject') {
            echo BangumiAPI::updateSubjectCacheAndReturn((int)($_GET['id'] ?? 0), $ValidTimeSpan);
            return;
        }

        if ($type == 'watching')
            echo BangumiAPI::updateWatchingCacheAndReturn($ID, self::COLLECTION_PAGE_SIZE + 1, $From, $ValidTimeSpan);
        elseif ($type == 'watched')
            echo BangumiAPI::updateWatchedCacheAndReturn($ID, self::COLLECTION_PAGE_SIZE + 1, $From, $ValidTimeSpan);
        elseif ($type == 'calendar')
            echo BangumiAPI::updateCalendarCacheAndReturn($ID, $ValidTimeSpan);
    }
}
