# 上线安全配置

本项目已经在应用层限制了后台操作、升级包和公开接口；部署服务器仍必须完成以下配置，尤其是使用 Nginx 时（`.htaccess` 不会生效）。

## 必须完成

1. 全站启用 HTTPS，并把 HTTP 跳转到 HTTPS。
2. 将网站根目录设置为本项目目录，PHP 进程账号只授予必要的读写权限。
3. 禁止浏览器直接访问 `data/`、`includes/`、`install/` 和以点号开头的文件。
4. `uploads/` 只允许图片类型，禁止 PHP、脚本和 CGI 执行。
5. 安装完成后确认 `install/install.lock` 存在，并移除 Web 可访问的 `install/` 目录或在服务器层拒绝访问。
6. 如果 HTTPS 在 CDN 或反向代理终止，在 `includes/db.php` 中增加 `define('FORCE_HTTPS_COOKIES', true);`，确保后台 Cookie 始终带 `Secure` 属性。
7. 确认 `data/sessions/` 可由 PHP 运行用户写入；本项目会优先在此目录保存 PHP 会话与 CSRF 令牌。

## Nginx 示例

将下列规则加入站点的 `server` 块，再执行配置测试并重载 Nginx：

```nginx
location ~ ^/(?:data|includes|install)/ { deny all; }
location ~ /\. { deny all; }
location ~* ^/uploads/.*\.(?:php[0-9s]?|phtml|phar|cgi|pl|py|sh)$ { deny all; }
```

## 上线后验证

- 访问 `/data/`、`/includes/`、`/install/` 应返回 403 或 404。
- 后台登录响应中的 `admin_token` 应含 `HttpOnly`、`SameSite=Lax` 和（HTTPS 下）`Secure`。
- 在另一站点提交后台表单应失败；正常后台操作应成功。
- 用一个不含 SHA256 或超过限制的更新 ZIP 测试，系统应拒绝它；包含 `includes/db.php`、`data/`、`uploads/` 的普通源码包应被自动净化后暂存。
