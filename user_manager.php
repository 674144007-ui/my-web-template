<?php
// user_manager.php - เพิ่มปุ่ม Edit User
if (ob_get_level() == 0) ob_start();
session_start();
require_once 'auth.php';
requireRole(['developer', 'admin']);
require_once 'db.php';

$msg = "";
$msg_type = "";

// --- Action: Reset Password ---
if (isset($_POST['action']) && $_POST['action'] == 'reset_password') {
    $target_id = intval($_POST['user_id']);
    $new_pass_hash = password_hash('12345678', PASSWORD_DEFAULT);
    $conn->query("UPDATE users SET password = '$new_pass_hash' WHERE id = $target_id");
    $msg = "✅ รีเซ็ตรหัสผ่านเป็น 12345678 เรียบร้อยแล้ว";
    $msg_type = "success";
}

// 🔴 FIX: เปลี่ยนการลบจาก GET เป็น POST (ป้องกัน CSRF) และเคลียร์ Foreign Key ทิ้งก่อนลบ
if (isset($_POST['action']) && $_POST['action'] == 'delete_user') {
    $del_id = intval($_POST['user_id']);
    if ($del_id != $_SESSION['user_id']) {
        try {
            // ลบข้อมูลที่ผูกอยู่กับ User คนนี้ออกก่อน (Manual Cascade)
            $conn->query("DELETE FROM attendance WHERE student_id = $del_id");
            $conn->query("DELETE FROM assigned_work WHERE teacher_id = $del_id OR student_id = $del_id");
            $conn->query("DELETE FROM assignment_library WHERE teacher_id = $del_id");
            $conn->query("DELETE FROM teacher_files WHERE teacher_id = $del_id");
            $conn->query("DELETE FROM teacher_schedule WHERE teacher_id = $del_id OR created_by = $del_id");
            $conn->query("DELETE FROM messages WHERE sender_id = $del_id OR receiver_id = $del_id");
            $conn->query("DELETE FROM student_quest_progress WHERE student_id = $del_id");
            $conn->query("DELETE FROM student_history WHERE user_id = $del_id");
            $conn->query("DELETE FROM student_qr WHERE student_id = $del_id");
            $conn->query("DELETE FROM friends WHERE user_id_1 = $del_id OR user_id_2 = $del_id");
            $conn->query("DELETE FROM login_logs WHERE user_id = $del_id");
            $conn->query("DELETE FROM results WHERE user_id = $del_id");
            
            // ลบ User
            $conn->query("DELETE FROM users WHERE id=$del_id");
            
            $msg = "🗑️ ลบผู้ใช้และข้อมูลที่เกี่ยวข้องเรียบร้อย";
            $msg_type = "success";
        } catch (Exception $e) {
            $msg = "❌ ไม่สามารถลบผู้ใช้นี้ได้: " . $e->getMessage();
            $msg_type = "error";
        }
    }
}

// --- Search & Filter ---
$where_clauses = ["1=1"]; 
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
if (!empty($search)) {
    $s_esc = $conn->real_escape_string($search);
    $where_clauses[] = "(username LIKE '%$s_esc%' OR display_name LIKE '%$s_esc%')";
}
$role_filter = isset($_GET['role']) ? $_GET['role'] : 'all';
if ($role_filter != 'all') {
    $r_esc = $conn->real_escape_string($role_filter);
    $where_clauses[] = "role = '$r_esc'";
}
$class_filter = isset($_GET['class_level']) ? $_GET['class_level'] : '';
if (!empty($class_filter) && $role_filter == 'student') {
    $c_esc = $conn->real_escape_string($class_filter);
    $where_clauses[] = "class_level = '$c_esc'";
}
$dept_filter = isset($_GET['dept']) ? $_GET['dept'] : '';
if (!empty($dept_filter) && $role_filter == 'teacher') {
    $d_esc = $conn->real_escape_string($dept_filter);
    $where_clauses[] = "teacher_department = '$d_esc'";
}

$sql = "SELECT * FROM users WHERE " . implode(" AND ", $where_clauses) . " ORDER BY role, class_level, username";
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>User Manager</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Sarabun', sans-serif; background: #f1f5f9; padding: 20px; }
    .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
    
    .header-bar { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #e2e8f0; padding-bottom: 15px; margin-bottom: 20px; }
    .tools-group { display: flex; gap: 10px; }

    .filter-box { background: #f8fafc; padding: 15px; border-radius: 10px; border: 1px solid #cbd5e1; display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 20px; }
    .filter-item { flex: 1; min-width: 150px; }
    .filter-item label { display: block; font-size: 0.85rem; color: #64748b; margin-bottom: 5px; }
    .filter-input { width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; }
    
    .btn { padding: 8px 15px; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; font-weight: bold; transition: 0.2s; font-size: 0.9rem; }
    .btn-search { background: #3b82f6; color: white; height: 38px; }
    .btn-add { background: #10b981; color: white; display: inline-flex; align-items: center; gap: 5px; }
    .btn-import { background: #0ea5e9; color: white; display: inline-flex; align-items: center; gap: 5px; }
    .btn-import:hover { background: #0284c7; }
    
    /* ปุ่ม Actions */
    .btn-edit { background: #3b82f6; color: white; padding: 5px 10px; font-size: 0.8rem; margin-right: 2px; }
    .btn-edit:hover { background: #2563eb; }
    .btn-reset { background: #f59e0b; color: white; padding: 5px 10px; font-size: 0.8rem; margin-right: 2px; }
    .btn-reset:hover { background: #d97706; }
    .btn-del { background: #ef4444; color: white; padding: 5px 10px; font-size: 0.8rem; border:none; cursor:pointer;}
    .btn-del:hover { background: #dc2626; }
    
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #e2e8f0; }
    th { background: #f1f5f9; color: #475569; }
    tr:hover { background: #f8fafc; }
    
    .badge { padding: 3px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: bold; }
    .role-student { background: #dcfce7; color: #166534; }
    .role-teacher { background: #dbeafe; color: #1e40af; }
    .role-admin { background: #fee2e2; color: #991b1b; }
    
    .alert { padding: 10px; margin-bottom: 15px; border-radius: 6px; text-align: center; }
    .success { background: #dcfce7; color: #166534; }
    .error { background: #fee2e2; color: #991b1b; }
</style>
</head>
<body>

<div class="container">
    <div class="header-bar">
        <h2 style="margin:0; color:#1e293b;">👥 จัดการผู้ใช้ (User Manager)</h2>
        <div class="tools-group">
            <a href="import_users.php" class="btn btn-import">📂 นำเข้า Excel/CSV</a>
            <a href="add_user.php" class="btn btn-add">+ เพิ่มผู้ใช้</a>
            <a href="dashboard_dev.php" class="btn" style="background:#64748b; color:white;">⬅ กลับ</a>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert <?= $msg_type ?>"><?= $msg ?></div>
    <?php endif; ?>

    <form method="GET" class="filter-box">
        <div class="filter-item" style="flex:2;">
            <label>🔍 ค้นหา</label>
            <input type="text" name="search" class="filter-input" value="<?= htmlspecialchars($search) ?>" placeholder="ชื่อ หรือ รหัสนักเรียน...">
        </div>
        
        <div class="filter-item">
            <label>หมวดหมู่</label>
            <select name="role" class="filter-input" onchange="this.form.submit()">
                <option value="all" <?= $role_filter=='all'?'selected':'' ?>>ทั้งหมด</option>
                <option value="student" <?= $role_filter=='student'?'selected':'' ?>>👨‍🎓 นักเรียน</option>
                <option value="teacher" <?= $role_filter=='teacher'?'selected':'' ?>>👩‍🏫 ครู</option>
                <option value="developer" <?= $role_filter=='developer'?'selected':'' ?>>👨‍💻 Admin/Dev</option>
            </select>
        </div>

        <?php if ($role_filter == 'student'): ?>
        <div class="filter-item">
            <label>ห้องเรียน</label>
            <select name="class_level" class="filter-input" onchange="this.form.submit()">
                <option value="">ทุกห้อง</option>
                <?php 
                for($m=1; $m<=6; $m++) {
                    for($r=1; $r<=5; $r++) {
                        $c_val = "ม.$m/$r";
                        $sel = ($class_filter == $c_val) ? 'selected' : '';
                        echo "<option value='$c_val' $sel>$c_val</option>";
                    }
                }
                ?>
            </select>
        </div>
        <?php endif; ?>

        <div style="align-self: flex-end;">
            <button type="submit" class="btn btn-search">ค้นหา</button>
            <?php if(!empty($search) || $role_filter!='all'): ?>
                <a href="user_manager.php" class="btn" style="background:#94a3b8; color:white;">Reset</a>
            <?php endif; ?>
        </div>
    </form>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Username/รหัส</th>
                <th>ชื่อ-นามสกุล</th>
                <th>Role</th>
                <th>ข้อมูลสังกัด</th>
                <th style="text-align:center; min-width: 200px;">จัดการ</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($result->num_rows > 0): ?>
                <?php while($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?= $row['id'] ?></td>
                    <td style="font-family:monospace; font-weight:bold; color:#3b82f6;"><?= htmlspecialchars($row['username']) ?></td>
                    <td><?= htmlspecialchars($row['display_name']) ?></td>
                    <td>
                        <?php $rc = ($row['role']=='student') ? 'role-student' : (($row['role']=='teacher') ? 'role-teacher' : 'role-admin'); ?>
                        <span class="badge <?= $rc ?>"><?= ucfirst($row['role']) ?></span>
                    </td>
                    <td>
                        <?php if ($row['role'] == 'student'): ?>
                            <span style="color:#059669;">📚 <?= $row['class_level'] ?: '-' ?></span>
                        <?php elseif ($row['role'] == 'teacher'): ?>
                            <span style="color:#2563eb;">🏫 <?= $row['teacher_department'] ?: '-' ?></span>
                        <?php else: ?> - <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <a href="edit_user.php?id=<?= $row['id'] ?>" class="btn btn-edit" title="แก้ไขข้อมูล">✏️ Edit</a>

                        <form method="post" style="display:inline;" onsubmit="return confirm('ยืนยันรีเซ็ตรหัสผ่านเป็น 12345678 ?');">
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
                            <button type="submit" class="btn btn-reset" title="Reset Password">🔑</button>
                        </form>
                        
                        <?php if($row['id'] != $_SESSION['user_id']): ?>
                            <form method="post" style="display:inline;" onsubmit="return confirm('⚠️ ยืนยันการลบผู้ใช้นี้ใช่หรือไม่?\nข้อมูลการส่งงาน แชท และประวัติทั้งหมดจะถูกลบถาวร!');">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
                                <button type="submit" class="btn btn-del" title="ลบผู้ใช้">🗑️</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="6" style="text-align:center; padding:30px; color:#64748b;">ไม่พบข้อมูล</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <div style="margin-top:10px; font-size:0.85rem; color:#64748b; text-align:right;">ทั้งหมด: <?= $result->num_rows ?> รายการ</div>
</div>

</body>
</html>