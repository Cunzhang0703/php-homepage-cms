<?php
/**
 * 公开接口 · 留言板
 * 接收前台留言提交，含来源校验与频率限制；仅写入待审核状态。
 */

// Public guestbook endpoint. POSTed messages stay pending unless auto-approval is enabled.
require_once __DIR__ . '/../includes/common.php';

@header('Content-Type: application/json; charset=UTF-8');
@header('Cache-Control: no-store, no-cache, must-revalidate');

function gbOut($ok, $payload = array(), $code = 200)
{
    http_response_code($code);
    echo json_encode(array_merge(array('ok' => $ok), $payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isInstalled(ROOT_DIR) || !$DB) {
    gbOut(false, array('msg' => '站点未安装或数据库不可用'), 503);
}

if (conf('guestbook_on', '1') !== '1') {
    gbOut(false, array('msg' => '留言板已关闭', 'list' => array()), 200);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $rows = $DB->findAll('guestbook', 'id,nickname,content,reply,created_at', array('status' => 1), 'id DESC', 50);
    if (!is_array($rows)) { $rows = array(); }
    $list = array();
    foreach ($rows as $r) {
        $list[] = array(
            'id'      => (int)$r['id'],
            'name'    => $r['nickname'],
            'content' => $r['content'],
            'reply'   => $r['reply'],
            'time'    => $r['created_at'],
        );
    }
    gbOut(true, array('list' => $list));
}

if (!checkRefererHost()) {
    gbOut(false, array('msg' => '提交来源不合法'), 403);
}

if (!empty($_POST['website'])) {
    gbOut(true, array('msg' => '已收到，等待审核'));
}

$nickname = trim((string)($_POST['nickname'] ?? ''));
$contact  = trim((string)($_POST['contact'] ?? ''));
$content  = trim((string)($_POST['content'] ?? ''));

if ($nickname === '' || $content === '') {
    gbOut(false, array('msg' => '昵称和留言内容不能为空'), 400);
}
if (mb_strlen($content) < 2)   { gbOut(false, array('msg' => '留言太短了'), 400); }
if (mb_strlen($content) > 500) { gbOut(false, array('msg' => '留言不能超过 500 字'), 400); }
if (mb_strlen($nickname) > 20) { gbOut(false, array('msg' => '昵称不能超过 20 字'), 400); }
if ($contact !== '' && mb_strlen($contact) > 60) { gbOut(false, array('msg' => '联系方式过长'), 400); }

$rateFile = DATA_DIR . '/gb_rate.json';
$visitorKey = substr(hash('sha256', getClientIp() . conf('syskey', '')), 0, 16);
$now    = time();
$retryAfter = 0;
$isAllowed = mutateRateStore($rateFile, function (&$rate) use ($visitorKey, $now, &$retryAfter) {
    foreach ($rate as $key => $last) { if ($now - (int)$last > 3600) { unset($rate[$key]); } }
    if (isset($rate[$visitorKey]) && ($now - (int)$rate[$visitorKey]) < 60) {
        $retryAfter = 60 - ($now - (int)$rate[$visitorKey]);
        return false;
    }
    $rate[$visitorKey] = $now;
    return true;
});
if (!$isAllowed) {
    gbOut(false, array('msg' => '发言太快了，请 ' . $retryAfter . ' 秒后再试'), 429);
}

$auto = conf('guestbook_auto', '0') === '1';

$ok = $DB->insert('guestbook', array(
    'nickname'   => $nickname,
    'contact'    => $contact,
    'content'    => $content,
    'ua'         => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    'status'     => $auto ? 1 : 0,
    'created_at' => 'NOW()',
));

if (!$ok) {
    gbOut(false, array('msg' => '提交失败，请稍后再试'), 500);
}
gbOut(true, array(
    'msg'  => $auto ? '留言成功，感谢留言 🙌' : '留言已提交，审核通过后会显示 🙌',
    'auto' => $auto,
));
