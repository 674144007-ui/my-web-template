<?php
require_once 'auth.php';
requireRole(['teacher','developer']);
require_once 'db.php';

$teacher_id = $_SESSION['user_id'];

// ---------------------------------------
// Query ตารางสอนแบบ Prepared Statement
// ---------------------------------------
$stmt = $conn->prepare("
    SELECT day_of_week, class_name, subject, time_start, time_end 
    FROM teacher_schedule 
    WHERE teacher_id = ?
    ORDER BY 
        FIELD(day_of_week, 'จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์'),
        time_start
");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$data = $stmt->get_result();

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

    <?php if ($data->num_rows === 0): ?>
        <div class="empty">ยังไม่มีตารางสอนที่บันทึกไว้</div>
    <?php else: ?>

    <table>
        <tr>
            <th>วัน</th>
            <th>วิชา</th>
            <th>ชั้นเรียน</th>
            <th>เวลา</th>
        </tr>

        <?php while($row = $data->fetch_assoc()): ?>
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

    <?php endif; ?>

    <a class="back" href="dashboard_teacher.php">⬅ กลับหน้าครู</a>
</div>

</body>
</html>
