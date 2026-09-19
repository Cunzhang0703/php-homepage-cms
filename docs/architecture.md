# 架构说明

> 面向需要改动源码的开发者。若只想部署或使用站点，请阅读 [安装说明](installation.md) 与 [用户手册](user-guide.md)。

---

## 1. 分层结构

项目不使用框架，按职责划分为四层，依赖方向自上而下，**不允许反向依赖**：

| 层 | 目录 | 职责 | 能否被浏览器直接访问 |
| --- | --- | --- | --- |
| 入口层 | `index.html`、`admin/`、`api/`、`install/`、`update-admin/` | 接收请求、渲染页面、返回 JSON | ✅ 可以 |
| 引导层 | `includes/common.php` | 定义路径常量、时区、安全响应头、建立会话与数据库连接 | ❌ 禁止 |
| 公共层 | `includes/functions.php`、`includes/lib/PdoHelper.php` | 业务工具函数、数据访问封装 | ❌ 禁止 |
| 数据层 | `includes/db.php`、`data/`、`uploads/` | 凭据配置、运行期数据、上传文件 | ❌ 禁止 |

---

## 2. 目录职责

```text
includes/common.php        统一引导，所有入口的第一行
includes/functions.php     核心函数库（约 1000 行），按功能分组
includes/lib/PdoHelper.php PDO 封装，唯一的数据访问出口
includes/db.php            数据库凭据（安装向导写入，不提交版本库）

admin/                     后台，每个 .php = 一个功能模块
  head.php / foot.php      公共头部与页脚，业务页必须成对引入
api/                       面向访客的公开 JSON 接口
install/                   安装向导，完成后应删除或禁用
update-admin/              云更新「发布端」，仅维护者使用
data/                      会话文件、SQLite 回退库
uploads/                   上传的图片
tests/README.md            上线前验证清单
docs/                      项目文档
```

---

## 3. 请求生命周期

以「后台某页面」为例：

```text
浏览器请求 /admin/works.php
   │
   ├─ 1. require '../includes/common.php'
   │      ├─ 定义 ROOT_DIR / DATA_DIR / DB_FILE
   │      ├─ 加载 functions.php → lib/PdoHelper.php → db.php   （顺序不可调整）
   │      ├─ 设置时区 Asia/Shanghai、error_reporting(0)
   │      ├─ 下发 nosniff / X-Frame-Options / Referrer-Policy / Permissions-Policy
   │      ├─ isInstalled() 判定是否已安装
   │      ├─ 建立 PDO 连接，读取 config 表填充 $conf
   │      ├─ verifyAdminToken() 解析会话令牌 → $islogin / $admin_user
   │      └─ ensureSchema() 按 SCHEMA_VER 建表或补列
   │
   ├─ 2. require 'head.php'      ← 校验登录；未登录则跳转 login.php
   │      输出侧边栏、顶栏、当前页高亮
   │
   ├─ 3. 业务逻辑
   │      所有数据库操作经常见 $DB->find / insert / update / delete
   │      所有写操作前校验 CSRF Token
   │
   └─ 4. require 'foot.php'      ← 输出页脚并闭合文档
```

### 引导阶段产生的全局对象

| 变量 | 含义 |
| --- | --- |
| `$DB` | `lib\PdoHelper` 实例；未安装时为 `null` |
| `$conf` | `config` 表键值对；用 `conf($key, $default)` 读取 |
| `$islogin` | `1` 表示已通过后台鉴权 |
| `$admin_user` | 当前登录的管理员账号名 |
| `$SYS_KEY` | 会话签名密钥，用于签发与校验后台令牌 |

> ⚠️ `common.php` 中 `error_reporting(0)` 会关闭错误输出。排查问题时，可临时改为
> `error_reporting(E_ALL); ini_set('display_errors', '1');`，**调试完务必改回**。

---

## 4. 数据模型

所有表名在代码中统一以 `pre_` 作为占位前缀书写，由 `PdoHelper` 按 `includes/db.php` 中的
`DB_QZ` 替换（默认 `DB_QZ` 为空，即最终表名不带前缀）。

| 表 | 字段要点 | 说明 |
| --- | --- | --- |
| `config` | `k`, `v` | 站点配置键值对，`schema_ver` 也存于此 |
| `admin` | `username`, `password_hash` | 管理员账号，密码经哈希存储 |
| `links` | `title`, `url`, `icon`, `note`, `sort`, `enabled` | 友情 / 社交链接 |
| `works` | `title`, `summary`, `cover`, `url`, `tags`, `sort`, `enabled` | 作品墙 |
| `guestbook` | `nickname`, `contact`, `content`, `reply`, `ua`, `status` | 留言板，`status` 区分待审核 |
| `updates` | 版本、说明、SHA256、`status` | 云更新待更新包记录 |

**表结构变更流程**：在 `functions.php` 的 `ensureSchema()` 中新增 `CREATE TABLE` 或补列逻辑，
并把 `SCHEMA_VER` 常量 +1。已安装的站点会在下次请求时自动平滑升级，无需人工执行 SQL。

---

## 5. 云更新数据流

```text
【发布端】update-admin/
  上传源码 ZIP
    └─ validateZipPackage()   校验大小、文件数、路径穿越、符号链接
    └─ sanitizeZipPackage()   剔除 db.php / data / uploads / install / .env
    └─ distillReleaseNotes()  从包内「更新记录.txt」提取**最后一个**版本块作为更新说明
    └─ 计算 SHA256 → 生成 update.json，状态 pending

【客户端】admin/update.php
  拉取 update.json
    └─ 展示 版本 / 日期 / 说明 / SHA256 / 文件名，弹出确认窗口
    └─ 管理员点击「确认更新」
         └─ downloadFile()     下载发布包
         └─ verifyFileHash()   校验 SHA256，不匹配即中止
         └─ backupSite()       自动创建备份
         └─ extractZipSafe()   安全解压并覆盖
         └─ setSiteVersion()   写入新版本号
              └─ 任一步失败 → 自动回滚到备份
```

> **关键约束**：`distillReleaseNotes()` 取的是 `更新记录.txt` 中**位置最靠后**的版本块。
> 因此该文件必须**按由旧到新的顺序**追加版本块，新版本永远写在文件末尾。

---

## 6. 不可改动的契约

以下内容被代码硬编码引用，改名或移动会直接破坏站点或云更新：

| 契约 | 引用位置 |
| --- | --- |
| `admin/` `api/` `includes/` `install/` `update-admin/` `data/` `uploads/` 的目录名与层级 | 各入口文件的相对 `require`，以及 `common.php` 的 `ROOT_DIR = dirname(__DIR__)` |
| `includes/db.php` 的路径 | `install/index.php` 的 `DB_CONFIG_FILE` |
| `version.json` 的文件名与 `version` / `schema_ver` 字段 | `getSiteVersion()`、`setSiteVersion()`、`lib_update.php` |
| `更新记录.txt` 的文件名 | `lib_update.php` 的 `distillReleaseNotes()` 按 basename 精确匹配 |
| `更新记录.txt` 中 `【Vx.xx · YYYY-MM-DD】` 的块格式 | `_distillNotesText()` 的正则 |
| `.htaccess` | 被更新包**排除**，不会随云更新下发，需手动同步 |

如需调整目录结构，请先确认所有 `require` 路径与上述常量同步修改，并在 [tests/README.md](../tests/README.md)
中补充对应的回归项。

---

## 7. 已知取舍

- **不使用 Composer / 命名空间自动加载**：换取「上传即运行」的部署体验，代价是 `functions.php` 较大且需手动 `require`
- **`error_reporting(0)`**：避免生产环境泄露路径与堆栈，代价是排查问题需临时开启
- **配置存于数据库 `config` 表**：便于后台在线修改，代价是首次连接前无法读取配置
- **`更新记录.txt` 用中文文件名**：历史包袱，因被云更新模块硬编码引用而保留
