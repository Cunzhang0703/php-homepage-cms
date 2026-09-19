# 个人主页 · Personal Homepage

轻量级个人主页系统：**前台展示 + 后台管理 + 一键云更新**。
原生 PHP + PDO，零框架、零 Composer 依赖，下载即部署，适合宝塔面板等常见环境。

![PHP](https://img.shields.io/badge/PHP-%E2%89%A57.2-777BB4?logo=php&logoColor=white)
![Database](https://img.shields.io/badge/DB-MySQL%20%7C%20SQLite-4479A1?logo=mysql&logoColor=white)
![Dependencies](https://img.shields.io/badge/dependencies-0-brightgreen)
![License](https://img.shields.io/badge/license-MIT-blue)

---

## 目录

- [功能特性](#功能特性)
- [环境要求](#环境要求)
- [快速开始](#快速开始)
- [目录结构](#目录结构)
- [安全注意事项](#安全注意事项)
- [日常维护](#日常维护)
- [开发与贡献](#开发与贡献)
- [文档](#文档)
- [更新记录](#更新记录)
- [许可证](#许可证)

---

## 功能特性

**前台**

- 单页个人主页，响应式布局，支持浅色 / 深色主题
- 作品墙展示，图片懒加载
- 访客留言板（提交后进入待审核队列）
- 友情链接 / 社交链接区
- 完整的键盘可达性（Tab 聚焦、方向键导航、Esc 关闭菜单）

**后台**

| 模块 | 说明 |
| --- | --- |
| 仪表盘 | 站点概况与访问统计 |
| 站点配置 | 标题、介绍、联系方式、主题色 |
| 作品墙 | 作品增删改与排序 |
| 留言板 | 审核、回复、删除访客留言 |
| 链接管理 | 友情链接与社交链接维护 |
| 备份恢复 | 一键导出代码备份，异常时回滚 |
| 云更新 | 检查 → 确认 → 校验 SHA256 → 备份 → 安全解压，失败自动回滚 |
| 修改密码 | 更换管理员密码，并使其会话失效 |

**云更新机制（本项目的核心设计）**

更新包不在上传后立即生效，而是先**暂存为待更新**，由管理员在后台手动确认后才执行：

```
发布端上传源码 ZIP
   └─ 自动净化：剔除 includes/db.php、data/、uploads/、install/、.env
   └─ 计算 SHA256，生成 update.json，标记为 pending
后台「云更新」
   └─ 拉取 update.json → 展示版本 / 日期 / 说明 / SHA256 / 文件名
   └─ 管理员点击「确认更新」
        └─ 下载 → 校验 SHA256 → 自动备份 → 安全解压 → 写版本号
             └─ 任一步失败 → 自动回滚
```

**安全加固**

- 后台 CSRF Token、防暴力登录、签名会话令牌
- Cookie 具备 `HttpOnly` / `SameSite=Lax`，HTTPS 下自动 `Secure`
- 账号与密码**区分大小写**
- 升级包强制 64 位 SHA256 校验，拒绝 `../` 与符号链接
- 公开接口限流（重复提交返回 429，非法来源返回 403）
- `data/`、`includes/`、`install/` 目录访问保护（Apache `.htaccess` + Nginx 示例）

---

## 环境要求

| 项 | 最低要求 | 说明 |
| --- | --- | --- |
| PHP | **7.2** | 需要 `pdo`、`pdo_mysql`、`zip`、`json`、`mbstring` 扩展 |
| 数据库 | MySQL 5.6+ / MariaDB 10.1+ | 亦支持 SQLite 作为回退 |
| Web 服务器 | Nginx / Apache | 宝塔面板任一环境均可 |
| 磁盘 | 约 5 MB | 不含上传的图片 |

> **零依赖**：项目不使用 Composer，也不依赖任何第三方 PHP 库，直接上传即可运行。

---

## 快速开始

### 方式一：宝塔面板（推荐新手）

详细图文步骤见 **[docs/installation.md](docs/installation.md)**，流程概要：

1. 在宝塔面板创建站点与数据库（记录数据库名、用户名、密码）
2. 将源码包解压到站点根目录
3. 设置运行目录为站点根目录，PHP 版本选择 7.2 以上
4. 浏览器访问 `https://你的域名/install/`
5. 按向导填写数据库信息与管理员账号
6. 完成后**删除或禁用 `install/` 目录**

### 方式二：命令行

```bash
# 1. 获取源码并放入 Web 根目录
unzip site_source_V1.25.zip -d /www/wwwroot/your-domain

# 2. 确保可写目录存在且权限正确
chmod -R 755 /www/wwwroot/your-domain
chmod -R 775 /www/wwwroot/your-domain/data /www/wwwroot/your-domain/uploads

# 3. 浏览器访问 /install/ 完成向导
```

安装向导会自动写入 `includes/db.php` 并生成 `install/install.lock`。

---

## 目录结构

```text
.
├── index.html                  # 前台单页
├── 404.html                    # 404 页面
├── version.json                # 版本清单（云更新读取，勿手改格式）
├── 更新记录.txt                 # 版本记录（云更新读取文件名，勿重命名）
├── .htaccess                   # Apache 目录保护
├── nginx.conf.sample           # Nginx 目录保护示例
├── .gitignore / .editorconfig  # 仓库工程配置
│
├── admin/                      # 后台管理（每页 = 一个功能模块）
│   ├── head.php / foot.php     # 公共头部 / 页脚，必须成对引入
│   ├── login.php / logout.php  # 登录与退出
│   ├── index.php               # 仪表盘
│   ├── site.php                # 站点配置
│   ├── works.php               # 作品墙
│   ├── guestbook.php           # 留言板
│   ├── links.php               # 链接管理
│   ├── backup.php              # 备份恢复
│   ├── update.php              # 云更新（客户端）
│   └── profile.php             # 修改密码
│
├── api/                        # 面向访客的公开接口
│   ├── site.php                # 站点公开数据
│   ├── guestbook.php           # 留言提交
│   └── visit.php               # 访问统计
│
├── includes/                   # 后端公共层
│   ├── common.php              # 统一引导：路径常量 + 加载顺序
│   ├── db.php                  # 数据库配置（安装向导写入，勿提交到仓库）
│   ├── functions.php           # 核心函数库
│   └── lib/PdoHelper.php       # PDO 封装（MySQL / SQLite）
│
├── install/index.php           # 安装向导
│
├── update-admin/               # 云更新「发布端」（仅维护者使用）
│   ├── index.php               # 发布控制台
│   ├── upload.php              # 上传与净化处理
│   ├── lib_update.php          # 生成 update.json / 提取更新说明
│   └── api/check.php           # 版本检查接口
│
├── tests/README.md             # 上线前验证清单
├── docs/                       # 项目文档
├── data/                       # 运行期数据（会话、SQLite，勿提交）
└── uploads/                    # 上传文件（勿提交）
```

> **重要**：`admin/`、`api/`、`includes/`、`install/`、`update-admin/`、`data/`、`uploads/` 的路径与 `version.json`、`更新记录.txt` 的文件名**被代码硬编码引用**。移动这些文件或改名会直接导致站点与云更新功能失效。详见 [docs/architecture.md](docs/architecture.md)。

---

## 安全注意事项

上线前**必须**完成以下配置（完整清单见 [docs/deployment-security.md](docs/deployment-security.md)）：

1. 全站启用 HTTPS，并将 HTTP 跳转到 HTTPS
2. 禁止浏览器直接访问 `data/`、`includes/`、`install/` 及以点号开头的文件
3. `uploads/` 只允许图片，禁止任何脚本执行
4. 安装完成后确认 `install/install.lock` 存在，并移除或禁用 `install/` 目录
5. **切勿将 `includes/db.php`、`data/`、`uploads/` 提交到版本库**（已由 `.gitignore` 默认排除）
6. HTTPS 在 CDN 或反向代理终止时，在 `includes/db.php` 中增加 `define('FORCE_HTTPS_COOKIES', true);`

### Nginx 示例

```nginx
location ~ ^/(?:data|includes|install)/ { deny all; }
location ~ /\. { deny all; }
location ~* ^/uploads/.*\.(?:php[0-9s]?|phtml|phar|cgi|pl|py|sh)$ { deny all; }
```

完整示例见仓库根目录 `nginx.conf.sample`。

### 漏洞上报

请勿通过公开 Issue 上报安全问题，参见 [SECURITY.md](SECURITY.md)。

---

## 日常维护

- 站点使用说明见 **[docs/user-guide.md](docs/user-guide.md)**
- 每次大改动前，先在后台「备份恢复」创建一次备份
- 发布新版本后，运行 **[tests/README.md](tests/README.md)** 中的验证清单
- 更新日志：[更新记录.txt](更新记录.txt)（随发布包分发）与 [CHANGELOG.md](CHANGELOG.md)

---

## 开发与贡献

- 代码风格与命名规范见 [docs/code-style.md](docs/code-style.md)
- 提交前请先执行 PHP 语法检查：

```bash
find . -name '*.php' -not -path './releases/*' -print0 | xargs -0 -n1 php -l
```

- 提交信息建议遵循 [Conventional Commits](https://www.conventionalcommits.org/zh-hans/)：`feat:` / `fix:` / `docs:` / `refactor:` / `security:`
- 详细流程见 [CONTRIBUTING.md](CONTRIBUTING.md)

---

## 文档

| 文档 | 面向 |
| --- | --- |
| [docs/installation.md](docs/installation.md) | 首次部署（宝塔面板图文步骤） |
| [docs/user-guide.md](docs/user-guide.md) | 站点管理员的日常操作 |
| [docs/deployment-security.md](docs/deployment-security.md) | 上线安全配置清单 |
| [docs/architecture.md](docs/architecture.md) | 模块划分与数据流 |
| [docs/code-style.md](docs/code-style.md) | 命名与注释规范 |
| [tests/README.md](tests/README.md) | 上线前验证清单 |

---

## 更新记录

- 面向使用者的版本记录：[更新记录.txt](更新记录.txt) —— **文件名不可更改**，云更新模块依赖它提取更新说明
- 面向开发者的变更日志：[CHANGELOG.md](CHANGELOG.md)

当前版本 **V1.25**。

---

## 许可证

[MIT License](LICENSE) © 2026 CunZhang
