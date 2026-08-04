<?php

namespace TypechoPlugin\PandaBangumi;

use Typecho\Plugin\PluginInterface;
use Typecho\Plugin\Exception as PluginException;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Layout;
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
 * @version 3.0.10
 * @link https://www.himiku.com/archives/pandabangumi.html
 */

define('PandaBangumi_Plugin_VERSION', '3.0.10');

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

        require_once __DIR__ . '/BangumiAPI.php';
        try {
            $cacheInitialized = BangumiAPI::initializeCache();
        } catch (\Throwable $error) {
            $cacheInitialized = false;
        }
        if (!$cacheInitialized) {
            throw new PluginException('启用失败，PandaBangumi 缓存目录不可写或无法完成安全初始化。');
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
        $version = htmlspecialchars(PandaBangumi_Plugin_VERSION, ENT_QUOTES, 'UTF-8');
        $adminVersion = $version . '.3';
        $adminCss = htmlspecialchars(self::pluginAssetUrl('css/PandaBangumiAdmin.css'), ENT_QUOTES, 'UTF-8');
        $adminJs = htmlspecialchars(self::pluginAssetUrl('js/PandaBangumiAdmin.js'), ENT_QUOTES, 'UTF-8');

        echo '<link rel="stylesheet" href="' . $adminCss . '?v=' . $adminVersion . '">';
        echo '<script defer src="' . $adminJs . '?v=' . $adminVersion . '"></script>';
        echo '作者：<a href="https://www.imalan.cn">熊猫小A</a>，插件介绍页：<a href="https://www.himiku.com/archives/pandabangumi.html">PandaBangumi 插件介绍与使用说明</a><br>';

        $snippets = new Layout('details', ['class' => 'pb-settings-snippets']);
        $snippets->html(<<<'HTML'
<summary class="btn btn-xs">插入代码速查 <i class="i-caret-down" aria-hidden="true"></i></summary>
<div class="pb-snippets-body">
    <ul class="typecho-option">
        <li>
            <label class="typecho-label">收藏列表</label>
            <div class="pb-snippet-controls">
                <label>内容类型 <select id="pb-snippet-category"><option value="anime">动画</option><option value="real">三次元</option><option value="book">书籍</option><option value="game">游戏</option><option value="music">音乐</option></select></label>
                <label>收藏状态 <select id="pb-snippet-status"></select></label>
            </div>
            <div class="pb-snippet-output"><input id="pb-collection-code" class="text" type="text" readonly><button class="btn" type="button" data-copy-target="pb-collection-code">复制</button></div>
        </li>
    </ul>
    <ul class="typecho-option">
        <li>
            <label class="typecho-label">追番日历</label>
            <div class="pb-snippet-controls">
                <label>展示范围 <select id="pb-calendar-filter"><option value="watching">仅在看</option><option value="all">全部番剧</option></select></label>
            </div>
            <div class="pb-snippet-output"><input id="pb-calendar-code" class="text" type="text" readonly><button class="btn" type="button" data-copy-target="pb-calendar-code">复制</button></div>
        </li>
    </ul>
    <ul class="typecho-option">
        <li>
            <label class="typecho-label" for="pb-subject-id">Bangumi 条目卡片</label>
            <div class="pb-subject-controls"><input id="pb-subject-id" class="text" type="text" inputmode="numeric" placeholder="subject id"><div class="pb-snippet-output"><input id="pb-card-code" class="text" type="text" readonly><button class="btn" type="button" data-copy-target="pb-card-code">复制</button></div></div>
            <p class="description pb-copy-status" aria-live="polite"></p>
        </li>
    </ul>
</div>
HTML);
        $form->addItem($snippets);

        $ID = new Text('ID', null, '', _t('用户 ID'), _t('Bangumi 主页地址中 /user/ 后面的用户名或数字 ID。'));
        $ID->addRule('required', _t('请填写 Bangumi 用户 ID。'));
        $form->addInput($ID);

        $ApiBase = new Text('ApiBase', null, '', _t('Bangumi API 镜像'), _t('填写等价于 https://api.bgm.tv 的 HTTPS 地址，不要附加 /v0 等路径；留空使用官方 API。'));
        $ApiBase->addRule(
            static function ($value): bool {
                $value = trim((string)$value);
                if ($value === '') {
                    return true;
                }
                $parts = parse_url($value);
                return is_array($parts)
                    && strtolower((string)($parts['scheme'] ?? '')) === 'https'
                    && !empty($parts['host'])
                    && !isset($parts['user'])
                    && !isset($parts['pass'])
                    && !isset($parts['query'])
                    && !isset($parts['fragment'])
                    && (!isset($parts['path']) || $parts['path'] === '' || $parts['path'] === '/');
            },
            _t('请填写不带路径的 HTTPS API 镜像地址。')
        );
        $form->addInput($ApiBase);

        $ImageMode = new Radio(
            'ImageMode',
            array(
                'direct' => _t('直接加载 API 返回的图片'),
                'cache' => _t('缓存到本站后加载')
            ),
            'direct',
            _t('封面加载方式'),
            _t('访客浏览器直接请求 API 返回的图片地址。')
        );
        $form->addInput($ImageMode);

        $ValidTimeSpan = new Text('ValidTimeSpan', null, '86400', _t('数据刷新间隔'), _t('最低 300 秒。'));
        $ValidTimeSpan->addRule('required', _t('请填写数据刷新间隔。'));
        $ValidTimeSpan->addRule('isInteger', _t('数据刷新间隔必须是整数。'));
        $ValidTimeSpan->addRule(
            static fn($value): bool => (int)$value >= 300,
            _t('数据刷新间隔不能低于 300 秒。')
        );
        $form->addInput($ValidTimeSpan);

        $Limit = new Text('Limit', null, '30', _t('收藏列表数量上限'), _t('每类可展示 0–300 条；追番日历使用独立的在看集合，不受此项限制。'));
        $Limit->addRule('required', _t('请填写收藏列表数量上限。'));
        $Limit->addRule('isInteger', _t('收藏列表数量上限必须是整数。'));
        $Limit->addRule(
            static fn($value): bool => (int)$value >= 0 && (int)$value <= 300,
            _t('收藏列表数量上限必须在 0–300 之间。')
        );
        $form->addInput($Limit);

    }

    private static function pluginAssetUrl(string $path): string
    {
        ob_start();
        Options::alloc()->pluginUrl('/PandaBangumi/' . ltrim($path, '/'));
        return (string)ob_get_clean();
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
