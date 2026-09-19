<?php
/**
 * 云更新发布端 · 函数库
 * 生成 update.json、净化源码包（剔除 db.php / data / uploads / install 等），并从包内提取「更新记录.txt」作为更新说明。
 */

// Shared storage and manifest helpers for the update library.
if (!defined('UPDATE_ADMIN_DIR')) { define('UPDATE_ADMIN_DIR', __DIR__); }
if (!defined('RELEASE_DIR'))     { define('RELEASE_DIR', UPDATE_ADMIN_DIR . '/releases'); }
if (!defined('UPDATE_JSON'))     { define('UPDATE_JSON', UPDATE_ADMIN_DIR . '/update.json'); }

if (!function_exists('ensureUpdateSchema')) {
    function ensureUpdateSchema()
    {
        global $DB;
        if (!$DB) { return false; }
        $DB->exec("CREATE TABLE IF NOT EXISTS pre_updates (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            version VARCHAR(32) NOT NULL DEFAULT '',
            channel VARCHAR(16) NOT NULL DEFAULT 'release',
            notes TEXT,
            file VARCHAR(255) NOT NULL DEFAULT '',
            url VARCHAR(512) NOT NULL DEFAULT '',
            sha256 VARCHAR(64) NOT NULL DEFAULT '',
            min_php VARCHAR(16) NOT NULL DEFAULT '',
            is_current TINYINT NOT NULL DEFAULT 0,
            status VARCHAR(16) NOT NULL DEFAULT 'current',
            created_at DATETIME DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try {
            $DB->exec("ALTER TABLE pre_updates ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'current'");
        } catch (\Throwable $e) { /* 列已存在，忽略 */ }
        return true;
    }
}

if (!function_exists('listVersions')) {
    function listVersions()
    {
        global $DB;
        if (!$DB) { return array(); }
        $rows = $DB->findAll('updates', '*', array(), 'id DESC');
        return $rows ? $rows : array();
    }
}

if (!function_exists('webBase')) {
    function webBase()
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        return $scheme . '://' . $host . $base;
    }
}

if (!function_exists('formatUpdateNotesForLog')) {
    function formatUpdateNotesForLog($notes, $maxLength = 300)
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string)$notes));
        if ($text === '') { return '更新说明：未填写'; }

        $maxLength = max(40, (int)$maxLength);
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') > $maxLength) {
                $text = mb_substr($text, 0, $maxLength, 'UTF-8') . '…';
            }
        } elseif (strlen($text) > $maxLength) {
            $text = substr($text, 0, $maxLength) . '…';
        }
        return '更新说明：' . $text;
    }
}

if (!function_exists('publishVersion')) {
    function publishVersion($srcTmp, $version, $notes, $minPhp, $channel)
    {
        global $DB;
        if (!$DB) { return array(false, '数据库未初始化'); }
        if (!is_file($srcTmp)) { return array(false, '压缩包不存在'); }

        $safeVer = preg_replace('/[^a-zA-Z0-9._-]/', '', $version);
        if (!$safeVer) { return array(false, '版本号非法'); }

        if (!is_dir(RELEASE_DIR)) { @mkdir(RELEASE_DIR, 0755, true); }
        $destName = 'site-' . $safeVer . '.zip';
        $destPath = RELEASE_DIR . '/' . $destName;
        $tmpPath = RELEASE_DIR . '/.site-' . $safeVer . '-' . randomStr(8) . '.zip';

        list($packageOk, $packageMsg) = sanitizeZipPackage($srcTmp, $tmpPath);
        if (!$packageOk) { return array(false, $packageMsg); }

        if (is_file($destPath)) { @unlink($destPath); }
        $moved = @rename($tmpPath, $destPath);
        if (!$moved || !is_file($destPath)) { @unlink($tmpPath); return array(false, '文件保存失败'); }

        $sha  = hash_file('sha256', $destPath);
        $url  = webBase() . '/releases/' . $destName;

        $DB->update('updates', array('status' => 'superseded'), array('status' => 'pending'));
        $DB->insert('updates', array(
            'version'    => $safeVer,
            'channel'    => $channel,
            'notes'      => $notes,
            'file'       => $destName,
            'url'        => $url,
            'sha256'     => $sha,
            'min_php'    => $minPhp,
            'is_current' => 0,
            'status'     => 'pending',
            'created_at' => 'NOW()',
        ));
        regenerateUpdateJson();
        return array(true, $url);
    }
}

if (!function_exists('rollbackTo')) {
    function rollbackTo($id)
    {
        global $DB;
        if (!$DB) { return false; }
        $row = $DB->find('updates', '*', array('id' => $id));
        if (!$row) { return false; }
        $DB->update('updates', array('is_current' => 0), array('is_current' => 1));
        $DB->update('updates', array('is_current' => 1, 'status' => 'current'), array('id' => $id));
        regenerateUpdateJson();
        return true;
    }
}

if (!function_exists('pendingVersion')) {
    function pendingVersion()
    {
        global $DB;
        if (!$DB) { return null; }
        $rows = $DB->findAll('updates', '*', array('status' => 'pending'), 'id DESC');
        return ($rows && !empty($rows)) ? $rows[0] : null;
    }
}

if (!function_exists('activateVersion')) {
    function activateVersion($id)
    {
        global $DB;
        if (!$DB) { return false; }
        $row = $DB->find('updates', '*', array('id' => $id));
        if (!$row) { return false; }
        $DB->update('updates', array('is_current' => 0), array('is_current' => 1));
        $DB->update('updates', array('is_current' => 1, 'status' => 'current'), array('id' => $id));
        regenerateUpdateJson();
        return true;
    }
}

if (!function_exists('regenerateUpdateJson')) {
    function regenerateUpdateJson()
    {
        global $DB;
        if (!$DB) { return false; }
        $row  = $DB->find('updates', '*', array('is_current' => 1));
        $pend = pendingVersion();

        if (!$row && !$pend) {
            if (is_file(UPDATE_JSON)) { @unlink(UPDATE_JSON); }
            return false;
        }

        if ($row) {
            $data = array(
                'version' => $row['version'],
                'url'     => $row['url'],
                'sha256'  => $row['sha256'],
                'date'    => substr((string)($row['created_at'] ?? date('Y-m-d')), 0, 10),
                'min_php' => $row['min_php'],
                'channel' => $row['channel'],
                'notes'   => $row['notes'],
            );
        } else {
            $siteVer = '0.0.0';
            $siteVerFile = dirname(UPDATE_ADMIN_DIR) . '/version.json';
            if (is_file($siteVerFile)) {
                $j = @json_decode(@file_get_contents($siteVerFile), true);
                if (!empty($j['version'])) { $siteVer = $j['version']; }
            }
            $data = array(
                'version' => $siteVer,
                'url'     => '',
                'sha256'  => '',
                'date'    => date('Y-m-d'),
                'min_php' => '',
                'channel' => '',
                'notes'   => '等待管理员确认首个更新包。',
            );
        }

        if ($pend) {
            $data['pending'] = array(
                'id'      => (int)$pend['id'],
                'version' => $pend['version'],
                'url'     => $pend['url'],
                'sha256'  => $pend['sha256'],
                'date'    => substr((string)($pend['created_at'] ?? date('Y-m-d')), 0, 10),
                'min_php' => $pend['min_php'],
                'channel' => $pend['channel'],
                'notes'   => $pend['notes'],
                'file'    => $pend['file'],
            );
        }
        @file_put_contents(UPDATE_JSON, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return true;
    }
}

if (!function_exists('currentPublished')) {
    function currentPublished()
    {
        if (!is_file(UPDATE_JSON)) { return null; }
        $j = @json_decode(@file_get_contents(UPDATE_JSON), true);
        return is_array($j) ? $j : null;
    }
}

if (!function_exists('distillReleaseNotes')) {
    function distillReleaseNotes($zipPath)
    {
        if (!is_file($zipPath) || !class_exists('ZipArchive')) { return ''; }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== TRUE) { return ''; }
        $content = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $base = preg_replace('#^.*/#u', '', $name);   // 兼容 site_source_V1.06/更新记录.txt
            if ($base === '更新记录.txt') {
                $content = (string)$zip->getFromIndex($i);
                break;
            }
        }
        $zip->close();
        return $content === '' ? '' : _distillNotesText($content);
    }
}

if (!function_exists('_distillNotesText')) {
    function _distillNotesText($txt)
    {
        $lines = preg_split('/\r\n|\r|\n/', $txt);
        $blocks = array(); $curTitle = null; $buf = array();
        foreach ($lines as $ln) {
            if (preg_match('/^【\s*V[0-9]+(\.[0-9]+)*\s*·\s*[0-9\-]+\s*】/u', $ln)) {
                if ($curTitle !== null) { $blocks[] = $buf; }
                $curTitle = $ln; $buf = array();
            } elseif ($curTitle !== null) { $buf[] = $ln; }
        }
        if ($curTitle !== null) { $blocks[] = $buf; }

        $clean = function($arr) {
            $out = array();
            foreach ($arr as $l) {
                $t = trim($l);
                if ($t === '') { continue; }
                if (preg_match('/^[=\-*_]{3,}$/u', $t)) { continue; }            // 装饰线
                if (preg_match('/^(共\s*\d+\s*个版本记录|以下按版本顺序累积记录|当前版本：|更新记录)/u', $t)) { continue; }
                $out[] = $l;
            }
            return trim(implode("\n", $out));
        };

        if (!empty($blocks)) {
            $last = $clean(end($blocks));
            if ($last !== '') { return $last; }
        }
        return $clean($lines);   // 退化：无版本块则整文去噪
    }
}
