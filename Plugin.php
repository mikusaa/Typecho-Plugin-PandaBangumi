<?php

namespace TypechoPlugin\PandaBangumi;

use Typecho\Plugin\PluginInterface;
use Typecho\Plugin\Exception as PluginException;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Widget\Options;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 给博客添加精美的番剧展示页吧！
 *
 *
 * @package PandaBangumi
 * @author 熊猫小A
 * @version 3.0.2.12
 * @link https://www.imalan.cn
 */

define('PandaBangumi_Plugin_VERSION', '3.0.2.12');

class Plugin implements PluginInterface
{
    private static bool $headerInjected = false;
    private static bool $footerInjected = false;

    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return void
     * @throws PluginException
     */
    public static function activate(): void
    {
        if (PHP_VERSION_ID < 80000) {
            throw new PluginException('启用失败，PandaBangumi 需运行在 PHP 8.0 或更高版本。');
        }

        // 检查是否存在对应扩展
        if (!extension_loaded('openssl')) {
            throw new PluginException('启用失败，PHP 需启用 OpenSSL 扩展。');
        }
        if (!extension_loaded('curl')) {
            throw new PluginException('启用失败，PHP 需启用 CURL 扩展。');
        }

        \Typecho\Plugin::factory('Widget_Archive')->header = __CLASS__ . '::header';
        \Typecho\Plugin::factory('Widget_Archive')->footer = __CLASS__ . '::footer';
        Helper::addRoute("route_PandaBangumi", "/PandaBangumi", "PandaBangumi_Action", 'action');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     */
    public static function deactivate(): void
    {
        Helper::removeRoute("route_PandaBangumi");
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Form $form 配置面板
     * @return void
     */
    public static function config(Form $form): void
    {
        echo '作者：<a href="https://www.imalan.cn">熊猫小A</a>，插件介绍页：<a href="https://blog.imalan.cn/archives/128/">熊猫追番 (PandaBangumi) for Typecho</a><br>';
        echo '<br><strong>使用方法，在文章要插入的地方写：</strong><br>';
        echo htmlspecialchars('在看动画：<div data-type="watching" data-cate="anime" class="bgm-collection"></div>');
        echo '<br>';
        echo htmlspecialchars('在看三次元：<div data-type="watching" data-cate="real" class="bgm-collection"></div>');
        echo '<br>';
        echo htmlspecialchars('在读书籍：<div data-type="watching" data-cate="book" class="bgm-collection"></div>');
        echo '<br>';
        echo htmlspecialchars('在玩游戏：<div data-type="watching" data-cate="game" class="bgm-collection"></div>');
        echo '<br>';
        echo htmlspecialchars('已看动画：<div data-type="watched" data-cate="anime" class="bgm-collection"></div>');
        echo '<br>';
        echo htmlspecialchars('已看三次元：<div data-type="watched" data-cate="real" class="bgm-collection"></div>');
        echo '<br>';
        echo htmlspecialchars('读过书籍：<div data-type="watched" data-cate="book" class="bgm-collection"></div>');
        echo '<br>';
        echo htmlspecialchars('玩过游戏：<div data-type="watched" data-cate="game" class="bgm-collection"></div>');
        echo '<br>';
        echo htmlspecialchars('追番日历：<div data-filter="watching" class="bgm-calendar"></div>');
        echo '<br>';
        echo htmlspecialchars('Bangumi 条目卡片：<div class="bgm-card" data-id="Subject ID"></div>');
        echo '<br>';

        $ID = new Text('ID', NULL, '', _t('用户 ID'), _t('填写 Bangumi 主页链接 /user/ 后面的用户名或数字 ID。'));
        $form->addInput($ID);

        $ApiBase = new Text('ApiBase', NULL, '', _t('Bangumi API 镜像'), _t('只填写等价于 https://api.bgm.tv 的 HTTPS 镜像域名，例如 https://example.com；不要带 /v0 或其他路径，路径会被自动忽略。留空则使用官方 API，HTTP 地址会被忽略。'));
        $form->addInput($ApiBase);

        $ImageMode = new Radio(
            'ImageMode',
            array(
                'direct' => _t('直接加载 API 返回的图片'),
                'cache' => _t('缓存到本站后加载')
            ),
            'direct',
            _t('封面加载方式'),
            _t('直接加载会原样使用 API 返回的图片地址；使用镜像时，建议由镜像代理图片并改写 JSON。本站缓存会隐藏图片来源，并应用于日历、收藏列表和单部条目卡片。')
        );
        $form->addInput($ImageMode);

        $ValidTimeSpan = new Text('ValidTimeSpan', NULL, '86400', _t('缓存过期时间'), _t('设置缓存过期时间，单位为秒，默认 24 小时。'));
        $form->addInput($ValidTimeSpan);

        $Limit = new Text('Limit', NULL, '30', _t('收藏列表数量上限'), _t('同时限制各分类的在看和已看列表，每类严格获取 0–300 条；设置过大可能增加 Bangumi API 请求频率。'));
        $form->addInput($Limit);
    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Form $form
     * @return void
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 输出头部css
     *
     * @access public
     * @return void
     * @throws PluginException
     */
    public static function header(): void
    {
        if (self::$headerInjected) {
            return;
        }
        self::$headerInjected = true;

        ob_start();
        Options::alloc()->index('/PandaBangumi');
        $bgmBase = ob_get_clean();

        echo '<link rel="stylesheet" href="';
        Options::alloc()->pluginUrl('/PandaBangumi/css/PandaBangumi.css');
        echo '?v=' . PandaBangumi_Plugin_VERSION . '" />';
        echo '<script>window.bgmBase=' . json_encode($bgmBase) . ';</script>';
    }

    /**
     * 在底部输出所需 JS
     *
     * @access public
     * @return void
     */
    public static function footer(): void
    {
        if (self::$footerInjected) {
            return;
        }
        self::$footerInjected = true;

        echo '<script type="text/javascript" src="';
        Options::alloc()->pluginUrl('/PandaBangumi/js/PandaBangumi.js');
        echo '?v=' . PandaBangumi_Plugin_VERSION . '"></script>';
    }
}
