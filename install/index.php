<?php
/**
 * 安装向导
 * 检测运行环境与目录权限，写入 includes/db.php，初始化数据表与管理账号，生成 install.lock。
 */

// First-run installer.
error_reporting(0);
date_default_timezone_set('Asia/Shanghai');
define('ROOT_DIR', dirname(__DIR__));
define('DATA_DIR', ROOT_DIR . '/data');
define('DB_FILE', DATA_DIR . '/site.sqlite');
define('LOCK_FILE', __DIR__ . '/install.lock');
define('DB_CONFIG_FILE', ROOT_DIR . '/includes/db.php');

@header('Content-Type: text/html; charset=UTF-8');

if (file_exists(LOCK_FILE)) {
    http_response_code(404);
    exit('Not Found');
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$errorMsg = '';
$success = 0;
$error = 0;

function writeDbConfig($host, $port, $name, $user, $pass)
{
    $c = "<?php\n";
    $c .= "/**\n * 数据库配置（安装向导写入，可手动修改）\n */\n";
    $c .= "if (!defined('DB_TYPE'))  define('DB_TYPE', 'mysql');\n";
    $c .= "if (!defined('DB_HOST'))  define('DB_HOST', " . var_export($host, true) . ");\n";
    $c .= "if (!defined('DB_PORT'))  define('DB_PORT', " . var_export($port, true) . ");\n";
    $c .= "if (!defined('DB_NAME'))  define('DB_NAME', " . var_export($name, true) . ");\n";
    $c .= "if (!defined('DB_USER'))  define('DB_USER', " . var_export($user, true) . ");\n";
    $c .= "if (!defined('DB_PASS'))  define('DB_PASS', " . var_export($pass, true) . ");\n";
    $c .= "if (!defined('DB_QZ'))    define('DB_QZ', '');\n";
    return @file_put_contents(DB_CONFIG_FILE, $c) !== false;
}

if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbhost = trim($_POST['dbhost'] ?? '127.0.0.1');
    $dbport = trim($_POST['dbport'] ?? '3306');
    $dbname = trim($_POST['dbname'] ?? '');
    $dbuser = trim($_POST['dbuser'] ?? '');
    $dbpass = $_POST['dbpass'] ?? '';

    if (isset($_POST['act']) && $_POST['act'] === 'test') {
        header('Content-Type: application/json; charset=UTF-8');
        try {
            $t = new PDO("mysql:host={$dbhost};port={$dbport};dbname={$dbname};charset=utf8mb4", $dbuser, $dbpass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
            echo json_encode(array('ok' => true, 'msg' => '连接成功' . ($dbname ? '（数据库 ' . $dbname . ' 可访问）' : '')));
        } catch (\Exception $e) {
            error_log('Installer database test failed: ' . $e->getMessage());
            echo json_encode(array('ok' => false, 'msg' => '连接失败，请检查数据库地址、账号和权限'));
        }
        exit;
    }

    if ($dbname === '') {
        $errorMsg = '数据库名不能为空！';
    } else {
        try {
            $conn = new PDO("mysql:host={$dbhost};port={$dbport};dbname={$dbname};charset=utf8mb4", $dbuser, $dbpass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
            if (!writeDbConfig($dbhost, $dbport, $dbname, $dbuser, $dbpass)) {
                $errorMsg = '无法写入数据库配置文件 includes/db.php（请检查目录权限）';
            } else {
                $step = 3;
            }
        } catch (\Exception $e) {
            error_log('Installer database connection failed: ' . $e->getMessage());
            $errorMsg = '数据库连接失败，请检查数据库地址、账号和权限。';
        }
    }
}

if ($step === 4 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin_user = trim($_POST['admin_user'] ?? '');
    $admin_pwd = $_POST['admin_pwd'] ?? '';
    $admin_pwd2 = $_POST['admin_pwd2'] ?? '';
    $site_title = trim($_POST['site_title'] ?? '我的个人主页');

    if ($admin_user === '' || $admin_pwd === '') {
        $errorMsg = '管理员账号和密码不能为空！';
    } elseif ($admin_pwd !== $admin_pwd2) {
        $errorMsg = '两次输入的密码不一致！';
    } elseif (strlen($admin_pwd) < 6) {
        $errorMsg = '密码至少 6 位！';
    } elseif (!file_exists(DB_CONFIG_FILE)) {
        $errorMsg = '未检测到数据库配置，请返回「连接数据库」步骤。';
    } else {
        try {
            require_once DB_CONFIG_FILE;
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $db = new PDO($dsn, DB_USER, DB_PASS, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
            $db->exec('SET NAMES utf8mb4');

            $db->exec("CREATE TABLE IF NOT EXISTS config (
                k VARCHAR(64) NOT NULL,
                v TEXT,
                PRIMARY KEY (k)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $db->exec("CREATE TABLE IF NOT EXISTS admin (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                username VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                password_hash TEXT NOT NULL,
                addtime DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_username (username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $sysKey = bin2hex(random_bytes(16));
            $defaults = array(
                'version' => '1.04',
                'syskey' => $sysKey,
                'site_title' => $site_title,
                'name' => '你的名字',
                'role' => '一句话头衔 / 职业',
                'bio' => '这里写一句或一段简短的自我介绍。',
                'tags' => "标签1\n标签2\n标签3",
                'email' => 'you@example.com',
                'wechat' => 'your-wechat',
                'link1_text' => '我在考虑',
                'link1_href' => './',
                'link2_text' => '博客 / 作品集',
                'link2_href' => './',
                'copyright_year' => date('Y'),
                'copyright_name' => '你的名字',
                'icp' => '京ICP备00000000号-1',
                'police' => '京公网安备 00000000000000号',
                'accent' => '#6366f1',
                'use_hitokoto' => '1',
            );
            $ins = $db->prepare('INSERT IGNORE INTO config (k, v) VALUES (?, ?)');
            foreach ($defaults as $k => $v) {
                $ins->execute(array($k, $v));
            }

            $adminCount = $db->query("SELECT COUNT(*) FROM admin")->fetchColumn();
            if ((int)$adminCount === 0) {
                $hash = password_hash($admin_pwd, PASSWORD_BCRYPT);
                $db->prepare('INSERT INTO admin (username, password_hash, addtime) VALUES (?, ?, NOW())')
                   ->execute(array($admin_user, $hash));
            }

            file_put_contents(LOCK_FILE, 'installed at ' . date('Y-m-d H:i:s'));

            $success = 1;
            $step = 5;
        } catch (\Exception $e) {
            $errorMsg = '安装失败：' . $e->getMessage();
            $step = 4;
        }
    }
}

$stepper = array(1 => '环境检测', 2 => '连接数据库', 3 => '管理员设置', 4 => '完成');
$cur = $step >= 5 ? 4 : ($step >= 3 ? 3 : ($step >= 2 ? 2 : 1));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>个人主页后台 · 安装向导</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --bg1: #6366f1; --bg2: #8b5cf6; --bg3: #06b6d4; --bg4: #ec4899;
            --glass-bg: rgba(255, 255, 255, 0.16);
            --glass-brd: rgba(255, 255, 255, 0.35);
            --glass-shadow: rgba(31, 38, 135, 0.28);
            --text: #0f172a;
            --text-soft: rgba(15, 23, 42, 0.66);
            --input-bg: rgba(255, 255, 255, 0.22);
            --input-brd: rgba(255, 255, 255, 0.42);
            --accent: #6366f1;
            --accent2: #8b5cf6;
            --ok: #10b981; --bad: #ef4444; --warn: #f59e0b;
        }
        [data-theme="dark"] {
            --glass-bg: rgba(17, 25, 40, 0.5);
            --glass-brd: rgba(255, 255, 255, 0.14);
            --glass-shadow: rgba(0, 0, 0, 0.55);
            --text: #e8edf7;
            --text-soft: rgba(232, 237, 247, 0.66);
            --input-bg: rgba(255, 255, 255, 0.07);
            --input-brd: rgba(255, 255, 255, 0.18);
        }
        * { box-sizing: border-box; }
        body {
            min-height: 100vh; margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", system-ui, sans-serif;
            color: var(--text);
            background: linear-gradient(135deg, var(--bg1), var(--bg3));
            overflow-x: hidden;
            transition: background .5s ease;
        }
        .blob {
            position: fixed; border-radius: 50%; filter: blur(70px);
            opacity: .5; z-index: -1; animation: float 18s ease-in-out infinite;
        }
        .blob.b1 { width: 380px; height: 380px; background: var(--bg2); top: -90px; left: -70px; }
        .blob.b2 { width: 440px; height: 440px; background: var(--bg4); bottom: -130px; right: -90px; animation-delay: -6s; }
        .blob.b3 { width: 320px; height: 320px; background: var(--bg3); top: 45%; left: 58%; animation-delay: -12s; }
        @keyframes float { 0%, 100% { transform: translate(0, 0) scale(1); } 50% { transform: translate(34px, -42px) scale(1.08); } }

        .theme-toggle {
            position: fixed; top: 18px; right: 18px; z-index: 10;
            width: 44px; height: 44px; border-radius: 50%; cursor: pointer;
            background: var(--glass-bg); border: 1px solid var(--glass-brd);
            backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
            color: var(--text); font-size: 18px; line-height: 1;
            display: flex; align-items: center; justify-content: center;
            transition: transform .25s, box-shadow .25s;
        }
        .theme-toggle:hover { transform: scale(1.08); box-shadow: 0 8px 22px var(--glass-shadow); }

        .box { max-width: 600px; margin: 6vh auto; padding: 0 16px; }
        .glass {
            background: var(--glass-bg);
            backdrop-filter: blur(26px) saturate(160%);
            -webkit-backdrop-filter: blur(26px) saturate(160%);
            border: 1px solid var(--glass-brd);
            border-radius: 28px;
            box-shadow: 0 14px 50px var(--glass-shadow);
            animation: rise .55s ease both;
        }
        @keyframes rise { from { opacity: 0; transform: translateY(18px); } to { opacity: 1; transform: none; } }

        .card-head { padding: 30px 32px 8px; text-align: center; }
        .card-head h2 { margin: 0; font-size: 24px; font-weight: 700; letter-spacing: .5px; }
        .card-head .sub { margin: 8px 0 0; font-size: 13px; color: var(--text-soft); }
        .card-body { padding: 12px 32px 32px; }

        /* 步骤指示器 */
        .stepper { display: flex; gap: 6px; margin: 22px 0 26px; }
        .step { flex: 1; text-align: center; }
        .step .dot {
            width: 38px; height: 38px; border-radius: 50%; margin: 0 auto 7px;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 15px;
            background: var(--input-bg); border: 1px solid var(--input-brd);
            color: var(--text-soft); transition: .35s;
        }
        .step .lbl { font-size: 12px; color: var(--text-soft); transition: .35s; }
        .step.active .dot { background: linear-gradient(135deg, var(--accent), var(--accent2)); color: #fff; border: none; box-shadow: 0 8px 18px rgba(99, 102, 241, .45); }
        .step.active .lbl { color: var(--text); font-weight: 600; }
        .step.done .dot { background: var(--ok); color: #fff; border: none; }

        .form-group { margin-bottom: 16px; }
        .form-group > label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; color: var(--text); }
        .form-control {
            width: 100%; background: var(--input-bg); border: 1px solid var(--input-brd);
            border-radius: 14px; color: var(--text); padding: 12px 14px; font-size: 14px;
            transition: border-color .25s, box-shadow .25s; box-shadow: none;
        }
        .form-control::placeholder { color: var(--text-soft); }
        .form-control:focus { border-color: var(--accent); box-shadow: 0 0 0 4px rgba(99, 102, 241, .25); outline: none; }
        .help-block { display: block; margin-top: 6px; font-size: 12px; color: var(--text-soft); }
        .row { margin: 0 -7px; }
        .row > [class*="col-"] { padding: 0 7px; }

        .btn {
            border: none; border-radius: 14px; font-weight: 600; font-size: 14px;
            padding: 12px 18px; cursor: pointer; transition: transform .22s, box-shadow .22s, background .22s;
            color: #fff;
        }
        .btn-block { display: block; width: 100%; }
        .btn-primary { background: linear-gradient(135deg, var(--accent), var(--accent2)); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(99, 102, 241, .42); }
        .btn-info { background: linear-gradient(135deg, #06b6d4, #3b82f6); }
        .btn-info:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(6, 182, 212, .42); }
        .btn-success { background: linear-gradient(135deg, #10b981, #059669); }
        .btn-success:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(16, 185, 129, .42); }
        .btn-default {
            background: var(--input-bg); border: 1px solid var(--input-brd);
            color: var(--text); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
        }
        .btn-default:hover { transform: translateY(-2px); }

        .alert {
            border-radius: 16px; padding: 16px 18px; margin-bottom: 16px; font-size: 14px;
            background: var(--glass-bg); border: 1px solid var(--glass-brd);
            backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px);
        }
        .alert-success { border-left: 4px solid var(--ok); }
        .alert-danger { border-left: 4px solid var(--bad); }
        .alert-warning { border-left: 4px solid var(--warn); }
        .alert ul { margin: 8px 0 12px; padding-left: 20px; }
        .alert a { color: var(--accent); font-weight: 600; }

        .list-group { border-radius: 16px; overflow: hidden; }
        .list-group-item {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 18px; background: var(--input-bg); border: 1px solid var(--input-brd);
            border-bottom: none; color: var(--text); font-size: 14px;
        }
        .list-group-item:first-child { border-radius: 16px 16px 0 0; }
        .list-group-item:last-child { border-radius: 0 0 16px 16px; border-bottom: 1px solid var(--input-brd); }
        .label { padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; color: #fff; }
        .label-success { background: var(--ok); }
        .label-danger { background: var(--bad); }
        .text-success { color: var(--ok) !important; }
        .text-danger { color: var(--bad) !important; }
        .text-center { text-align: center; }
        hr { border: none; border-top: 1px solid var(--input-brd); margin: 18px 0; }
        a { color: var(--accent); }
    </style>
</head>
<body>
<div class="blob b1"></div>
<div class="blob b2"></div>
<div class="blob b3"></div>
<button class="theme-toggle" id="themeToggle" title="切换主题">🌙</button>
<div class="container">
    <div class="box">
        <div class="glass">
            <div class="card-head">
                <h2>个人主页后台 · 安装向导</h2>
                <p class="sub">几步完成部署，开启你的专属空间</p>
            </div>
            <div class="card-body">
                <div class="stepper">
<?php foreach ($stepper as $i => $lbl):
    $cls = $i < $cur ? 'done' : ($i == $cur ? 'active' : '');
    $mark = $i < $cur ? '✓' : $i;
?>
                    <div class="step <?php echo $cls; ?>">
                        <div class="dot"><?php echo $mark; ?></div>
                        <div class="lbl"><?php echo $lbl; ?></div>
                    </div>
<?php endforeach; ?>
                </div>
<?php if ($step === 5 && $success): ?>
                <div class="alert alert-success">
                    <p>🎉 安装成功！</p>
                    <ul>
                        <li>后台地址：<a href="../admin/" target="_blank">/admin/</a></li>
                        <li>管理员账号：<b><?php echo htmlspecialchars($admin_user); ?></b></li>
                        <li>请尽快在后台「站点配置」中修改默认资料。</li>
                    </ul>
                    <a href="../admin/" class="btn btn-success btn-block">进入后台</a>
                </div>
<?php elseif ($step === 4): ?>
<?php if ($errorMsg): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($errorMsg); ?></div>
<?php endif; ?>
                <form method="post" action="?step=4">
                    <div class="form-group">
                        <label>站点标题</label>
                        <input type="text" name="site_title" class="form-control" value="我的个人主页" required>
                    </div>
                    <div class="form-group">
                        <label>管理员账号</label>
                        <input type="text" name="admin_user" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>管理员密码（≥6 位）</label>
                        <input type="password" name="admin_pwd" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>确认密码</label>
                        <input type="password" name="admin_pwd2" class="form-control" required>
                    </div>
                    <button class="btn btn-primary btn-block">开始安装</button>
                </form>
                <a href="?step=2" class="btn btn-default btn-block" style="margin-top:8px;">返回：连接数据库</a>
<?php elseif ($step === 3): ?>
<?php if ($errorMsg): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($errorMsg); ?></div>
<?php endif; ?>
                <form method="post" action="?step=4">
                    <div class="form-group">
                        <label>站点标题</label>
                        <input type="text" name="site_title" class="form-control" value="我的个人主页" required>
                    </div>
                    <div class="form-group">
                        <label>管理员账号</label>
                        <input type="text" name="admin_user" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>管理员密码（≥6 位）</label>
                        <input type="password" name="admin_pwd" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>确认密码</label>
                        <input type="password" name="admin_pwd2" class="form-control" required>
                    </div>
                    <button class="btn btn-primary btn-block">开始安装</button>
                </form>
                <a href="?step=2" class="btn btn-default btn-block" style="margin-top:8px;">返回：连接数据库</a>
<?php elseif ($step === 2): ?>
<?php if ($errorMsg): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($errorMsg); ?></div>
<?php endif; ?>
                <form id="dbForm" method="post" action="?step=2">
                    <div class="row">
                        <div class="col-sm-8 form-group">
                            <label>数据库主机</label>
                            <input type="text" name="dbhost" class="form-control" value="<?php echo htmlspecialchars($_POST['dbhost'] ?? '127.0.0.1'); ?>" required>
                        </div>
                        <div class="col-sm-4 form-group">
                            <label>端口</label>
                            <input type="text" name="dbport" class="form-control" value="<?php echo htmlspecialchars($_POST['dbport'] ?? '3306'); ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>数据库名</label>
                        <input type="text" name="dbname" class="form-control" value="<?php echo htmlspecialchars($_POST['dbname'] ?? ''); ?>" placeholder="请输入数据库名称" required>
                        <span class="help-block">请填写您为站点创建的数据库名称</span>
                    </div>
                    <div class="form-group">
                        <label>数据库用户名</label>
                        <input type="text" name="dbuser" class="form-control" value="<?php echo htmlspecialchars($_POST['dbuser'] ?? ''); ?>" placeholder="请输入数据库用户名" required>
                    </div>
                    <div class="form-group">
                        <label>数据库密码</label>
                        <input type="password" name="dbpass" class="form-control" value="<?php echo htmlspecialchars($_POST['dbpass'] ?? ''); ?>">
                    </div>
                    <button type="button" id="testBtn" class="btn btn-info">测试连接</button>
                    <span id="testMsg" style="margin-left:10px;"></span>
                    <hr>
                    <button type="submit" class="btn btn-primary btn-block">保存连接并下一步</button>
                </form>
                <a href="?step=1" class="btn btn-default btn-block" style="margin-top:8px;">返回：环境检测</a>
<?php else: ?>
<?php
    $checks = array();
    $checks['php'] = version_compare(PHP_VERSION, '7.1.0', '>=') ? 'ok' : 'no';
    $checks['pdo'] = extension_loaded('pdo_mysql') ? 'ok' : 'no';
    $checks['installdir'] = is_writable(__DIR__) ? 'ok' : 'no';
    $canInstall = $checks['php'] === 'ok' && $checks['pdo'] === 'ok' && $checks['installdir'] === 'ok';
?>
                <ul class="list-group">
                    <li class="list-group-item">PHP ≥ 7.1
                        <span class="label label-<?php echo $checks['php']==='ok'?'success':'danger'; ?>"><?php echo $checks['php']==='ok'?'支持':'不支持'; ?></span></li>
                    <li class="list-group-item">PDO_MySQL 扩展
                        <span class="label label-<?php echo $checks['pdo']==='ok'?'success':'danger'; ?>"><?php echo $checks['pdo']==='ok'?'支持':'不支持'; ?></span></li>
                    <li class="list-group-item">install 目录可写
                        <span class="label label-<?php echo $checks['installdir']==='ok'?'success':'danger'; ?>"><?php echo $checks['installdir']==='ok'?'可写':'不可写'; ?></span></li>
                </ul>
<?php if ($canInstall): ?>
                <a href="?step=2" class="btn btn-primary btn-block">环境通过，下一步（连接数据库）</a>
<?php else: ?>
                <div class="alert alert-warning">请先满足上述环境要求（开启 PDO_MySQL、保证 install 目录可写）。</div>
<?php endif; ?>
<?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/jquery@1.12.4/dist/jquery.min.js"></script>
<script>
$('#testBtn').on('click', function () {
    var $btn = $(this), $msg = $('#testMsg');
    $btn.prop('disabled', true).text('测试中…');
    $msg.text('').removeClass('text-danger text-success');
    $.ajax({
        url: '?step=2',
        type: 'POST',
        dataType: 'json',
        data: $('#dbForm').serialize() + '&act=test',
        success: function (r) {
            if (r.ok) { $msg.text(r.msg).addClass('text-success'); }
            else { $msg.text(r.msg).addClass('text-danger'); }
        },
        error: function () { $msg.text('测试请求失败').addClass('text-danger'); },
        complete: function () { $btn.prop('disabled', false).text('测试连接'); }
    });
});
(function () {
    var t = document.getElementById('themeToggle');
    var saved = localStorage.getItem('install_theme');
    if (saved === 'dark') { document.documentElement.setAttribute('data-theme', 'dark'); t.textContent = '☀️'; }
    t.addEventListener('click', function () {
        var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        if (isDark) {
            document.documentElement.removeAttribute('data-theme');
            localStorage.setItem('install_theme', 'light');
            t.textContent = '🌙';
        } else {
            document.documentElement.setAttribute('data-theme', 'dark');
            localStorage.setItem('install_theme', 'dark');
            t.textContent = '☀️';
        }
    });
})();
</script>
</body>
</html>
