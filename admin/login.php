<?php
/**
 * 后台 · 登录
 * 校验账号密码、限制暴力尝试并建立后台会话；账号与密码均区分大小写。
 */

// Admin sign-in.
require_once '../includes/common.php';

if ($islogin) {
    header('Location: index.php');
    exit;
}

$errorMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = '提交来源不合法，请重试。';
    } else {
        $user = trim($_POST['user'] ?? '');
        $pwd = $_POST['pwd'] ?? '';
        $rateKey = hash('sha256', strtolower($user) . '|' . getClientIp());
        $retryAfter = 0;
        if ($user === '' || $pwd === '') {
            $errorMsg = '账号和密码不能为空！';
        } elseif (isLoginRateLimited($rateKey, $retryAfter)) {
            $errorMsg = '尝试次数过多，请 ' . max(1, (int)ceil($retryAfter / 60)) . ' 分钟后再试。';
            writeSecurityLog('login rate limited for account ' . strtolower($user));
        } else {
            $row = $DB->find('admin', 'username,password_hash', array('username' => $user));
            if (verifyAdminCredentials($user, $pwd, $row)) {
                $token = buildAdminToken($row['username'], $SYS_KEY);
                setAdminAuthCookie($token, time() + 604800);
                clearLoginFailures($rateKey);
                writeSecurityLog('login success for account ' . strtolower($row['username']));
                header('Location: index.php');
                exit;
            }
            recordLoginFailure($rateKey);
            writeSecurityLog('login failed for account ' . strtolower($user));
            $errorMsg = '账号或密码错误！';
        }
    }
}
$accent = htmlspecialchars(conf('accent', '#6366f1'));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>登录 · 个人主页后台</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --accent: <?php echo $accent; ?>; }
        * { box-sizing: border-box; }
        body {
            min-height: 100vh; margin: 0; display: flex; align-items: center; justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
            color: #1e293b;
            background: linear-gradient(135deg, #eef2ff 0%, #fdf2f8 45%, #e0f2fe 100%);
            position: relative; overflow: hidden;
        }
        .bg-blobs { position: fixed; inset: 0; z-index: -1; overflow: hidden; }
        .bg-blobs span { position: absolute; border-radius: 50%; filter: blur(70px); opacity: .5; }
        .bg-blobs .b1 { width: 380px; height: 380px; background: #a5b4fc; top: -90px; left: -70px; }
        .bg-blobs .b2 { width: 340px; height: 340px; background: #f9a8d4; bottom: -80px; right: -50px; }
        .bg-blobs .b3 { width: 300px; height: 300px; background: #7dd3fc; top: 45%; left: 52%; }

        .login-wrap { width: 100%; padding: 16px; }
        .login-card {
            width: 100%; max-width: 400px; padding: 10px; margin: 0 auto;
            background: rgba(255,255,255,.55);
            -webkit-backdrop-filter: blur(18px) saturate(160%);
            backdrop-filter: blur(18px) saturate(160%);
            border: 1px solid rgba(255,255,255,.6);
            border-radius: 20px;
            box-shadow: 0 12px 40px rgba(31,38,135,.15);
        }
        .brand { text-align: center; margin-bottom: 22px; }
        .brand .logo {
            width: 58px; height: 58px; border-radius: 16px; display: inline-flex; align-items: center; justify-content: center;
            font-size: 26px; color: #fff; background: var(--accent);
            box-shadow: 0 8px 22px color-mix(in srgb, var(--accent) 40%, transparent);
        }
        .panel { background: transparent; border: none; box-shadow: none; }
        .panel-body { padding: 4px 6px; }
        .btn-primary { background: var(--accent); border-color: var(--accent); width: 100%; padding: 10px; font-size: 15px; }
        .btn-primary:hover, .btn-primary:focus { background: color-mix(in srgb, var(--accent) 88%, #000); border-color: transparent; }
        .form-control:focus { border-color: var(--accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 25%, transparent); }
    </style>
</head>
<body>
<div class="bg-blobs"><span class="b1"></span><span class="b2"></span><span class="b3"></span></div>
<div class="login-wrap">
    <div class="login-card">
        <div class="brand">
            <div class="logo">✦</div>
            <h3 style="margin-top:12px;">个人主页后台</h3>
        </div>
        <div class="panel">
            <div class="panel-body">
                <?php if ($errorMsg): ?><div class="alert alert-danger"><?php echo htmlspecialchars($errorMsg); ?></div><?php endif; ?>
                <form method="post" action="login.php">
                    <?php echo csrfField(); ?>
                    <div class="form-group">
                        <label>管理员账号</label>
                        <input type="text" name="user" class="form-control" value="<?php echo isset($_POST['user']) ? htmlspecialchars($_POST['user']) : ''; ?>" autofocus required>
                    </div>
                    <div class="form-group">
                        <label>密码</label>
                        <input type="password" name="pwd" class="form-control" required>
                    </div>
                    <button class="btn btn-primary">登 录</button>
                </form>
            </div>
        </div>
        <p class="text-center text-muted" style="font-size:12px;margin:6px 0 14px;">
            忘记密码？请手动修改 <code>admin</code> 表对应 <code>password_hash</code>
        </p>
    </div>
</div>
</body>
</html>
