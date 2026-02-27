<?php
// promote_students.php - ระบบเลื่อนชั้นนักเรียนอัตโนมัติรายปี (Phase 3)
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once 'logger.php';

// ฟีเจอร์นี้สำคัญมาก อนุญาตเฉพาะ Developer หรือครูระดับแอดมินเท่านั้น
requireRole(['developer', 'teacher']);

$page_title = "เลื่อนชั้นเรียนอัตโนมัติรายปี";
$msg = "";
$msg_type = "";
$csrf = generate_csrf_token();

// ---------------------------------------------------------
// 1. จัดการเมื่อกดปุ่ม "ยืนยันการเลื่อนชั้น"
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'promote') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    $promotions = $_POST['promotions'] ?? []; // Array [รหัสห้องเดิม => รหัสห้องใหม่]
    
    if (empty($promotions)) {
        $msg = "❌ ไม่พบข้อมูลการเลื่อนชั้น";
        $msg_type = "error";
    } else {
        $success_moves = 0;
        $graduated_count = 0;
        $error_count = 0;

        foreach ($promotions as $old_class_id => $new_class_id) {
            $old_class_id = intval($old_class_id);
            
            // ถ้าเลือก "ไม่เลื่อนชั้น" ให้ข้ามไป
            if ($new_class_id === 'none' || empty($new_class_id)) {
                continue; 
            } 
            // ถ้าเลือก "จบการศึกษา"
            elseif ($new_class_id === 'graduate') {
                // อัปเดตให้นักเรียนในห้องนี้ class_id = NULL และ is_deleted = 1 (เป็นศิษย์เก่า ไม่สามารถล็อกอินได้ แต่ข้อมูลยังอยู่)
                $stmt = $conn->prepare("UPDATE users SET class_id = NULL, is_deleted = 1 WHERE class_id = ? AND role = 'student' AND is_deleted = 0");
                $stmt->bind_param("i", $old_class_id);
                if ($stmt->execute()) {
                    $graduated_count += $stmt->affected_rows;
                    if ($stmt->affected_rows > 0) {
                        systemLog($_SESSION['user_id'], 'GRADUATE_STUDENTS', "Set students in class ID $old_class_id to Alumni/Graduated");
                    }
                } else {
                    $error_count++;
                }
                $stmt->close();
            } 
            // เลื่อนไปห้องใหม่ที่ระบุ
            else {
                $new_class_id = intval($new_class_id);
                // ป้องกันการย้ายไปห้องเดิมตัวเอง
                if ($new_class_id > 0 && $old_class_id !== $new_class_id) {
                    $stmt = $conn->prepare("UPDATE users SET class_id = ? WHERE class_id = ? AND role = 'student' AND is_deleted = 0");
                    $stmt->bind_param("ii", $new_class_id, $old_class_id);
                    if ($stmt->execute()) {
                        $success_moves += $stmt->affected_rows;
                        if ($stmt->affected_rows > 0) {
                            systemLog($_SESSION['user_id'], 'PROMOTE_STUDENTS', "Moved students from class ID $old_class_id to $new_class_id");
                        }
                    } else {
                        $error_count++;
                    }
                    $stmt->close();
                }
            }
        }

        if ($error_count === 0) {
            $msg = "✔ ดำเนินการเสร็จสิ้น! ย้ายนักเรียนสำเร็จ <b>$success_moves</b> คน และจบการศึกษา <b>$graduated_count</b> คน";
            $msg_type = "success";
        } else {
            $msg = "⚠️ ดำเนินการเสร็จสิ้นบางส่วน (ย้าย $success_moves คน, จบ $graduated_count คน) แต่มีบางรายการผิดพลาด";
            $msg_type = "error";
        }
    }
}

// ---------------------------------------------------------
// 2. ดึงข้อมูล "ห้องเรียนทั้งหมด" ไว้สำหรับทำ Dropdown ปลายทาง
// ---------------------------------------------------------
$all_classes = [];
$res_all = $conn->query("SELECT id, class_name, level, room FROM classes ORDER BY level ASC, room ASC");
if ($res_all) {
    while ($row = $res_all->fetch_assoc()) {
        $all_classes[] = $row;
    }
}

// ---------------------------------------------------------
// 3. ดึงข้อมูล "ห้องเรียนที่มีนักเรียนอยู่" เพื่อนำมาเป็นต้นทาง
// ---------------------------------------------------------
$active_classes = [];
$query_active = "
    SELECT 
        c.id, c.class_name, c.level, c.room,
        (SELECT COUNT(id) FROM users WHERE class_id = c.id AND role = 'student' AND is_deleted = 0) AS student_count
    FROM classes c
    HAVING student_count > 0
    ORDER BY c.level DESC, c.room ASC
";
// หมายเหตุ: เรียงระดับชั้น DESC (ม.6 ลงมา ม.1) 
// เพื่อให้ห้องรุ่นพี่โดนย้ายก่อน ป้องกันปัญหาห้องรุ่นน้องย้ายขึ้นมาทับแล้วปนกัน

$res_active = $conn->query($query_active);
if ($res_active) {
    while ($row = $res_active->fetch_assoc()) {
        $active_classes[] = $row;
    }
}

// ฟังก์ชันช่วยหา ID ห้องเรียนถัดไปอัตโนมัติ (Auto-Mapping Logic)
function suggestNextClassId($current_level, $current_room, $all_classes) {
    if (empty($current_level)) return 'none';

    // ดึงตัวเลขออกมาจาก "ม.1" -> 1
    if (preg_match('/ม\.(\d+)/', $current_level, $matches)) {
        $current_num = intval($matches[1]);
        $next_num = $current_num + 1;
        
        // ถ้าเกิน ม.6 แปลว่าจบการศึกษา
        if ($next_num > 6) {
            return 'graduate';
        }

        $next_level_str = "ม." . $next_num;

        // วนหาห้องที่ level ตรงกับชั้นถัดไป และ room ตรงกับห้องเดิม
        foreach ($all_classes as $c) {
            if ($c['level'] === $next_level_str && intval($c['room']) === intval($current_room)) {
                return $c['id'];
            }
        }
    }
    return 'none'; // ถ้าหาห้องไม่เจอ ให้ตั้งเป็น ไม่เลื่อนชั้น
}

require_once 'header.php';
?>

<style>
    .promote-container { background: white; padding: 30px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
    .alert-box { background: #fffbeb; border-left: 5px solid #f59e0b; padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; color: #b45309; }
    
    .promote-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    .promote-table th, .promote-table td { padding: 15px; text-align: left; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
    .promote-table th { background: #f8fafc; color: #475569; font-size: 1.05rem; }
    .promote-table tr:hover { background: #f8fafc; }
    
    .class-tag { font-weight: bold; font-size: 1.1rem; color: #0f172a; display: inline-block; width: 80px; }
    .student-count { background: #dbeafe; color: #1e40af; padding: 4px 10px; border-radius: 20px; font-size: 0.9em; font-weight: bold; margin-left: 10px; }
    
    select.target-class { width: 100%; max-width: 300px; padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 1rem; outline: none; background: #fff; }
    select.target-class:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
    
    .arrow-icon { color: #94a3b8; font-size: 1.5rem; text-align: center; }

    .opt-graduate { color: #b91c1c; font-weight: bold; }
    .opt-none { color: #64748b; font-style: italic; }
</style>

<div style="margin-bottom: 20px;">
    <?php if ($_SESSION['role'] === 'developer'): ?>
        <a href="dashboard_dev.php" style="color: #64748b; text-decoration: none; font-weight: bold;">⬅ กลับ Dev Dashboard</a>
    <?php else: ?>
        <a href="dashboard_teacher.php" style="color: #64748b; text-decoration: none; font-weight: bold;">⬅ กลับ Teacher Dashboard</a>
    <?php endif; ?>
</div>

<div class="promote-container">
    <h2 style="margin-top: 0; color: #0f172a;">🚀 ระบบเลื่อนชั้นอัตโนมัติรายปี (Academic Year Promotion)</h2>
    <p style="color: #64748b; font-size: 1.1rem;">ย้ายนักเรียนจากห้องปัจจุบันไปยังห้องเรียนใหม่ในปีการศึกษาถัดไปในคลิกเดียว</p>

    <?php if ($msg): ?>
        <div class="msg <?= h($msg_type) ?>" style="font-size: 1.1rem; padding: 15px;"><?= $msg ?></div>
    <?php endif; ?>

    <div class="alert-box">
        <h4 style="margin: 0 0 5px 0;">💡 คำแนะนำก่อนใช้งาน:</h4>
        <ul style="margin: 0; padding-left: 20px;">
            <li>กรุณาไปที่เมนู <b>"จัดการชั้นเรียน"</b> เพื่อสร้างห้องเรียนของปีการศึกษาใหม่ให้ครบถ้วนก่อน</li>
            <li>ระบบได้ทำการจับคู่ห้องเรียนปลายทางให้ <b>"อัตโนมัติ"</b> แล้ว (เช่น ม.1/1 ไป ม.2/1) โปรดตรวจสอบความถูกต้องอีกครั้ง</li>
            <li>นักเรียนที่จบการศึกษา (ม.6) จะถูกเปลี่ยนสถานะเป็นศิษย์เก่า (ระงับบัญชีเพื่อไม่ให้ล็อกอินได้ แต่ข้อมูลผลการเรียนจะยังคงอยู่)</li>
        </ul>
    </div>

    <?php if (count($active_classes) > 0): ?>
        <form method="post" onsubmit="return confirm('⚠️ คำเตือน: การเลื่อนชั้นจะมีผลกับนักเรียนทุกคนในห้องที่เลือก คุณตรวจสอบข้อมูลถูกต้องแล้วใช่หรือไม่?');">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="promote">

            <div style="overflow-x: auto;">
                <table class="promote-table">
                    <thead>
                        <tr>
                            <th width="35%">🏠 ห้องเรียนปัจจุบัน (ปีการศึกษาเดิม)</th>
                            <th width="10%" style="text-align: center;">ย้ายไป</th>
                            <th width="55%">🎯 ห้องเรียนปลายทาง (ปีการศึกษาใหม่)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($active_classes as $c): ?>
                            <?php 
                                // หาห้องถัดไปที่ระบบแนะนำ
                                $suggested_id = suggestNextClassId($c['level'], $c['room'], $all_classes); 
                            ?>
                            <tr>
                                <td>
                                    <span class="class-tag"><?= h($c['class_name']) ?></span>
                                    <span class="student-count">👥 มีนักเรียน <?= h($c['student_count']) ?> คน</span>
                                </td>
                                <td class="arrow-icon">➡️</td>
                                <td>
                                    <select name="promotions[<?= h($c['id']) ?>]" class="target-class">
                                        <option value="none" class="opt-none" <?= $suggested_id === 'none' ? 'selected' : '' ?>>
                                            -- ⏸️ ไม่เลื่อนชั้น (คงไว้ห้องเดิม) --
                                        </option>
                                        <option value="graduate" class="opt-graduate" <?= $suggested_id === 'graduate' ? 'selected' : '' ?>>
                                            -- 🎓 จบการศึกษา / ศิษย์เก่า --
                                        </option>
                                        
                                        <optgroup label="📚 เลือกห้องเรียนปลายทาง">
                                            <?php foreach ($all_classes as $ac): ?>
                                                <option value="<?= h($ac['id']) ?>" <?= ($suggested_id === $ac['id']) ? 'selected' : '' ?>>
                                                    ย้ายไป <?= h($ac['class_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 30px; text-align: center; border-top: 2px solid #f1f5f9; padding-top: 20px;">
                <button type="submit" class="btn-primary" style="background: #2563eb; font-size: 1.2rem; padding: 15px 40px; box-shadow: 0 4px 10px rgba(37,99,235,0.3);">
                    ✨ ยืนยันการเลื่อนชั้นนักเรียนทั้งหมด
                </button>
            </div>
        </form>
    <?php else: ?>
        <div style="text-align: center; color: #94a3b8; padding: 50px 0; border: 2px dashed #cbd5e1; border-radius: 12px; background: #f8fafc;">
            <span style="font-size: 4rem;">📭</span><br>
            <h3 style="color: #475569; margin-bottom: 5px;">ไม่พบนักเรียนในระบบ</h3>
            <p>ยังไม่มีนักเรียนถูกจัดเข้าห้องเรียนใดๆ จึงไม่มีรายการให้เลื่อนชั้น</p>
        </div>
    <?php endif; ?>

</div>

<?php require_once 'footer.php'; ?>