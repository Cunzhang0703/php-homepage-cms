<?php
/**
 * 公共函数库
 * 站点配置读写、备份与恢复、安全解压、文件净化、版本号同步等核心工具函数。
 */

// Shared helpers used by the public site, admin area and updater.

if (!function_exists('getClientIp')) {
    function getClientIp()
    {
        // Trust forwarded headers only from a configured proxy.
        $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if (defined('TRUSTED_PROXY_IPS') && is_array(TRUSTED_PROXY_IPS)
            && in_array($remote, TRUSTED_PROXY_IPS, true)
            && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return $remote;
    }
}

if (!function_exists('isHttpsRequest')) {
    function isHttpsRequest()
    {
        return (defined('FORCE_HTTPS_COOKIES') && FORCE_HTTPS_COOKIES)
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    }
}

if (!function_exists('startAppSession')) {
    function startAppSession()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }
        if (headers_sent()) {
            return false;
        }
        // Avoid collisions with unrelated PHPSESSID cookies.
        if (session_name() !== 'home_session') {
            session_name('home_session');
        }
        // Prefer a local session directory when the web user can write to it.
        if (defined('DATA_DIR')) {
            $sessionDir = DATA_DIR . '/sessions';
            if (!is_dir($sessionDir)) { @mkdir($sessionDir, 0750, true); }
            if (is_dir($sessionDir) && is_writable($sessionDir)) {
                @session_save_path($sessionDir);
            }
        }
        $secure = isHttpsRequest();
        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params(array(
                'lifetime' => 0,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ));
        } else {
            session_set_cookie_params(0, '/; samesite=Lax', '', $secure, true);
        }
        return session_start();
    }
}

if (!function_exists('getCsrfToken')) {
    function getCsrfToken()
    {
        // Admin CSRF tokens are tied to the signed login cookie.
        global $SYS_KEY;
        if (!empty($_COOKIE['admin_token']) && !empty($SYS_KEY)) {
            return hash_hmac('sha256', 'csrf:' . (string)$_COOKIE['admin_token'], $SYS_KEY);
        }
        if (!startAppSession()) {
            return '';
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verifyCsrfToken')) {
    function verifyCsrfToken($token)
    {
        if (!is_string($token)) {
            return false;
        }
        global $SYS_KEY;
        if (!empty($_COOKIE['admin_token']) && !empty($SYS_KEY)) {
            $expected = hash_hmac('sha256', 'csrf:' . (string)$_COOKIE['admin_token'], $SYS_KEY);
            return hash_equals($expected, $token);
        }
        if (!startAppSession() || empty($_SESSION['csrf_token'])) { return false; }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('csrfField')) {
    function csrfField()
    {
        $token = getCsrfToken();
        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('setAdminAuthCookie')) {
    function setAdminAuthCookie($token, $expires)
    {
        $secure = isHttpsRequest();
        if (PHP_VERSION_ID >= 70300) {
            return setcookie('admin_token', $token, array(
                'expires' => (int)$expires,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ));
        }
        $parts = array(
            'admin_token=' . rawurlencode($token),
            'Expires=' . gmdate('D, d M Y H:i:s T', (int)$expires),
            'Max-Age=' . max(0, (int)$expires - time()),
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
        );
        if ($secure) { $parts[] = 'Secure'; }
        header('Set-Cookie: ' . implode('; ', $parts), false);
        return true;
    }
}

if (!function_exists('clearAdminAuthCookie')) {
    function clearAdminAuthCookie()
    {
        return setAdminAuthCookie('', time() - 3600);
    }
}

if (!function_exists('writeSecurityLog')) {
    function writeSecurityLog($event)
    {
        $file = DATA_DIR . '/security.log';
        if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0755, true); }
        $line = '[' . date('Y-m-d H:i:s') . '] ' . getClientIp() . ' ' . trim((string)$event);
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        return true;
    }
}

if (!function_exists('randomStr')) {
    function randomStr($length = 32)
    {
        $bytes = random_bytes((int)ceil($length / 2));
        return substr(bin2hex($bytes), 0, $length);
    }
}

if (!function_exists('checkRefererHost')) {
    // Compare hosts only; TLS may terminate at a reverse proxy.
    function checkRefererHost()
    {
        $current = parse_url('http://' . (string)($_SERVER['HTTP_HOST'] ?? ''));
        if (!$current || !isset($current['host'])) {
            return false;
        }
        $currentPort = isset($current['port']) ? (int)$current['port'] : null;
        if (!empty($_SERVER['HTTP_ORIGIN'])) {
            $origin = parse_url((string)$_SERVER['HTTP_ORIGIN']);
            if (!$origin || !isset($origin['host'])) { return false; }
            $originPort = isset($origin['port']) ? (int)$origin['port'] : null;
            return strcasecmp($origin['host'], $current['host']) === 0 && $originPort === $currentPort;
        }
        if (empty($_SERVER['HTTP_REFERER'])) { return false; }
        $referer = parse_url($_SERVER['HTTP_REFERER']);
        if (!$referer || !isset($referer['host'])) { return false; }
        $refererPort = isset($referer['port']) ? (int)$referer['port'] : null;
        return strcasecmp($referer['host'], $current['host']) === 0 && $refererPort === $currentPort;
    }
}

if (!function_exists('mutateRateStore')) {
    function mutateRateStore($file, $callback)
    {
        $dir = dirname($file);
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $fp = @fopen($file, 'c+');
        if (!$fp) { return false; }
        $result = false;
        if (flock($fp, LOCK_EX)) {
            rewind($fp);
            $raw = stream_get_contents($fp);
            $data = $raw ? json_decode($raw, true) : array();
            if (!is_array($data)) { $data = array(); }
            $result = call_user_func_array($callback, array(&$data));
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        return $result;
    }
}

if (!function_exists('isLoginRateLimited')) {
    function isLoginRateLimited($key, &$retryAfter = 0)
    {
        $retryAfter = 0;
        $now = time();
        $result = mutateRateStore(DATA_DIR . '/login_rate.json', function (&$data) use ($key, $now, &$retryAfter) {
            foreach ($data as $k => $item) {
                if (!is_array($item) || $now - (int)($item['last'] ?? 0) > 900) { unset($data[$k]); }
            }
            $item = $data[$key] ?? array('count' => 0, 'last' => 0);
            if ((int)$item['count'] >= 5 && $now - (int)$item['last'] < 900) {
                $retryAfter = 900 - ($now - (int)$item['last']);
                return true;
            }
            return false;
        });
        return $result === true;
    }
}

if (!function_exists('recordLoginFailure')) {
    function recordLoginFailure($key)
    {
        $now = time();
        return mutateRateStore(DATA_DIR . '/login_rate.json', function (&$data) use ($key, $now) {
            $item = $data[$key] ?? array('count' => 0, 'last' => 0);
            if ($now - (int)$item['last'] > 900) { $item = array('count' => 0, 'last' => 0); }
            $item['count'] = (int)$item['count'] + 1;
            $item['last'] = $now;
            $data[$key] = $item;
            return true;
        });
    }
}

if (!function_exists('clearLoginFailures')) {
    function clearLoginFailures($key)
    {
        return mutateRateStore(DATA_DIR . '/login_rate.json', function (&$data) use ($key) {
            unset($data[$key]);
            return true;
        });
    }
}

if (!function_exists('verifyAdminCredentials')) {
    // MySQL may match usernames case-insensitively, so check it again in PHP.
    function verifyAdminCredentials($username, $password, $row)
    {
        if (!is_array($row) || !isset($row['username'], $row['password_hash'])
            || !is_string($username) || !is_string($password)) {
            return false;
        }
        return hash_equals((string)$row['username'], $username)
            && password_verify($password, (string)$row['password_hash']);
    }
}

if (!function_exists('verifyAdminToken')) {
    /**
     * 校验 admin_token cookie，返回管理员用户名或 false
     * token 结构： base64(user \t expire \t sig)，sig = hash_hmac('sha256', user\texpire, SYS_KEY)
     */
    function verifyAdminToken($sysKey)
    {
        if (empty($_COOKIE['admin_token'])) {
            return false;
        }
        $raw = base64_decode($_COOKIE['admin_token'], true);
        if ($raw === false) {
            return false;
        }
        $parts = explode("\t", $raw, 3);
        if (count($parts) !== 3) {
            return false;
        }
        list($user, $expire, $sig) = $parts;
        if ((int)$expire < time()) {
            return false;
        }
        $expect = hash_hmac('sha256', $user . "\t" . $expire, $sysKey);
        if (!hash_equals($expect, $sig)) {
            return false;
        }
        return $user;
    }
}

if (!function_exists('buildAdminToken')) {
    function buildAdminToken($user, $sysKey, $ttl = 604800)
    {
        $expire = time() + $ttl;
        $sig = hash_hmac('sha256', $user . "\t" . $expire, $sysKey);
        return base64_encode($user . "\t" . $expire . "\t" . $sig);
    }
}

if (!function_exists('checkIfActive')) {
    function checkIfActive($names)
    {
        $cur = basename($_SERVER['PHP_SELF'], '.php');
        $list = explode(',', $names);
        return in_array($cur, $list, true) ? 'active' : '';
    }
}

if (!function_exists('checkIfActiveMod')) {
    function checkIfActiveMod($mod)
    {
        $cur = isset($_GET['mod']) ? $_GET['mod'] : '';
        return $cur === $mod ? 'active' : '';
    }
}

if (!function_exists('isInstalled')) {
    function isInstalled($rootDir)
    {
        $cfg = $rootDir . '/includes/db.php';
        $lock = $rootDir . '/install/install.lock';
        return file_exists($cfg) && file_exists($lock);
    }
}

if (!function_exists('getVisitsStore')) {
    // File-backed visit totals; daily entries are retained for 60 days.
    function getVisitsStore()
    {
        return DATA_DIR . '/visits.json';
    }
}

if (!function_exists('readVisits')) {
    function readVisits()
    {
        $file = getVisitsStore();
        if (is_file($file)) {
            $json = @file_get_contents($file);
            $data = $json ? @json_decode($json, true) : null;
            if (is_array($data)) {
                if (!isset($data['total']) || !is_numeric($data['total'])) {
                    $data['total'] = 0;
                }
                if (!isset($data['days']) || !is_array($data['days'])) {
                    $data['days'] = array();
                }
                return $data;
            }
        }
        return array('total' => 0, 'days' => array());
    }
}

if (!function_exists('recordVisit')) {
    function recordVisit()
    {
        $file = getVisitsStore();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return false;
        }
        $data = array('total' => 0, 'days' => array());
        if (flock($fp, LOCK_EX)) {
            $size = filesize($file);
            if ($size > 0) {
                $json = @fread($fp, $size);
                $parsed = $json ? @json_decode($json, true) : null;
                if (is_array($parsed)) {
                    $data = $parsed;
                    if (!isset($data['total']) || !is_numeric($data['total'])) {
                        $data['total'] = 0;
                    }
                    if (!isset($data['days']) || !is_array($data['days'])) {
                        $data['days'] = array();
                    }
                }
            }
            $today = date('Y-m-d');
            $data['total'] = (int)$data['total'] + 1;
            if (!isset($data['days'][$today])) {
                $data['days'][$today] = 0;
            }
            $data['days'][$today] = (int)$data['days'][$today] + 1;
            // 仅保留最近 60 天，避免文件无限增长
            if (count($data['days']) > 60) {
                $keys = array_keys($data['days']);
                sort($keys);
                while (count($keys) > 60) {
                    unset($data['days'][array_shift($keys)]);
                }
            }
            ftruncate($fp, 0);
            fseek($fp, 0);
            fwrite($fp, json_encode($data));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        return $data;
    }
}

if (!function_exists('deleteAvatarFile')) {
    function deleteAvatarFile($path)
    {
        if (!$path) {
            return false;
        }
        if (strpos($path, 'uploads/') === 0) {
            $full = ROOT_DIR . '/' . $path;
            if (is_file($full)) {
                return @unlink($full);
            }
        }
        return false;
    }
}

if (!function_exists('saveUploadedAvatar')) {
    function saveUploadedAvatar($file)
    {
        return saveUploadedImage($file, 'avatar');
    }
}

if (!function_exists('saveUploadedImage')) {
    // Validate MIME type and dimensions before saving an uploaded image.
    function saveUploadedImage($file, $prefix = 'img')
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return false;
        }
        if (isset($file['size']) && $file['size'] > 5 * 1024 * 1024) {
            return false;
        }
        $allowed = array(
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        );
        if (!class_exists('finfo')) {
            return false;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!isset($allowed[$mime])) {
            return false;
        }
        $imageInfo = @getimagesize($file['tmp_name']);
        if (!$imageInfo) {
            return false;
        }
        $width = (int)($imageInfo[0] ?? 0);
        $height = (int)($imageInfo[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > 8192 || $height > 8192 || ($width * $height) > 20000000) {
            return false;
        }
        $ext = $allowed[$mime];
        $prefix = preg_replace('/[^a-z0-9_]/i', '', $prefix);
        $name = 'uploads/' . ($prefix ? $prefix : 'img') . '_' . randomStr(16) . '.' . $ext;
        $dest = ROOT_DIR . '/' . $name;
        if (!is_dir(dirname($dest))) {
            @mkdir(dirname($dest), 0755, true);
        }
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return false;
        }
        return $name;
    }
}

if (!defined('SCHEMA_VER')) {
    define('SCHEMA_VER', 2); // 每次新增/变更数据表就 +1
}

if (!function_exists('getSiteVersion')) {
    function getSiteVersion()
    {
        $manifest = dirname(__DIR__) . '/version.json';
        if (is_file($manifest)) {
            $data = @json_decode(@file_get_contents($manifest), true);
            if (!empty($data['version'])) { return $data['version']; }
        }
        $version = conf('version', '');
        if ($version) { return $version; }
        return '1.04';
    }
}

// Release versions use decimal-style x.xx notation rather than PHP version segments.
if (!function_exists('isStrictUpdateVersion')) {
    function isStrictUpdateVersion($version)
    {
        return is_string($version) && preg_match('/^[0-9]\.[0-9]{2}$/', $version) === 1;
    }
}

if (!function_exists('isComparableUpdateVersion')) {
    // Older releases may have more decimals; new uploads use the strict rule.
    function isComparableUpdateVersion($version)
    {
        return is_string($version) && preg_match('/^[0-9]+\.[0-9]+$/', $version) === 1;
    }
}

if (!function_exists('compareUpdateVersions')) {
    // Returns -1, 0, 1, or null for an invalid value without float conversion.
    function compareUpdateVersions($left, $right)
    {
        if (!isComparableUpdateVersion($left) || !isComparableUpdateVersion($right)) {
            return null;
        }
        list($leftMajor, $leftMinor) = explode('.', $left, 2);
        list($rightMajor, $rightMinor) = explode('.', $right, 2);

        $leftMajor = ltrim($leftMajor, '0');
        $rightMajor = ltrim($rightMajor, '0');
        $leftMajor = $leftMajor === '' ? '0' : $leftMajor;
        $rightMajor = $rightMajor === '' ? '0' : $rightMajor;
        if (strlen($leftMajor) !== strlen($rightMajor)) {
            return strlen($leftMajor) > strlen($rightMajor) ? 1 : -1;
        }
        if ($leftMajor !== $rightMajor) {
            return strcmp($leftMajor, $rightMajor) > 0 ? 1 : -1;
        }

        $width = max(strlen($leftMinor), strlen($rightMinor));
        $leftMinor = str_pad($leftMinor, $width, '0');
        $rightMinor = str_pad($rightMinor, $width, '0');
        if ($leftMinor === $rightMinor) { return 0; }
        return strcmp($leftMinor, $rightMinor) > 0 ? 1 : -1;
    }
}

if (!function_exists('ensureSchema')) {
    // Add tables introduced by newer releases once per schema version.
    function ensureSchema()
    {
        global $DB;
        if (!$DB) { return false; }
        $cur = (int)conf('schema_ver', 0);
        if ($cur >= SCHEMA_VER) { return true; }

        $isMysql = method_exists($DB, 'isMysql') ? $DB->isMysql() : true;
        $tail    = $isMysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $pk      = $isMysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $dt      = $isMysql ? 'DATETIME' : 'TEXT';

        $DB->exec("CREATE TABLE IF NOT EXISTS pre_links (
            id $pk,
            title VARCHAR(80) NOT NULL DEFAULT '',
            url VARCHAR(255) NOT NULL DEFAULT '',
            icon VARCHAR(80) NOT NULL DEFAULT '',
            note VARCHAR(160) NOT NULL DEFAULT '',
            sort INT NOT NULL DEFAULT 0,
            enabled INT NOT NULL DEFAULT 1,
            created_at $dt DEFAULT NULL
        )$tail");

        $DB->exec("CREATE TABLE IF NOT EXISTS pre_works (
            id $pk,
            title VARCHAR(120) NOT NULL DEFAULT '',
            summary VARCHAR(255) NOT NULL DEFAULT '',
            cover VARCHAR(255) NOT NULL DEFAULT '',
            url VARCHAR(255) NOT NULL DEFAULT '',
            tags VARCHAR(160) NOT NULL DEFAULT '',
            sort INT NOT NULL DEFAULT 0,
            enabled INT NOT NULL DEFAULT 1,
            created_at $dt DEFAULT NULL
        )$tail");

        $DB->exec("CREATE TABLE IF NOT EXISTS pre_guestbook (
            id $pk,
            nickname VARCHAR(60) NOT NULL DEFAULT '',
            contact VARCHAR(120) NOT NULL DEFAULT '',
            content VARCHAR(1000) NOT NULL DEFAULT '',
            reply VARCHAR(1000) NOT NULL DEFAULT '',
            ua VARCHAR(255) NOT NULL DEFAULT '',
            status INT NOT NULL DEFAULT 0,
            created_at $dt DEFAULT NULL
        )$tail");

        setConf('schema_ver', SCHEMA_VER);
        return true;
    }
}

if (!function_exists('pendingGuestbookBadge')) {
    function pendingGuestbookBadge()
    {
        global $DB;
        if (!$DB) { return ''; }
        $n = $DB->count('guestbook', array('status' => 0));
        $n = (int)$n;
        return $n > 0 ? '<span class="badge-dot">' . ($n > 99 ? '99+' : $n) . '</span>' : '';
    }
}

if (!function_exists('setSiteVersion')) {
    function setSiteVersion($v)
    {
        setConf('version', $v);
        // 同步写静态 manifest，保证 version.json 与 config 一致
        $f = dirname(__DIR__) . '/version.json';
        if (is_writable(dirname($f)) || !is_file($f)) {
            $j = array('version' => $v, 'schema_ver' => SCHEMA_VER);
            @file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }
}

if (!function_exists('getBackupDir')) {
    function getBackupDir()
    {
        $dir = DATA_DIR . '/.backups';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        return $dir;
    }
}

if (!function_exists('backupSite')) {
    /**
     * 打包当前站点"代码"为备份 zip（排除 data/、uploads/、备份目录自身、.git、install.lock）。
     * 返回备份文件路径或 false。备份只包含代码，恢复时不会触碰数据库/数据/上传文件。
     */
    function backupSite($note = '')
    {
        $dir = getBackupDir();
        if (!is_dir($dir)) { return false; }
        $ver = preg_replace('/[^a-zA-Z0-9._-]/', '', getSiteVersion());
        $stamp = date('Ymd-His');
        $suffix = $note ? '-' . preg_replace('/[^a-zA-Z0-9._-]/', '', $note) : '';
        // 文件名带随机串，避免被外部按时间戳猜到下载地址
        $name = 'backup-' . $stamp . '-v' . $ver . $suffix . '-' . randomStr(8) . '.zip';
        $dest = $dir . '/' . $name;
        $root = realpath(ROOT_DIR);
        if (!$root) { return false; }
        $zip = new \ZipArchive();
        if ($zip->open($dest, \ZipArchive::CREATE) !== true) { return false; }
        $excludeDirs = array($root . '/data', $root . '/uploads', $dir, $root . '/.git');
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if ($file->isDir()) { continue; }
            $path = $file->getPathname();
            foreach ($excludeDirs as $ex) {
                if (strpos($path, $ex . DIRECTORY_SEPARATOR) === 0 || $path === $ex) { continue 2; }
            }
            if (basename($path) === 'install.lock') { continue; }
            $rel = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
            // A code backup must never contain deployment credentials or local secrets.
            if (in_array($rel, array('includes/db.php', '.env', '.env.local'), true)) { continue; }
            if ($rel !== '') { $zip->addFile($path, $rel); }
        }
        $zip->close();
        return is_file($dest) ? $dest : false;
    }
}

if (!function_exists('listBackups')) {
    function listBackups()
    {
        $dir = getBackupDir();
        $out = array();
        if (!is_dir($dir)) { return $out; }
        foreach (glob($dir . '/backup-*.zip') as $f) {
            $out[] = array('file' => basename($f), 'size' => filesize($f), 'time' => filemtime($f));
        }
        usort($out, function ($a, $b) { return $b['time'] - $a['time']; });
        return $out;
    }
}

if (!function_exists('restoreBackup')) {
    /**
     * 从备份 zip 恢复代码（安全解压，排除 db.php/install.lock/version.json/.htaccess/data/uploads）。
     */
    function restoreBackup($file)
    {
        $dir = getBackupDir();
        $path = $dir . '/' . basename($file);
        if (!is_file($path)) { return false; }
        return extractZipSafe($path, ROOT_DIR,
            array('db.php', 'install.lock', 'version.json', '.htaccess'),
            array('data', 'uploads'));
    }
}

if (!function_exists('deleteBackup')) {
    // 删除单个备份（只允许删除备份目录内、backup-*.zip 命名的文件）
    function deleteBackup($file)
    {
        $name = basename($file);
        if (strpos($name, 'backup-') !== 0 || substr($name, -4) !== '.zip') { return false; }
        $path = getBackupDir() . '/' . $name;
        return is_file($path) ? @unlink($path) : false;
    }
}

if (!function_exists('httpGet')) {
    /**
     * 简单 HTTP GET，返回 array(body|false, errMsg)
     */
    function httpGet($url, $timeout = 20)
    {
        if (!function_exists('curl_init')) {
            return array(false, '服务器未开启 curl 扩展');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => 'HomeSite-Updater/1.0',
        ));
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) { return array(false, $err ? $err : '请求失败'); }
        if ($code < 200 || $code >= 300) { return array(false, 'HTTP ' . $code); }
        return array($body, '');
    }
}

if (!function_exists('downloadFile')) {
    /**
     * 下载远程文件到本地，返回 array(bool, errMsg)
     */
    function downloadFile($url, $dest)
    {
        if (!function_exists('curl_init')) {
            return array(false, '服务器未开启 curl 扩展');
        }
        $fp = @fopen($dest, 'wb');
        if (!$fp) { return array(false, '本地临时文件不可写：' . $dest); }
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_USERAGENT      => 'HomeSite-Updater/1.0',
        ));
        $ok   = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if (!$ok)                      { return array(false, $err ? $err : '下载失败'); }
        if ($code < 200 || $code >= 300) { @unlink($dest); return array(false, 'HTTP ' . $code); }
        if (!is_file($dest) || filesize($dest) < 100) { @unlink($dest); return array(false, '下载文件为空或过小'); }
        return array(true, '');
    }
}

if (!function_exists('formatBytes')) {
    function formatBytes($bytes)
    {
        $units = array('B', 'KB', 'MB', 'GB');
        $i = 0;
        $bytes = (float)$bytes;
        while ($bytes >= 1024 && $i < 3) { $bytes /= 1024; $i++; }
        return round($bytes, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }
}

if (!function_exists('writeUpdateLog')) {
    // 更新日志（追加），仅保留最后 200 行
    function writeUpdateLog($line)
    {
        $file = DATA_DIR . '/update.log';
        if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0755, true); }
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $line;
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if (is_array($lines) && count($lines) > 200) {
            @file_put_contents($file, implode(PHP_EOL, array_slice($lines, -200)) . PHP_EOL, LOCK_EX);
        }
        return true;
    }
}

if (!function_exists('readUpdateLog')) {
    function readUpdateLog($tail = 30)
    {
        $file = DATA_DIR . '/update.log';
        if (!is_file($file)) { return array(); }
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) { return array(); }
        return array_reverse(array_slice($lines, -$tail));
    }
}

if (!function_exists('fetchRemoteVersion')) {
    /**
     * 拉取更新源 update.json，返回 array(info|false, errMsg)
     * update.json 结构：
     *   { "version":"1.04", "url":"https://.../site-1.04.zip",
     *     "sha256":"...", "date":"2026-08-08", "notes":"更新说明", "min_php":"7.2" }
     */
    function fetchRemoteVersion($url)
    {
        if (!$url || !preg_match('#^https?://#i', $url)) {
            return array(false, '更新源地址无效（需以 http:// 或 https:// 开头）');
        }
        // 加时间戳绕过 CDN 缓存
        $u = $url . (strpos($url, '?') === false ? '?' : '&') . '_t=' . time();
        list($body, $err) = httpGet($u);
        if ($body === false) { return array(false, '拉取更新源失败：' . $err); }
        $info = json_decode($body, true);
        if (!is_array($info) || empty($info['version'])) {
            return array(false, '更新源返回的 JSON 格式不正确（需含 version 字段）');
        }
        if (!isComparableUpdateVersion((string)$info['version'])) {
            return array(false, '更新源版本号格式不正确（应为数字.数字，例如 1.20）');
        }
        // current 与 pending 至少有一个包含完整且可校验的下载信息；拒绝无哈希更新包。
        $hasCurrent = !empty($info['url']) && !empty($info['sha256'])
                   && preg_match('/^[a-f0-9]{64}$/i', (string)$info['sha256']);
        $hasPending = !empty($info['pending']) && is_array($info['pending'])
                      && !empty($info['pending']['version']) && !empty($info['pending']['url'])
                      && !empty($info['pending']['sha256'])
                      && preg_match('/^[a-f0-9]{64}$/i', (string)$info['pending']['sha256']);
        if (!$hasCurrent && !$hasPending) {
            return array(false, '更新源返回的 JSON 格式不正确（更新包必须包含下载地址和有效 SHA256）');
        }
        if ($hasPending && !isComparableUpdateVersion((string)$info['pending']['version'])) {
            return array(false, '待更新包的版本号格式不正确（应为数字.数字，例如 1.20）');
        }
        return array($info, '');
    }
}

if (!function_exists('verifyFileHash')) {
    function verifyFileHash($file, $expect)
    {
        if (!is_string($expect) || !preg_match('/^[a-f0-9]{64}$/i', $expect)) { return false; }
        if (!is_file($file)) { return false; }
        $got = hash_file('sha256', $file);
        return hash_equals(strtolower($expect), strtolower($got));
    }
}

if (!function_exists('getZipCommonRootPrefix')) {
    function getZipCommonRootPrefix($zip)
    {
        $tops = array();
        $hasRootFile = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) { continue; }
            $entry = ltrim(str_replace('\\', '/', $name), '/');
            if ($entry === '') { continue; }
            $slash = strpos($entry, '/');
            if ($slash === false) { $hasRootFile = true; break; }
            $tops[substr($entry, 0, $slash)] = 1;
        }
        if (!$hasRootFile && count($tops) === 1) {
            $keys = array_keys($tops);
            return $keys[0] . '/';
        }
        return '';
    }
}

if (!function_exists('releasePackageShouldStrip')) {
    /** Local configuration and user data must never travel in a cloud update package. */
    function releasePackageShouldStrip($entry)
    {
        $entry = trim(str_replace('\\', '/', (string)$entry), '/');
        if ($entry === '') { return false; }
        $parts = explode('/', $entry);
        if (in_array($parts[0], array('data', 'uploads', '.git', 'install'), true)) { return true; }
        if ($entry === 'includes/db.php' || $entry === 'install.lock') { return true; }
        return in_array(basename($entry), array('.env', '.env.local'), true);
    }
}

if (!function_exists('validateZipPackage')) {
    /**
     * Validate an archive before it is stored or extracted. Limits prevent zip
     * bombs and the deny list keeps deployment secrets out of release packages.
     */
    function validateZipPackage($zipPath, $maxArchiveBytes = 52428800, $maxFiles = 2000, $maxExpandedBytes = 104857600, $maxFileBytes = 20971520, $allowStrippableFiles = false)
    {
        if (!is_file($zipPath) || filesize($zipPath) < 1 || filesize($zipPath) > $maxArchiveBytes || !class_exists('\ZipArchive')) {
            return array(false, '压缩包无效或超过大小限制');
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) { return array(false, '无法读取压缩包'); }
        if ($zip->numFiles < 1 || $zip->numFiles > $maxFiles) {
            $zip->close();
            return array(false, '压缩包文件数量不合法');
        }
        $total = 0;
        $prefix = getZipCommonRootPrefix($zip);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat && isset($stat['name']) ? $stat['name'] : '';
            $entry = ltrim(str_replace('\\', '/', $name), '/');
            if ($entry === '' || strpos($entry, '..') !== false || preg_match('#^[a-zA-Z]:|^/#', $entry)) {
                $zip->close();
                return array(false, '压缩包包含不安全路径');
            }
            $relative = $prefix !== '' && strpos($entry, $prefix) === 0 ? substr($entry, strlen($prefix)) : $entry;
            if (!$allowStrippableFiles && releasePackageShouldStrip($relative)) {
                $zip->close();
                return array(false, '压缩包包含受保护文件或目录');
            }
            $attrs = (int)($stat['external_attributes'] ?? 0);
            if ((($attrs >> 16) & 0170000) === 0120000) {
                $zip->close();
                return array(false, '压缩包不能包含符号链接');
            }
            $size = (int)($stat['size'] ?? 0);
            if ($size < 0 || $size > $maxFileBytes) {
                $zip->close();
                return array(false, '压缩包包含过大的文件');
            }
            $total += $size;
            if ($total > $maxExpandedBytes) {
                $zip->close();
                return array(false, '压缩包解压后体积超过限制');
            }
        }
        $zip->close();
        return array(true, '');
    }
}

if (!function_exists('sanitizeZipPackage')) {
    /**
     * Rebuild an uploaded source archive into a safe release archive. Dangerous
     * paths still fail validation; local config/data paths are intentionally skipped.
     */
    function sanitizeZipPackage($zipPath, $destPath)
    {
        list($valid, $message) = validateZipPackage($zipPath, 52428800, 2000, 104857600, 20971520, true);
        if (!$valid) { return array(false, $message); }
        $source = new \ZipArchive();
        if ($source->open($zipPath) !== true) { return array(false, '无法读取压缩包'); }
        $dir = dirname($destPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { $source->close(); return array(false, '无法创建发布目录'); }
        $tmp = $destPath . '.tmp';
        @unlink($tmp);
        $target = new \ZipArchive();
        if ($target->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $source->close();
            return array(false, '无法创建净化后的升级包');
        }
        $prefix = getZipCommonRootPrefix($source);
        $written = 0;
        $failed = '';
        for ($i = 0; $i < $source->numFiles; $i++) {
            $name = $source->getNameIndex($i);
            if ($name === false) { continue; }
            $entry = ltrim(str_replace('\\', '/', $name), '/');
            if ($entry === '' || substr($entry, -1) === '/') { continue; }
            $relative = $prefix !== '' && strpos($entry, $prefix) === 0 ? substr($entry, strlen($prefix)) : $entry;
            if (releasePackageShouldStrip($relative)) { continue; }
            $stream = $source->getStream($name);
            if (!$stream) { $failed = '读取压缩包文件失败'; break; }
            $contents = stream_get_contents($stream);
            fclose($stream);
            if ($contents === false || !$target->addFromString($entry, $contents)) {
                $failed = '写入净化后的升级包失败';
                break;
            }
            $written++;
        }
        $target->close();
        $source->close();
        if ($failed !== '' || $written < 1) {
            @unlink($tmp);
            return array(false, $failed !== '' ? $failed : '压缩包不包含可发布的代码文件');
        }
        if (!@rename($tmp, $destPath)) {
            @unlink($tmp);
            return array(false, '保存净化后的升级包失败');
        }
        return array(true, '已自动剔除本地配置和数据文件');
    }
}

if (!function_exists('extractZipSafe')) {
    /**
     * 安全解压：锁定目标根为 destRoot 前缀，防 Zip Slip 路径穿越；排除指定文件/目录。
     * $excludeFiles: 根下文件名精确排除；$excludeDirs: 根下一级目录名排除。
     */
    function extractZipSafe($zipPath, $destRoot, $excludeFiles = array(), $excludeDirs = array(), $stripRoot = true)
    {
        $destRoot = realpath($destRoot);
        if (!$destRoot || !is_file($zipPath)) { return 0; }
        if (!class_exists('\ZipArchive')) { return 0; }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) { return 0; }
        if ($zip->numFiles < 1 || $zip->numFiles > 2000) { $zip->close(); return 0; }

        // 若压缩包所有内容都在同一个顶级目录下（如 site_source_V1.04/…），自动剥离该层
        $prefix = '';
        if ($stripRoot) {
            $tops = array();
            $hasRootFile = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = $zip->getNameIndex($i);
                if ($n === false) { continue; }
                $n = ltrim(str_replace('\\', '/', $n), '/');
                if ($n === '') { continue; }
                $p = strpos($n, '/');
                if ($p === false) { $hasRootFile = true; break; }
                $tops[substr($n, 0, $p)] = 1;
            }
            if (!$hasRootFile && count($tops) === 1) {
                $keys = array_keys($tops);
                $prefix = $keys[0] . '/';
            }
        }

        $count = 0;
        $expandedBytes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) { continue; }
            $entry = ltrim(str_replace('\\', '/', $name), '/');
            if ($prefix !== '') {
                if (strpos($entry, $prefix) !== 0) { continue; }
                $entry = substr($entry, strlen($prefix));
            }
            if ($entry === '') { continue; }
            if (strpos($entry, '..') !== false) { continue; }              // 防路径穿越 Zip Slip
            if (preg_match('#^[a-zA-Z]:|^/#', $entry)) { continue; }        // 防绝对路径
            $top = explode('/', $entry)[0];
            if (in_array($top, $excludeDirs, true)) { continue; }           // 排除受保护目录
            if (in_array(basename($entry), $excludeFiles, true)) { continue; } // 排除受保护文件

            $isDir = (substr($entry, -1) === '/');
            $stat = $zip->statIndex($i);
            $entrySize = (int)($stat['size'] ?? 0);
            if ($entrySize < 0 || $entrySize > 20971520 || $expandedBytes + $entrySize > 104857600) { continue; }
            $abs = $destRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry);
            $parent = $isDir ? rtrim($abs, DIRECTORY_SEPARATOR) : dirname($abs);
            if (!is_dir($parent)) { @mkdir($parent, 0755, true); }
            $realParent = realpath($parent);
            if ($realParent === false) { continue; }
            // 二次校验：规范化后仍必须落在 destRoot 内
            if (strpos($realParent . DIRECTORY_SEPARATOR, $destRoot . DIRECTORY_SEPARATOR) !== 0) { continue; }
            if ($isDir) { continue; }

            $stream = $zip->getStream($name);
            if (!$stream) { continue; }
            $out = @fopen($abs, 'wb');
            if (!$out) { fclose($stream); continue; }
            while (!feof($stream)) {
                $buf = fread($stream, 8192);
                if ($buf === false) { break; }
                fwrite($out, $buf);
            }
            fclose($out);
            fclose($stream);
            $expandedBytes += $entrySize;
            $count++;
        }
        $zip->close();
        return $count; // 返回成功写入的文件数，0 表示失败
    }
}
