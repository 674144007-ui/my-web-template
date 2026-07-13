<?php
/**
 * api_online_users.php — Online User Tracker
 * ─────────────────────────────────────────────────────────────
 * เฉพาะ role: developer
 *
 * GET ?level=platform
 *   → จำนวน online ทุกโรงเรียน (อ่านจาก master + tenant แต่ละตัว)
 *
 * GET ?level=school&code=BKW
 *   → รายชื่อ user online ทั้งหมดในโรงเรียน BKW
 *     แยก students (ระดับ/ห้อง), teachers (กลุ่มสาระ), others
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

// ── เฉพาะ developer ─────────────────────────────────────────
requireRole(['developer']);

$level = $_GET['level'] ?? 'platform';
$code  = strtoupper(trim($_GET['code'] ?? ''));

// Online = last_seen_at ภายใน 5 นาที
define('ONLINE_THRESHOLD_MINUTES', 5);

// ══ Level: platform ══════════════════════════════════════════
if ($level === 'platform') {

    // ดึงโรงเรียนทั้งหมดจาก master
    $tenants = $master_pdo
        ->query("SELECT school_code, school_name, db_name FROM sys_tenants WHERE is_active = 1 ORDER BY id")
        ->fetchAll();

    $result   = [];
    $total    = 0;

    foreach ($tenants as $t) {
        // เชื่อมต่อ tenant DB ชั่วคราวเพื่อนับ
        try {
            $tpdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    env('MASTER_DB_HOST', 'localhost'),
                    (int) env('MASTER_DB_PORT', 3306),
                    $t['db_name']
                ),
                env('MASTER_DB_USER', 'root'),
                env('MASTER_DB_PASS', ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );

            $count = (int) $tpdo->query(
                "SELECT COUNT(DISTINCT user_id) FROM user_sessions
                 WHERE last_seen_at >= DATE_SUB(NOW(), INTERVAL " . ONLINE_THRESHOLD_MINUTES . " MINUTE)"
            )->fetchColumn();

        } catch (PDOException $e) {
            error_log('[online_users] tenant connect failed (' . $t['db_name'] . '): ' . $e->getMessage());
            $count = 0;
        }

        $total += $count;
        $result[] = [
            'school_code' => $t['school_code'],
            'school_name' => $t['school_name'],
            'online'      => $count,
        ];
    }

    echo json_encode([
        'level'   => 'platform',
        'total'   => $total,
        'schools' => $result,
        'ts'      => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ══ Level: school ═════════════════════════════════════════════
if ($level === 'school') {

    if (!$code) {
        http_response_code(400);
        echo json_encode(['error' => 'กรุณาระบุ ?code=SCHOOL_CODE']);
        exit;
    }

    // ตรวจสอบว่า school_code มีในระบบ
    $tenant_row = $master_pdo->prepare(
        "SELECT db_name, school_name FROM sys_tenants WHERE school_code = ? AND is_active = 1"
    );
    $tenant_row->execute([$code]);
    $trow = $tenant_row->fetch();

    if (!$trow) {
        http_response_code(404);
        echo json_encode(['error' => 'ไม่พบโรงเรียน ' . h($code)]);
        exit;
    }

    // เชื่อม tenant DB ที่ขอ (อาจต่างจาก $pdo ปัจจุบัน)
    try {
        $tpdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                env('MASTER_DB_HOST', 'localhost'),
                (int) env('MASTER_DB_PORT', 3306),
                $trow['db_name']
            ),
            env('MASTER_DB_USER', 'root'),
            env('MASTER_DB_PASS', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (PDOException $e) {
        http_response_code(503);
        echo json_encode(['error' => 'เชื่อมต่อ DB ไม่สำเร็จ']);
        exit;
    }

    // ดึง user ที่ online พร้อมข้อมูลครบ
    $rows = $tpdo->prepare(
        "SELECT
            s.user_id,
            s.page_current,
            s.last_seen_at,
            u.display_name,
            u.username,
            r.role_key,
            u.class_id,
            /* นักเรียน */
            c.class_name,
            c.level   AS class_level,
            c.room    AS class_room,
            /* ครู */
            t.full_name  AS teacher_name,
            t.department AS teacher_dept
         FROM user_sessions s
         JOIN users     u ON u.id = s.user_id
         JOIN sys_roles r ON r.id = u.role_id
         LEFT JOIN classes  c ON c.id = u.class_id AND r.role_key = 'student'
         LEFT JOIN teachers t ON t.user_id = u.id  AND r.role_key = 'teacher'
         WHERE s.school_code = ?
           AND s.last_seen_at >= DATE_SUB(NOW(), INTERVAL " . ONLINE_THRESHOLD_MINUTES . " MINUTE)
         ORDER BY r.role_key, u.display_name"
    );
    $rows->execute([$code]);
    $users = $rows->fetchAll();

    // จัดกลุ่ม
    $students  = [];
    $teachers  = [];
    $others    = [];

    foreach ($users as $u) {
        $base = [
            'user_id'      => $u['user_id'],
            'display_name' => $u['display_name'],
            'page_current' => $u['page_current'],
            'last_seen_at' => $u['last_seen_at'],
        ];

        if ($u['role_key'] === 'student') {
            $students[] = array_merge($base, [
                'class_name'  => $u['class_name'],
                'class_level' => $u['class_level'],
                'class_room'  => $u['class_room'],
            ]);
        } elseif ($u['role_key'] === 'teacher') {
            $teachers[] = array_merge($base, [
                'full_name'  => $u['teacher_name'] ?: $u['display_name'],
                'department' => $u['teacher_dept'],
            ]);
        } else {
            $others[] = array_merge($base, ['role' => $u['role_key']]);
        }
    }

    // สรุปนักเรียนแยก level (ม.1–ม.6)
    $by_level = [];
    foreach ($students as $s) {
        $lv = $s['class_level'] ?? 'ไม่ระบุ';
        if (!isset($by_level[$lv])) $by_level[$lv] = [];
        $by_level[$lv][] = $s;
    }
    ksort($by_level);

    // สรุปครูแยกกลุ่มสาระ
    $by_dept = [];
    foreach ($teachers as $t) {
        $dept = $t['department'] ?? 'ไม่ระบุ';
        if (!isset($by_dept[$dept])) $by_dept[$dept] = [];
        $by_dept[$dept][] = $t;
    }
    ksort($by_dept);

    echo json_encode([
        'level'       => 'school',
        'school_code' => $code,
        'school_name' => $trow['school_name'],
        'total'       => count($users),
        'summary'     => [
            'students' => count($students),
            'teachers' => count($teachers),
            'others'   => count($others),
        ],
        'students_by_level'  => $by_level,
        'teachers_by_dept'   => $by_dept,
        'others'             => $others,
        'ts'                 => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── fallback ────────────────────────────────────────────────
http_response_code(400);
echo json_encode(['error' => 'ระบุ ?level=platform หรือ ?level=school&code=BKW']);
