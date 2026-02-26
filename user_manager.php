<?php
require_once 'auth.php';
requireRole(['developer']);
require_once 'db.php';

$msg = "";
$msg_type = "";

// -----------------------------
// สร้าง CSRF Token
// -----------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// -----------------------------
// ลบผู้ใช้ (ใช้ POST เท่านั้น)
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {

    // ตรวจสอบ CSRF Token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf) {
        http_response_code(403);
        exit("❌ Invalid CSRF token");
    }

    $delete_id = intval($_POST['delete_id']);

    // ป้องกันลบตัวเอง
    if ($delete_id == $_SESSION['user_id']) {
        $msg = "❌ ไม่สามารถลบตัวเองได้";
        $msg_type = "error";
    } else {

        // ตรวจ role ผู้ที่จะถูกลบ
        $check = $conn->prepare("SELECT role FROM users WHERE id = ?");
        $check->bind_param("i", $delete_id);
        $check->execute();
        $check->store_result();

        if ($check->num_rows === 0) {
            $msg = "❌ ไม่พบบัญชีผู้ใช้";
            $msg_type = "error";
        } else {
            $check->bind_result($target_role);
            $check->fetch();

            // Developer ห้ามลบ Developer เพื่อความปลอดภัย
            if ($target_role === 'developer') {
                $msg = "❌ ไม่สามารถลบผู้ใช้ระดับ developer ได้";
                $msg_type = "error";
            } else {

                // Delete แบบ prepared
                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->bind_param("i", $delete_id);

                if ($stmt->execute()) {
                    $msg = "✔ ลบผู้ใช้สำเร็จ";
                    $msg_type = "success";
                } else {
                    $msg = "❌ เกิดข้อผิดพลาดในการลบผู้ใช้";
                    $msg_type = "error";
                }

                $stmt->close();
            }
        }

        $check->close();
    }
}

// -----------------------------
// ดึงข้อมูลผู้ใช้ทั้งหมด
// -----------------------------
$stmt = $conn->prepare("SELECT id, username, display_name, role FROM users ORDER BY role, username");
$stmt->execute();
$result = $stmt->get_result();

?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>จัดการผู้ใช้</title>
<style>
body {
    font-family: system-ui;
    background: #0A0F24;
    color: #E2E8F0;
    padding: 20px;
}
h2 { margin-bottom: 20px; }
.table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
}
.table th, .table td {
    padding: 12px;
    border-bottom: 1px solid #334155;
}
.btn-add {
    display:inline-block;
    padding: 10px 14px;
    background:#22c55e;
    color:#0f172a;
    border-radius:8px;
    text-decoration:none;
    font-weight:bold;
}
.btn-del {
    color:#f87171;
    font-weight:bold;
    text-decoration:none;
    cursor:pointer;
    background:none;
    border:none;
}
.btn-del:hover { color:#ef4444; }
.back {
    color:#60a5fa;
    text-decoration:none;
}
.back:hover { text-decoration:underline; }

.msg {
    padding: 10px;
    border-radius: 8px;
    margin-bottom: 15px;
}
.msg.success { background: #14532d; }
.msg.error   { background: #7f1d1d; }
</style>
</head>
<body>

<h2>👥 จัดการผู้ใช้งาน</h2>

<?php if ($msg): ?>
<div class="msg <?= htmlspecialchars($msg_type) ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<a class="btn-add" href="add_user.php">➕ เพิ่มผู้ใช้ใหม่</a>
<br><br>

<table class="table">
    <tr>
        <th>ID</th>
        <th>Username</th>
        <th>ชื่อแสดง</th>
        <th>บทบาท</th>
        <th>จัดการ</th>
    </tr>

    <?php while($row = $result->fetch_assoc()): ?>
    <tr>
        <td><?= htmlspecialchars($row['id']) ?></td>
        <td><?= htmlspecialchars($row['username']) ?></td>
        <td><?= htmlspecialchars($row['display_name']) ?></td>
        <td><?= htmlspecialchars($row['role']) ?></td>
        <td>
            <?php if ($row['role'] !== 'developer'): ?>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <button class="btn-del" onclick="return confirm('ต้องการลบผู้ใช้นี้?');">❌ ลบ</button>
                </form>
            <?php else: ?>
                <span style="opacity:0.5;">ไม่สามารถลบ</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php endwhile; ?>

</table>

<a class="back" href="dashboard_dev.php">⬅ กลับ Dev Dashboard</a>

</body>
</html>
