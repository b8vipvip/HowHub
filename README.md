# HowHub · 图文教程与攻略发布平台

## 文章详情极简阅读模式（v0.8.2）

仅文章详情页 `/guide/:slug` 去掉顶部导航、分类标签和面包屑，以及右侧「本页目录」和提示侧栏。正文集中显示文章标题、摘要、更新日期和完整富文本及图片，底部保留返回全部文章链接。首页与后台导航不变，不修改已发布文章的实际数据。


面向个人或小团队的轻量教程站：清爽的前台、图文教程阅读、分类搜索，以及可视化后台。首篇文章已录入 **《TCL雷鸟电视查看和绑定酷狗账号》**，并按语雀原始顺序保留五张截图引用。初始使用语雀 CDN 图片；可在服务器一键转存到本地。

技术栈：**PHP 8.2+ / SQLite / 原生 CSS 和 JavaScript**。不依赖 Node、npm、Docker、Composer 或第三方数据库。适合 Ubuntu + 宝塔面板的普通 PHP 网站。

## 安全更新、自动备份与失败恢复（v0.8.1）

**今后不要再直接运行 `git pull` 更新线上 HowHub。** 统一通过 `sudo bash bin/update.sh` 完成：串行更新锁 → 检查远端 main 是否可快进 → 临时维护状态 → 用 SQLite `VACUUM INTO` 生成包含已提交 WAL 内容的一致性数据库快照 → 备份整个 `storage/`（AI 密钥、附件、诊断日志、其他私有数据）和 `public/uploads/`（图片）→ SHA256 校验 → 代码快进 → PHP 语法、启动、SQLite 完整性检查。成功后继续保留最新备份，不恢复旧内容；**失败自动回滚到旧 Git 提交并恢复数据库、附件、图片和密钥**。失败期间产生的数据另存在备份文件夹 `failed-live-storage/` 和 `failed-live-uploads/`，避免静默销毁。

默认备份目录 `/root/howhub-backups/`（仅 root 可读写）；自动保留最近 **7 次成功更新**的备份，失败/回滚备份不会自动删除。需要保留更多可在调用前设置 `HOWHUB_BACKUP_KEEP=15`。如果磁盘可用空间不足，更新会在备份前停止。无新版本则不重复备份。无需额外数据库、Node 或 Docker。

### 首次启用自动更新器（当前服务器还没有脚本）

旧版本尚不包含更新脚本，为了让**第一次升级也先备份**，在服务器终端执行：

```bash
cd /www/wwwroot/howhub.k2n.cn/HowHub
git status --short
# 若有跟踪文件修改，先处理再继续
git fetch --no-tags origin refs/heads/main:refs/remotes/origin/main
git show origin/main:bin/update.sh > /tmp/howhub-update-once.sh
sudo env HOWHUB_ROOT="$PWD" bash /tmp/howhub-update-once.sh
rm -f /tmp/howhub-update-once.sh
```

这个引导流程先从已连接的 GitHub SSH 仓库取出最新更新器本身，**不会提前用 git pull 修改线上代码**。使用前确认远程仓库是 `b8vipvip/HowHub`。从此以后执行：

```bash
cd /www/wwwroot/howhub.k2n.cn/HowHub
sudo bash bin/update.sh
```

注意：首次从尚未支持维护标记的旧版本升级时，应避开正在上传素材、保存文章或进行 AI 长任务的时段；新版本会在 `storage/.maintenance` 存在时返回 HTTP 503，避免后续升级时产生新的写入。已在运行的旧请求无法由维护标记直接中断，因此仍应尽量选择空闲时段。

安全说明：自动恢复仅在脚本内的升级验证失败时触发；它不可能自动判断所有上线后才被发现的业务逻辑问题。对于被强制中止（例如断电、SIGKILL）且留有维护标记的情况，脚本会拒绝重复更新，须先检查备份和现场并由管理员处理。常规成功升级不能再恢复旧数据，否则会覆盖升级后新增的教程。

## 更新器权限保护（v0.8.3）

统一通过 `sudo bash /www/wwwroot/howhub.k2n.cn/HowHub/bin/update.sh` 更新。更新器仍用 `umask 077` 保护私有备份，但 Git 更新时会对**受版本管理的代码文件**恢复权限：普通文件 `644`、可执行文件 `755`、代码目录 `755`。SQLite、AI 密钥、`storage/`、`public/uploads/` 等数据路径不会统一改成公开权限。失败回滚后和没有新版本时也会修复代码权限，避免 PHP-FPM 拒绝读取站点文件。遇到 `Access denied.` 可检查入口脚本 `public/index.php` 是否错误变成 `600`；不要递归给 `storage` 执行 `chmod 755/644`。

## 简洁首页文章目录（v0.8.0）

去掉首页装饰插画、宣传区域、文章封面图片卡片和分页。首页直接列出全部已发布文章，按置顶与发布时间排序；保留搜索与分类筛选。文章详情与管理后台不改变。

## 通用网页内容 → AI HTML 源代码（v0.7.0）

在后台教程编辑器上方，可粘贴**任意来源**的网页 HTML（完整页面或片段）、博客/CMS/知识库内容、富文本、Markdown 或普通文字，不限于语雀或特定标签结构。点击 **「AI 生成 HTML 并填入下方编辑器」**，通过已配置的 chat2api 供应商流式生成 HTML，校验后自动切到当前文章的「HTML 源代码」编辑模式。

- **模型直接输出 HTML 源码**，不再要求模型生成 JSON。后台经过安全的 HTML/CSS 白名单过滤才应用。
- 图文来源中的图片按顺序标记；返回时强制验证图片数量与顺序，由服务端填回原图地址，不能让模型随意发明图片链接。
- 支持撤销这次填入，预览当前未保存结果，再自行保存。不会另建草稿、修改数据库或自动发布。
- 当前文章已有未保存正文时先征求替换确认；AI 生成过程中如果正文发生变化，拒绝覆盖。
- 带脚本的原始网页源码仅作为文本解析，脚本不会在后台执行；部分依赖 JavaScript 动态渲染的网站需要复制渲染后的正文。
- 已公开的图片外链可能存在防盗链，建议用素材库转存替换。

## 新增：素材管理与附件下载（v0.2）

- 后台 **「素材管理」**（`/admin/media`）集中查看图片和附件，支持上传（可多选）、重命名、复制素材链接和删除未被文章引用的文件。已引用的素材不能直接删除。
- 教程编辑器可点击正文中的现有图片，然后 **替换、删除、修改描述、在全宽／70%／40%之间切换、上移下移**。可打开「素材库」插入已上传的图片或下载附件，封面也可从素材库选择或移除。
- 附件支持 PDF、TXT、DOC/DOCX、XLS/XLSX、PPT/PPTX、ZIP，最多 20MB。**文档始终强制下载**，存放于非公开的 `storage/media_files/`，只有已发布教程引用或管理员登录状态才允许下载；上传文件永不在服务器执行。
- 图片支持 PNG/JPEG/WebP/GIF，最大 6MB；记录进入数据库并保存在 `public/uploads/`。旧版本已上传的本地图片会在首次打开素材管理时自动补录，不会迁移、重命名或删除已有文件。
- 点击图片进行修改之后必须再点击 **保存教程** 才会公开生效。文件重命名仅修改素材清单显示名（附件下载名），不改变已经引用的链接。
- 无法保证第三方语雀 CDN 链接一直有效。截图如出现破图，先尝试 [转存语雀配图脚本](#五转存首篇教程配图建议)，转存失败则应下载原图或使用原始截图并在编辑器中逐一替换。**图片源码不存放在 Git 仓库，网站更新前后都要备份 `storage/` 和 `public/uploads/`。**

## 功能

- 首页：简明无图的全部已发布文章目录，展示标题、简介、分类和日期；支持关键词搜索和分类筛选，不分页，草稿不可见。
- 详情页：完整富文本图文混排、自动章节目录、阅读量、移动端适配。
- 后台 `/admin/login`：用户名密码登录；只允许管理员写入，CSRF 防护，登录限速。
- 教程管理：新建、编辑、删除、草稿、正式发布、置顶、标题、摘要、SEO 友好的网址别名、分类、标签、封面。
- 富文本编辑：H2/H3 标题、加粗、斜体、列表、链接、图片上传、粘贴截图、远程图片地址、选中图片调整与素材库插入附件。
- 分类管理：增加、编辑、删除分类；删除分类不删除其文章。
- 素材管理：图片/附件上传、重命名、复制地址、引用校验、删除及旧版图片自动入库。
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

# 后续更新统一通过自动备份与失败回滚工具
cd /www/wwwroot/howhub.k2n.cn/HowHub
sudo bash bin/update.sh
```

**重要：** `storage/howhub.sqlite` 及 `public/uploads/` 在 `.gitignore` 中，不会被 Git 更新覆盖。上线后要在宝塔「计划任务」中每天备份这两个目录（包含 storage/media_files 附件），SQLite 数据备份建议用 `sqlite3 ... '.backup ...'` 或停写/快照方式，切勿在写入期间仅复制单一 `.sqlite` 而忽略 WAL 文件。避免给 `storage` 开启公开下载。

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
storage/media_files/ 下载附件（不提交 Git，不能通过 Nginx 静态访问）
app/media.php         素材登记、类型验证、引用保护
storage/             SQLite 数据库（不提交 Git）
.github/workflows/   GitHub Actions 语法/功能校验
```

## 安全说明与后续升级方向

本项目不预置弱密码。管理操作需要会话认证与 CSRF Token；富文本 HTML 在服务端经过白名单过滤；服务器独立存储数据库与上传图片。推荐配置 HTTPS、定期备份、定期升级 PHP。

当前为单管理员内容平台（CLI 可创建其他管理员），不含评论、付费、多人审核工作流和邮件订阅。可以后续加入 Markdown 导入/导出、语雀文档导入、文章版本历史、素材库、全文搜索、访问统计及多管理员权限。

## 从其他网页复制图文 / AI 整理网页代码

编辑教程 `https://howhub.k2n.cn/admin/guides/new` 或 `/admin/guides/edit?id=...`：

- **直接复制网页图文**：在其他网页选中内容复制，在「教程正文」里 Ctrl+V。浏览器发送的 `text/html` 由 HowHub 服务端清洗危险标签、事件属性和非法 URL，再插入编辑器，尽量保留段落、标题、列表、表格、外链图片及链接。单次 300KB 上限。截图剪贴板图片仍走原有图片上传接口。远程图片不会自动下载，来源网站的图片如果防盗链或失效，请使用素材库上传/替换。
- **粘贴网页源码交给 AI**：在正文上方「导入网页图文教程」区域粘贴原始 HTML 或语雀 `ne-viewer-body / ne-card` 代码，点击「AI 解析并创建文章草稿」。支持聊天复制时带 `\\<div>`、`https\\://` 的转义形式；会解析标题、步骤、文字及 `<img>` 配图，给每个原始图片地址分配固定 `[[IMAGE_N]]` 占位符。AI 只决定文章内容和占位符位置，后端将图片安全替换为原始地址，不允许模型制造未经来源确认的图片 URL。生成后自动在 SQLite 中创建**新的、未公开的草稿**并跳转到编辑页；不会覆盖当前在编辑的已发布文章。
- **AI 服务端配置**：登录后台 →「AI 供应商」→ 输入与 mv3c 一致的 chat2api OpenAI 兼容接口地址（如 `https://your-chat2api.example/v1`）、API Key、模型 ID → 保存 → 可点击「从供应商发现模型」调用 GET `/v1/models`。文章导入调用 POST `/v1/chat/completions`，使用非流式 Chat Completions，避免改动原 chat2api 或 mv3c 运行环境。外网地址需 HTTPS，内网/本机地址允许 HTTP。调用失败不创建草稿，不自动重试以避免重复扣费。

**注意：** HowHub 的供应商凭据不直接读取 mv3c 的数据库，也不会自动同步 mv3c 里的密钥；管理员需在 HowHub 后台独立配置相同的服务商。API Key 仅存于服务器 SQLite 中的 AES-256-GCM 密文，解密密钥位于项目非公开的 `storage/ai-secret.key`（`0600`），或可设置 `HOWHUB_AI_SECRET_KEY` 64 位十六进制环境变量。备份数据库时务必一并备份这个密钥文件，否则无法解密原有配置。不要把任何密钥提交到 GitHub。

运行环境额外需要 PHP `curl` 和 `openssl` 模块（除原有 `pdo_sqlite / mbstring / dom / fileinfo` 外），PHP-FPM 与 Nginx 的读写超时应按模型耗时合理设置（AI 调用最多等候 180 秒；可在宝塔站点 PHP-FPM / Nginx 配置中设为略高于 180 秒），否则浏览器可能在服务端完成前收到 504。代码和网站当前**不会运行定时/后台 AI 任务**。

AI 仅接收手动提交的网页源码/文本及图片占位符，不会抓取其他网站，也不会下载或识别原始图片内容。带验证码、支付信息、账号密码等敏感资料的网页内容需先移除这些信息后再提交 AI。

## HTML 源码编辑、实时预览与 AI 优化（v0.4）

登录后台并打开任意一篇文章的编辑页：

- **`</> HTML 源代码`**：在可视化与 HTML 源码模式之间切换。源码修改会参与保存；切换回可视化时调用 `/admin/import/clean` 安全清理 HTML，不会暗中保存。
- **预览当前内容**：安全过滤当前未保存的 HTML，在受限 `sandbox` iframe 中展示文章效果。关闭预览不修改文章、不发布。
- **✦ AI 优化**：将**当前编辑器里的正文**（包括未保存的修改）交给「AI 供应商」中配置的 chat2api 模型进行段落、措辞、颜色、提示样式及图片尺寸优化，结果直接写入 HTML 源码区。由管理员自行检查、预览、点击「保存教程」才会写入数据库。
- **撤销 AI 优化**：不保存的情况下可将本次结果恢复为调用前的内容；若已保存，可从备份恢复。
- 优化对原有图片采用服务器端占位符：原图地址、数量、顺序不得丢失或改变；原有超链接地址也必须完整保留。未通过校验的 AI 输出会被拒绝，不影响原文章。
- 仅允许安全的颜色、字体、对齐、图片宽度和专用提示框类名；`script`、事件属性、危险 CSS、外部样式表等会被过滤。**不是无限制 HTML/CSS 编辑器**。
- 优化请求使用后台 CSRF 与 10 分钟 6 次的会话限额；当前正文会发送给你配置的 AI 服务。**不会自动保存和公开发布。**

> 宝塔部署更新前务必备份 `storage/` 和 `public/uploads/`，并保留 `storage/ai-secret.key`；Git 更新只覆盖受版本控制的代码文件。编辑器资源若未刷新，可在浏览器按 `Ctrl+F5` 强制刷新。


## 运行诊断日志（v0.5.0）

后台导航点击 **诊断日志**：`/admin/diagnostics`。展示正在运行的 PHP 版本、HowHub 发布版本、Git commit（存在 `.git` 时）、SQLite/所需 PHP 扩展、数据与素材目录权限、剩余磁盘空间及 AI 供应商是否已配置。可按严重程度/模块筛选最近 200 条记录，并导出最多 2000 条脱敏 JSONL 日志。

记录点包括：登录成功/失败与限速、教程保存与删除、分类和网站设置、素材上传/改名/删除、AI 密钥配置、模型同步、AI 生成草稿、AI 优化、HTML 粘贴、浏览器编辑器/上传失败、PHP 警告和致命错误、HTTP 非成功请求及耗时。每条日志关联请求 ID 和运行版本。`X-HowHub-Request-ID` 响应头可用于定位请求。Web 端不会上传原始异常堆栈、网页正文、文件名或密钥；服务端会对异常消息再次脱敏。

- 路径：项目根目录 **`storage/diagnostics/`**（不位于公开的 `public/` 下）。请保持 PHP-FPM 用户 `www` 可写 `storage/`。
- 格式：结构化 JSONL；每份最多约 1 MB，自动保留当前文件加 4 个历史文件，总容量约 5 MB。不需要安装额外数据库或守护进程。
- 导出仅管理员登录可使用，不会公开给游客。**敏感值不会主动写入日志**，但仍请将导出报告视为内部资料。
- 如果遇到故障，请到「诊断日志」导出 JSONL 并连同请求 ID、发生时间发送给开发者；日志记录的是操作状态，不包含文章正文及图片二进制。
- 通过 `php tests/diagnostics.php` 可离线验证文件轮转和脱敏；GitHub Actions 自动运行此检查。
- 原有 SQLite 数据、图片、附件和密钥文件均不迁移、不重置。升级前仍建议备份 `storage/` 和 `public/uploads/`，并在宝塔启用常规数据备份。

## AI 真实流式进度（v0.6）

- 在「AI 导入网页教程」和「AI 优化」时显示实时进度面板、已等待秒数、接收字符数和 AI 增量输出（只显示纯文本，不执行模型生成的 HTML）。
- 从 chat2api 调用 `POST /v1/chat/completions` 且 `stream: true`，服务端通过 `text/event-stream` 边接收边传给编辑器（不再等待完整 AI 响应）。
- 支持一键取消；没有收到最终 `complete` 事件时**不**应用 AI 优化，**不**写入部分草稿。原有非流式兼容接口仍保留。
- 流式阶段包括连接中、等待首个输出、正在生成、校验结果、保存草稿（或安全应用至源码模式）；等待期间的动画不表示虚构的完成百分比。
- 首次部署请确认 PHP `curl`、`openssl` 可用。Nginx 会收到 `X-Accel-Buffering: no`；如果仍在最后一刻一次性展示，检查宝塔 Nginx/PHP-FPM 是否额外缓冲输出。
- 对长任务检查网站所用 Nginx `fastcgi_read_timeout` 与 PHP-FPM `request_terminate_timeout`：至少能覆盖所配置的上游 Worker 最大执行时长。HowHub 的 cURL 最长等待 660 秒。默认 PHP 进程会被请求占用，建议限制同时发起的 AI 请求。
- 诊断日志只记录流式调用结果、时长、状态码与输出长度，不保存用户提交的网页代码或模型回复。
