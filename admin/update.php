<?php
/**
 * 后台 · 云更新
 * 拉取远端 update.json，展示待更新包，校验 SHA256 后执行 下载→备份→安全解压→写版本号，失败自动回滚。
 */

// Cloud update screen. Packages are verified, backed up and applied only after confirmation.
require_once '../includes/common.php';

if (!$islogin) {
    header('Location: login.php');
    exit;
}

if (is_file(__DIR__ . '/../update-admin/lib_update.php')) {
    require_once __DIR__ . '/../update-admin/lib_update.php';
    if (function_exists('ensureUpdateSchema')) { ensureUpdateSchema(); }
}

@set_time_limit(0);
@ignore_user_abort(true);

$msg = '';
$msgType = '';
$remote = null;        // 远端版本信息（含 pending）
$current = getSiteVersion();
$updateUrl = conf('update_url', '');
$autoCheck = conf('update_autocheck', '1');

$PROTECT_FILES = array('db.php', 'install.lock', 'version.json', '.htaccess');
$PROTECT_DIRS  = array('data', 'uploads', 'install');

// Download, validate and apply one update package.
function applyRemoteUpdate($info, $curVer)
{
    $steps = array();
    $fail  = '';
    $tmp   = getBackupDir() . '/_update_tmp.zip';
    $bak   = '';

    do {
        if (!class_exists('ZipArchive')) { $fail = 'PHP 未启用 ZipArchive 扩展'; break; }
        if (!is_writable(ROOT_DIR))      { $fail = '站点根目录不可写，无法写入新文件'; break; }
        $steps[] = '环境检查通过（ZipArchive / 目录可写）';

        $versionOrder = compareUpdateVersions((string)($info['version'] ?? ''), (string)$curVer);
        if ($versionOrder === null) {
            $fail = '版本号格式无法比较：更新源与当前版本均应为数字.数字（例如 1.20）';
            break;
        }
        if ($versionOrder < 0) {
            $fail = '待更新版本 v' . $info['version'] . ' 低于当前 v' . $curVer . '，已终止';
            break;
        }
        if (!empty($info['min_php']) && version_compare(PHP_VERSION, $info['min_php'], '<')) {
            $fail = '该版本要求 PHP >= ' . $info['min_php'] . '，当前为 ' . PHP_VERSION;
            break;
        }

        list($ok, $derr) = downloadFile($info['url'], $tmp);
        if (!$ok) { $fail = '下载失败：' . $derr; break; }
        $steps[] = '下载完成（' . formatBytes(filesize($tmp)) . '）';

        list($packageOk, $packageMsg) = validateZipPackage($tmp);
        if (!$packageOk) {
            @unlink($tmp);
            $fail = '压缩包校验不通过：' . $packageMsg;
            break;
        }
        if (!verifyFileHash($tmp, $info['sha256'] ?? '')) {
            @unlink($tmp);
            $fail = 'SHA256 校验不通过，文件可能被篡改或下载不完整';
            break;
        }
        $steps[] = '压缩包结构及 SHA256 校验通过';

        $bak = backupSite('preupdate');
        if (!$bak) { @unlink($tmp); $fail = '自动备份失败，出于安全考虑已终止更新'; break; }
        $steps[] = '已自动备份：' . basename($bak);

        global $PROTECT_FILES, $PROTECT_DIRS;
        $n = extractZipSafe($tmp, ROOT_DIR, $PROTECT_FILES, $PROTECT_DIRS);
        if (!$n) {
            $fail = '解压失败，未写入任何文件';
            break;
        }
        $steps[] = '已更新 ' . (int)$n . ' 个文件';

        $up = ROOT_DIR . '/upgrade.php';
        if (is_file($up)) {
            try { include $up; $steps[] = '已执行 upgrade.php 数据升级脚本'; }
            catch (\Throwable $e) { $steps[] = 'upgrade.php 执行异常：' . $e->getMessage(); }
            @unlink($up);
        }

        setSiteVersion($info['version']);
        if (function_exists('opcache_reset')) { @opcache_reset(); }
        $steps[] = '版本号已更新为 v' . $info['version'] . '，opcache 已重置';
        @unlink($tmp);
    } while (false);

    return array('ok' => !$fail, 'steps' => $steps, 'fail' => $fail, 'bak' => $bak, 'tmp' => $tmp);
}

function buildUpdateFeedback($res, $newVer)
{
    if ($res['ok']) {
        $m = '🎉 更新成功，当前版本 v' . htmlspecialchars($newVer);
        $t = 'success';
    } else {
        $rolled = '';
        if ($res['bak'] && strpos(implode('|', $res['steps']), '已更新') !== false) {
            $r = restoreBackup(basename($res['bak']));
            $rolled = $r ? '，已自动回滚到更新前状态' : '，⚠ 自动回滚也失败了，请到「备份与恢复」手动恢复 ' . basename($res['bak']);
        }
        @unlink($res['tmp']);
        $m = '❌ 更新失败：' . htmlspecialchars($res['fail']) . $rolled;
        $t = 'danger';
    }
    $m .= '<div style="margin-top:8px;font-size:12px;line-height:1.9;">'
        . implode('<br>', array_map(function ($s) { return '· ' . htmlspecialchars($s); }, $res['steps']))
        . '</div>';
    return array($m, $t);
}

function buildUpdateSourceErrorFeedback($err)
{
    if (stripos((string)$err, 'HTTP 404') !== false) {
        return array(
            'ℹ 当前更新源还没有版本包。首次使用请先点击「前往备份管理」，上传并暂存一个版本包；暂存成功后再回来检查更新。若已上传过仍出现此提示，请检查更新源地址和版本库记录。',
            'info'
        );
    }
    return array('❌ ' . $err, 'danger');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = '提交来源不合法，请重试。';
        $msgType = 'danger';
    } else {
        try {
        $action = $_POST['action'] ?? '';

        if ($action === 'savesrc') {
            $u = trim($_POST['update_url'] ?? '');
            if ($u !== '' && !preg_match('#^https?://#i', $u)) {
                $msg = '更新源地址必须以 http:// 或 https:// 开头。';
                $msgType = 'danger';
            } else {
                setConf('update_url', $u);
                setConf('update_autocheck', isset($_POST['autocheck']) ? '1' : '0');
                $updateUrl = $u;
                $autoCheck = isset($_POST['autocheck']) ? '1' : '0';
                $msg = '✅ 更新源已保存。';
                $msgType = 'success';
            }
        }

        if ($action === 'check') {
            list($info, $err) = fetchRemoteVersion($updateUrl);
            if (!$info) {
                list($msg, $msgType) = buildUpdateSourceErrorFeedback($err);
            } else {
                $remote = $info;
                $versionOrder = compareUpdateVersions((string)$info['version'], (string)$current);
                if ($versionOrder === null) {
                    $msg = '❌ 版本号格式无法比较：更新源与当前版本均应为数字.数字（例如 1.20）';
                    $msgType = 'danger';
                } elseif ($versionOrder > 0) {
                    $msg = '🎉 发现新版本 v' . htmlspecialchars($info['version']) . '（当前 v' . htmlspecialchars($current) . '）';
                    $msgType = 'success';
                } elseif (!empty($info['pending'])) {
                    $msg = '📦 已拉取到待更新包 v' . htmlspecialchars($info['pending']['version']) . '，请确认后更新。';
                    $msgType = 'success';
                } else {
                    $msg = '✅ 已是最新版本 v' . htmlspecialchars($current) . '。';
                    $msgType = 'success';
                }
            }
        }

        if ($action === 'confirmupdate') {
            list($info, $err) = fetchRemoteVersion($updateUrl);
            $pending = ($info && !empty($info['pending'])) ? $info['pending'] : null;
            if (!$info) {
                list($msg, $msgType) = buildUpdateSourceErrorFeedback($err);
            } elseif (!$pending) {
                $msg = '当前没有待更新的压缩包。';
                $msgType = 'danger';
            } else {
                $res = applyRemoteUpdate($pending, $current);
                if ($res['ok']) {
                    $newVer = $pending['version'];
                    if (function_exists('activateVersion')) {
                        $pid = (int)($pending['id'] ?? 0);
                        if ($pid > 0) { activateVersion($pid); }
                        else { regenerateUpdateJson(); }
                    }
                    $current = $newVer;
                    writeUpdateLog('手动确认更新成功 → v' . $newVer . '｜' . implode('；', $res['steps'])
                        . '｜' . formatUpdateNotesForLog($pending['notes'] ?? ''));
                } else {
                    writeUpdateLog('手动确认更新失败：' . $res['fail']);
                }
                list($m, $t) = buildUpdateFeedback($res, $pending['version']);
                $msg = $m;
                $msgType = $t;
            }
        }

        if ($action === 'doupdate') {
            list($info, $err) = fetchRemoteVersion($updateUrl);
            if (!$info) {
                list($msg, $msgType) = buildUpdateSourceErrorFeedback($err);
            } else {
                $res = applyRemoteUpdate($info, $current);
                if ($res['ok']) {
                    $current = $info['version'];
                    writeUpdateLog('更新成功 → v' . $current . '｜' . implode('；', $res['steps'])
                        . '｜' . formatUpdateNotesForLog($info['notes'] ?? ''));
                } else {
                    writeUpdateLog('更新失败：' . $res['fail']);
                }
                list($m, $t) = buildUpdateFeedback($res, $info['version']);
                $msg = $m;
                $msgType = $t;
            }
        }
        } catch (\Throwable $e) {
            $msg = '⚠ 操作失败，请查看服务器日志后重试。';
            $msgType = 'danger';
            error_log('Cloud update operation failed: ' . $e->getMessage());
            if (function_exists('writeUpdateLog')) { writeUpdateLog('云更新操作异常'); }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $autoCheck === '1' && $updateUrl) {
    list($info, $err) = fetchRemoteVersion($updateUrl);
    if ($info) { $remote = $info; }
    elseif (stripos((string)$err, 'HTTP 404') !== false) { list($msg, $msgType) = buildUpdateSourceErrorFeedback($err); }
}

$remoteVersionOrder = $remote ? compareUpdateVersions((string)$remote['version'], (string)$current) : null;
$hasNew = $remoteVersionOrder !== null && $remoteVersionOrder > 0;
$logs = readUpdateLog(20);

$pending = ($remote && is_array($remote['pending'])) ? $remote['pending'] : null;
$pendingExists = !empty($pending);
$hasPending = $pendingExists && !empty($pending['version']) && !empty($pending['url'])
    && !empty($pending['sha256']) && preg_match('/^[a-f0-9]{64}$/i', (string)$pending['sha256']);

require_once 'head.php';
?>
<style>
  .panel-update-pending{border-color:color-mix(in srgb,var(--accent) 45%,transparent)!important;
    box-shadow:0 8px 32px rgba(99,102,241,.18)!important}
  .btn-confirm-update{background:linear-gradient(120deg,var(--accent),#06b6d4);border:none;color:#fff;
    font-weight:700;box-shadow:0 8px 22px rgba(99,102,241,.35)}
  .btn-confirm-update:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(99,102,241,.45)}
  .btn-confirm-update:disabled{opacity:.6;cursor:not-allowed;transform:none;box-shadow:none}

  .pending-action-bar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;
    gap:10px;margin-top:16px;padding:12px 14px;border-radius:12px;
    background:linear-gradient(90deg, rgba(99,102,241,.10), rgba(6,182,212,.10));
    border:1px dashed rgba(99,102,241,.35)}
  .pending-action-bar .pending-info{font-size:13px;color:#334155}
  .pending-action-bar .pending-btns{display:flex;gap:8px;flex-wrap:wrap}

  .update-modal{position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;
    background:rgba(15,23,42,.55);backdrop-filter:blur(4px);padding:16px}
  .update-modal.open{display:flex}
  .update-modal-box{width:100%;max-width:560px;max-height:90vh;overflow:auto;border-radius:18px;
    background:rgba(255,255,255,.92);-webkit-backdrop-filter:blur(20px) saturate(180%);
    backdrop-filter:blur(20px) saturate(180%);border:1px solid rgba(255,255,255,.7);
    box-shadow:0 24px 80px rgba(15,23,42,.35);animation:modalIn .25s ease}
  .update-modal-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;
    border-bottom:1px solid rgba(0,0,0,.06)}
  .update-modal-header h4{margin:0;font-size:16px;font-weight:700;color:#1e293b}
  .update-modal-body{padding:18px 20px}
  .update-modal-footer{display:flex;justify-content:flex-end;gap:10px;padding:14px 20px;
    border-top:1px solid rgba(0,0,0,.06)}
  .update-modal .close-x{background:transparent;border:none;font-size:22px;color:#64748b;cursor:pointer}
  .update-modal .close-x:hover{color:#1e293b}
  .update-modal .info-row{display:flex;gap:10px;margin-bottom:10px;font-size:13px;line-height:1.6}
  .update-modal .info-row .k{width:90px;color:#64748b;flex-shrink:0}
  .update-modal .info-row .v{flex:1;color:#334155;word-break:break-all}
  .update-modal .info-row .v pre{white-space:pre-wrap;margin:0;background:transparent;border:none;padding:0;font:inherit}
  @keyframes modalIn{from{opacity:0;transform:translateY(16px) scale(.97)}to{opacity:1;transform:none}}
  @media (max-width:640px){
    .update-modal-box{max-width:100%;border-radius:14px}
    .update-modal .info-row{flex-direction:column;gap:2px}
    .update-modal .info-row .k{width:auto}
    .pending-action-bar{flex-direction:column;align-items:flex-start}
  }
</style>
<?php if ($msg): ?>
    <div class="alert alert-<?php echo $msgType; ?>"><?php echo $msg; ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-sm-7">
        <div class="panel panel-default">
            <div class="panel-heading">☁️ 云更新</div>
            <div class="panel-body">
                <p style="font-size:14px;">
                    当前版本：<b style="font-size:18px;color:var(--accent);">v<?php echo htmlspecialchars($current); ?></b>
                    <span class="text-muted" style="font-size:12px;margin-left:10px;">PHP <?php echo PHP_VERSION; ?></span>
                </p>

                <?php if (!$updateUrl): ?>
                    <div class="alert alert-danger" style="margin-bottom:0;">还没有配置更新源，先在右侧填入 <code>update.json</code> 的地址。</div>
                <?php elseif ($remote): ?>
                    <table class="table table-bordered" style="margin-bottom:14px;">
                        <tr><th style="width:110px;">远端版本</th><td>v<?php echo htmlspecialchars($remote['version']); ?>
                            <?php if ($hasNew): ?><span class="label label-success">有新版本</span><?php else: ?><span class="label label-default">已是最新</span><?php endif; ?>
                        </td></tr>
                        <tr><th>发布日期</th><td><?php echo htmlspecialchars($remote['date'] ?? '—'); ?></td></tr>
                        <tr><th>更新说明</th><td style="white-space:pre-wrap;"><?php echo htmlspecialchars($remote['notes'] ?? '—'); ?></td></tr>
                        <tr><th>校验方式</th><td><?php echo !empty($remote['sha256']) ? 'SHA256 <code style="font-size:11px;">' . htmlspecialchars(substr($remote['sha256'], 0, 24)) . '…</code>' : '<span class="text-danger">未提供，禁止更新</span>'; ?></td></tr>
                    </table>
                <?php endif; ?>

                <form id="checkUpdateForm" method="post" style="display:inline;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="check">
                    <button class="btn btn-default" <?php echo $updateUrl ? '' : 'disabled'; ?>>🔍 检查更新</button>
                </form>
                <?php if ($hasNew): ?>
                    <form method="post" style="display:inline;" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerHTML='⏳ 更新中，请勿关闭页面…';return true;">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="doupdate">
                        <button class="btn btn-primary">🚀 一键更新到 v<?php echo htmlspecialchars($remote['version']); ?></button>
                    </form>
                <?php endif; ?>
                <a href="backup.php" class="btn btn-link btn-sm">前往备份管理 →</a>

                <?php if ($pendingExists): ?>
                <div class="pending-action-bar" id="pendingActionBar">
                    <span class="pending-info">📦 检测到待更新包 <b>v<?php echo htmlspecialchars($pending['version'] ?? ''); ?></b>，请确认后应用。</span>
                    <span class="pending-btns">
                        <button type="button" class="btn btn-confirm-update btn-sm" onclick="openPendingModal()">✅ 确认更新</button>
                        <button type="button" class="btn btn-default btn-sm" onclick="dismissPending()">❌ 取消</button>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($pendingExists): ?>
        <!-- 待更新压缩包：需手动确认 -->
        <div class="panel panel-update-pending" id="pendingInlinePanel">
            <div class="panel-heading">📦 待更新压缩包（需手动确认）</div>
            <div class="panel-body">
                <?php if (!$hasPending): ?>
                <div class="alert alert-warning" style="margin-bottom:12px;">
                    检测到远端有待更新包 <b>v<?php echo htmlspecialchars($pending['version'] ?? ''); ?></b>，但其版本不高于当前版本 <b>v<?php echo htmlspecialchars($current); ?></b>，无法执行更新。请检查更新源或清理旧 pending。
                </div>
                <?php else: ?>
                <p style="font-size:13px;color:#475569;margin-bottom:12px;line-height:1.7;">
                    更新管理端已上传一个新版本包。请核对信息，确认无误后点击下方按钮应用更新。点击后将执行：
                    <b>下载压缩包 → SHA256 校验 → 自动备份 → 安全解压 → 写版本号</b>，任意步骤失败将自动回滚，站点始终保持可用。
                </p>
                <?php endif; ?>
                <table class="table table-bordered" style="margin-bottom:14px;">
                    <tr><th style="width:110px;">待更新版本</th><td>v<?php echo htmlspecialchars($pending['version'] ?? ''); ?> <?php if ($hasPending): ?><span class="label label-warning">待确认</span><?php else: ?><span class="label label-default">不可应用</span><?php endif; ?></td></tr>
                    <tr><th>发布日期</th><td><?php echo htmlspecialchars($pending['date'] ?? '—'); ?></td></tr>
                    <tr><th>更新说明</th><td style="white-space:pre-wrap;"><?php echo htmlspecialchars($pending['notes'] ?? '—'); ?></td></tr>
                    <tr><th>完整性校验</th><td><?php echo !empty($pending['sha256']) ? 'SHA256 <code style="font-size:11px;">' . htmlspecialchars(substr($pending['sha256'], 0, 24)) . '…</code>' : '<span class="text-danger">未提供，禁止更新</span>'; ?></td></tr>
                    <tr><th>压缩包文件</th><td><code><?php echo htmlspecialchars($pending['file'] ?? ''); ?></code></td></tr>
                </table>
                <form id="confirmForm" method="post" style="display:inline;" onsubmit="return onConfirmUpdate(this);">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="confirmupdate">
                    <button id="confirmBtn" class="btn btn-confirm-update" type="submit" <?php echo $hasPending ? '' : 'disabled title="待更新包版本不高于当前版本，无法确认"'; ?>>✅ 确认更新到 v<?php echo htmlspecialchars($pending['version'] ?? ''); ?></button>
                    <button type="button" class="btn btn-default" onclick="dismissPending()">❌ 取消 / 暂不更新</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="panel panel-default">
            <div class="panel-heading">更新日志（最近 20 条）</div>
            <div class="panel-body" style="font-size:12px;line-height:1.9;max-height:280px;overflow:auto;">
                <?php if (!$logs): ?>
                    <span class="text-muted">暂无记录。</span>
                <?php else: foreach ($logs as $l): ?>
                    <div style="border-bottom:1px dashed rgba(0,0,0,.06);padding:3px 0;word-break:break-all;"><?php echo htmlspecialchars($l); ?></div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <div class="col-sm-5">
        <div class="panel panel-default">
            <div class="panel-heading">更新源设置</div>
            <div class="panel-body">
                <form method="post">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="savesrc">
                    <div class="form-group">
                        <label>update.json 地址</label>
                        <input type="text" name="update_url" class="form-control" placeholder="https://your-domain.com/release/update.json"
                               value="<?php echo htmlspecialchars($updateUrl); ?>">
                        <p class="help-block" style="font-size:12px;">指向你自己的静态托管地址（对象存储 / GitHub Raw / 另一台服务器都行）。</p>
                    </div>
                    <div class="checkbox">
                        <label><input type="checkbox" name="autocheck" <?php echo $autoCheck === '1' ? 'checked' : ''; ?>> 打开本页时自动检查更新</label>
                    </div>
                    <button class="btn btn-primary">💾 保存</button>
                </form>
            </div>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">🔒 更新时受保护（永不覆盖）</div>
            <div class="panel-body" style="font-size:12px;line-height:2;">
                文件：<?php foreach ($PROTECT_FILES as $f) { echo '<code>' . htmlspecialchars($f) . '</code> '; } ?><br>
                目录：<?php foreach ($PROTECT_DIRS as $d) { echo '<code>' . htmlspecialchars($d) . '/</code> '; } ?><br>
                <hr style="margin:10px 0;">
                安全机制：SHA256 完整性校验 · Zip Slip 路径穿越防护 · 更新前自动全量备份 · 失败自动回滚 · 仅登录管理员可操作。
            </div>
        </div>
    </div>
</div>
<?php if ($pendingExists): ?>
<!-- 确认更新弹窗（自动弹出，含确认/取消按钮，z-index 置顶） -->
<div class="update-modal" id="updateModal" aria-hidden="true">
  <div class="update-modal-box" role="dialog" aria-modal="true" aria-labelledby="umTitle">
    <div class="update-modal-header">
      <h4 id="umTitle">📦 确认应用待更新包</h4>
      <button type="button" class="close-x" aria-label="关闭" onclick="dismissPending()">×</button>
    </div>
    <div class="update-modal-body">
      <div class="info-row"><span class="k">待更新版本</span><span class="v">v<?php echo htmlspecialchars($pending['version'] ?? ''); ?></span></div>
      <div class="info-row"><span class="k">发布日期</span><span class="v"><?php echo htmlspecialchars($pending['date'] ?? '—'); ?></span></div>
      <div class="info-row"><span class="k">更新说明</span><span class="v"><pre><?php echo htmlspecialchars($pending['notes'] ?? '—'); ?></pre></span></div>
      <div class="info-row"><span class="k">完整性校验</span><span class="v"><?php echo !empty($pending['sha256']) ? 'SHA256 ' . htmlspecialchars(substr($pending['sha256'], 0, 24)) . '…' : '未提供，不推荐'; ?></span></div>
      <div class="info-row"><span class="k">压缩包</span><span class="v"><code><?php echo htmlspecialchars($pending['file'] ?? ''); ?></code></span></div>
      <p style="font-size:12px;color:#64748b;margin:6px 0 0;line-height:1.7;">
        点击「确认更新」后将执行：<b>下载 → SHA256 校验 → 自动备份 → 安全解压 → 写版本号</b>，任一步失败自动回滚，站点保持可用。
      </p>
    </div>
    <div class="update-modal-footer">
      <button type="button" class="btn btn-default" onclick="dismissPending()">❌ 取消</button>
      <button type="button" id="umConfirmBtn" class="btn btn-confirm-update" onclick="submitConfirm()"<?php echo $hasPending ? '' : ' disabled title="待更新版本不高于当前版本，无法确认"'; ?>>
        ✅ 确认更新<?php echo $hasPending ? ' 到 v' . htmlspecialchars($pending['version'] ?? '') : ''; ?>
      </button>
    </div>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('updateModal');
  var bar = document.getElementById('pendingActionBar');
  var inline = document.getElementById('pendingInlinePanel');
  var pendingVersion = <?php echo json_encode($pending['version'] ?? '', JSON_UNESCAPED_UNICODE); ?>;
  var storageKey = 'dismissed_update_' + pendingVersion;

  function hidePendingUi() {
    if (modal) { modal.classList.remove('open'); modal.setAttribute('aria-hidden', 'true'); }
    if (bar) { bar.style.display = 'none'; }
    if (inline) { inline.style.display = 'none'; }
  }

  function openPendingModal() {
    if (pendingVersion && sessionStorage.getItem(storageKey)) {
      hidePendingUi();
      return;
    }
    if (!modal) return;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
  }
  window.openPendingModal = openPendingModal;

    window.dismissPending = function () {
      if (pendingVersion) { sessionStorage.setItem(storageKey, '1'); }
      hidePendingUi();
    };

    var checkUpdateForm = document.getElementById('checkUpdateForm');
    if (checkUpdateForm) {
      checkUpdateForm.addEventListener('submit', function () {
        if (pendingVersion) { sessionStorage.removeItem(storageKey); }
      });
    }

    window.submitConfirm = function () {
    var btn = document.getElementById('umConfirmBtn');
    var form = document.getElementById('confirmForm');
    if (!form) { openPendingModal(); return; }
    if (btn) { btn.disabled = true; btn.innerHTML = '⏳ 更新中，请勿关闭页面…'; }
    if (bar) { bar.style.display = 'none'; }
    form.submit();
  };

    window.onConfirmUpdate = function (form) {
    var btn = form.querySelector('button[type=submit]');
    if (btn) { btn.disabled = true; btn.innerHTML = '⏳ 更新中，请勿关闭页面…'; }
    if (modal) { modal.classList.remove('open'); modal.setAttribute('aria-hidden', 'true'); }
    return true;
  };

  if (modal) {
    modal.addEventListener('click', function (e) { if (e.target === modal) dismissPending(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') dismissPending(); });
  }

    <?php if ($pendingExists && $hasPending): ?>
  window.addEventListener('load', function () {
    if (pendingVersion && sessionStorage.getItem(storageKey)) {
      hidePendingUi();
    } else {
      openPendingModal();
    }
  });
  <?php endif; ?>
})();
</script>
<?php endif; ?>
<?php
require_once 'foot.php';
