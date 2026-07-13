<?php
/**
 * api_heartbeat.php — Online Status Heartbeat + Lab State Sync (Phase 6)
 * เรียกทุก 60 วิ จาก header.php
 * เพิ่ม: รับ lab_state JSON → บันทึกใน user_sessions เพื่อ restore state
 */
ob_start();
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isLoggedIn()) {
    echo json_encode(['ok'=>false,'status'=>'not_logged_in']);
    exit;
}

$uid   = (int)($_SESSION['user_id']    ?? 0);
$role  = $_SESSION['role']              ?? 'unknown';
$name  = $_SESSION['display_name']      ?? '';
$scode = $_SESSION['school_code']       ?? ($_SESSION['dev_school_code'] ?? 'DEV');
$page  = basename($_POST['page']        ?? ($_SERVER['HTTP_REFERER'] ?? 'unknown'));
$ip    = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$sid   = session_id();

$_SESSION['last_activity'] = time();

// ── รับ lab_state JSON (ส่งมาจาก _startAutosave ใน lab_realistic.php) ──
$raw_body = file_get_contents('php://input');
$body = $raw_body ? json_decode($raw_body, true) : [];
$lab_state_json = null;
if (!empty($body['lab_state']) && is_array($body['lab_state'])) {
    // sanitize: เก็บเฉพาะ telemetry + hp (ไม่เก็บ full beaker state เพื่อประหยัด space)
    $lab_state_json = json_encode([
        'hp'        => (int)($body['lab_state']['hp'] ?? 100),
        'telemetry' => $body['lab_state']['telemetry'] ?? [],
        'ts'        => time(),
    ]);
}

if ($pdo && $uid) {
    try {
        $pdo->prepare("
            INSERT INTO user_sessions
                (session_token, user_id, school_code, role_key, display_name,
                 page_current, ip_address, last_seen_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                page_current  = VALUES(page_current),
                display_name  = VALUES(display_name),
                last_seen_at  = NOW()
        ")->execute([$sid, $uid, $scode, $role, $name, $page, $ip]);

        // บันทึก lab_state ถ้ามี (column lab_state_json อาจยังไม่มี → catch gracefully)
        if ($lab_state_json) {
            try {
                $pdo->prepare("
                    UPDATE user_sessions SET lab_state_json = ? WHERE session_token = ?
                ")->execute([$lab_state_json, $sid]);
            } catch (PDOException $e) {
                // column ยังไม่มี — ไม่ error
            }
        }

        // ลบ session inactive > 10 นาที
        $pdo->prepare("DELETE FROM user_sessions WHERE last_seen_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)")
            ->execute();

        echo json_encode(['ok'=>true,'status'=>'alive','ts'=>time()]);
    } catch (PDOException $e) {
        echo json_encode(['ok'=>true,'status'=>'alive','note'=>'sessions table not ready']);
    }
} else {
    echo json_encode(['ok'=>true,'status'=>'alive']);
}
