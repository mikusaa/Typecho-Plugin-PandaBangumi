# Typecho-Plugin-PandaBangumi

为你的 Typecho 博客增加追番列表显示功能。

介绍：**[熊猫追番 (PandaBangumi) for Typecho 发布！ - 熊猫小A的博客](https://blog.imalan.cn/archives/128/)**

## 使用

插件版添加了**分页功能**，这样收藏很多时能节约流量，加快速度。收藏列表、追番日历和单部 Subject 条目卡片均按需插入对应的 HTML 标记，不需要在插件设置中单独开关。

使用方法：去 GitHub 上下载插件：[mikusaa/Typecho-Plugin-PandaBangumi](https://github.com/mikusaa/Typecho-Plugin-PandaBangumi)

解压后把文件夹改名为 `PandaBangumi` ，上传到服务器 `usr/plugins` 目录下，在 Typecho 后台启用本插件，填写 ID（即用户主页链接后的那串数字）。收藏列表首批展示最多 11 部，并在网格末尾保留一张操作卡；后续每批加载最多 12 部。缓存内容耗尽或达到配置上限后，操作卡会变为“在 Bangumi 查看更多”。

`收藏列表数量上限` 同时作用于各分类的在看和已看列表，每类可设置为 `0–300` 条。插件会严格按该值截断本站列表；达到上限后仍可通过操作卡前往对应的 Bangumi 收藏页。

如果服务器无法直接访问 Bangumi API，可以在插件设置的 `Bangumi API 镜像` 中填写等价于 `https://api.bgm.tv` 的 HTTPS 镜像域名，例如 `https://bgm-api.example.com`；留空则使用官方 API，HTTP 地址会被忽略。请只填写域名，不要带 `/v0` 或其他路径；如果误填了路径，插件会自动忽略路径。API 请求均由服务器发起；如果镜像返回的图片地址也使用该镜像域名，选择“直接加载”时访客仍会看到这个图片域名。

插件统一选择 API 响应中的 `images.large` 作为封面，不根据图片 URL 猜测或替换尺寸。日历、收藏列表和单部 Subject 条目卡片共用 `封面加载方式`：默认的“直接加载”会原样使用 API 返回的图片地址；如果 API 镜像使用自己的图片反代或独立 CDN，应由镜像改写响应 JSON 中的各尺寸图片地址，并保证返回的图片可以被访客浏览器访问。HTTPS 页面直接加载 HTTP 图片时，浏览器可能会按混合内容拦截。

选择“缓存到本站后加载”时，服务器会下载 API 返回的 `large` 封面并保存到 `插件目录/cache/covers/`，访客只请求本站地址，不会看到图片来源域名。缓存模式保留严格懒加载和长期浏览器缓存；下载失败时显示缺图，不会回退到外部图片。为避免服务端请求被滥用，缓存只接受公网 HTTPS 图片地址；Bangumi 官方日历中的 `http://lain.bgm.tv` 地址会仅在服务器下载时升级为 HTTPS。

在任何页面，不论是独立页还是一般的文章页面，在文章里插入代码：

在看动画

```html
<div data-type="watching" data-cate="anime" class="bgm-collection"></div>
```

在看三次元

```html
<div data-type="watching" data-cate="real" class="bgm-collection"></div>
```

在读书籍（Bangumi 的 `book` 分类同时包含小说和漫画）

```html
<div data-type="reading" data-cate="book" class="bgm-collection"></div>
```

在玩游戏

```html
<div data-type="playing" data-cate="game" class="bgm-collection"></div>
```

在听音乐（音乐收藏使用方形封面，进度单位为曲目）

```html
<div data-type="listening" data-cate="music" class="bgm-collection"></div>
```

已看动画

```html
<div data-type="watched" data-cate="anime" class="bgm-collection"></div>
```

三次元已看

```html
<div data-type="watched" data-cate="real" class="bgm-collection"></div>
```

读过书籍

```html
<div data-type="read" data-cate="book" class="bgm-collection"></div>
```

玩过游戏

```html
<div data-type="played" data-cate="game" class="bgm-collection"></div>
```

听过音乐

```html
<div data-type="listened" data-cate="music" class="bgm-collection"></div>
```

追番日历（去掉`data-filter="watching"`则显示所有番剧）
```html
<div data-filter="watching" class="bgm-calendar"></div>
```

Bangumi 单部 Subject 条目卡片（正式适配动画、书籍、游戏、三次元和音乐；音乐使用方形封面并展示创作者、曲目数和碟片数）
```html
<div class="bgm-card" data-id="Subject ID"></div>
```

保存发布，这个位置就会展开成追番展示面板。加载和分页都使用 AJAX 请求～

插件带了缓存功能，可以极大地提升速度，**但是记得要保证 `插件目录/cache/` 这个目录可写**。

JSON 缓存过期后会由单个请求负责刷新；如果 Bangumi API 暂时不可用，插件会继续使用已有缓存，并在短暂退避后重试，避免页面内容突然清空或并发重复请求上游。

## 注意事项

服务器需要 PHP 8.0 或更高版本，并启用 PHP curl openssl 扩展。

单部 Subject 条目卡片不依赖特定主题正文类，并提供 `--pb-subject-bg`、`--pb-subject-border`、`--pb-subject-text`、`--pb-subject-muted`、`--pb-subject-accent` CSS 变量供主题按需覆盖。

插件会把固定 JSON 数据、Bangumi 条目 JSON 和封面图片分别写入 `插件目录/cache/data/`、`cache/subjects/` 和 `cache/covers/`，请保证 `cache/` 及其子目录可写。缓存刷新时会自动删除已不再被日历、收藏列表或近期条目卡片引用且超过 90 天的封面。

插件会自动监听常见 PJAX 事件并重新初始化番剧展示。只有主题使用非标准 PJAX 事件、切换后仍未加载时，才需要在主题回调中手动调用 `window.PandaBangumi.init();`。

从仍使用旧版图片配置或 `json/` 缓存目录的版本跨版本升级时，请先卸载旧版插件，再安装新版并重新配置。

## 开发测试

PHP 测试不访问真实 Bangumi API，也不写入插件缓存目录：

```bash
php tests/run.php
```

单部 Subject 卡片的数据标准化测试使用 Node.js 内置模块，不需要安装依赖：

```bash
node tests/subject-card.js
```

Typecho 直接加载的入口文件保留在插件根目录；内部 PHP 模块统一放在 `src/`：

- `PluginConfig.php`：插件配置读取与规范化。
- `HttpClient.php`、`HttpTransport.php`：Bangumi JSON 请求及可替换传输接口。
- `CacheStore.php`：文件缓存、原子写入、刷新锁和失败退避。
- `CoverService.php`：封面校验、下载、缓存响应和清理。
- `CollectionService.php`、`CalendarService.php`、`SubjectService.php`：各类数据刷新与输出。
