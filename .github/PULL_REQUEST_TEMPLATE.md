## 改动说明

<!-- 这个 PR 做了什么？为什么需要它？ -->

## 改动类型

- [ ] `feat` 新功能
- [ ] `fix` 缺陷修复
- [ ] `docs` 文档
- [ ] `refactor` 重构（不改变行为）
- [ ] `security` 安全加固
- [ ] `chore` 工程配置

## 影响范围

- [ ] 前台展示
- [ ] 后台管理
- [ ] 公开接口
- [ ] 安装向导
- [ ] 云更新
- [ ] 目录结构 / 文件命名（**若勾选，请说明迁移方案与兼容处理**）
- [ ] 仅文档与工程配置，不涉及运行时代码

## 验证情况

```
# 请粘贴已执行的检查结果
$ find . -name '*.php' -not -path './releases/*' -print0 | xargs -0 -n1 php -l
```

- [ ] PHP 语法检查全部通过
- [ ] 已按 `tests/README.md` 执行受影响模块的验证项
- [ ] 已确认未提交 `includes/db.php`、`data/`、`uploads/` 等敏感内容

## 自检

- [ ] 新增 PHP 文件已按 `docs/code-style.md` 补齐文件头 docblock
- [ ] 未改动 `admin/` `api/` `includes/` `install/` `update-admin/` 的目录层级与 `version.json`、`更新记录.txt` 的文件名
- [ ] 如涉及表结构变更，已递增 `SCHEMA_VER` 并同步更新 `docs/architecture.md`
- [ ] 如涉及新版本发布，`version.json` 与 `更新记录.txt` 头部版本号已保持一致

## 相关 Issue

<!-- 例如 Closes #12 -->
