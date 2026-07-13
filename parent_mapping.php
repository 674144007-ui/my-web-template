<?php
// parent_mapping.php - ระบบจับคู่ผู้ปกครองกับนักเรียน - Phase 4
require_once __DIR__ . '/env_loader.php';
require_once 'config.php';
require_once 'db.php';
require_once 'security.php';
require_once 'auth.php';
require_once 'logger.php';

requireRole(['developer']);

$page_title = "ระบบจับคู่ผู้ปกครอง-นักเรียน";
$msg = "";
$msg_type = "";
$csrf = generate_csrf_token();

// -----------------------------
// จัดการการบันทึก (POST)
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'map_parent') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $parent_id = intval($_POST['parent_id'] ?? 0);
    $student_id = intval($_POST['student_id'] ?? 0);

    if ($parent_id <= 0) {
        $msg = "❌ กรุณาเลือกบัญชีผู้ปกครอง";
        $msg_type = "error";
    } else {
        // อนุญาตให้ยกเลิกการผูกบัญชีได้ หาก student_id เป็น 0 ให้ใส่ NULL ลง DB
        $final_student_id = ($student_id > 0) ? $student_id : NULL;

        // BUG-01 FIX: execute() เดิมถูกเรียก 2 ครั้ง — แก้ให้เก็บผลลัพธ์ครั้งเดียว
        // BUG FIX: users ไม่มี column 'role' ใช้ role_id=3 (parent) แทน
        $stmt = $pdo->prepare("UPDATE users SET parent_of = ? WHERE id = ? AND role_id = 3");
        $ok = $stmt->execute([$final_student_id, $parent_id]);

        if ($ok) {
            if ($final_student_id === NULL) {
                $msg = "✔ ยกเลิกการจับคู่ผู้ปกครองสำเร็จ";
                systemLog($_SESSION['user_id'], 'UNMAP_PARENT', "Unlinked parent ID: $parent_id from student");
            } else {
                $msg = "✔ จับคู่บัญชีผู้ปกครองกับนักเรียนสำเร็จเรียบร้อย!";
                systemLog($_SESSION['user_id'], 'MAP_PARENT', "Linked parent ID: $parent_id to student ID: $final_student_id");
            }
            $msg_type = "success";
        } else {
            $msg = "❌ เกิดข้อผิดพลาดในฐานข้อมูล";
            $msg_type = "error";
        }
        
    }
}

// -----------------------------
// ดึงข้อมูลรายชื่อนักเรียน (สำหรับ Dropdown)
// -----------------------------
$students = [];
$res_stu = $pdo->query("
    SELECT u.id, u.username, u.display_name, c.class_name 
    FROM users u 
    LEFT JOIN classes c ON u.class_id = c.id 
    JOIN sys_roles r ON r.id=u.role_id WHERE r.role_key='student' AND u.is_deleted = 0 
    ORDER BY c.class_name ASC, u.display_name ASC
");
while ($row = $res_stu->fetch()) {
    $students[] = $row;
}

// -----------------------------
// ดึงข้อมูลผู้ปกครองทั้งหมด พร้อมข้อมูลนักเรียนที่ผูกไว้
// -----------------------------
$parents = [];
$res_par = $pdo->query("
    SELECT 
        p.id AS parent_id, p.username AS parent_username, p.display_name AS parent_name,
        s.id AS student_id, s.display_name AS student_name, c.class_name
    FROM users p
    LEFT JOIN users s ON p.parent_of = s.id
    LEFT JOIN classes c ON s.class_id = c.id
    WHERE p.role = 'parent' AND p.is_deleted = 0
    ORDER BY p.display_name ASC
");
while ($row = $res_par->fetch()) {
    $parents[] = $row;
}

require_once 'header.php';
?>

<style>
    .mapping-container { display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-start; }
    .mapping-panel { background: white; padding: 25px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); flex: 1; min-width: 320px; }
    
    .table-list { width: 100%; border-collapse: collapse; margin-top: 15px; }
    .table-list th, .table-list td { padding: 12px; border-bottom: 1px solid #e2e8f0; text-align: left; }
    .table-list th { background: #f8fafc; color: #475569; }
    .table-list tr:hover { background: #f1f5f9; }

    .btn-unlink { padding: 4px 10px; background: #fee2e2; color: #b91c1c; border: none; border-radius: 6px; cursor: pointer; font-size: 0.85em; font-weight: bold; }
    .btn-unlink:hover { background: #f87171; color: white; }
</style>

<div style="margin-bottom: 20px;">
    <a href="user_manager.php" style="color: #64748b; text-decoration: none; font-weight: bold;">⬅ กลับหน้ารายชื่อผู้ใช้</a>
</div>

<h2>👨‍👩‍👦 ระบบจับคู่บัญชี ผู้ปกครอง-นักเรียน</h2>
<p style="color: #64748b; margin-top: -10px; margin-bottom: 25px;">กำหนดสิทธิ์ให้ผู้ปกครองสามารถดูผลการเรียนและรับแจ้งเตือนของบุตรหลานได้</p>

<?php if ($msg): ?>
    <div class="msg <?= h($msg_type) ?>" style="font-size: 1.1rem; padding: 15px;"><?= h($msg) ?></div>
<?php endif; ?>

<div class="mapping-container">
    
    <div class="mapping-panel" style="flex: 1;">
        <h3 style="margin-top:0; color:#0f172a; border-bottom: 2px solid #f1f5f9; padding-bottom: 15px;">🔗 กำหนดการจับคู่ใหม่</h3>
        
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="map_parent">

            <label for="parent_id">บัญชีผู้ปกครอง <span style="color:red">*</span></label>
            <select name="parent_id" id="parent_id" required style="width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #cbd5e1; outline: none; margin-bottom: 15px;">
                <option value="">-- เลือกชื่อผู้ปกครอง --</option>
                <?php foreach ($parents as $p): ?>
                    <option value="<?= $p['parent_id'] ?>"><?= h($p['parent_name']) ?> (<?= h($p['parent_username']) ?>)</option>
                <?php endforeach; ?>
            </select>

            <label for="student_id">บัญชีนักเรียน (บุตรหลาน)</label>
            <select name="student_id" id="student_id" style="width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #cbd5e1; outline: none; margin-bottom: 20px;">
                <option value="0">-- ยกเลิกการผูกบัญชี (Unlink) --</option>
                <?php foreach ($students as $s): ?>
                    <option value="<?= $s['id'] ?>">
                        <?= $s['class_name'] ? '['.h($s['class_name']).'] ' : '' ?><?= h($s['display_name']) ?> (<?= h($s['username']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn-primary" style="width: 100%; background: #3b82f6; font-size: 1.1rem; padding: 15px;">
                💾 บันทึกการจับคู่
            </button>
        </form>
    </div>

    <div class="mapping-panel" style="flex: 2;">
        <h3 style="margin-top:0; color:#0f172a; border-bottom: 2px solid #f1f5f9; padding-bottom: 15px;">📋 รายชื่อผู้ปกครองในระบบ</h3>
        
        <?php if (count($parents) > 0): ?>
            <div style="overflow-x: auto;">
                <table class="table-list">
                    <thead>
                        <tr>
                            <th>ชื่อผู้ปกครอง</th>
                            <th>บุตรหลาน (นักเรียน)</th>
                            <th>ระดับชั้น</th>
                            <th style="text-align: center;">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($parents as $p): ?>
                            <tr>
                                <td><strong><?= h($p['parent_name']) ?></strong></td>
                                <td>
                                    <?php if ($p['student_id']): ?>
                                        <span style="color: #10b981; font-weight: bold;">👤 <?= h($p['student_name']) ?></span>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-style: italic;">ยังไม่ได้จับคู่</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $p['class_name'] ? h($p['class_name']) : '-' ?></td>
                                <td style="text-align: center;">
                                    <?php if ($p['student_id']): ?>
                                        <form method="post" onsubmit="return confirm('ต้องการยกเลิกการจับคู่บุตรหลานของ <?= h(addslashes($p['parent_name'])) ?> ใช่หรือไม่?');">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="action" value="map_parent">
                                            <input type="hidden" name="parent_id" value="<?= $p['parent_id'] ?>">
                                            <input type="hidden" name="student_id" value="0"> <button type="submit" class="btn-unlink">✂️ เลิกผูก</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color:#cbd5e1; font-size: 0.8em;">ไม่มี</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="text-align: center; color: #94a3b8; padding: 40px 0;">
                ไม่มีข้อมูลบัญชีผู้ปกครองในระบบ
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once 'footer.php'; ?>