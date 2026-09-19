<?php
/**
 * 后台 · 留言管理
 * 留言列表、审核、回复与删除。
 */

// Guestbook moderation.
require_once '../includes/common.php';

if (!$islogin) {
    header('Location: login.php');
    exit;
}

$csrfError = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $csrfError = true;
    } else {
        $action = $_POST['action'] ?? '';
        $id     = (int)($_POST['id'] ?? 0);

        if ($action === 'pass' && $id) {
            $DB->update('guestbook', array('status' => 1), array('id' => $id));
        } elseif ($action === 'spam' && $id) {
            $DB->update('guestbook', array('status' => 2), array('id' => $id));
        } elseif ($action === 'hide' && $id) {
            $DB->update('guestbook', array('status' => 0), array('id' => $id));
        } elseif ($action === 'del' && $id) {
            $DB->delete('guestbook', array('id' => $id));
        } elseif ($action === 'reply' && $id) {
            $reply = mb_substr(trim((string)($_POST['reply'] ?? '')), 0, 500);
            $DB->update('guestbook', array('reply' => $reply), array('id' => $id));
        } elseif ($action === 'setting') {
            setConf('guestbook_on', isset($_POST['guestbook_on']) ? '1' : '0');
            setConf('guestbook_auto', isset($_POST['guestbook_auto']) ? '1' : '0');
        } elseif ($action === 'clearspam') {
            $DB->delete('guestbook', array('status' => 2));
        }

        header('Location: guestbook.php?st=' . (int)($_POST['back_st'] ?? 0) . '&saved=1');
        exit;
    }
}

$st = isset($_GET['st']) ? (int)$_GET['st'] : 0;
if (!in_array($st, array(0, 1, 2), true)) { $st = 0; }

$rows = $DB->findAll('guestbook', '*', array('status' => $st), 'id DESC', 100);
if (!is_array($rows)) { $rows = array(); }

$cnt = array(
    0 => (int)$DB->count('guestbook', array('status' => 0)),
    1 => (int)$DB->count('guestbook', array('status' => 1)),
    2 => (int)$DB->count('guestbook', array('status' => 2)),
);
$tabName = array(0 => '待审核', 1 => '已通过', 2 => '垃圾箱');

require_once 'head.php';
?>
<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">✅ 操作完成。</div><?php endif; ?>
<?php if ($csrfError): ?><div class="alert alert-danger">提交校验失败，请刷新页面后重试。</div><?php endif; ?>

<div class="row">
    <div class="col-sm-8">
        <div class="panel panel-default">
            <div class="panel-heading" style="display:flex;gap:8px;align-items:center;">
                <?php foreach ($tabName as $k => $v): ?>
                    <a href="guestbook.php?st=<?php echo $k; ?>" class="btn btn-xs <?php echo $st === $k ? 'btn-primary' : 'btn-default'; ?>">
                        <?php echo $v; ?> (<?php echo $cnt[$k]; ?>)
                    </a>
                <?php endforeach; ?>
                <?php if ($st === 2 && $cnt[2] > 0): ?>
                    <form method="post" style="margin-left:auto;" onsubmit="return confirm('清空垃圾箱？不可撤销。');">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="clearspam"><input type="hidden" name="back_st" value="2">
                        <button class="btn btn-danger btn-xs">清空垃圾箱</button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="panel-body">
                <?php if (!$rows): ?>
                    <p class="text-muted" style="text-align:center;padding:24px 0;">「<?php echo $tabName[$st]; ?>」暂无留言。</p>
                <?php else: foreach ($rows as $r): ?>
                    <div style="border:1px solid rgba(255,255,255,.6);background:rgba(255,255,255,.4);border-radius:12px;padding:14px;margin-bottom:12px;">
                        <div style="display:flex;justify-content:space-between;align-items:baseline;">
                            <b><?php echo htmlspecialchars($r['nickname']); ?></b>
                            <span class="text-muted" style="font-size:12px;"><?php echo htmlspecialchars((string)$r['created_at']); ?></span>
                        </div>
                        <?php if ($r['contact']): ?>
                            <div class="text-muted" style="font-size:12px;">联系方式：<?php echo htmlspecialchars($r['contact']); ?></div>
                        <?php endif; ?>
                        <div style="margin:8px 0;white-space:pre-wrap;word-break:break-word;"><?php echo htmlspecialchars($r['content']); ?></div>
                        <?php if ($r['reply']): ?>
                            <div style="background:color-mix(in srgb, var(--accent) 10%, transparent);border-radius:8px;padding:8px 10px;font-size:13px;margin-bottom:8px;">
                                <b style="color:var(--accent);">站长回复：</b><span style="white-space:pre-wrap;"><?php echo htmlspecialchars($r['reply']); ?></span>
                            </div>
                        <?php endif; ?>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                            <?php if ($st !== 1): ?>
                                <form method="post" style="display:inline;"><?php echo csrfField(); ?><input type="hidden" name="action" value="pass"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="back_st" value="<?php echo $st; ?>"><button class="btn btn-success btn-xs">通过</button></form>
                            <?php else: ?>
                                <form method="post" style="display:inline;"><?php echo csrfField(); ?><input type="hidden" name="action" value="hide"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="back_st" value="<?php echo $st; ?>"><button class="btn btn-default btn-xs">撤回审核</button></form>
                            <?php endif; ?>
                            <?php if ($st !== 2): ?>
                                <form method="post" style="display:inline;"><?php echo csrfField(); ?><input type="hidden" name="action" value="spam"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="back_st" value="<?php echo $st; ?>"><button class="btn btn-warning btn-xs">标记垃圾</button></form>
                            <?php endif; ?>
                            <form method="post" style="display:inline;" onsubmit="return confirm('彻底删除该留言？');"><?php echo csrfField(); ?><input type="hidden" name="action" value="del"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="back_st" value="<?php echo $st; ?>"><button class="btn btn-danger btn-xs">删除</button></form>
                            <form method="post" style="display:flex;gap:6px;flex:1;min-width:240px;">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="reply"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="back_st" value="<?php echo $st; ?>">
                                <input type="text" name="reply" class="form-control input-sm" placeholder="回复内容…" value="<?php echo htmlspecialchars($r['reply']); ?>">
                                <button class="btn btn-primary btn-xs">回复</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <div class="col-sm-4">
        <div class="panel panel-default">
            <div class="panel-heading">留言板设置</div>
            <div class="panel-body">
                <form method="post">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="setting"><input type="hidden" name="back_st" value="<?php echo $st; ?>">
                    <div class="checkbox"><label><input type="checkbox" name="guestbook_on" <?php echo conf('guestbook_on', '1') === '1' ? 'checked' : ''; ?>> 前台显示留言板</label></div>
                    <div class="checkbox"><label><input type="checkbox" name="guestbook_auto" <?php echo conf('guestbook_auto', '0') === '1' ? 'checked' : ''; ?>> 新留言免审核直接显示</label></div>
                    <p class="help-block" style="font-size:12px;">建议保持「需要审核」，避免垃圾广告直接出现在首页。</p>
                    <button class="btn btn-primary">💾 保存设置</button>
                </form>
            </div>
        </div>
        <div class="panel panel-default">
            <div class="panel-heading">防护说明</div>
            <div class="panel-body" style="font-size:12px;line-height:1.9;color:#64748b;">
                已启用：同源校验 · 蜜罐字段 · 同 IP 60 秒限流 · 长度限制 · 输出转义（前台用 textContent 渲染，无 XSS）。<br>
                只保存 IP 哈希用于限流，不存明文 IP。
            </div>
        </div>
    </div>
</div>
<?php
require_once 'foot.php';
