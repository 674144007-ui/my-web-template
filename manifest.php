<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/manifest+json; charset=utf-8');

$logo = 'uploads/logos/logo_bkw.png'; // default
if (!empty($tenant['logo_path']) && file_exists(__DIR__.'/'.$tenant['logo_path'])) {
    $logo = $tenant['logo_path'];
}

$name       = $tenant['school_name'] ?? 'Smart School Plus';
$color      = $tenant['primary_color'] ?? '#1e3a8a';

echo json_encode([
    'name'             => $name,
    'short_name'       => mb_substr($name, 0, 12),
    'description'      => 'ระบบบริหารจัดการห้องเรียนอัจฉริยะ',
    'start_url'        => './index.php',
    'display'          => 'standalone',
    'background_color' => '#f1f5f9',
    'theme_color'      => $color,
    'orientation'      => 'portrait-primary',
    'icons'            => [
        ['src' => $logo, 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => $logo, 'sizes' => '512x512', 'type' => 'image/png'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
