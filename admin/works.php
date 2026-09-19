<?php
/**
 * 作品墙管理 · 前台项目/作品卡片数据源（表 pre_works）
 * 封面支持上传图片或填写外链 URL。
 */
require_once '../includes/common.php';

if (!$islogin) {
    header('Location: login.php');
    exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $err = 'nocsrf';
    } else {
        $action = $_POST['action'] ?? '';
        $id     = (int)($_POST['id'] ?? 0);

        if ($action === 'add' || $action === 'edit') {
            $title   = trim((string)($_POST['title'] ?? ''));
            $summary = trim((string)($_POST['summary'] ?? ''));
            $url     = trim((string)($_POST['url'] ?? ''));
            $tags    = trim((string)($_POST['tags'] ?? ''));
            $sort    = (int)($_POST['sort'] ?? 0);
            $en      = isset($_POST['enabled']) ? 1 : 0;

            if ($title === '') {
                $err = 'empty';
            } elseif ($url !== '' && !preg_match('#^(https?://|/|\#)#i', $url)) {
                $err = 'badurl';
            } else {
                $old = $id ? $DB->find('works', 'cover', array('id' => $id)) : null;
                $cover = $old ? $old['cover'] : '';

                if (!empty($_POST['cover_clear'])) {
                    deleteAvatarFile($cover);
                    $cover = '';
                } elseif (!empty($_FILES['cover_file']) && $_FILES['cover_file']['error'] === UPLOAD_ERR_OK) {
                    $p = saveUploadedImage($_FILES['cover_file'], 'work');
                    if ($p) { deleteAvatarFile($cover); $cover = $p; }
                    else { $err = 'badimg'; }
                } elseif (!empty($_POST['cover_url'])) {
                    deleteAvatarFile($cover);
                    $cover = trim((string)$_POST['cover_url']);
                }

                if (!$err) {
                    $data = array(
                        'title'   => mb_substr($title, 0, 120),
                        'summary' => mb_substr($summary, 0, 255),
                        'cover'   => mb_substr($cover, 0, 255),
                        'url'     => mb_substr($url, 0, 255),
                        'tags'    => mb_substr($tags, 0, 160),
                        'sort'    => $sort, 'enabled' => $en,
                    );
                    if ($action === 'add') {
                        $data['created_at'] = 'NOW()';
                        $DB->insert('works', $data);
                    } else {
                        $DB->update('works', $data, array('id' => $id));
                    }
                }
            }
        } elseif ($action === 'del' && $id) {
            $row = $DB->find('works', 'cover', array('id' => $id));
            if ($row) { deleteAvatarFile($row['cover']); }
            $DB->delete('works', array('id' => $id));
        } elseif ($action === 'toggle' && $id) {
            $row = $DB->find('works', 'enabled', array('id' => $id));
            $DB->update('works', array('enabled' => $row && (int)$row['enabled'] === 1 ? 0 : 1), array('id' => $id));
        }
    }
    if (!$err) {
        header('Location: works.php?saved=1');
        exit;
    }
}

$editId  = (int)($_GET['edit'] ?? 0);
$editRow = $editId ? $DB->find('works', '*', array('id' => $editId)) : null;
$rows    = $DB->findAll('works', '*', array(), 'sort ASC, id DESC');
if (!is_array($rows)) { $rows = array(); }

require_once 'head.php';
?>
<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">✅ 已保存。</div><?php endif; ?>
<?php if ($err === 'empty'): ?><div class="alert alert-danger">作品标题不能为空。</div><?php endif; ?>
<?php if ($err === 'badurl'): ?><div class="alert alert-danger">作品链接格式不合法。</div><?php endif; ?>
<?php if ($err === 'badimg'): ?><div class="alert alert-danger">封面上传失败：仅支持 jpg / png / webp / gif，且不超过 5MB。</div><?php endif; ?>
<?php if ($err === 'nocsrf'): ?><div class="alert alert-danger">提交来源不合法，请重试。</div><?php endif; ?>

<div class="row">
    <div class="col-sm-7">
        <div class="panel panel-default">
            <div class="panel-heading">🖼️ 作品列表（<?php echo count($rows); ?>）</div>
            <div class="panel-body" style="padding:0;">
                <table class="table table-bordered" style="margin:0;">
                    <thead><tr><th style="width:78px;">封面</th><th>标题 / 简介</th><th style="width:64px;">状态</th><th style="width:110px;">操作</th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="4" class="text-muted" style="text-align:center;padding:24px;">还没有作品，用右侧表单添加。<br><span style="font-size:12px;">一条都没有时，前台不会显示作品墙区块。</span></td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr<?php echo (int)$r['enabled'] === 0 ? ' style="opacity:.45;"' : ''; ?>>
                            <td>
                                <?php if ($r['cover']): ?>
                                    <img src="<?php echo htmlspecialchars($r['cover']); ?>" style="width:62px;height:44px;object-fit:cover;border-radius:6px;">
                                <?php else: ?>
                                    <div style="width:62px;height:44px;border-radius:6px;background:#e2e8f0;color:#94a3b8;font-size:11px;display:flex;align-items:center;justify-content:center;">无图</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <b><?php echo htmlspecialchars($r['title']); ?></b>
                                <div class="text-muted" style="font-size:12px;"><?php echo htmlspecialchars($r['summary']); ?></div>
                                <?php if ($r['tags']): ?><div style="font-size:11px;color:var(--accent);">#<?php echo htmlspecialchars(str_replace(',', ' #', $r['tags'])); ?></div><?php endif; ?>
                            </td>
                            <td>
                                <form method="post" style="display:inline;">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                    <button class="btn btn-xs <?php echo (int)$r['enabled'] === 1 ? 'btn-success' : 'btn-default'; ?>"><?php echo (int)$r['enabled'] === 1 ? '显示' : '隐藏'; ?></button>
                                </form>
                            </td>
                            <td>
                                <a class="btn btn-default btn-xs" href="works.php?edit=<?php echo (int)$r['id']; ?>">编辑</a>
                                <form method="post" style="display:inline;" onsubmit="return confirm('删除该作品？封面文件会一并删除。');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="del"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                    <button class="btn btn-danger btn-xs">删除</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-sm-5">
        <div class="panel panel-default">
            <div class="panel-heading"><?php echo $editRow ? '✏️ 编辑作品 #' . (int)$editRow['id'] : '➕ 添加作品'; ?></div>
            <div class="panel-body">
                <form method="post" action="works.php" enctype="multipart/form-data">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="<?php echo $editRow ? 'edit' : 'add'; ?>">
                    <?php if ($editRow): ?><input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>"><?php endif; ?>
                    <div class="form-group">
                        <label>标题</label>
                        <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($editRow['title'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>一句话简介</label>
                        <textarea name="summary" class="form-control" rows="2"><?php echo htmlspecialchars($editRow['summary'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>作品链接（可空）</label>
                        <input type="text" name="url" class="form-control" placeholder="https://..." value="<?php echo htmlspecialchars($editRow['url'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>标签（英文逗号分隔）</label>
                        <input type="text" name="tags" class="form-control" placeholder="PHP,MySQL,前端" value="<?php echo htmlspecialchars($editRow['tags'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>封面</label>
                        <?php if (!empty($editRow['cover'])): ?>
                            <div style="margin-bottom:6px;"><img src="<?php echo htmlspecialchars($editRow['cover']); ?>" style="max-width:100%;max-height:110px;border-radius:8px;"></div>
                            <label style="font-weight:400;font-size:12px;"><input type="checkbox" name="cover_clear" value="1"> 移除当前封面</label>
                        <?php endif; ?>
                        <input type="file" name="cover_file" accept="image/*" class="form-control" style="padding:6px;">
                        <input type="text" name="cover_url" class="form-control" style="margin-top:6px;" placeholder="或填写图片 URL（留空不改）">
                    </div>
                    <div class="row">
                        <div class="col-xs-6 form-group">
                            <label>排序（小在前）</label>
                            <input type="number" name="sort" class="form-control" value="<?php echo (int)($editRow['sort'] ?? 0); ?>">
                        </div>
                        <div class="col-xs-6 form-group" style="padding-top:26px;">
                            <label><input type="checkbox" name="enabled" <?php echo (!$editRow || (int)$editRow['enabled'] === 1) ? 'checked' : ''; ?>> 前台显示</label>
                        </div>
                    </div>
                    <button class="btn btn-primary"><?php echo $editRow ? '💾 保存修改' : '➕ 添加'; ?></button>
                    <?php if ($editRow): ?><a href="works.php" class="btn btn-default">取消</a><?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>
<?php
require_once 'foot.php';
