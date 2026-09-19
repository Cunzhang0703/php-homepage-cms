<?php
/**
 * 云更新发布端 · 上传处理
 * 接收发布包的上传与暂存请求，以及 peek 预览请求，统一返回 JSON。
 */

// Write actions for the update library.
require_once __DIR__ . '/../includes/common.php';
require_once __DIR__ . '/lib_update.php';

if (!$islogin) { header('Location: ../admin/login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { header('Location: index.php?err=' . urlencode('提交校验失败，请刷新页面后重试')); exit; }

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'publish') {
    if (empty($_FILES['pkg']['tmp_name']) || !is_uploaded_file($_FILES['pkg']['tmp_name'])) {
        header('Location: index.php?err=' . urlencode('请选择版本压缩包'));
        exit;
    }
    if ((int)($_FILES['pkg']['size'] ?? 0) > 52428800) {
        header('Location: index.php?err=' . urlencode('压缩包不能超过 50 MB'));
        exit;
    }
    $version = trim($_POST['version'] ?? '');
    if (!isStrictUpdateVersion($version)) {
        header('Location: index.php?err=' . urlencode('版本号格式不正确：必须是 x.xx（主版本号 1 位、次版本号 2 位），例如 1.00 或 2.35'));
        exit;
    }
    $channel = in_array($_POST['channel'] ?? 'release', array('release', 'beta', 'dev'), true)
        ? $_POST['channel'] : 'release';
    $notes  = trim($_POST['notes'] ?? '');
    $minPhp = trim($_POST['min_php'] ?? '');

    list($ok, $msg) = publishVersion($_FILES['pkg']['tmp_name'], $version, $notes, $minPhp, $channel);
    if ($ok) {
        writeUpdateLog('云更新暂存 v' . $version . '（' . $channel . '）→ ' . $msg . '｜' . formatUpdateNotesForLog($notes));
        header('Location: index.php?ok=' . urlencode('已暂存为待更新包 v' . $version . '，请在后台「云更新」中确认更新'));
    } else {
        header('Location: index.php?err=' . urlencode('暂存失败：' . $msg));
    }
    exit;
}

if ($action === 'peek') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_FILES['pkg']['tmp_name']) || !is_uploaded_file($_FILES['pkg']['tmp_name'])) {
        echo json_encode(array('ok' => false, 'msg' => '请选择版本压缩包'));
        exit;
    }
    if ((int)($_FILES['pkg']['size'] ?? 0) > 52428800) {
        echo json_encode(array('ok' => false, 'msg' => '压缩包不能超过 50 MB'));
        exit;
    }
    list($packageOk, $packageMsg) = validateZipPackage($_FILES['pkg']['tmp_name'], 52428800, 2000, 104857600, 20971520, true);
    if (!$packageOk) {
        echo json_encode(array('ok' => false, 'msg' => $packageMsg));
        exit;
    }
    $notes = distillReleaseNotes($_FILES['pkg']['tmp_name']);
    echo json_encode(array('ok' => true, 'notes' => $notes));
    exit;
}

if ($action === 'rollback') {
    $id = (int)($_POST['id'] ?? 0);
    if (rollbackTo($id)) {
        writeUpdateLog('云更新回退到版本记录 #' . $id);
        header('Location: index.php?ok=' . urlencode('已回退到所选版本'));
    } else {
        header('Location: index.php?err=' . urlencode('回退失败：记录不存在'));
    }
    exit;
}

if ($action === 'savesrc') {
    $sourceUrl = trim($_POST['update_url'] ?? '');
    if ($sourceUrl !== '' && !preg_match('#^https?://#i', $sourceUrl)) {
        header('Location: index.php?err=' . urlencode('更新源地址必须以 http:// 或 https:// 开头'));
        exit;
    }
    setConf('update_url', $sourceUrl);
    header('Location: index.php?ok=' . urlencode('更新源地址已保存'));
    exit;
}

header('Location: index.php');
exit;
