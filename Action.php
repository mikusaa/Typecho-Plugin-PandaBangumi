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
    /**
     * 返回请求的 HTML
     * @access public
     */
    public function action(): void
    {
        header("Content-Type: application/json; charset=UTF-8");

        $type = strtolower((string)($_GET['type'] ?? ''));
        if (!in_array($type, ['watching', 'watched', 'calendar'], true)) {
            echo BangumiAPI::encodeJson(array());
            exit;
        }

        $options = Helper::options();
        $pluginOptions = $options->plugin('PandaBangumi');
        $ID = trim((string)($pluginOptions->ID ?? ''));
        $PageSize = (int)($pluginOptions->PageSize ?? 6);
        $ValidTimeSpan = max(0, (int)($pluginOptions->ValidTimeSpan ?? 86400));
        $From = (int)($_GET['from'] ?? 0);

        if ($PageSize == -1) {
            $PageSize = 1000000;
        } else {
            $PageSize = max(1, min($PageSize, 100));
        }

        if ($type == 'watching')
            echo BangumiAPI::updateWatchingCacheAndReturn($ID, $PageSize, $From, $ValidTimeSpan);
        elseif ($type == 'watched')
            echo BangumiAPI::updateWatchedCacheAndReturn($ID, $PageSize, $From, $ValidTimeSpan);
        elseif ($type == 'calendar')
            echo BangumiAPI::updateCalendarCacheAndReturn($ID, $ValidTimeSpan);
    }
}
