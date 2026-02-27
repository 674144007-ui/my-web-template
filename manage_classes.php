<?php
// manage_classes.php - จัดการข้อมูลชั้นเรียน (รองรับการสร้างทีละหลายห้องรวดเดียว)
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once 'logger.php';

// เฉพาะครูและนักพัฒนาเท่านั้นที่จัดการห้องเรียนได้
requireRole(['teacher', 'developer']);

$msg = "";
$msg_type = "";
$page_title = "จัดการชั้นเรียนโรงเรียนบ้านคาวิทยา";
$csrf = generate_csrf_token();

// ---------------------------------------------------------
// 1. ส่วนการบันทึกเพิ่มชั้นเรียน (รองรับการสร้างพร้อมกันหลายห้อง)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_add_classes') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $level = trim($_POST['level'] ?? '');
    $room_start = intval($_POST['room_start'] ?? 0);
    $room_end = intval($_POST['room_end'] ?? 0);
    $advisor_id = intval($_POST['advisor_id'] ?? 0); // ปกติสร้างหลายห้องจะเว้นครูที่ปรึกษาไว้ก่อน

    if (empty($level) || $room_start <= 0 || $room_end < $room_start) {
        $msg = "❌ กรุณาเลือกระดับชั้น และระบุช่วงหมายเลขห้องให้ถูกต้อง (เช่น 1 ถึง 10)";
        $msg_type = "error";
    } else {
        $success_count = 0;
        $duplicate_count = 0;
        $error_count = 0;
        
        $final_advisor = ($advisor_id > 0) ? $advisor_id : NULL;

        // เตรียมคำสั่ง SQL ไว้ล่วงหน้าเพื่อความรวดเร็ว
        $check = $conn->prepare("SELECT id FROM classes WHERE level = ? AND room = ?");
        $insert = $conn->prepare("INSERT INTO classes (class_name, level, room, teacher_id) VALUES (?, ?, ?, ?)");

        // ลูปสร้างห้องตั้งแต่หมายเลขเริ่มต้น ถึง หมายเลขสิ้นสุด
        for ($r = $room_start; $r <= $room_end; $r++) {
            $class_name = $level . "/" . $r;

            // เช็คว่ามีห้องนี้ซ้ำหรือไม่
            $check->bind_param("si", $level, $r);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                // ถ้ามีห้องนี้อยู่แล้ว ให้ข้ามไป (ป้องกันข้อมูลซ้ำ)
                $duplicate_count++;
            } else {
                // ถ้ายังไม่มี ให้บันทึกสร้างใหม่
                $insert->bind_param("ssii", $class_name, $level, $r, $final_advisor);
                if ($insert->execute()) {
                    $success_count++;
                } else {
                    $error_count++;
                }
            }
        }

        $check->close();
        $insert->close();

        // สรุปผลการสร้าง
        if ($success_count > 0) {
            $msg = "✔ สร้างชั้นเรียน $level สำเร็จจำนวน $success_count ห้อง";
            if ($duplicate_count > 0) {
                $msg .= " (ข้ามห้องที่มีอยู่แล้ว $duplicate_count ห้อง)";
            }
            $msg_type = "success";
            systemLog($_SESSION['user_id'], 'BULK_CREATE_CLASS', "Created $success_count classes for $level (Rooms $room_start to $room_end)");
        } else {
            if ($duplicate_count > 0) {
                $msg = "⚠️ ไม่ได้สร้างห้องใหม่ เนื่องจากมีห้องเหล่านี้ในระบบอยู่แล้วทั้งหมด";
                $msg_type = "error";
            } else {
                $msg = "❌ เกิดข้อผิดพลาดในการสร้างห้องเรียน";
                $msg_type = "error";
            }
        }
    }
}

// ---------------------------------------------------------
// 2. ส่วนการลบชั้นเรียน (อนุญาตเฉพาะห้องที่ไม่มีนักเรียนอยู่)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_class') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    $delete_id = intval($_POST['delete_id'] ?? 0);
    
    // ตรวจสอบว่ามีนักเรียนอยู่ในห้องนี้หรือไม่
    $check_users = $conn->prepare("SELECT COUNT(id) AS total FROM users WHERE class_id = ? AND is_deleted = 0");
    $check_users->bind_param("i", $delete_id);
    $check_users->execute();
    $student_count = $check_users->get_result()->fetch_assoc()['total'];
    $check_users->close();

    if ($student_count > 0) {
        $msg = "❌ ไม่สามารถลบห้องเรียนนี้ได้ เนื่องจากยังมีนักเรียนอยู่ในห้องจำนวน $student_count คน (ต้องย้ายนักเรียนออกก่อน)";
        $msg_type = "error";
    } else {
        $stmt_del = $conn->prepare("DELETE FROM classes WHERE id = ?");
        $stmt_del->bind_param("i", $delete_id);
        if ($stmt_del->execute()) {
            $msg = "✔ ลบชั้นเรียนเรียบร้อยแล้ว";
            $msg_type = "success";
            systemLog($_SESSION['user_id'], 'DELETE_CLASS', "Deleted class ID: $delete_id");
        } else {
            $msg = "❌ เกิดข้อผิดพลาด ไม่สามารถลบชั้นเรียนได้";
            $msg_type = "error";
        }
        $stmt_del->close();
    }
}

// ---------------------------------------------------------
// 3. ดึงข้อมูลชั้นเรียนทั้งหมดมาจัดกลุ่ม
// ---------------------------------------------------------
$grouped_classes = [
    'ม.1' => [], 'ม.2' => [], 'ม.3' => [], 
    'ม.4' => [], 'ม.5' => [], 'ม.6' => [],
    'อื่นๆ' => [] // สำหรับห้องที่ไม่ได้ระบุ level ถูกต้อง
];

// ดึงข้อมูลชั้นเรียน พร้อมนับจำนวนนักเรียนในห้องนั้น
$query = "
    SELECT 
        c.id, c.class_name, c.level, c.room, c.created_at, 
        u.display_name AS teacher_name,
        (SELECT COUNT(id) FROM users WHERE class_id = c.id AND is_deleted = 0 AND role = 'student') AS student_count
    FROM classes c
    LEFT JOIN users u ON c.teacher_id = u.id
    ORDER BY c.level ASC, c.room ASC
";
$result = $conn->query($query);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $lvl = $row['level'];
        if (array_key_exists($lvl, $grouped_classes)) {
            $grouped_classes[$lvl][] = $row;
        } else {
            $grouped_classes['อื่นๆ'][] = $row;
        }
    }
}

// ---------------------------------------------------------
// 4. ดึงรายชื่อครูมาใส่ใน Dropdown ครูที่ปรึกษา
// ---------------------------------------------------------
$teachers = [];
$t_res = $conn->query("SELECT id, display_name FROM users WHERE role = 'teacher' AND is_deleted = 0 ORDER BY display_name ASC");
if ($t_res) {
    while ($row = $t_res->fetch_assoc()) {
        $teachers[] = $row;
    }
}

require_once 'header.php';
?>

<style>
    .layout-grid { display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start; }
    .panel-form { flex: 1; min-width: 340px; background: white; padding: 25px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); position: sticky; top: 20px; }
    .panel-data { flex: 2; min-width: 400px; }
    
    .level-section { background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 25px; overflow: hidden; }
    .level-header { background: #1e293b; color: white; padding: 15px 20px; font-size: 1.2rem; font-weight: bold; display: flex; justify-content: space-between; align-items: center; }
    .level-header span.badge { background: #3b82f6; color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; }
    
    .room-table { width: 100%; border-collapse: collapse; }
    .room-table th, .room-table td { padding: 15px 20px; text-align: left; border-bottom: 1px solid #e2e8f0; }
    .room-table th { background: #f8fafc; color: #64748b; font-weight: bold; }
    .room-table tr:last-child td { border-bottom: none; }
    .room-table tr:hover { background: #f8fafc; }
    
    .btn-del-sm { background: #fee2e2; color: #b91c1c; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 0.85rem; font-weight: bold; transition: 0.3s; }
    .btn-del-sm:hover { background: #ef4444; color: white; }
    .btn-disabled { background: #f1f5f9; color: #94a3b8; border: none; padding: 6px 12px; border-radius: 6px; cursor: not-allowed; font-size: 0.85rem; }

    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; font-weight: bold; color: #334155; margin-bottom: 5px; }
    .form-group input, .form-group select { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; box-sizing: border-box; font-size: 1rem; }
    .form-group input:focus, .form-group select:focus { border-color: #3b82f6; }
</style>

<div style="margin-bottom: 20px;">
    <?php if ($_SESSION['role'] === 'developer'): ?>
        <a href="dashboard_dev.php" style="color: #64748b; text-decoration: none; font-weight: bold;">⬅ กลับ Dev Dashboard</a>
    <?php else: ?>
        <a href="dashboard_teacher.php" style="color: #64748b; text-decoration: none; font-weight: bold;">⬅ กลับ Teacher Dashboard</a>
    <?php endif; ?>
</div>

<h2>🏫 โครงสร้างชั้นเรียน โรงเรียนบ้านคาวิทยา</h2>
<p style="color: #64748b; margin-top: -10px; margin-bottom: 15px;">สร้างห้องเรียนทีละหลายๆ ห้องพร้อมกันได้ เพื่อประหยัดเวลาในการจัดการ</p>

<div style="margin-bottom: 30px;">
    <a href="promote_students.php" style="display: inline-block; padding: 12px 25px; background: #8b5cf6; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; box-shadow: 0 4px 10px rgba(139,92,246,0.3); transition: 0.3s;">
        🚀 ไปหน้าระบบเลื่อนชั้นอัตโนมัติรายปี
    </a>
</div>

<?php if ($msg): ?>
    <div class="msg <?= h($msg_type) ?>" style="font-size: 1.1rem; padding: 15px;"><?= h($msg) ?></div>
<?php endif; ?>

<div class="layout-grid">
    
    <div class="panel-form">
        <h3 style="margin-top: 0; color: #0f172a; border-bottom: 2px solid #f1f5f9; padding-bottom: 15px;">➕ สร้างห้องเรียนอัตโนมัติ</h3>
        
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="bulk_add_classes">

            <div class="form-group">
                <label for="level">เลือกระดับชั้น <span style="color:red">*</span></label>
                <select id="level" name="level" required>
                    <option value="">-- เลือกระดับชั้น --</option>
                    <option value="ม.1">มัธยมศึกษาปีที่ 1 (ม.1)</option>
                    <option value="ม.2">มัธยมศึกษาปีที่ 2 (ม.2)</option>
                    <option value="ม.3">มัธยมศึกษาปีที่ 3 (ม.3)</option>
                    <option value="ม.4">มัธยมศึกษาปีที่ 4 (ม.4)</option>
                    <option value="ม.5">มัธยมศึกษาปีที่ 5 (ม.5)</option>
                    <option value="ม.6">มัธยมศึกษาปีที่ 6 (ม.6)</option>
                </select>
            </div>

            <div style="display: flex; gap: 15px;">
                <div class="form-group" style="flex: 1;">
                    <label for="room_start">ตั้งแต่ห้องที่ <span style="color:red">*</span></label>
                    <input type="number" id="room_start" name="room_start" min="1" max="99" value="1" required>
                </div>
                <div class="form-group" style="flex: 1;">
                    <label for="room_end">ถึงห้องที่ <span style="color:red">*</span></label>
                    <input type="number" id="room_end" name="room_end" min="1" max="99" value="10" required>
                </div>
            </div>
            <p style="color: #64748b; font-size: 0.85em; margin-top: -5px; margin-bottom: 15px;">
                ตัวอย่าง: ถ้าเลือก 1 ถึง 10 ระบบจะสร้างห้อง /1 ไปจนถึง /10 ให้ทันที
            </p>

            <div class="form-group">
                <label for="advisor_id">ครูที่ปรึกษา (สามารถกำหนดภายหลังได้)</label>
                <select id="advisor_id" name="advisor_id">
                    <option value="0">-- ยังไม่ระบุ --</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= h($t['id']) ?>"><?= h($t['display_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn-primary" style="width: 100%; background: #10b981; font-size: 1.1rem; padding: 15px; margin-top: 10px;">
                🚀 สร้างห้องเรียนทั้งหมด
            </button>
        </form>
    </div>

    <div class="panel-data">
        
        <?php foreach ($grouped_classes as $lvl => $rooms): ?>
            <?php if (count($rooms) > 0 || $lvl !== 'อื่นๆ'): // แสดง ม.1-ม.6 เสมอแม้จะว่าง แต่ 'อื่นๆ' แสดงเมื่อมีข้อมูล ?>
                <div class="level-section">
                    <div class="level-header">
                        <span>📚 ระดับชั้น <?= h($lvl) ?></span>
                        <span class="badge"><?= count($rooms) ?> ห้องเรียน</span>
                    </div>
                    
                    <?php if (count($rooms) > 0): ?>
                        <div style="overflow-x: auto;">
                            <table class="room-table">
                                <thead>
                                    <tr>
                                        <th width="20%">ห้องเรียน</th>
                                        <th width="35%">ครูที่ปรึกษา</th>
                                        <th width="25%">จำนวนนักเรียน</th>
                                        <th width="20%" style="text-align: center;">จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rooms as $room): ?>
                                        <tr>
                                            <td><strong style="font-size: 1.1rem; color: #0f172a;"><?= h($room['class_name']) ?></strong></td>
                                            <td>
                                                <?= $room['teacher_name'] ? h($room['teacher_name']) : '<span style="color:#94a3b8; font-style:italic;">ยังไม่มี</span>' ?>
                                            </td>
                                            <td>
                                                <span style="background: #f1f5f9; padding: 4px 10px; border-radius: 6px; font-weight: bold; color: <?= $room['student_count'] > 0 ? '#3b82f6' : '#94a3b8' ?>;">
                                                    👥 <?= h($room['student_count']) ?> คน
                                                </span>
                                            </td>
                                            <td style="text-align: center;">
                                                <?php if ($room['student_count'] == 0): ?>
                                                    <form method="post" onsubmit="return confirm('ยืนยันการลบห้องเรียน <?= h($room['class_name']) ?> ใช่หรือไม่?');">
                                                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                                        <input type="hidden" name="action" value="delete_class">
                                                        <input type="hidden" name="delete_id" value="<?= h($room['id']) ?>">
                                                        <button type="submit" class="btn-del-sm" title="ลบห้องเรียน">🗑️ ลบ</button>
                                                    </form>
                                                <?php else: ?>
                                                    <button type="button" class="btn-disabled" title="ไม่สามารถลบได้เนื่องจากมีนักเรียนอยู่">🔒 มี นร.</button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div style="padding: 30px; text-align: center; color: #94a3b8;">
                            ยังไม่มีการสร้างห้องเรียนในระดับชั้นนี้
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

    </div>
</div>

<?php require_once 'footer.php'; ?>