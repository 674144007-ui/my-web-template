<?php
require_once 'security.php';
require_once 'auth.php';
requireRole(['teacher','developer']);
require_once 'db.php';

$teacher_id = $_SESSION['user_id'];

// ---------------------------------------
// Query ตารางสอนแบบ Prepared Statement
// ---------------------------------------
$stmt = $pdo->prepare("
    SELECT day_of_week, class_name, subject, time_start, time_end 
    FROM teacher_schedule 
    WHERE teacher_id = ?
    ORDER BY 
        FIELD(day_of_week, 'จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์'),
        time_start
");
$stmt->execute([$teacher_id]);
$stmt->execute();
$data = $stmt;

// ---------------------------------------
// สำหรับความปลอดภัย: วันในระบบต้องเป็นวันจริงเท่านั้น
// ---------------------------------------
$valid_days = ['จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>ตารางสอนของครู</title>
<style>
body { font-family:system-ui; background:#e0f2fe; padding:20px; }
.table-container {
    background:white; padding:20px; border-radius:14px;
    max-width:800px; margin:auto; box-shadow:0 8px 20px rgba(0,0,0,0.15);
}
table {
    width:100%; border-collapse:collapse; margin-top:10px;
}
th, td {
    border:1px solid #cbd5e1; padding:10px; text-align:center;
}
th { background:#2563eb; color:white; }
.back {
    display:inline-block; margin-top:20px; text-decoration:none;
    padding:8px 14px; background:#2563eb; color:white; border-radius:8px;
}
.empty {
    background:#fef9c3; padding:12px; border-radius:8px; margin-top:10px;
    color:#854d0e; text-align:center;
}
</style>
</head>
<body>

<div class="table-container">
    <h2>📅 ตารางสอนในเทอมนี้</h2>

    <?php if ($data->rowCount() === 0): ?>
        <div class="empty">ยังไม่มีตารางสอนที่บันทึกไว้</div>
    <?php else: ?>

    <table>
        <tr>
            <th>วัน</th>
            <th>วิชา</th>
            <th>ชั้นเรียน</th>
            <th>เวลา</th>
        </tr>

        <?php while($row = $data->fetch()): ?>
        <tr>
            <td>
                <?php
                $day = $row['day_of_week'];
                echo in_array($day, $valid_days)
                    ? htmlspecialchars($day)
                    : "<span style='color:red;'>ข้อมูลผิดพลาด</span>";
                ?>
            </td>

            <td><?= htmlspecialchars($row['subject']) ?></td>
            <td><?= htmlspecialchars($row['class_name']) ?></td>

            <td>
                <?= htmlspecialchars($row['time_start']) ?>
                 - 
                <?= htmlspecialchars($row['time_end']) ?>
            </td>
        </tr>
        <?php endwhile; ?>

    </table>

    <?php endif; 
    } catch (\PDOException $e) {
        error_log('[db_error] ' . $e->getMessage() . ' in ' . __FILE__ . ':' . __LINE__);
        if (!headers_sent()) {
            http_response_code(500);
        }
        $is_dev = defined('APP_ENV') && APP_ENV === 'development' && defined('APP_DEBUG') && APP_DEBUG;
        $msg = $is_dev ? htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') : 'กรุณาลองใหม่อีกครั้ง';
        die('<div style="font-family:sans-serif;text-align:center;padding:3rem">'
          . '<h3 style="color:#ef4444">เกิดข้อผิดพลาดในระบบฐานข้อมูล</h3>'
          . '<p style="color:#6b7280">' . $msg . '</p>'
          . '<a href="index.php" style="color:#3b82f6">← กลับหน้าหลัก</a>'
          . '</div>');
    }

?>

try {

    <a class="back" href="dashboard_teacher.php">⬅ กลับหน้าครู</a>
</div>

</body>
</html>
