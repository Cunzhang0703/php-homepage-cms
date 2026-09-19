<?php
/**
 * 后台 · 备份与恢复
 * 导出全站数据与代码备份，并提供从备份包恢复的入口。
 */

// Code backup and restore screen.
require_once '../includes/common.php';

if (!$islogin) {
    header('Location: login.php');
    exit;
}

if (isset($_GET['dl'])) {
    $name = basename($_GET['dl']);
    $path = getBackupDir() . '/' . $name;
    if (strpos($name, 'backup-') === 0 && substr($name, -4) === '.zip' && is_file($path)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
    header('Location: backup.php?e=nf');
    exit;
}

$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = '提交来源不合法，请重试。';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';
        $file   = isset($_POST['file']) ? basename($_POST['file']) : '';

        if ($action === 'create') {
            $t0 = microtime(true);
            $path = backupSite('manual');
            if ($path) {
                $msg = '✅ 备份成功：' . basename($path) . '（' . formatBytes(filesize($path)) . '，耗时 ' . round(microtime(true) - $t0, 2) . 's）';
                $msgType = 'success';
                writeUpdateLog('手动备份成功 ' . basename($path));
            } else {
                $msg = '❌ 备份失败：请检查 PHP 是否启用 ZipArchive 扩展，以及 data 目录是否可写。';
                $msgType = 'danger';
            }
        } elseif ($action === 'restore' && $file) {
            backupSite('prerestore');
            $n = restoreBackup($file);
            if ($n) {
                if (function_exists('opcache_reset')) { @opcache_reset(); }
                $msg = '✅ 已从 ' . htmlspecialchars($file) . ' 恢复 ' . (int)$n . ' 个文件。恢复前状态已自动另存为 prerestore 备份。';
                $msgType = 'success';
                writeUpdateLog('恢复备份 ' . $file . '，写入 ' . (int)$n . ' 个文件');
            } else {
                $msg = '❌ 恢复失败：备份文件不存在或已损坏。';
                $msgType = 'danger';
            }
        } elseif ($action === 'delete' && $file) {
            if (deleteBackup($file)) {
                $msg = '🗑 已删除备份 ' . htmlspecialchars($file);
                $msgType = 'success';
            } else {
                $msg = '❌ 删除失败。';
                $msgType = 'danger';
            }
        }
    }
}

if (isset($_GET['e']) && $_GET['e'] === 'nf') {
    $msg = '备份文件不存在。';
    $msgType = 'danger';
}

$backups  = listBackups();
$totalSz  = 0;
foreach ($backups as $b) { $totalSz += $b['size']; }
$hasZip   = class_exists('ZipArchive');
$dirW     = is_writable(getBackupDir());

require_once 'head.php';
?>
<?php if ($msg): ?>
    <div class="alert alert-<?php echo $msgType; ?>"><?php echo $msg; ?></div>
<?php endif; ?>

<?php if (!$hasZip || !$dirW): ?>
    <div class="alert alert-danger">
        环境检查未通过：
        <?php if (!$hasZip): ?>PHP 未启用 <b>ZipArchive</b> 扩展（宝塔 → PHP 设置 → 安装扩展 → zip）；<?php endif; ?>
        <?php if (!$dirW): ?>备份目录 <code>data/.backups</code> 不可写；<?php endif; ?>
    </div>
<?php endif; ?>

<div class="panel panel-default">
    <div class="panel-heading">💾 备份与恢复</div>
    <div class="panel-body">
        <p class="text-muted" style="font-size:13px;line-height:1.9;">
            备份内容 = <b>站点代码</b>（php / html / css 等）。<br>
            <b>不包含</b>：<code>data/</code>（访问统计等）、<code>uploads/</code>（头像）、MySQL 数据库、<code>includes/db.php</code>。<br>
            恢复时同样会跳过上述受保护文件，所以<b>不会把数据库配置冲掉</b>。数据库请用宝塔的「数据库 → 备份」单独处理。
        </p>
        <form method="post" style="display:inline;">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="create">
            <button class="btn btn-primary" <?php echo ($hasZip && $dirW) ? '' : 'disabled'; ?>>📦 立即创建备份</button>
        </form>
        <span class="text-muted" style="margin-left:12px;font-size:12px;">
            共 <?php echo count($backups); ?> 份，占用 <?php echo formatBytes($totalSz); ?>
        </span>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">备份列表</div>
    <div class="panel-body" style="padding:0;">
        <table class="table table-bordered" style="margin:0;">
            <thead>
            <tr>
                <th style="width:46%;">文件名</th>
                <th style="width:14%;">大小</th>
                <th style="width:20%;">创建时间</th>
                <th style="width:20%;">操作</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$backups): ?>
                <tr><td colspan="4" class="text-muted" style="text-align:center;padding:24px;">暂无备份，点上面的按钮创建第一份。</td></tr>
            <?php else: foreach ($backups as $b): ?>
                <tr>
                    <td style="word-break:break-all;font-size:12px;"><?php echo htmlspecialchars($b['file']); ?></td>
                    <td><?php echo formatBytes($b['size']); ?></td>
                    <td><?php echo date('Y-m-d H:i:s', $b['time']); ?></td>
                    <td>
                        <a class="btn btn-default btn-xs" href="backup.php?dl=<?php echo urlencode($b['file']); ?>">下载</a>
                        <form method="post" style="display:inline;" onsubmit="return confirm('确定用这份备份覆盖当前代码吗？\n（当前状态会自动另存为 prerestore 备份）');">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="file" value="<?php echo htmlspecialchars($b['file']); ?>">
                            <button class="btn btn-warning btn-xs">恢复</button>
                        </form>
                        <form method="post" style="display:inline;" onsubmit="return confirm('删除该备份？不可撤销。');">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="file" value="<?php echo htmlspecialchars($b['file']); ?>">
                            <button class="btn btn-danger btn-xs">删除</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
require_once 'foot.php';
