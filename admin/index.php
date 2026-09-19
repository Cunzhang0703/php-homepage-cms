<?php
/**
 * 后台仪表盘
 */
require_once '../includes/common.php';
require_once 'head.php';

$confCount = $DB->count('config', array());
$adminCount = $DB->count('admin', array());
$versionLabel = htmlspecialchars(getSiteVersion());
$hitokoto = conf('use_hitokoto', '1') === '1' ? '开启' : '关闭';
$accent = htmlspecialchars(conf('accent', '#6366f1'));

// ===== 访问人数统计（文件计数，不入库、不存 IP）=====
$visits = readVisits();
$totalVisits = (int)$visits['total'];
$today = date('Y-m-d');
$todayVisits = isset($visits['days'][$today]) ? (int)$visits['days'][$today] : 0;
$yesterday   = date('Y-m-d', strtotime('-1 day'));
$yesterdayVisits = isset($visits['days'][$yesterday]) ? (int)$visits['days'][$yesterday] : 0;
// 近 7 日访问量（因不存储 IP，无法做独立访客去重，故用近 7 日总量代替）
$last7 = 0;
for ($i = 0; $i < 7; $i++) {
    $dt = date('Y-m-d', strtotime("-$i days"));
    $last7 += isset($visits['days'][$dt]) ? (int)$visits['days'][$dt] : 0;
}

// 近 14 天趋势
$raw = isset($visits['days']) ? $visits['days'] : array();
$days = array();
$maxC = 1;
for ($i = 13; $i >= 0; $i--) {
    $dt = date('Y-m-d', strtotime("-$i days"));
    $c = isset($raw[$dt]) ? (int)$raw[$dt] : 0;
    if ($c > $maxC) { $maxC = $c; }
    $days[] = array(
        'date'  => $dt,
        'count' => $c,
        'label' => date('m-d', strtotime($dt)),
    );
}
foreach ($days as &$d) {
    $d['pct'] = $maxC > 0 ? round($d['count'] / $maxC * 100) : 0;
}
unset($d);

// 站点配置速览数据
$avatarUrl = conf('avatar', '');
$overview = array(
    array('icon' => '🏷️', 'label' => '站点标题', 'value' => conf('site_title', '')),
    array('icon' => '👤', 'label' => '姓名', 'value' => conf('name', '')),
    array('icon' => '💼', 'label' => '头衔 / 职业', 'value' => conf('role', '')),
    array('icon' => '✉️', 'label' => '邮箱', 'value' => conf('email', '')),
    array('icon' => '💬', 'label' => '微信', 'value' => conf('wechat', '')),
    array('icon' => '📝', 'label' => '一言 API', 'value' => $hitokoto),
    array('icon' => '📋', 'label' => '备案号', 'value' => conf('icp', '')),
);
?>
<style>
.overview-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:16px; }
.overview-item {
    display:flex; align-items:flex-start; gap:14px;
    padding:16px; border-radius:14px;
    background:rgba(255,255,255,.45);
    border:1px solid rgba(255,255,255,.55);
    box-shadow:0 4px 18px rgba(31,38,135,.08);
    transition:transform .15s ease, box-shadow .15s ease;
}
.overview-item:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(31,38,135,.12); }
.overview-item .ico {
    width:40px; height:40px; border-radius:12px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    font-size:20px; background:rgba(255,255,255,.7);
    box-shadow:0 2px 8px rgba(31,38,135,.08);
}
.overview-item .body { min-width:0; }
.overview-item .label { font-size:12px; color:#94a3b8; margin-bottom:3px; }
.overview-item .value { font-size:15px; font-weight:600; color:#334155; word-break:break-all; }
.overview-item .value.empty { color:#94a3b8; font-weight:400; }
.avatar-preview {
    width:64px; height:64px; border-radius:50%; object-fit:cover;
    border:2px solid #fff; box-shadow:0 4px 14px rgba(31,38,135,.18);
}
.avatar-none {
    width:64px; height:64px; border-radius:50%; display:inline-flex;
    align-items:center; justify-content:center;
    background:#e2e8f0; color:#94a3b8; font-size:13px;
}
</style>

<div class="row">
    <div class="col-sm-3"><div class="stat"><div class="n"><?php echo $confCount; ?></div><div class="l">配置项</div></div></div>
    <div class="col-sm-3"><div class="stat"><div class="n"><?php echo $adminCount; ?></div><div class="l">管理员</div></div></div>
    <div class="col-sm-3"><div class="stat"><div class="n"><?php echo $versionLabel; ?></div><div class="l">后台版本</div></div></div>
    <div class="col-sm-3"><div class="stat"><div class="n" style="background:<?php echo $accent; ?>;width:46px;height:46px;border-radius:12px;color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;">主题</div><div class="l">强调色</div></div></div>
</div>

<div class="row" style="margin-top:20px;">
    <div class="col-sm-3"><div class="stat"><div class="n"><?php echo $todayVisits; ?></div><div class="l">今日访问量</div></div></div>
    <div class="col-sm-3"><div class="stat"><div class="n"><?php echo $totalVisits; ?></div><div class="l">累计访问量</div></div></div>
    <div class="col-sm-3"><div class="stat"><div class="n"><?php echo $last7; ?></div><div class="l">近 7 日访问量</div></div></div>
    <div class="col-sm-3"><div class="stat"><div class="n"><?php echo $yesterdayVisits; ?></div><div class="l">昨日访问量</div></div></div>
</div>

<div class="panel panel-default" style="margin-top:20px;">
    <div class="panel-heading">站点配置速览</div>
    <div class="panel-body">
        <div class="overview-grid">
            <?php foreach ($overview as $it): ?>
            <div class="overview-item">
                <div class="ico"><?php echo $it['icon']; ?></div>
                <div class="body">
                    <div class="label"><?php echo htmlspecialchars($it['label']); ?></div>
                    <div class="value<?php echo $it['value'] === '' ? ' empty' : ''; ?>"><?php echo $it['value'] === '' ? '未填写' : htmlspecialchars($it['value']); ?></div>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="overview-item">
                <div class="ico">🖼️</div>
                <div class="body">
                    <div class="label">前台头像</div>
                    <div class="value">
                        <?php if ($avatarUrl): ?>
                            <a href="<?php echo htmlspecialchars($avatarUrl); ?>" target="_blank">
                                <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="前台头像" class="avatar-preview">
                            </a>
                        <?php else: ?>
                            <span class="avatar-none">未设置</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">访问趋势（近 14 天）</div>
    <div class="panel-body">
        <div class="trend">
            <?php foreach ($days as $d): ?>
            <div class="col" title="<?php echo $d['date']; ?>：<?php echo $d['count']; ?> 次访问">
                <div class="v"><?php echo $d['count'] ? $d['count'] : ''; ?></div>
                <div class="bar" style="height:<?php echo $d['pct']; ?>%"></div>
                <div class="x"><?php echo $d['label']; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="text-muted" style="font-size:12px;margin:14px 0 0;">说明：访问量按页面加载次数累计（文件计数，不入库、不记录 IP）。</p>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">快捷操作</div>
    <div class="panel-body">
        <a href="site.php" class="btn btn-primary">修改站点资料 / 头像</a>
        <a href="../" class="btn btn-default" target="_blank">打开前台主页</a>
    </div>
</div>
<?php
require_once 'foot.php';
