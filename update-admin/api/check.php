<?php
/**
 * api/check.php · 客户端检测端点（灰度就绪）
 * 当前返回当前对外发布的 update.json 内容；后续可在本端点按 install_id 做 percentile 灰度。
 * 客户端 admin/update.php 也可直接读取 update.json，本端点提供统一检测入口。
 */
require_once __DIR__ . '/../../includes/common.php';
require_once __DIR__ . '/../lib_update.php';
ensureUpdateSchema();

header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

if (!is_file(UPDATE_JSON)) {
    // 无发布版本：204 语义上更准，但部分客户端偏好 JSON，这里返回空对象并 200
    echo '{}';
    exit;
}
echo file_get_contents(UPDATE_JSON);
exit;
