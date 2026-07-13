<?php
/**
 * api_keep_alive.php — Multi-Tenant Edition
 * ─────────────────────────────────────────
 * 1. ต่ออายุ Session (เดิม)
 * 2. UPSERT user_sessions → อัปเดต last_seen_at (ใหม่)
 * 3. ส่ง heartbeat ทุก 60 วิจาก JS
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rate_limiter.php';

header('Content-Type: application/json; charset=utf-8');
checkApiRateLimit(120, 60);

// ── ต้อง login อยู่ ───────────────────────────────────────────
if (empty($_SESSION['user_id']) || empty($_SESSION['school_code'])) {
    echo json_encode(['status' => 'dead', 'message' => 'No active session.']);
    exit;
}

// ── 1. ต่ออายุ Session (เดิม) ────────────────────────────────
$_SESSION['last_activity'] = time();

// ── 2. UPSERT user_sessions ──────────────────────────────────
try {
    require_once __DIR__ . '/db.php';

    if ($pdo) {
        $token       = session_id();
        $user_id     = (int) $_SESSION['user_id'];
        $school_code = $_SESSION['school_code'];
        $page        = basename($_SERVER['HTTP_REFERER'] ?? '', '?');
        // ตัด query string ออก
        if (($qpos = strpos($page, '?')) !== false) {
            $page = substr($page, 0, $qpos);
        }
        $page        = substr($page, 0, 150);
        $ip          = $_SERVER['HTTP_X_FORWARDED_FOR']
                       ?? $_SERVER['REMOTE_ADDR']
                       ?? null;
        $ua          = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        // UPSERT: ถ้ามีแล้วให้อัปเดต last_seen_at + page_current
        $sql = "INSERT INTO user_sessions
                    (session_token, user_id, school_code, page_current, ip_address, user_agent, last_seen_at)
                VALUES
                    (:token, :uid, :school, :page, :ip, :ua, NOW())
                ON DUPLICATE KEY UPDATE
                    last_seen_at  = NOW(),
                    page_current  = VALUES(page_current)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':token'  => $token,
            ':uid'    => $user_id,
            ':school' => $school_code,
            ':page'   => $page ?: null,
            ':ip'     => $ip,
            ':ua'     => $ua,
        ]);

        // ── ลบ session เก่า > 5 นาที (cleanup) ─────────────
        $pdo->prepare(
            "DELETE FROM user_sessions
             WHERE school_code = :school
               AND last_seen_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
        )->execute([':school' => $school_code]);
    }
} catch (PDOException $e) {
    // ไม่ให้ error นี้หยุด session ต่ออายุ — แค่ log ไว้
    error_log('[keep_alive] DB error: ' . $e->getMessage());
}

echo json_encode(['status' => 'alive', 'message' => 'Session extended.']);
