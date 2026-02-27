<?php
// export_csv.php - Backend สำหรับส่งออกข้อมูลเป็นไฟล์ Excel/CSV (Phase 7)
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once 'logger.php';

// บังคับสิทธิ์
requireRole(['teacher', 'developer']);

if (!isset($_GET['class_id'])) {
    die("Invalid Request");
}

$class_id = intval($_GET['class_id']);
$teacher_id = $_SESSION['user_id'];

// ดึงชื่อชั้นเรียนเพื่อเอาไปตั้งชื่อไฟล์
$class_name = "All_Classes";
if ($class_id > 0) {
    $stmt_c = $conn->prepare("SELECT class_name FROM classes WHERE id = ?");
    $stmt_c->bind_param("i", $class_id);
    $stmt_c->execute();
    $res_c = $stmt_c->get_result();
    if ($res_c->num_rows > 0) {
        $class_name = str_replace(['/', ' '], ['_', ''], $res_c->fetch_assoc()['class_name']);
    }
    $stmt_c->close();
}

$filename = "Lab_Report_" . $class_name . "_" . date('Ymd') . ".csv";

// ตั้งค่า Header ให้เบราว์เซอร์รู้ว่านี่คือไฟล์ดาวน์โหลด CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// ป้องกันปัญหาภาษาไทยเป็นภาษาต่างดาวใน Excel ด้วยการพิมพ์ BOM (Byte Order Mark)
echo "\xEF\xBB\xBF";

// เปิด output stream
$output = fopen('php://output', 'w');

// พิมพ์หัวตาราง (Header Row)
fputcsv($output, ['ลำดับ', 'รหัสนักเรียน', 'ชื่อ-นามสกุล', 'ระดับชั้น', 'จำนวนครั้งที่ทดลอง', 'คะแนนเฉลี่ย', 'คะแนนสูงสุด', 'เกรดประเมิน', 'จำนวนอุบัติเหตุ']);

// สร้าง Query ดึงข้อมูลเด็กและสรุปผลแล็บ
$sql = "
    SELECT 
        u.username, 
        u.display_name, 
        c.class_name,
        COUNT(lr.id) as total_labs,
        AVG(lr.final_score) as avg_score,
        MAX(lr.final_score) as max_score,
        SUM(CASE WHEN lr.hp_remaining < 50 THEN 1 ELSE 0 END) as accidents
    FROM users u
    LEFT JOIN classes c ON u.class_id = c.id
    LEFT JOIN lab_reports lr ON u.id = lr.student_id
    WHERE u.role = 'student' AND u.is_deleted = 0
";

if ($class_id > 0) {
    $sql .= " AND u.class_id = $class_id";
}
$sql .= " GROUP BY u.id ORDER BY u.username ASC";

$result = $conn->query($sql);

$counter = 1;
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        
        $total_labs = intval($row['total_labs']);
        $avg_score = $total_labs > 0 ? round(floatval($row['avg_score']), 1) : 0;
        $max_score = intval($row['max_score']);
        $accidents = intval($row['accidents']);
        
        // คำนวณเกรด
        $grade = "-";
        if ($total_labs > 0) {
            if ($avg_score >= 80) $grade = "A";
            elseif ($avg_score >= 70) $grade = "B";
            elseif ($avg_score >= 60) $grade = "C";
            elseif ($avg_score >= 50) $grade = "D";
            else $grade = "F";
        }

        // จับยัดลงไฟล์ CSV
        fputcsv($output, [
            $counter,
            $row['username'],
            $row['display_name'],
            $row['class_name'] ?? 'ไม่ระบุ',
            $total_labs,
            $avg_score,
            $max_score,
            $grade,
            $accidents
        ]);
        $counter++;
    }
} else {
    fputcsv($output, ['ไม่พบข้อมูลนักเรียนในชั้นเรียนนี้']);
}

fclose($output);

// บันทึก Log ว่าครูทำการโหลดข้อมูล
systemLog($teacher_id, 'EXPORT_DATA', "Exported CSV for Class ID: $class_id");
exit;
?>