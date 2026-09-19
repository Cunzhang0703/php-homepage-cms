<?php
/**
 * 公开接口 · 站点信息
 * 向前台输出站点配置、作品与友链等公开数据。
 */

// Public site configuration used by the homepage.
require_once __DIR__ . '/../includes/common.php';

@header('Content-Type: application/json; charset=UTF-8');
@header('Cache-Control: public, max-age=60, stale-while-revalidate=300');

if (!isInstalled(ROOT_DIR) || !$DB) {
    http_response_code(503);
    echo json_encode(array('error' => '站点未安装或数据库不可用'), JSON_UNESCAPED_UNICODE);
    exit;
}

$tagsRaw = conf('tags', '');
$tags = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $tagsRaw)), function ($t) {
    return $t !== '';
}));

$linkRows = $DB->findAll('links', 'title,url,icon,note', array('enabled' => 1), 'sort ASC, id ASC');
$links = array();
if (is_array($linkRows) && $linkRows) {
    foreach ($linkRows as $r) {
        $links[] = array('text' => $r['title'], 'href' => $r['url'], 'icon' => $r['icon'], 'note' => $r['note']);
    }
} else {
    foreach (array(1, 2) as $i) {
        $t = conf('link' . $i . '_text', '');
        $h = conf('link' . $i . '_href', '');
        if ($t !== '' || $h !== '') {
            $links[] = array('text' => $t, 'href' => $h, 'icon' => '', 'note' => '');
        }
    }
}

$workRows = $DB->findAll('works', 'title,summary,cover,url,tags', array('enabled' => 1), 'sort ASC, id DESC', 24);
$works = array();
if (is_array($workRows)) {
    foreach ($workRows as $r) {
        $works[] = array(
            'title'   => $r['title'],
            'summary' => $r['summary'],
            'cover'   => $r['cover'],
            'url'     => $r['url'],
            'tags'    => array_values(array_filter(array_map('trim', explode(',', (string)$r['tags'])), function ($x) { return $x !== ''; })),
        );
    }
}

$data = array(
    'title'        => conf('site_title', ''),
    'name'         => conf('name', ''),
    'role'         => conf('role', ''),
    'bio'          => conf('bio', ''),
    'tags'         => $tags,
    'email'        => conf('email', ''),
    'wechat'       => conf('wechat', ''),
    'links'        => $links,
    'works'        => $works,
    'guestbook'    => conf('guestbook_on', '1') === '1',
    'copyright'    => array(
        'year' => conf('copyright_year', date('Y')),
        'name' => conf('copyright_name', ''),
    ),
    'icp'          => conf('icp', ''),
    'police'       => conf('police', ''),
    'accent'       => conf('accent', '#6366f1'),
    'avatar'       => conf('avatar', ''),
    'useHitokoto'  => conf('use_hitokoto', '1') === '1',
    'version'      => getSiteVersion(),
);

echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
