<?php
// export_csv.php - ส่งออกคะแนนจากระบบ AI เป็นไฟล์ Excel (Phase 5)
error_reporting(E_ALL);
ini_set('display_errors', 0); // ปิดการแสดง Error บนหน้าเว็บ เพื่อไม่ให้ไฟล์ CSV เสียหาย

require_once __DIR__ . '/env_loader.php';
require_once 'config.php';
require_once 'db.php';

if (!isset($pdo)) {
    die("Database Connection Error");
}

require_once 'auth.php';
requireRole(['teacher', 'developer']);

$assignment_id = isset($_GET['assignment_id']) ? intval($_GET['assignment_id']) : 0;
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;

$data = [];
$filename = "Student_Scores_" . date('Ymd_Hi') . ".csv";

try {
    // กรณีที่ส่งออกคะแนนตาม Assignment (การบ้านแบบเจาะจง)
    if ($assignment_id > 0) {
        $stmt_title = $pdo->prepare("SELECT title FROM assignments WHERE id = ?");
        $stmt_title->execute([$assignment_id]);
        $title = $stmt_title->fetchColumn();
        if ($title) {
            // ทำความสะอาดชื่อไฟล์ ไม่ให้มีอักขระพิเศษ
            $clean_title = preg_replace('/[^A-Za-z0-9ก-๙]/u', '_', $title);
            $filename = "Score_" . $clean_title . ".csv";
        }

        $sql = "
            SELECT u.username as 'รหัสนักเรียน', u.display_name as 'ชื่อ-นามสกุล', c.class_name as 'ห้องเรียน',
                   sa.status as 'สถานะ', sa.score as 'คะแนน', sa.submitted_at as 'เวลาส่งงาน'
            FROM student_assignments sa
            JOIN users u ON sa.student_id = u.id
            LEFT JOIN classes c ON u.class_id = c.id
            WHERE sa.assignment_id = ?
            ORDER BY c.level ASC, c.room ASC, u.username ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$assignment_id]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } 
    // กรณีส่งออกภาพรวมของนักเรียนทั้งห้อง (Overall Average)
    else {
        $sql = "
            SELECT u.username as 'รหัสนักเรียน', u.display_name as 'ชื่อ-นามสกุล', c.class_name as 'ห้องเรียน',
                   COUNT(sa.id) as 'จำนวนงานที่ส่ง', AVG(sa.score) as 'คะแนนเฉลี่ยรวม'
            FROM users u
            LEFT JOIN classes c ON u.class_id = c.id
            LEFT JOIN student_assignments sa ON u.id = sa.student_id
            WHERE u.role = 'student' AND u.is_deleted = 0
        ";
        $params = [];
        if ($class_id > 0) {
            $sql .= " AND u.class_id = ?";
            $params[] = $class_id;
            $filename = "Overall_Score_Class_" . $class_id . ".csv";
        }
        $sql .= " GROUP BY u.id ORDER BY c.level ASC, c.room ASC, u.username ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    die("Database Fetch Error");
}

// =========================================================
// 🚀 สร้างไฟล์ CSV และส่งให้เบราว์เซอร์ดาวน์โหลดทันที
// =========================================================
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// ใส่ BOM (Byte Order Mark) เพื่อให้เปิดใน Microsoft Excel แล้วภาษาไทยไม่เป็นภาษาต่างดาว
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

if (count($data) > 0) {
    // เขียนหัวคอลัมน์ (Headers)
    fputcsv($output, array_keys($data[0]));

    // เขียนข้อมูลนักเรียนทีละบรรทัด
    foreach ($data as $row) {
        // ปรับแต่งข้อมูลให้สวยงามก่อนลง Excel
        if (isset($row['สถานะ'])) {
            if ($row['สถานะ'] == 'graded' || $row['สถานะ'] == 'completed') $row['สถานะ'] = 'ส่งแล้ว';
            else $row['สถานะ'] = 'ยังไม่ส่ง';
        }
        if (isset($row['คะแนนเฉลี่ยรวม'])) {
            $row['คะแนนเฉลี่ยรวม'] = number_format(floatval($row['คะแนนเฉลี่ยรวม']), 2);
        }
        fputcsv($output, $row);
    }
} else {
    // กรณีไม่มีข้อมูล
    fputcsv($output, ['ไม่มีข้อมูลนักเรียนสำหรับเงื่อนไขที่เลือก']);
}

fclose($output);
exit;
?>