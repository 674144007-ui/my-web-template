<?php
/**
 * logout.php — Multi-Tenant Edition
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

_destroySession();

header('Location: index.php?msg=logged_out');
exit;
