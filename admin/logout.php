<?php
/**
 * 退出登录：清除 admin_token cookie 并跳转登录页
 */
require_once '../includes/common.php';
clearAdminAuthCookie();
writeSecurityLog('logout');
header('Location: login.php');
exit;
