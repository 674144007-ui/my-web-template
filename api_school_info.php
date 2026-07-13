<?php
/**
 * api_school_info.php — AJAX: ดึงข้อมูลโรงเรียนจาก school_code
 * ใช้ในหน้า login เพื่อแสดงโลโก้ + ชื่อ + สีหลักก่อน login
 */
ob_start();
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/config.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$code = strtoupper(trim($_GET['code'] ?? ''));
if (!$code || !preg_match('/^[A-Z0-9]{2,10}$/', $code)) {
    echo json_encode(['ok' => false]);
    exit;
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            env('MASTER_DB_HOST','localhost'),
            (int)env('MASTER_DB_PORT',3306),
            env('MASTER_DB_NAME','db_ssp_master')),
        env('MASTER_DB_USER','root'),
        env('MASTER_DB_PASS',''),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
    $stmt = $pdo->prepare(
        "SELECT school_code, school_name, primary_color, logo_path
         FROM sys_tenants WHERE school_code=? AND is_active=1 LIMIT 1"
    );
    $stmt->execute([$code]);
    $row = $stmt->fetch();

    if (!$row) {
        echo json_encode(['ok' => false]);
        exit;
    }

    $logo_url = null;
    if (!empty($row['logo_path'])) {
        $logo_file = __DIR__ . '/' . ltrim($row['logo_path'], '/');
        if (file_exists($logo_file)) {
            // Build absolute URL จาก script location
            $script_dir = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');
            $logo_url   = $script_dir . '/' . ltrim($row['logo_path'], '/');
        }
    }

    echo json_encode([
        'ok'            => true,
        'school_name'   => $row['school_name'],
        'primary_color' => $row['primary_color'],
        'logo_url'      => $logo_url,
        'logo_path'     => $row['logo_path'] ?? null,
    ]);
} catch (Exception $e) {
    echo json_encode(['ok' => false]);
}
