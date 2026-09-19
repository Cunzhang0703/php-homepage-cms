<?php
/**
 * 公共引导文件
 * 定义路径常量与错误级别，依次加载函数库、PDO 封装与数据库配置。所有入口脚本必须先引入本文件。
 */

// Application bootstrap: paths, database connection, settings and login state.
if (!defined('ROOT_DIR')) {
    define('ROOT_DIR', dirname(__DIR__)); // 项目根（includes 的上一级）
}
if (!defined('DATA_DIR')) {
    define('DATA_DIR', ROOT_DIR . '/data');
}
if (!defined('DB_FILE')) {
    define('DB_FILE', DATA_DIR . '/site.sqlite'); // 兼容旧引用（SQLite 回退用）
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lib/PdoHelper.php';
require_once __DIR__ . '/db.php';

date_default_timezone_set('Asia/Shanghai');
error_reporting(0);
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

$islogin = 0;
$admin_user = '';
$conf = array();
$DB = null;
$SYS_KEY = '';

if (isInstalled(ROOT_DIR)) {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    try {
        $DB = new \lib\PdoHelper($dsn, DB_USER, DB_PASS, DB_QZ);
    } catch (\Exception $e) {
        error_log('Database initialization failed: ' . $e->getMessage());
        http_response_code(500);
        exit('服务暂时不可用，请稍后再试。');
    }

    $settings = $DB->findAll('config', 'k,v');
    if ($settings) {
        foreach ($settings as $setting) {
            $conf[$setting['k']] = $setting['v'];
        }
    }
    $SYS_KEY = isset($conf['syskey']) ? $conf['syskey'] : '';

    $admin_user = verifyAdminToken($SYS_KEY);
    if ($admin_user !== false && $DB->find('admin', 'username', array('username' => $admin_user))) {
        $islogin = 1;
    } else {
        $admin_user = '';
    }
}

if (!function_exists('conf')) {
    function conf($key, $default = '')
    {
        global $conf;
        return isset($conf[$key]) ? $conf[$key] : $default;
    }
}

if (!function_exists('setConf')) {
    function setConf($key, $value)
    {
        global $DB, $conf;
        $exists = $DB->find('config', 'k', array('k' => $key));
        $conf[$key] = $value;
        if ($exists) {
            return $DB->update('config', array('v' => $value), array('k' => $key));
        }
        return $DB->insert('config', array('k' => $key, 'v' => $value));
    }
}

if ($DB) {
    ensureSchema();
}
