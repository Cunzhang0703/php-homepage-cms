# 贡献指南

感谢你愿意改进这个项目。为保证改动可控、可回滚，请先阅读以下约定。

---

## 1. 环境准备

| 项 | 要求 |
| --- | --- |
| PHP | 7.2 及以上，需启用 `pdo`、`pdo_mysql`、`zip`、`json`、`mbstring` |
| 数据库 | MySQL 5.6+ / MariaDB 10.1+（SQLite 可作为回退） |
| Web 服务器 | Nginx 或 Apache |

本地开发建议使用 PHP 内置服务器，无需额外配置：

```bash
php -S 127.0.0.1:8080
```

然后访问 `http://127.0.0.1:8080/install/` 完成安装。

---

## 2. 分支与提交

- 从 `main` 拉出功能分支：`feat/xxx`、`fix/xxx`、`docs/xxx`
- 提交信息遵循 [Conventional Commits](https://www.conventionalcommits.org/zh-hans/)：

```text
feat(admin): 留言板增加批量审核
fix(update): 修正待更新包版本比较逻辑
docs(readme): 补充 Nginx 配置示例
security(api): 公开接口增加来源校验
refactor(includes): 抽取备份恢复公共函数
```

- 一个提交只做一件事，避免「功能 + 格式化」混在一起

---

## 3. 代码风格

完整规范见 [docs/code-style.md](docs/code-style.md)，核心要求：

- 编码 **UTF-8 无 BOM**；缩进 **4 空格**；文件以 LF 结尾
- 每个 PHP 文件顶部必须有统一 docblock（标题 + 一句话职责）
- 变量与函数使用 `snake_case`，类名使用 `PascalCase`，常量全大写
- **禁止在任何输出之前插入注释或空行**（会导致 `header()` 失效）
- 所有数据库操作必须走 PDO 预处理，禁止字符串拼接 SQL

---

## 4. 提交前自检

```bash
# 1. PHP 语法检查（任何一条报错都不要提交）
find . -name '*.php' -not -path './releases/*' -print0 | xargs -0 -n1 php -l

# 2. 确认没有把敏感文件加进来
git status --short | grep -E 'db\.php|data/|uploads/' && echo '!! 检查 .gitignore'
```

涉及后台、接口或更新的改动，请另外执行 [tests/README.md](tests/README.md) 中对应的验证项。

---

## 5. 不要改动的地方

以下路径与文件名**被代码硬编码引用**，改动会导致站点或云更新失效：

| 项 | 原因 |
| --- | --- |
| `admin/` `api/` `includes/` `install/` `update-admin/` `data/` `uploads/` | 相对路径引用，且与 `common.php` 的 `ROOT_DIR` 绑定 |
| `version.json` 的文件名与字段格式 | 云更新与安装向导读取 |
| `更新记录.txt` 的文件名与版本块格式 | 云更新发布端按文件名与 `【Vx.xx · 日期】` 正则提取更新说明 |

如需评审目录结构调整，请先提交 Issue 说明迁移方案。

---

## 6. 发布流程（维护者）

1. 更新 `更新记录.txt`，在**文件末尾**追加新版本块（顺序必须由旧到新）
2. 同步 `version.json` 的 `version` 与 `release_date`
3. 校验 `version.json` 的版本号与 `更新记录.txt` 头部「当前版本」一致
4. 打包源码 ZIP，上传至云更新发布端 `update-admin/`
5. 确认发布端已自动剔除 `includes/db.php`、`data/`、`uploads/`、`install/`
6. 在后台「云更新」中确认更新，并检查更新日志

---

## 7. 提交 Pull Request

- 描述「改了什么」与「为什么改」，附上复现步骤或截图
- 注明已执行的验证项
- 若改动影响部署方式或目录结构，请同步更新 `README.md` 与 `docs/`

安全问题请勿使用公开 PR 或 Issue，改走 [SECURITY.md](SECURITY.md) 的渠道。
