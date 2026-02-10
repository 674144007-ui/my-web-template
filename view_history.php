<?php
// view_history.php - ดูประวัติการศึกษาย้อนหลัง
session_start();
require_once 'auth.php';
require_once 'db.php';
requireRole(['developer', 'admin']);

// ดึงรายการปีการศึกษาที่มีในประวัติ
$years = $conn->query("SELECT DISTINCT academic_year FROM student_history ORDER BY academic_year DESC");

$selected_year = isset($_GET['year']) ? intval($_GET['year']) : '';
$history_data = [];

if ($selected_year) {
    $sql = "SELECT h.*, u.display_name, u.username 
            FROM student_history h 
            JOIN users u ON h.user_id = u.id 
            WHERE h.academic_year = $selected_year 
            ORDER BY h.old_class_level, u.username";
    $res = $conn->query($sql);
    while($row = $res->fetch_assoc()) {
        $history_data[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Academic History</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Sarabun', sans-serif; background: #f1f5f9; padding: 20px; }
    .container { max-width: 1000px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
    h2 { margin-top:0; color:#1e293b; border-bottom:2px solid #e2e8f0; padding-bottom:10px; }
    
    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
    th { background: #f8fafc; color: #64748b; }
    
    .btn-back { display:inline-block; margin-bottom:20px; text-decoration:none; color:#64748b; }
    select { padding:10px; font-size:1rem; border-radius:5px; border:1px solid #cbd5e1; }
</style>
</head>
<body>

<div class="container">
    <a href="dashboard_dev.php" class="btn-back">⬅ กลับ Dashboard</a>
    <h2>📜 ประวัติการศึกษาย้อนหลัง (History Log)</h2>
    
    <form method="get" style="background:#f0f9ff; padding:15px; border-radius:8px;">
        <label style="font-weight:bold;">เลือกปีการศึกษา:</label>
        <select name="year" onchange="this.form.submit()">
            <option value="">-- กรุณาเลือก --</option>
            <?php while($y = $years->fetch_assoc()): ?>
                <option value="<?= $y['academic_year'] ?>" <?= $selected_year==$y['academic_year']?'selected':'' ?>>
                    ปีการศึกษา <?= $y['academic_year'] ?>
                </option>
            <?php endwhile; ?>
        </select>
    </form>

    <?php if ($selected_year && count($history_data) > 0): ?>
        <h3 style="color:#0ea5e9;">ข้อมูลปีการศึกษา <?= $selected_year ?></h3>
        <table>
            <thead>
                <tr>
                    <th>รหัสนักเรียน</th>
                    <th>ชื่อ-นามสกุล</th>
                    <th>ชั้นเรียนในขณะนั้น</th>
                    <th>บันทึกเมื่อ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($history_data as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['username']) ?></td>
                    <td><?= htmlspecialchars($row['display_name']) ?></td>
                    <td><span style="background:#dbeafe; color:#1e40af; padding:2px 8px; border-radius:4px;"><?= $row['old_class_level'] ?></span></td>
                    <td style="font-size:0.85rem; color:#94a3b8;"><?= $row['recorded_at'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($selected_year): ?>
        <p style="text-align:center; margin-top:30px; color:#64748b;">ไม่พบข้อมูลประวัติของปีนี้</p>
    <?php endif; ?>
</div>

</body>
</html>