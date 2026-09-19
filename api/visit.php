<?php
/**
 * 公开接口 · 访问统计
 * 记录一次页面访问；仅接受 POST，并对同一来源做频率限制。
 */

// Public, rate-limited visit counter.
require_once __DIR__ . '/../includes/common.php';

@header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    @header('Allow: POST');
    echo json_encode(array('ok' => false, 'msg' => '仅支持 POST 请求'), JSON_UNESCAPED_UNICODE);
    exit;
}

$rateFile = DATA_DIR . '/visit_rate.json';
$visitorKey = substr(hash('sha256', getClientIp() . conf('syskey', '')), 0, 16);
$now = time();
$isAllowed = mutateRateStore($rateFile, function (&$rate) use ($visitorKey, $now) {
    foreach ($rate as $key => $last) {
        if ($now - (int)$last > 3600) { unset($rate[$key]); }
    }
    if (isset($rate[$visitorKey]) && $now - (int)$rate[$visitorKey] < 60) { return false; }
    $rate[$visitorKey] = $now;
    return true;
});
if (!$isAllowed) {
    http_response_code(429);
    echo json_encode(array('ok' => false, 'msg' => '请求过于频繁'), JSON_UNESCAPED_UNICODE);
    exit;
}

recordVisit();

@header('Cache-Control: no-store, max-age=0');
echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
exit;
