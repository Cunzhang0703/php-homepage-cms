# 命名与注释规范

> 目标：让任何人在半年后打开任意一个文件，都能在 10 秒内知道它是干什么的。
> 本规范适用于新增代码；重构既有代码时按「改动到哪就规范到哪」的原则渐进推进。

---

## 1. 文件编码与格式

| 项 | 要求 |
| --- | --- |
| 编码 | **UTF-8 无 BOM**（BOM 会导致 `header()` 失效与页面顶部出现空白） |
| 换行 | LF（由 `.gitattributes` 统一，`更新记录.txt` 例外，保留 CRLF） |
| 缩进 | **4 个空格**，不使用 Tab（由 `.editorconfig` 约束） |
| 行宽 | 建议不超过 120 字符 |
| 文件结尾 | 保留一个空行 |

---

## 2. 文件头注释（强制）

每个 PHP 文件顶部必须紧跟 `<?php` 之后写统一格式的 docblock：

```php
<?php
/**
 * <模块名> · <子功能>
 * <一句话说明职责；若有引入顺序或配对要求，在此写明。>
 */
```

真实示例：

```php
<?php
/**
 * 后台公共头部（含登录拦截 + 侧边栏 + 顶栏）
 * 用法：业务页先 require '../includes/common.php'，再立刻 require 'head.php'（head 之前不得有任何输出）
 */
```

```php
<?php
/**
 * 云更新发布端 · 函数库
 * 生成 update.json、净化源码包（剔除 db.php / data / uploads / install 等），并从包内提取「更新记录.txt」作为更新说明。
 */
```

**写法要求**

- 第一行是「模块 · 子功能」，用 `·` 分隔，便于扫读
- 第二行说清「这个文件负责什么」，**不要复述文件名**（如「本文件是留言接口」属于废话）
- 有隐含约定时必须写出来（例如引入顺序、必须成对使用、依赖某个常量）

---

## 3. 命名规范

### 文件与目录

| 类型 | 规范 | 示例 |
| --- | --- | --- |
| 目录 | 全小写，单个单词 | `admin/`、`includes/`、`uploads/` |
| 普通 PHP 文件 | 全小写；多词用下划线 | `login.php`、`lib_update.php` |
| **入口文件** | 一律 `index.php` | `admin/index.php` |
| 类文件 | `PascalCase`，与类名一致 | `PdoHelper.php` → `class PdoHelper` |
| 文档 | 全小写，多词用短横线 | `deployment-security.md` |
| 例外 | `更新记录.txt` 保留中文名 | 被云更新模块硬编码引用，**不可改名** |

### 代码元素

| 类型 | 规范 | 示例 |
| --- | --- | --- |
| 函数 / 变量 | `camelCase` | `verifyAdminToken()`、`$pendingCount` |
| 类名 | `PascalCase` | `PdoHelper` |
| 常量 | 全大写 + 下划线 | `ROOT_DIR`、`SCHEMA_VER`、`UPDATE_JSON` |
| 数据库字段 | 全小写 + 下划线 | `created_at`、`password_hash` |
| 布尔变量 | 以 `is` / `has` / `can` 开头 | `$islogin`、`$hasPending` |

> 既有代码中大量使用 `camelCase` 函数（如 `getSiteVersion`）。**新增代码沿用同一风格即可，不要在同一文件内混用两种风格**。

---

## 4. 注释写法

**要写「为什么」，不要写「是什么」。** 代码本身能说明是什么。

```php
// ❌ 废话注释
$count++;   // count 加 1

// ✅ 说明意图与约束
$count++;   // 计入本次访问；同一 IP 在 60 秒内的重复请求已在 recordVisit() 中过滤
```

**必须写注释的场景**

1. 反直觉的实现（例如为什么先判断再赋值）
2. 兼容性处理（例如「兼容 site_source_V1.06/ 顶层目录布局」）
3. 安全相关判断（例如「不匹配哈希即中止，避免写入被篡改的包」）
4. 时序或顺序依赖（例如「必须在 session_start 之前」）

**函数注释**：当函数有副作用、会写文件、会发起网络请求或返回特殊值时，用块注释说明：

```php
/**
 * 从升级包中提取最新版本说明。
 * 注意：取的是「更新记录.txt」中位置最靠后的版本块，因此该文件必须按由旧到新追加。
 *
 * @param string $zipPath 升级包路径
 * @return string 提取到的说明；失败返回空字符串
 */
```

---

## 5. PHP 编码约定

### 输出顺序（最容易踩的坑）

`header()`、`setcookie()`、`session_start()` 之前**不能有任何输出**，包括：

- 文件开头的 BOM
- `<?php` 之前或 `?>` 之后的空白行
- 调试用的 `echo` / `var_dump`

因此：**纯 HTML 片段文件（如 `admin/foot.php`）新增 docblock 时，必须包在 `<?php ... ?>` 内，且 `?>` 后不要留空行。**

### 数据库

- 所有查询必须走 `PdoHelper`，使用参数绑定；**禁止把变量拼进 SQL 字符串**
- 表名不要手写前缀，直接写逻辑表名（`links`、`works`），前缀由 `PdoHelper` 处理
- 需要事务时成对使用 `beginTransaction()` / `commit()`，异常分支 `rollBack()`

### 安全

- 后台所有写操作前必须校验 CSRF：`verifyCsrfToken($_POST['csrf_token'] ?? '')`
- 输出到 HTML 的变量必须转义：`htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`
- 文件路径拼接前必须校验，禁止直接使用用户传入的路径
- 新增公开接口时，同步加上来源校验（`checkRefererHost()`）与频率限制

### 兼容性

- 代码需同时兼容 MySQL 与 SQLite，建表语句参考 `ensureSchema()` 中的 `$pk` / `$dt` / `$tail` 写法
- 最低支持 PHP 7.2，**不要使用 7.3+ 才有的语法**（如箭头函数、类型化属性）
- 不使用 Composer 依赖，新增功能请用标准库实现

---

## 6. 前端约定

- `index.html` 为单文件前台，样式内联；改动时注意浅色 / 深色两套主题都要可见
- 交互元素必须支持键盘操作（Tab 聚焦、Enter 选择、Esc 关闭），并保留可见的焦点样式
- 响应式断点至少覆盖 360px / 768px / 1440px
- 不为视觉效果引入外部 CDN 依赖，保持「零依赖」特性

---

## 7. 提交前自检

```bash
# 语法检查，任何一条报错都不要提交
find . -name '*.php' -not -path './releases/*' -print0 | xargs -0 -n1 php -l
```

再对照一遍：

- [ ] 每个新增 PHP 文件都有文件头 docblock
- [ ] 没有 BOM，`<?php` 之前无空行与空白
- [ ] 新增的写操作有 CSRF 校验
- [ ] 输出到页面的变量已转义
- [ ] 未引入 Composer 依赖或 PHP 7.3+ 语法
