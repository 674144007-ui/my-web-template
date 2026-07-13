<?php
require_once 'security.php';
require_once 'auth.php';
requireRole(['developer']);
require_once 'db.php';

// -------------------------------------------------------
// โหลดรายชื่อครูแบบ Prepared Statement
// -------------------------------------------------------
$teacher_stmt = $pdo->prepare("SELECT id, display_name FROM users WHERE role = 'teacher' ORDER BY display_name");
$teacher_stmt->execute();
$teachers = $teacher_stmt;

// -------------------------------------------------------
// รับ teacher_id (ป้องกัน ID ปลอม)
// -------------------------------------------------------
$selected_teacher = isset($_GET['teacher_id']) ? intval($_GET['teacher_id']) : 0;
$schedule = null;

if ($selected_teacher > 0) {

    // ตรวจว่าครูมีอยู่จริง
    $check = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'teacher'");
    $check->execute([$selected_teacher]);
    $check->execute();
    
    if ($check->rowCount() > 0) {

        // -------------------------------------------------------
        // ดึงตารางสอนครู
        // -------------------------------------------------------
        $stmt = $pdo->prepare("
            SELECT id, day_of_week, subject, class_name, time_start, time_end 
            FROM teacher_schedule 
            WHERE teacher_id = ?
            ORDER BY 
                FIELD(day_of_week,'จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์'),
                time_start
        ");
        $stmt->execute([$selected_teacher]);
        $stmt->execute();
        $schedule = $stmt;
    }

    
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>ตารางสอนครูทั้งหมด</title>
<style>
body { font-family:system-ui;background:#0f172a;color:white;padding:20px;}
.card { background:#1e293b;padding:20px;border-radius:14px;max-width:900px;margin:auto; }
select { padding:10px;border-radius:8px;width:100%;margin-bottom:10px; }
table { width:100%;border-collapse:collapse;margin-top:10px;}
th, td { padding:10px;border:1px solid #475569;text-align:center; }
th { background:#2563eb; }
a { color:#60a5fa; text-decoration:none; }
.edit-btn { color:#22c55e;font-weight:bold; }
.del-btn { color:#ef4444;font-weight:bold; }
</style>
</head>
<body>

<div class="card">
    <h2>📅 ตารางสอนครูทั้งหมด</h2>

    <form method="GET">
        <label>เลือกครู</label>
        <select name="teacher_id" onchange="this.form.submit()">
            <option value="">-- เลือกครู --</option>
            <?php while($t = $teachers->fetch()): ?>
                <option value="<?= $t['id'] ?>" 
                    <?= $selected_teacher == $t['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($t['display_name']) ?>
                </option>
            <?php endwhile; ?>
        </select>
    </form>

    <?php if($selected_teacher && $schedule && $schedule->rowCount() > 0): ?>
        <table>
            <tr>
                <th>วัน</th>
                <th>วิชา</th>
                <th>ห้อง</th>
                <th>เวลา</th>
                <th>จัดการ</th>
            </tr>

            <?php while($s = $schedule->fetch()): ?>
            <tr>
                <td><?= htmlspecialchars($s['day_of_week']) ?></td>
                <td><?= htmlspecialchars($s['subject']) ?></td>
                <td><?= htmlspecialchars($s['class_name']) ?></td>
                <td>
                    <?= htmlspecialchars($s['time_start']) ?>
                    -
                    <?= htmlspecialchars($s['time_end']) ?>
                </td>
                <td>
                    <a class="edit-btn" href="dev_edit_schedule.php?id=<?= urlencode($s['id']) ?>">
                        แก้ไข
                    </a>
                    |
                    <a class="del-btn" 
                       href="dev_delete_schedule.php?id=<?= urlencode($s['id']) ?>"
                       onclick="return confirm('ต้องการลบรายการนี้หรือไม่?');">
                       ลบ
                    </a>
                </td>
            </tr>
            <?php endwhile; ?>
        </table>

    <?php elseif($selected_teacher): ?>
        <p>❗ ยังไม่มีตารางสอนสำหรับครูคนนี้</p>
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

    <br>
    <a href="dashboard_dev.php">⬅ กลับ Developer</a>
</div>

</body>
</html>
