<?php
/**
 * 链接管理 · 前台"行动按钮 / 社交链接 / 各种跳转链接"统一数据源（表 pre_links）
 * 原站点配置里的「行动按钮」已合并到此处；支持任意数量的链接，可排序、启用停用、设置 emoji 图标。
 */
require_once '../includes/common.php';

if (!$islogin) {
    header('Location: login.php');
    exit;
}

if (!function_exists('linkUrlOk')) {
    /**
     * 链接安全校验（黑名单式）
     * 拒绝：javascript: / data: / vbscript: / file: 等危险协议（含实体编码、空白字符绕过）
     * 放行：http(s):// mailto: tel: 绝对路径 / 锚点 # 相对路径 ./ ../ page.html?a=1
     */
    function linkUrlOk($u)
    {
        $s = html_entity_decode((string)$u, ENT_QUOTES, 'UTF-8');
        // 去掉空白与控制字符，防止 "java\tscript:" / "  javascript:" 之类绕过
        $s = preg_replace('/[\s[:cntrl:]]+/', '', $s);
        if ($s === null) {
            return false; // 正则异常时失败关闭，宁可拦错不可放过
        }
        return !preg_match('#^(javascript|data|vbscript|file):#i', $s);
    }
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $err = 'nocsrf';
    } else {
        $action = $_POST['action'] ?? '';
        $id     = (int)($_POST['id'] ?? 0);

        if ($action === 'add' || $action === 'edit') {
            $title = trim((string)($_POST['title'] ?? ''));
            $url   = trim((string)($_POST['url'] ?? ''));
            $icon  = trim((string)($_POST['icon'] ?? ''));
            $note  = trim((string)($_POST['note'] ?? ''));
            $sort  = (int)($_POST['sort'] ?? 0);
            $en    = isset($_POST['enabled']) ? 1 : 0;

            if ($title === '' || $url === '') {
                $err = 'empty';
            } elseif (!linkUrlOk($url)) {
                $err = 'badurl';
            } else {
                $data = array(
                    'title' => mb_substr($title, 0, 80), 'url' => mb_substr($url, 0, 255),
                    'icon'  => mb_substr($icon, 0, 8),   'note' => mb_substr($note, 0, 160),
                    'sort'  => $sort, 'enabled' => $en,
                );
                if ($action === 'add') {
                    $data['created_at'] = 'NOW()';
                    $DB->insert('links', $data);
                } else {
                    $DB->update('links', $data, array('id' => $id));
                }
            }
        } elseif ($action === 'del' && $id) {
            $DB->delete('links', array('id' => $id));
        } elseif ($action === 'toggle' && $id) {
            $row = $DB->find('links', 'enabled', array('id' => $id));
            $DB->update('links', array('enabled' => $row && (int)$row['enabled'] === 1 ? 0 : 1), array('id' => $id));
        } elseif ($action === 'legacy_save') {
            // 旧版行动按钮 link1/link2：校验链接格式后写回 config 表（作为 pre_links 为空时的前台兜底）
            foreach (array(1, 2) as $i) {
                $t = trim((string)($_POST['link' . $i . '_text'] ?? ''));
                $h = trim((string)($_POST['link' . $i . '_href'] ?? ''));
                if ($h !== '' && !linkUrlOk($h)) {
                    $err = 'badurl';
                    break;
                }
                setConf('link' . $i . '_text', mb_substr($t, 0, 80));
                setConf('link' . $i . '_href', mb_substr($h, 0, 255));
            }
        }
    }
    if (!$err) {
        header('Location: links.php?' . ($action === 'legacy_save' ? 'legacy=1' : 'saved=1'));
        exit;
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$editRow = $editId ? $DB->find('links', '*', array('id' => $editId)) : null;
$rows = $DB->findAll('links', '*', array(), 'sort ASC, id ASC');
if (!is_array($rows)) { $rows = array(); }

// 旧版 link1/link2：校验失败时回显用户刚输入的内容，否则回显已保存的配置
$isLegacyPost = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'legacy_save');
$legacyText = array(); $legacyHref = array();
foreach (array(1, 2) as $i) {
    $legacyText[$i] = $isLegacyPost ? trim((string)($_POST['link' . $i . '_text'] ?? '')) : conf('link' . $i . '_text', '');
    $legacyHref[$i] = $isLegacyPost ? trim((string)($_POST['link' . $i . '_href'] ?? '')) : conf('link' . $i . '_href', '');
}

require_once 'head.php';
?>
<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">✅ 已保存。</div><?php endif; ?>
<?php if ($err === 'empty'): ?><div class="alert alert-danger">名称和链接都不能为空。</div><?php endif; ?>
<?php if ($err === 'badurl'): ?><div class="alert alert-danger">链接不安全：不允许 <code>javascript:</code> / <code>data:</code> / <code>vbscript:</code> 等协议。可填 http(s):// 、mailto: 、tel: 、<code>/路径</code>、<code>#锚点</code> 或相对路径（如 <code>./</code>）。</div><?php endif; ?>
<?php if ($err === 'nocsrf'): ?><div class="alert alert-danger">提交来源不合法，请重试。</div><?php endif; ?>
<?php if (isset($_GET['legacy'])): ?><div class="alert alert-success">✅ 旧版行动按钮（link1 / link2）已保存。</div><?php endif; ?>

<div class="row">
    <div class="col-sm-7">
        <div class="panel panel-default">
            <div class="panel-heading">🔗 链接列表（<?php echo count($rows); ?>）</div>
            <div class="panel-body" style="padding:0;">
                <table class="table table-bordered" style="margin:0;">
                    <thead>
                    <tr><th style="width:58px;">排序</th><th>名称</th><th>地址</th><th style="width:64px;">状态</th><th style="width:110px;">操作</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="5" class="text-muted" style="text-align:center;padding:24px;">还没有链接，用右侧表单添加。<br><span style="font-size:12px;">添加后前台会自动用这里的数据替换旧的两个按钮。</span></td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr<?php echo (int)$r['enabled'] === 0 ? ' style="opacity:.45;"' : ''; ?>>
                            <td><?php echo (int)$r['sort']; ?></td>
                            <td><?php echo $r['icon'] ? htmlspecialchars($r['icon']) . ' ' : ''; ?><?php echo htmlspecialchars($r['title']); ?>
                                <?php if ($r['note']): ?><div class="text-muted" style="font-size:11px;"><?php echo htmlspecialchars($r['note']); ?></div><?php endif; ?>
                            </td>
                            <td style="word-break:break-all;font-size:12px;"><?php echo htmlspecialchars($r['url']); ?></td>
                            <td>
                                <form method="post" style="display:inline;">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                    <button class="btn btn-xs <?php echo (int)$r['enabled'] === 1 ? 'btn-success' : 'btn-default'; ?>"><?php echo (int)$r['enabled'] === 1 ? '显示' : '隐藏'; ?></button>
                                </form>
                            </td>
                            <td>
                                <a class="btn btn-default btn-xs" href="links.php?edit=<?php echo (int)$r['id']; ?>">编辑</a>
                                <form method="post" style="display:inline;" onsubmit="return confirm('删除该链接？');">
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
            <div class="panel-heading"><?php echo $editRow ? '✏️ 编辑链接 #' . (int)$editRow['id'] : '➕ 添加链接'; ?></div>
            <div class="panel-body">
                <form method="post" action="links.php">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="<?php echo $editRow ? 'edit' : 'add'; ?>">
                    <?php if ($editRow): ?><input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>"><?php endif; ?>
                    <div class="row">
                        <div class="col-xs-4 form-group">
                            <label>图标 emoji</label>
                            <input type="text" name="icon" class="form-control" maxlength="8" placeholder="🐙" value="<?php echo htmlspecialchars($editRow['icon'] ?? ''); ?>">
                        </div>
                        <div class="col-xs-8 form-group">
                            <label>名称</label>
                            <input type="text" name="title" class="form-control" placeholder="GitHub" value="<?php echo htmlspecialchars($editRow['title'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>链接地址</label>
                        <input type="text" name="url" class="form-control" placeholder="https://github.com/xxx" value="<?php echo htmlspecialchars($editRow['url'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>备注（鼠标悬停提示，可空）</label>
                        <input type="text" name="note" class="form-control" value="<?php echo htmlspecialchars($editRow['note'] ?? ''); ?>">
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
                    <?php if ($editRow): ?><a href="links.php" class="btn btn-default">取消</a><?php endif; ?>
                </form>
            </div>
        </div>
        <div class="panel panel-default">
            <div class="panel-heading">说明</div>
            <div class="panel-body" style="font-size:12px;line-height:1.9;color:#64748b;">
                这里的链接会显示在前台首页的按钮区。<br>
                <b>兼容旧数据</b>：如果一条都没有，前台仍会使用站点配置中保留的两个旧链接（link1/link2）；只要这里添加了任意一条，就以这里为准。<br>
                如需修改这两个旧链接，请用下方「旧版行动按钮」表单。
            </div>
        </div>
        <div class="panel panel-default">
            <div class="panel-heading">🧩 旧版行动按钮（link1 / link2）</div>
            <div class="panel-body">
                <p class="text-muted" style="font-size:12px;line-height:1.8;margin-bottom:12px;">
                    合并前的两个旧链接，仍保存在站点配置中。<b>仅当上方「链接列表」为空时</b>，前台才会用它们作为按钮区兜底；维护好这里可保证升级前后前台按钮数据一致。
                </p>
                <form method="post" action="links.php">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="legacy_save">
                    <?php foreach (array(1, 2) as $i): ?>
                    <fieldset style="border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;margin-bottom:14px;">
                        <legend style="width:auto;font-size:13px;color:#475569;margin:0 0 0 4px;padding:0 6px;">链接 <?php echo $i; ?></legend>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label style="font-size:12px;">名称</label>
                            <input type="text" name="link<?php echo $i; ?>_text" class="form-control input-sm" maxlength="80" placeholder="例如：博客" value="<?php echo htmlspecialchars($legacyText[$i]); ?>">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label style="font-size:12px;">链接地址（可空；填了需以 http(s):// / mailto: / tel: / / # 开头）</label>
                            <input type="text" name="link<?php echo $i; ?>_href" class="form-control input-sm" maxlength="255" placeholder="https://..." value="<?php echo htmlspecialchars($legacyHref[$i]); ?>">
                        </div>
                    </fieldset>
                    <?php endforeach; ?>
                    <button class="btn btn-primary btn-sm">💾 保存旧版链接</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php
require_once 'foot.php';
