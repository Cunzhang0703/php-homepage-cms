<?php
/**
 * 站点配置编辑 · 写回 config 表（PRG：保存后重定向刷新，避免重复提交）
 * 含「前台头像」：支持上传图片 / 填写图片 URL / 移除。
 */
require_once '../includes/common.php';

// 可被本页保存的纯文本字段
$textFields = array(
    'site_title', 'name', 'role', 'bio', 'tags',
    'email', 'wechat',
    'copyright_year', 'copyright_name', 'icp', 'police', 'accent'
);

$saved = isset($_GET['saved']) ? true : false;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $err = '提交来源不合法，请重试。';
    } else {
        foreach ($textFields as $f) {
            $val = isset($_POST[$f]) ? (string)$_POST[$f] : '';
            if ($f === 'tags') {
                $val = trim(preg_replace('/\r\n|\r/', "\n", $val)); // 统一换行符
            }
            setConf($f, $val);
        }
        setConf('use_hitokoto', isset($_POST['use_hitokoto']) ? '1' : '0');

        // ===== 前台头像处理 =====
        $avatarHandled = false;
        $oldAvatar = conf('avatar', '');
        if (isset($_POST['avatar_clear']) && $_POST['avatar_clear'] === '1') {
            deleteAvatarFile($oldAvatar);   // 移除旧头像文件（若有）
            setConf('avatar', '');
            $avatarHandled = true;
        } elseif (!empty($_FILES['avatar_file']) && $_FILES['avatar_file']['error'] === UPLOAD_ERR_OK) {
            $path = saveUploadedAvatar($_FILES['avatar_file']);
            if ($path) {
                deleteAvatarFile($oldAvatar);   // 先删旧文件，再写入新头像
                setConf('avatar', $path);
                $avatarHandled = true;
            } else {
                $err = '头像上传失败：仅支持 jpg / png / webp / gif 图片，且文件需为有效图片。';
            }
        } elseif (!empty($_POST['avatar_url'])) {
            deleteAvatarFile($oldAvatar);   // 改用外链，删除本地旧文件
            setConf('avatar', trim((string)$_POST['avatar_url']));
            $avatarHandled = true;
        }
        // 未做任何头像操作时保持原值（不清空）
    }

    if (!$err) {
        header('Location: site.php?saved=1');
        exit;
    }
}

$curAvatar = conf('avatar', '');
require_once 'head.php';
?>
<?php if ($saved): ?>
    <div class="alert alert-success">✅ 配置已保存。</div>
<?php endif; ?>
<?php if ($err): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div>
<?php endif; ?>

<form method="post" action="site.php" enctype="multipart/form-data">
    <?php echo csrfField(); ?>
    <div class="panel panel-default">
        <div class="panel-heading">基础信息</div>
        <div class="panel-body">
            <div class="form-group">
                <label>站点标题（浏览器标签 / SEO）</label>
                <input type="text" name="site_title" class="form-control" value="<?php echo htmlspecialchars(conf('site_title', '')); ?>">
            </div>
            <div class="row">
                <div class="col-sm-6 form-group">
                    <label>姓名</label>
                    <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars(conf('name', '')); ?>">
                </div>
                <div class="col-sm-6 form-group">
                    <label>头衔 / 职业</label>
                    <input type="text" name="role" class="form-control" value="<?php echo htmlspecialchars(conf('role', '')); ?>">
                </div>
            </div>
            <div class="form-group">
                <label>自我介绍（bio）</label>
                <textarea name="bio" class="form-control" rows="3"><?php echo htmlspecialchars(conf('bio', '')); ?></textarea>
            </div>
            <div class="form-group">
                <label>标签（每行一个）</label>
                <textarea name="tags" class="form-control" rows="3"><?php echo htmlspecialchars(conf('tags', '')); ?></textarea>
            </div>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading">前台头像</div>
        <div class="panel-body">
            <div class="form-group">
                <label>当前头像预览</label><br>
                <?php if ($curAvatar): ?>
                    <img src="<?php echo htmlspecialchars($curAvatar); ?>" alt="当前头像" style="width:84px;height:84px;border-radius:50%;object-fit:cover;border:2px solid #fff;box-shadow:0 4px 14px rgba(31,38,135,.18);">
                    <span class="text-muted" style="font-size:12px;margin-left:8px;"><?php echo htmlspecialchars($curAvatar); ?></span>
                <?php else: ?>
                    <div style="width:84px;height:84px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:#e2e8f0;color:#94a3b8;font-size:13px;">未设置</div>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label>上传图片（建议 1:1，≥200px；支持 jpg / png / webp / gif）</label>
                <input type="file" name="avatar_file" accept="image/*" class="form-control" style="padding:6px;">
            </div>
            <div class="form-group">
                <label>或填写图片 URL（留空则不改变当前头像）</label>
                <input type="text" name="avatar_url" class="form-control" placeholder="https://.../avatar.png" value="">
            </div>
            <label style="font-weight:500;color:#475569;">
                <input type="checkbox" name="avatar_clear" value="1"> 移除当前头像（前台将显示姓名首字）
            </label>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading">联系方式</div>
        <div class="panel-body">
            <div class="row">
                <div class="col-sm-6 form-group">
                    <label>邮箱</label>
                    <input type="text" name="email" class="form-control" value="<?php echo htmlspecialchars(conf('email', '')); ?>">
                </div>
                <div class="col-sm-6 form-group">
                    <label>微信</label>
                    <input type="text" name="wechat" class="form-control" value="<?php echo htmlspecialchars(conf('wechat', '')); ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading">链接 / 行动按钮</div>
        <div class="panel-body">
            <p class="text-muted" style="font-size:13px;line-height:1.8;">
                前台的所有链接（含原「行动按钮」）已统一在
                <a href="links.php">链接管理</a> 中维护（支持 N 个、可设图标 / 备注 / 排序 / 启停）。<br>
                本页不再单独维护旧的两个链接；原 link1/link2 数据仍保留，若「链接管理」里尚未添加任何链接，前台会自动回退显示它们。
            </p>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading">页脚 / 备案 / 外观</div>
        <div class="panel-body">
            <div class="row">
                <div class="col-sm-6 form-group">
                    <label>版权年份</label>
                    <input type="text" name="copyright_year" class="form-control" value="<?php echo htmlspecialchars(conf('copyright_year', date('Y'))); ?>">
                </div>
                <div class="col-sm-6 form-group">
                    <label>版权署名</label>
                    <input type="text" name="copyright_name" class="form-control" value="<?php echo htmlspecialchars(conf('copyright_name', '')); ?>">
                </div>
            </div>
            <div class="row">
                <div class="col-sm-6 form-group">
                    <label>ICP 备案号</label>
                    <input type="text" name="icp" class="form-control" value="<?php echo htmlspecialchars(conf('icp', '')); ?>">
                </div>
                <div class="col-sm-6 form-group">
                    <label>公安备案号</label>
                    <input type="text" name="police" class="form-control" value="<?php echo htmlspecialchars(conf('police', '')); ?>">
                </div>
            </div>
            <div class="row">
                <div class="col-sm-6 form-group">
                    <label>强调色（主题色）</label>
                    <input type="color" name="accent" class="form-control" style="height:42px;padding:2px;" value="<?php echo htmlspecialchars(conf('accent', '#6366f1')); ?>">
                </div>
                <div class="col-sm-6 form-group" style="padding-top:24px;">
                    <label><input type="checkbox" name="use_hitokoto" value="1" <?php echo conf('use_hitokoto', '1') === '1' ? 'checked' : ''; ?>> 启用「一言」API（随机句子）</label>
                </div>
            </div>
        </div>
    </div>

    <button class="btn btn-primary btn-lg">💾 保存配置</button>
</form>
<?php
require_once 'foot.php';
