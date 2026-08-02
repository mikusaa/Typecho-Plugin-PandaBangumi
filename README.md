# Typecho-Plugin-PandaBangumi

为你的 Typecho 博客增加追番列表显示功能。

介绍：**[熊猫追番 (PandaBangumi) for Typecho 发布！ - 熊猫小A的博客](https://blog.imalan.cn/archives/128/)**

## 使用

插件版添加了**分页功能**，这样追番很多时能节约流量，加快速度。追番列表与追番日历功能都可以自己选择要不要开启，在插件里设置就好。

使用方法：去 GitHub 上下载插件：[mikusaa/Typecho-Plugin-PandaBangumi](https://github.com/mikusaa/Typecho-Plugin-PandaBangumi)

解压后把文件夹改名为 `PandaBangumi` ，上传到服务器 `usr/plugins` 目录下，在 Typecho 后台启用本插件，填写 ID（即用户主页链接后的那串数字）。番剧列表每次展示 12 条，仅在还有更多记录时显示“加载更多”。

如果服务器无法直接访问 Bangumi API，可以在插件设置的 `Bangumi API 镜像` 中填写等价于 `https://api.bgm.tv` 的 HTTPS 镜像域名，例如 `https://bgm-api.example.com`；留空则使用官方 API，HTTP 地址会被忽略。请只填写域名，不要带 `/v0` 或其他路径；如果误填了路径，插件会自动忽略路径。API 请求均由服务器发起，镜像地址不会输出到访客页面。

日历封面会使用 Bangumi 的 `large` 图片并缓存在 `插件目录/cache/covers/`。如果服务器也无法访问 `lain.bgm.tv`，可以开启 `通过 API 镜像获取日历封面`；此时镜像还必须将 `/pic/*` 代理到 `https://lain.bgm.tv/pic/*`。该开关只改变服务器下载封面的来源，访客始终从本站加载日历封面。追番列表与番剧卡片仍使用 Bangumi 原始大图。

在任何页面，不论是独立页还是一般的文章页面，在文章里插入代码：

在看

```html
<div data-type="watching" class="bgm-collection"></div>
```

动漫已看

```html
<div data-type="watched" data-cate="anime" class="bgm-collection"></div>
```

三次元已看

```html
<div data-type="watched" data-cate="real" class="bgm-collection"></div>
```

追番日历（去掉`data-filter="watching"`则显示所有番剧）
```html
<div data-filter="watching" class="bgm-calendar"></div>
```

番剧卡片
```html
<div class="bgm-card" data-id="番剧id"></div>
```

保存发布，这个位置就会展开成追番展示面板。加载和分页都使用 AJAX 请求～

插件带了缓存功能，可以极大地提升速度，**但是记得要保证 `插件目录/cache/` 这个目录可写**。

## 注意事项

服务器需要 PHP 8.0 或更高版本，并启用 PHP curl openssl 扩展。

不一定所有主题都完美。

插件会把固定 JSON 数据、番剧卡片 JSON 和日历封面分别写入 `插件目录/cache/data/`、`cache/subjects/` 和 `cache/covers/`，请保证 `cache/` 及其子目录可写。升级后会自动迁移旧 `json/` 目录中的缓存；日历刷新时会自动删除已不再引用且超过 90 天的封面。

插件会自动监听常见 PJAX 事件并重新初始化番剧展示。只有主题使用非标准 PJAX 事件、切换后仍未加载时，才需要在主题回调中手动调用 `window.PandaBangumi.init();`；旧接口 `initCollection();` 仍然保留兼容。
