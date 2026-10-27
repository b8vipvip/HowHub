# HowHub · 图文教程与攻略发布平台

面向个人或小团队的轻量教程站：清爽的前台、图文教程阅读、分类搜索，以及可视化后台。首篇文章已录入 **《TCL雷鸟电视查看和绑定酷狗账号》**，并按语雀原始顺序保留五张截图引用。初始使用语雀 CDN 图片；可在服务器一键转存到本地。

技术栈：**PHP 8.2+ / SQLite / 原生 CSS 和 JavaScript**。不依赖 Node、npm、Docker、Composer 或第三方数据库。适合 Ubuntu + 宝塔面板的普通 PHP 网站。

## 功能

- 首页：响应式布局、教程卡片、分类导航、关键词搜索、分页。
- 详情页：完整富文本图文混排、自动章节目录、阅读量、移动端适配。
- 后台 `/admin/login`：用户名密码登录；只允许管理员写入，CSRF 防护，登录限速。
- 教程管理：新建、编辑、删除、草稿、正式发布、置顶、标题、摘要、SEO 友好的网址别名、分类、标签、封面。
- 富文本编辑：H2/H3 标题、加粗、斜体、列表、链接、图片上传、粘贴截图、远程图片地址。
- 分类管理：增加、编辑、删除分类；删除分类不删除其文章。
- 设置：网站名称与介绍、管理员修改密码。
- 图片安全：PNG/JPEG/WebP/GIF，限制 6 MB，服务器校验 MIME 和内容、随机文件名；上传后从本站 `/uploads` 提供。
- 站点运行：`/healthz` 返回状态 JSON，免 Docker，数据库保存在不对公网开放的 `storage/`。

## 宝塔面板正式部署（howhub.k2n.cn）

### 一、环境

1. 在宝塔安装 **Nginx** 和 **PHP 8.2 / 8.3 / 8.4**（推荐 8.3）。
2. 在 PHP 的扩展管理中开启 **PDO SQLite / SQLite3、mbstring、dom（XML）、fileinfo**。需要同时具备 `pdo_sqlite`、`mbstring`、`dom`、`fileinfo` 模块。某些宝塔版本 SQLite3 包会提供 `pdo_sqlite`，以 `php -m` 实际结果为准。
3. 确认宝塔已有站点 `howhub.k2n.cn`，并且 DNS 解析正确。HTTPS 证书在宝塔站点「SSL」里申请后开启强制 HTTPS。

### 二、克隆项目

在 Ubuntu SSH / 宝塔终端操作（不改动其他站点）：

```bash
cd /www/wwwroot/howhub.k2n.cn
git clone https://github.com/b8vipvip/HowHub.git HowHub

# 数据目录与图片目录必须允许 PHP-FPM 的 www 用户读写
sudo chown -R www:www /www/wwwroot/howhub.k2n.cn/HowHub/storage /www/wwwroot/howhub.k2n.cn/HowHub/public/uploads
sudo chmod 770 /www/wwwroot/howhub.k2n.cn/HowHub/storage
sudo chmod 775 /www/wwwroot/howhub.k2n.cn/HowHub/public/uploads
```

若你的站点文件夹不是上述路径，按宝塔真实路径调整；如宝塔 PHP 运行用户不是 `www`，请替换为实际运行用户。

### 三、配置网站运行目录与 URL 重写

1. 在宝塔 **网站 → howhub.k2n.cn → 网站目录 → 运行目录**，将其设置为：

   `/www/wwwroot/howhub.k2n.cn/HowHub/public`

   **不能指向 HowHub 项目根目录**，否则配置、数据库目录可能暴露。
2. PHP 版本选择已经安装扩展的 PHP 8.2+，不要选「纯静态」。
3. 宝塔 **伪静态** 规则使用：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
location ^~ /uploads/ {
    try_files $uri =404;
}
```

   保留宝塔原有的 PHP-FPM 转发配置，不要复制第二份 `location ~ \.php`。`/uploads/` 使用 `^~` 防止文件被 PHP-FPM 解析（PHP 上传接口仅允许真实图片）。
4. 保存配置并在宝塔「配置文件 → 检查配置」确认 Nginx 检查通过，随后重载。

### 四、创建初始管理员（没有默认密码）

宝塔 PHP-CLI 路径一般如下；实际路径根据 PHP 版本调整：

```bash
cd /www/wwwroot/howhub.k2n.cn/HowHub
PHP=/www/server/php/83/bin/php

# 先确认 CLI PHP 扩展齐全
$PHP -m | grep -Ei 'pdo_sqlite|mbstring|dom|fileinfo'

# 以网站运行用户执行，避免数据库文件被 root 独占
sudo -u www "$PHP" bin/create-admin.php admin
```

输入一个至少 **12 位**的管理员密码。创建后登录：

- 前台：`https://howhub.k2n.cn`
- 后台：`https://howhub.k2n.cn/admin/login`
- 管理员：`admin`（密码为刚刚创建的密码）

初始化无需运行 SQL 文件；数据库及内置首篇教程首次访问时自动创建。

### 五、转存首篇教程配图（建议）

语雀 CDN 图片初始使用原始外链。如果服务器能够访问这些 URL，运行下面的可选迁移脚本，把 5 张图转存本站，避免源图失效：

```bash
cd /www/wwwroot/howhub.k2n.cn/HowHub
sudo -u www /www/server/php/83/bin/php bin/import-yuque-images.php
```

脚本只会下载内置首篇教程的 **5 个固定 CDN URL**；逐个检查大小、PNG 文件头，成功的图片改成本地引用，失败的保持原链接，不会损坏文章。后续新教程通过后台直接上传图片，自动保存在本站。

### 六、检查与更新

```bash
curl -fsS https://howhub.k2n.cn/healthz
# 预期：{"ok":true,"app":"HowHub"}

# 后续更新（操作前先备份 storage 和 uploads）
cd /www/wwwroot/howhub.k2n.cn/HowHub
git pull --ff-only origin main
sudo chown -R www:www storage public/uploads
```

**重要：** `storage/howhub.sqlite` 及 `public/uploads/` 在 `.gitignore` 中，不会被 Git 更新覆盖。上线后要在宝塔「计划任务」中每天备份这两个目录，SQLite 数据备份建议用 `sqlite3 ... '.backup ...'` 或停写/快照方式，切勿在写入期间仅复制单一 `.sqlite` 而忽略 WAL 文件。避免给 `storage` 开启公开下载。

## 本地开发与检查

要求同样的 PHP 扩展：

```bash
php -S 127.0.0.1:8000 -t public public/router.php
# 浏览 http://127.0.0.1:8000
php bin/create-admin.php admin
```

所有 PHP 文件可用 `find . -name '*.php' -print0 | xargs -0 -n1 php -l` 做语法检查。CI 会执行语法检查以及基础数据/富文本安全检查。

## 项目结构

```text
app/                 SQLite 初始化、后台安全与模板
bin/                 CLI 管理员创建、语雀图片本地迁移
public/index.php     页面与后台路由
public/assets/       CSS、前台富文本编辑 JavaScript
public/uploads/      上传的图片（不提交 Git）
storage/             SQLite 数据库（不提交 Git）
.github/workflows/   GitHub Actions 语法/功能校验
```

## 安全说明与后续升级方向

本项目不预置弱密码。管理操作需要会话认证与 CSRF Token；富文本 HTML 在服务端经过白名单过滤；服务器独立存储数据库与上传图片。推荐配置 HTTPS、定期备份、定期升级 PHP。

当前为单管理员内容平台（CLI 可创建其他管理员），不含评论、付费、多人审核工作流和邮件订阅。可以后续加入 Markdown 导入/导出、语雀文档导入、文章版本历史、素材库、全文搜索、访问统计及多管理员权限。
