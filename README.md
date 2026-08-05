# PandaBangumi

将 Bangumi 收藏、每日放送和条目卡片带到 Typecho。

[使用说明](https://www.himiku.com/archives/pandabangumi.html) | [更新日志](changelog.md) | [下载发布版](https://github.com/mikusaa/Typecho-Plugin-PandaBangumi/releases)

本项目由 AlanDecode 创建，基于 [Izumiko 维护的版本](https://github.com/Izumiko/PandaBangumi-Typecho-Plugin) 继续开发。

## 功能

- 展示动画、书籍、游戏、三次元和音乐的在看与已看收藏。
- 展示 Bangumi 每日放送，支持只保留自己正在看的动画。
- 在文章中插入动画、书籍、游戏、三次元或音乐条目卡片。
- 收藏列表、日历和条目卡片支持响应式布局，封面按需懒加载。
- 后台提供代码速查和一键复制，文章编辑器可通过 Subject ID 或 Bangumi 条目链接插入卡片。
- 支持官方 Bangumi API 或自定义 HTTPS API 镜像。
- 封面可由访客直接加载，也可缓存到本站后通过同源地址加载。
- 内置数据缓存、失败回退和 PJAX 生命周期处理，减少上游波动对页面的影响。

## 环境要求

- PHP 8.0 或更高版本。
- PHP curl 和 openssl 扩展。
- 插件目录可写，以便创建和维护 `.cache/`。

## 安装

1. 从 [Releases](https://github.com/mikusaa/Typecho-Plugin-PandaBangumi/releases) 下载发布包并解压。
2. 将插件文件夹重命名为 `PandaBangumi`。
3. 上传到 Typecho 的 `usr/plugins` 目录。
4. 在 Typecho 后台启用插件并填写 Bangumi 用户 ID。

启用后可在插件设置页生成收藏列表、追番日历和条目卡片代码。文章编辑器工具栏也会出现条目卡片按钮，可直接输入 Subject ID，或粘贴 `bangumi.tv`、`bgm.tv`、`chii.in` 的条目链接。

完整配置、插入方式和主题适配请阅读[使用说明](https://www.himiku.com/archives/pandabangumi.html)。

## 从旧版本升级

> PandaBangumi 4.0.0 不支持直接覆盖旧版本升级。

请先停用插件，删除服务器上整个 `usr/plugins/PandaBangumi` 文件夹，再上传并启用新的 `PandaBangumi` 文件夹。新版本不会读取、迁移或删除旧版 `cache/`、`json/` 及其中的数据，直接覆盖可能留下可公开访问的旧缓存文件。

## 缓存目录

插件只会在自身目录中创建和维护 `.cache/`。Apache 会使用随附的 `.htaccess` 拒绝访问；Nginx 和 Caddy 用户必须在站点配置中禁止通过 Web 访问 `usr/plugins/PandaBangumi/.cache/`。

具体配置示例和缓存规则请查看[使用说明](https://www.himiku.com/archives/pandabangumi.html)。

## License

本项目采用 [MIT](LICENSE) 许可证，版权归 AlanDecode、Izumiko 与 mikusaa 所有。
