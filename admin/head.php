<?php
/**
 * 后台公共头部（含登录拦截 + 侧边栏 + 顶栏）· 毛玻璃风格
 * 用法：业务页先 require '../includes/common.php'，再立刻 require 'head.php'（head 之前不得有任何输出）
 */
if (!isset($islogin) || !$islogin) {
    header('Location: login.php');
    exit;
}
$accent = htmlspecialchars(conf('accent', '#6366f1'));
$siteTitle = htmlspecialchars(conf('site_title', '个人主页'));
$adminName = htmlspecialchars($admin_user);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $siteTitle; ?> · 后台管理</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --accent: <?php echo $accent; ?>; }
        * { box-sizing: border-box; }
        :focus-visible { outline: 3px solid var(--accent); outline-offset: 3px; }
        body {
            position: relative; min-height: 100vh; margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
            color: #1e293b;
            background: linear-gradient(135deg, #eef2ff 0%, #fdf2f8 45%, #e0f2fe 100%);
        }
        /* 背景柔光球，衬托毛玻璃 */
        .bg-blobs { position: fixed; inset: 0; z-index: -1; overflow: hidden; }
        .bg-blobs span { position: absolute; border-radius: 50%; filter: blur(70px); opacity: .5; }
        .bg-blobs .b1 { width: 380px; height: 380px; background: #a5b4fc; top: -90px; left: -70px; }
        .bg-blobs .b2 { width: 340px; height: 340px; background: #f9a8d4; bottom: -80px; right: -50px; }
        .bg-blobs .b3 { width: 300px; height: 300px; background: #7dd3fc; top: 45%; left: 52%; }

        .glass {
            background: rgba(255,255,255,.55);
            -webkit-backdrop-filter: blur(16px) saturate(160%);
            backdrop-filter: blur(16px) saturate(160%);
            border: 1px solid rgba(255,255,255,.6);
            box-shadow: 0 8px 32px rgba(31,38,135,.10);
        }

        .sidebar {
            position: fixed; top: 0; left: 0; bottom: 0; width: 224px;
            padding: 24px 14px; z-index: 10;
            background: rgba(255,255,255,.5);
            -webkit-backdrop-filter: blur(18px) saturate(160%);
            backdrop-filter: blur(18px) saturate(160%);
            border-right: 1px solid rgba(255,255,255,.55);
        }
        .sidebar .brand { text-align: center; margin-bottom: 28px; }
        .sidebar .brand .logo {
            width: 48px; height: 48px; border-radius: 14px; display: inline-flex; align-items: center; justify-content: center;
            font-size: 22px; color: #fff; background: var(--accent);
            box-shadow: 0 8px 20px color-mix(in srgb, var(--accent) 40%, transparent);
        }
        .sidebar .brand .t { font-weight: 700; margin-top: 12px; letter-spacing: .5px; }
        .sidebar .nav > li > a {
            border-radius: 12px; color: #475569; padding: 11px 14px; margin-bottom: 6px; font-weight: 500;
            transition: all .15s ease;
        }
        .sidebar .nav > li.active > a, .sidebar .nav > li > a:hover {
            background: color-mix(in srgb, var(--accent) 14%, transparent); color: var(--accent);
        }
        .sidebar .nav > li.nav-sep {
            font-size: 11px; color: #94a3b8; letter-spacing: 1.5px; font-weight: 700;
            padding: 12px 14px 5px; pointer-events: none;
        }
        .sidebar .nav > li > a .badge-dot {
            display: inline-block; min-width: 17px; height: 17px; line-height: 17px; padding: 0 5px;
            border-radius: 9px; background: #ef4444; color: #fff; font-size: 11px; text-align: center;
            margin-left: 6px; font-weight: 700;
        }
        .sidebar .ver {
            position: absolute; left: 0; right: 0; bottom: 12px; text-align: center;
            font-size: 11px; color: #94a3b8; letter-spacing: .5px;
        }

        .main { margin-left: 224px; padding: 24px 28px 40px; }
        .topbar {
            display: flex; justify-content: space-between; align-items: center;
            padding: 14px 20px; margin-bottom: 22px; border-radius: 16px;
            background: rgba(255,255,255,.55);
            -webkit-backdrop-filter: blur(16px) saturate(160%);
            backdrop-filter: blur(16px) saturate(160%);
            border: 1px solid rgba(255,255,255,.6);
            box-shadow: 0 8px 32px rgba(31,38,135,.10);
        }
        .topbar .who { font-weight: 700; }

        .stat {
            padding: 20px; border-radius: 16px;
            background: rgba(255,255,255,.55);
            -webkit-backdrop-filter: blur(14px) saturate(160%);
            backdrop-filter: blur(14px) saturate(160%);
            border: 1px solid rgba(255,255,255,.6);
            box-shadow: 0 8px 32px rgba(31,38,135,.10);
        }
        .stat .n { font-size: 26px; font-weight: 800; color: var(--accent); }
        .stat .l { color: #94a3b8; font-size: 13px; margin-top: 4px; }

        /* 面板毛玻璃化 */
        .panel {
            border-radius: 16px !important; border: 1px solid rgba(255,255,255,.6) !important;
            background: rgba(255,255,255,.55) !important;
            -webkit-backdrop-filter: blur(14px) saturate(160%);
            backdrop-filter: blur(14px) saturate(160%);
            box-shadow: 0 8px 32px rgba(31,38,135,.10);
            overflow: hidden;
        }
        .panel-heading {
            background: transparent !important; border-bottom: 1px solid rgba(255,255,255,.5) !important;
            font-weight: 700; padding: 14px 18px; color: #334155;
        }
        .panel-body { background: transparent !important; }
        .panel .table { background: transparent; }
        .table-bordered > tbody > tr > td, .table-bordered > thead > tr > th {
            border-color: rgba(255,255,255,.5) !important;
        }
        .panel .table th { color: #64748b; }

        /* 访问趋势柱状图 */
        .trend { display: flex; align-items: flex-end; gap: 6px; height: 190px; padding: 12px 4px 0; }
        .trend .col { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; min-width: 0; }
        .trend .v { font-size: 10px; color: #64748b; margin-bottom: 3px; font-weight: 600; }
        .trend .bar {
            width: 72%; max-width: 26px; border-radius: 8px 8px 4px 4px;
            background: linear-gradient(180deg, var(--accent), color-mix(in srgb, var(--accent) 55%, #fff));
            transition: height .3s ease;
        }
        .trend .x { font-size: 10px; color: #94a3b8; margin-top: 6px; white-space: nowrap; }

        .btn-primary { background: var(--accent); border-color: var(--accent); }
        .btn-primary:hover, .btn-primary:focus { background: color-mix(in srgb, var(--accent) 88%, #000); border-color: transparent; }
        .form-control:focus { border-color: var(--accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 25%, transparent); }
        .alert-success { background: rgba(220,252,231,.7); border-color: rgba(52,211,153,.5); color: #065f46; }
        .alert-danger  { background: rgba(254,226,226,.7); border-color: rgba(248,113,113,.5); color: #991b1b; }

        /* ===== 移动端适配（≤768px：侧边栏改为顶部栏，主区取消左外边距）===== */
        @media (max-width: 768px) {
            .sidebar {
                position: static; width: 100%;
                padding: 10px 12px; border-right: none; border-bottom: 1px solid rgba(255,255,255,.55);
                display: flex; flex-direction: column; gap: 10px;
            }
            .sidebar .brand { margin-bottom: 0; display: flex; align-items: center; justify-content: center; gap: 8px; }
            .sidebar .brand .logo { width: 36px; height: 36px; font-size: 18px; border-radius: 10px; }
            .sidebar .brand .t { margin-top: 0; font-size: 15px; }
            .sidebar .nav { display: flex; flex-direction: row; justify-content: center; flex-wrap: wrap; gap: 6px; margin: 0; }
            .sidebar .nav > li { display: inline-block; }
            .sidebar .nav > li.nav-sep { display: none; }
            .sidebar .ver { display: none; }
            .sidebar .nav > li > a { margin-bottom: 0; padding: 8px 12px; font-size: 13px; white-space: nowrap; }
            .main { margin-left: 0; padding: 14px 14px 28px; }
            .topbar { flex-wrap: wrap; gap: 10px; }
            .stat .n { font-size: 22px; }
            .main .row > [class*="col-sm-3"] { width: 50%; float: left; }  /* 统计卡片手机上两列一排 */
            .trend { height: 160px; gap: 4px; }
        }
        @media (max-width: 480px) {
            .sidebar .nav { flex-wrap: nowrap; justify-content: flex-start; overflow-x: auto; padding-bottom: 3px; }
            .overview-grid { grid-template-columns: repeat(2, 1fr); }
            .overview-item { padding: 12px; }
            .overview-item .ico { width: 34px; height: 34px; font-size: 16px; }
            .stat .n { font-size: 20px; }
            .main .row > [class*="col-sm-3"] { width: 100%; float: none; }
            .trend { height: 140px; gap: 2px; }
            .topbar { padding: 12px 14px; }
        }
        @supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))) {
            .glass, .sidebar, .topbar, .stat, .panel { background: rgba(255,255,255,.94) !important; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; scroll-behavior: auto !important; }
            .bg-blobs { display: none; }
        }
    </style>
</head>
<body>
<div class="bg-blobs"><span class="b1"></span><span class="b2"></span><span class="b3"></span></div>
<div class="sidebar">
    <div class="brand">
        <div class="logo">✦</div>
        <div class="t"><?php echo $siteTitle; ?></div>
    </div>
    <ul class="nav nav-pills nav-stacked">
        <li class="<?php echo checkIfActive('index'); ?>"><a href="index.php">📊 仪表盘</a></li>
        <li class="<?php echo checkIfActive('site'); ?>"><a href="site.php">🎨 站点配置</a></li>
        <li class="nav-sep">运营</li>
        <li class="<?php echo checkIfActive('links'); ?>"><a href="links.php">🔗 链接管理</a></li>
        <li class="<?php echo checkIfActive('works'); ?>"><a href="works.php">🖼️ 作品墙</a></li>
        <li class="<?php echo checkIfActive('guestbook'); ?>"><a href="guestbook.php">💬 留言板<?php echo pendingGuestbookBadge(); ?></a></li>
        <li class="nav-sep">系统</li>
        <li class="<?php echo checkIfActive('backup'); ?>"><a href="backup.php">💾 备份恢复</a></li>
        <li class="<?php echo checkIfActive('update'); ?>"><a href="update.php">☁️ 云更新</a></li>
        <li class="<?php echo checkIfActive('profile'); ?>"><a href="profile.php">🔑 修改密码</a></li>
        <li><a href="logout.php">🚪 退出登录</a></li>
    </ul>
    <div class="ver">v<?php echo htmlspecialchars(getSiteVersion()); ?></div>
</div>
<div class="main">
    <div class="topbar">
        <span>欢迎，<span class="who"><?php echo $adminName; ?></span> 👋</span>
        <a href="../" class="btn btn-default btn-xs" target="_blank">查看前台</a>
    </div>
