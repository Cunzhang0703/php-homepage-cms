<?php
/**
 * 后台 · 账号设置
 * 修改登录密码；改密后使其他已登录会话失效。
 */

// Password change screen.
require_once '../includes/common.php';
require_once 'head.php';

$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = '提交来源不合法，请重试。';
        $msgType = 'danger';
    } else {
        $old = $_POST['old_pwd'] ?? '';
        $new = $_POST['new_pwd'] ?? '';
        $new2 = $_POST['new_pwd2'] ?? '';

        if ($old !== '' && $old === $new) {
            $msg = '新密码不能与旧密码相同。';
            $msgType = 'danger';
        }
        elseif ($new === '') {
            $msg = '请输入新密码。';
            $msgType = 'danger';
        }
        elseif ($new !== $new2) {
            $msg = '两次输入的新密码不一致。';
            $msgType = 'danger';
        }
        elseif (strlen($new) < 6) {
            $msg = '新密码至少 6 位。';
            $msgType = 'danger';
        } else {
            $row = $DB->find('admin', 'username,password_hash', array('username' => $admin_user));
            if (!verifyAdminCredentials($admin_user, $old, $row)) {
                $msg = '旧密码错误。';
                $msgType = 'danger';
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                $DB->update('admin', array('password_hash' => $hash), array('username' => $admin_user));
                // Invalidate existing login cookies after a password change.
                setConf('syskey', randomStr(64));
                clearAdminAuthCookie();
                writeSecurityLog('password changed for account ' . strtolower($admin_user));
                $msg = '✅ 密码已修改，所有旧登录状态已失效，请使用新密码重新登录。';
                $msgType = 'success';
            }
        }
    }
}

$version = htmlspecialchars(getSiteVersion());
?>
<?php if ($msg): ?>
    <div class="alert alert-<?php echo $msgType; ?>"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>

<div class="panel panel-default">
    <div class="panel-heading">修改管理员密码</div>
    <div class="panel-body">
        <p class="text-muted" style="font-size:12px;">当前登录账号：<b><?php echo htmlspecialchars($admin_user); ?></b> ｜ 系统版本：<b><?php echo $version; ?></b></p>
        <form method="post" action="profile.php">
            <?php echo csrfField(); ?>
            <div class="form-group">
                <label>旧密码</label>
                <input type="password" name="old_pwd" class="form-control" autocomplete="off" required>
            </div>
            <div class="form-group">
                <label>新密码（至少 6 位）</label>
                <input type="password" name="new_pwd" class="form-control" autocomplete="off" required>
            </div>
            <div class="form-group">
                <label>再次输入新密码</label>
                <input type="password" name="new_pwd2" class="form-control" autocomplete="off" required>
            </div>
            <button class="btn btn-primary">💾 保存新密码</button>
        </form>
    </div>
</div>
<script>
(function(){
  var form = document.querySelector('form');
  if (!form || !form.old_pwd) return;
  form.addEventListener('submit', function(e){
    var old = form.old_pwd.value;
    var n1  = form.new_pwd.value;
    var n2  = form.new_pwd2.value;
    if (old !== '' && old === n1) { alert('新密码不能与旧密码相同'); e.preventDefault(); return; }
    if (n1 === '') { alert('请输入新密码'); e.preventDefault(); return; }
    if (n1 !== n2) { alert('两次输入的新密码不一致'); e.preventDefault(); return; }
  });
})();
</script>
<?php
require_once 'foot.php';
