<?php
/**
 * api_sync_subjects.php — ซิงค์วิชาจาก teacher_schedules → teacher_subjects อัตโนมัติ
 * เรียกได้จาก teacher dashboard หรือ cron
 */
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once __DIR__ . '/rate_limiter.php';
checkApiRateLimit();
require_once 'logger.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$caller_id   = $_SESSION['user_id'];
$caller_role = $_SESSION['role'];

// developer sync ทุกคน, ครู sync เฉพาะตัวเอง
$target_teacher_id = ($caller_role === 'developer' || $caller_role === 'admin')
    ? (isset($_GET['teacher_id']) ? intval($_GET['teacher_id']) : null)
    : $caller_id;

$colors = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#06b6d4','#84cc16','#f97316','#6366f1'];
$icons  = ['🔬','📐','📚','🎨','⚽','🎵','🌍','💻','📖','🔭','🧮','🏛️'];

try {
    // ดึง teacher_schedules ที่ยังไม่ถูก sync
    $sql = "
        SELECT DISTINCT
            ts.teacher_id,
            ts.subject_code,
            COALESCE(ts.subject_name, s.subject_name, ts.subject_code) AS subject_name,
            ts.class_room,
            c.id   AS class_id,
            c.class_name,
            s.id   AS subject_master_id,
            s.color_theme,
            s.icon
        FROM teacher_schedules ts
        LEFT JOIN classes  c ON CONCAT(
            CASE
                WHEN ts.class_room LIKE 'ม.%' THEN ts.class_room
                ELSE CONCAT('ม.', SUBSTRING_INDEX(ts.class_room,'/',1))
            END
        ) = c.class_name
            OR c.class_name = ts.class_room
        LEFT JOIN subjects s ON s.subject_code = ts.subject_code AND s.is_active = 1
        WHERE ts.is_activity = 0
        " . ($target_teacher_id ? "AND ts.teacher_id = ?" : "") . "
        ORDER BY ts.teacher_id, ts.subject_code, ts.class_room
    ";

    $stmt = $pdo->prepare($sql);
    $target_teacher_id ? $stmt->execute([$target_teacher_id]) : $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ตรวจ teacher_subjects ที่มีอยู่แล้ว
    $exist_sql = "SELECT CONCAT(teacher_id,'_',subject_code,'_',class_id) AS key_combo FROM teacher_subjects";
    if ($target_teacher_id) $exist_sql .= " WHERE teacher_id = ?";
    $stmt_ex = $pdo->prepare($exist_sql);
    $target_teacher_id ? $stmt_ex->execute([$target_teacher_id]) : $stmt_ex->execute();
    $existing = array_flip($stmt_ex->fetchAll(PDO::FETCH_COLUMN));

    $stmt_ins = $pdo->prepare(
        "INSERT INTO teacher_subjects (teacher_id, subject_code, subject_name, class_id, cover_color)
         VALUES (?,?,?,?,?)"
    );
    // เพิ่ม subject ใน subjects table ถ้ายังไม่มี
    $stmt_sub_ins = $pdo->prepare(
        "INSERT IGNORE INTO subjects (subject_code, subject_name, color_theme, icon, is_active)
         VALUES (?,?,?,?,1)"
    );

    $added = 0; $skipped = 0;
    $color_i = 0; $icon_i = 0;

    foreach ($rows as $r) {
        if (!$r['class_id']) { $skipped++; continue; } // ไม่พบห้องในระบบ

        $combo = $r['teacher_id'] . '_' . $r['subject_code'] . '_' . $r['class_id'];
        if (isset($existing[$combo])) { $skipped++; continue; }

        // เพิ่มใน subjects master ถ้ายังไม่มี
        if (!$r['subject_master_id']) {
            $color = $colors[$color_i % count($colors)];
            $icon  = $icons[$icon_i % count($icons)];
            $stmt_sub_ins->execute([$r['subject_code'], $r['subject_name'], $color, $icon]);
            $color_i++; $icon_i++;
        }

        $cover = $r['color_theme'] ?? $colors[$added % count($colors)];
        $stmt_ins->execute([$r['teacher_id'], $r['subject_code'], $r['subject_name'], $r['class_id'], $cover]);
        $added++;
    }

    systemLog($caller_id, 'SYNC_SUBJECTS', "Added:{$added} Skipped:{$skipped}");
    echo json_encode(['status'=>'success','added'=>$added,'skipped'=>$skipped]);

} catch (Exception $e) {
    error_log("Sync subjects error: " . $e->getMessage());
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
?>
