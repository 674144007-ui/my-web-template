<?php
// export_data.php - ระบบดาวน์โหลดรายงาน (Advanced Export by Class - Phase 4)
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once 'logger.php';

// อนุญาตให้ครูและผู้พัฒนาใช้งานได้
requireRole(['teacher', 'developer']);

// ตรวจสอบว่าครูกดดาวน์โหลดรายงานหรือไม่
$action = $_GET['action'] ?? '';

// ---------------------------------------------------------
// ส่วนที่ 1: การประมวลผลดาวน์โหลดไฟล์ CSV
// ---------------------------------------------------------
if ($action === 'export') {
    $report_type = $_GET['report_type'] ?? '';
    $class_id = intval($_GET['class_id'] ?? 0);

    if ($report_type === '') die("ไม่พบประเภทรายงาน");

    // สร้างชื่อไฟล์
    $class_suffix = ($class_id > 0) ? "_class_{$class_id}" : "_all_classes";
    $filename = "bankha_report_{$report_type}{$class_suffix}_" . date('Ymd_His') . ".csv";
    
    // ตั้งค่า Header ให้เป็นไฟล์ดาวน์โหลด
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // BOM สำหรับภาษาไทยใน Excel

    // ดึงชื่องห้องเรียน (ถ้ามีการเลือกห้อง)
    $class_name_display = "ทั้งหมด";
    if ($class_id > 0) {
        $stmt_c = $conn->prepare("SELECT class_name FROM classes WHERE id = ?");
        $stmt_c->bind_param("i", $class_id);
        $stmt_c->execute();
        $res_c = $stmt_c->get_result();
        if ($res_c->num_rows > 0) {
            $class_name_display = $res_c->fetch_assoc()['class_name'];
        }
        $stmt_c->close();
    }

    // ---------------------------------------------------------
    // ประมวลผล: รายงานรายชื่อนักเรียน
    // ---------------------------------------------------------
    if ($report_type === 'students') {
        // หัวตาราง
        fputcsv($output, ["รายงานรายชื่อนักเรียน โรงเรียนบ้านคาวิทยา"]);
        fputcsv($output, ["ระดับชั้น/ห้องเรียน:", $class_name_display, "วันที่ออกรายงาน:", date('d/m/Y H:i')]);
        fputcsv($output, []); // บรรทัดว่าง
        fputcsv($output, ['ลำดับ', 'รหัสนักเรียน (ID)', 'Username', 'ชื่อ-นามสกุล', 'ระดับชั้น/ห้อง', 'วันที่ลงทะเบียน']);
        
        $query = "
            SELECT u.id, u.username, u.display_name, u.created_at, c.class_name 
            FROM users u
            LEFT JOIN classes c ON u.class_id = c.id
            WHERE u.role = 'student' AND u.is_deleted = 0
        ";
        if ($class_id > 0) {
            $query .= " AND u.class_id = " . $class_id;
        }
        $query .= " ORDER BY c.level ASC, c.room ASC, u.display_name ASC";
        
        $stmt = $conn->query($query);
        $counter = 1;
        while ($row = $stmt->fetch_assoc()) {
            fputcsv($output, [
                $counter++,
                $row['id'], 
                $row['username'], 
                $row['display_name'], 
                $row['class_name'] ?? 'ไม่ระบุ', 
                $row['created_at']
            ]);
        }
        systemLog($_SESSION['user_id'], 'EXPORT_CSV', "Exported student list for Class ID: $class_id");

    // ---------------------------------------------------------
    // ประมวลผล: รายงานการเข้าเรียน (เช็คชื่อ)
    // ---------------------------------------------------------
    } elseif ($report_type === 'attendance') {
        fputcsv($output, ["รายงานประวัติการเข้าเรียน โรงเรียนบ้านคาวิทยา"]);
        fputcsv($output, ["ระดับชั้น/ห้องเรียน:", $class_name_display, "วันที่ออกรายงาน:", date('d/m/Y H:i')]);
        fputcsv($output, []);
        fputcsv($output, ['ลำดับ', 'วันเวลา', 'วิชา', 'คาบเรียน', 'ชื่อนักเรียน', 'ระดับชั้น', 'สถานะ']);
        
        $query = "
            SELECT a.datetime, a.subject, a.period, a.status, u.display_name, c.class_name
            FROM attendance a
            LEFT JOIN users u ON a.student_id = u.id
            LEFT JOIN classes c ON u.class_id = c.id
            WHERE u.is_deleted = 0
        ";
        if ($class_id > 0) {
            $query .= " AND u.class_id = " . $class_id;
        }
        $query .= " ORDER BY a.datetime DESC, c.level ASC, c.room ASC, u.display_name ASC";
        
        $stmt = $conn->query($query);
        $counter = 1;
        while ($row = $stmt->fetch_assoc()) {
            $status_th = 'มาเรียน';
            if ($row['status'] == 'late') $status_th = 'มาสาย';
            if ($row['status'] == 'absent') $status_th = 'ขาดเรียน';

            fputcsv($output, [
                $counter++,
                $row['datetime'], 
                $row['subject'], 
                $row['period'], 
                $row['display_name'] ?? 'ไม่ทราบชื่อ', 
                $row['class_name'] ?? 'ไม่ระบุ', 
                $status_th
            ]);
        }
        systemLog($_SESSION['user_id'], 'EXPORT_CSV', "Exported attendance records for Class ID: $class_id");
    }

    fclose($output);
    exit; // จบการทำงาน ไม่ต้องโหลด HTML ต่อ
}

// ---------------------------------------------------------
// ส่วนที่ 2: หน้าจอแสดงเมนูดาวน์โหลด (HTML UI)
// ---------------------------------------------------------
$page_title = "ดาวน์โหลดรายงาน (Advanced Export)";

// ดึงข้อมูลชั้นเรียนมาจัดกลุ่มเพื่อสร้าง Dropdown
$grouped_classes = [];
$res_classes = $conn->query("SELECT id, class_name, level FROM classes ORDER BY level ASC, room ASC");
if ($res_classes) {
    while ($row = $res_classes->fetch_assoc()) {
        $lvl = $row['level'] ? $row['level'] : 'อื่นๆ';
        $grouped_classes[$lvl][] = $row;
    }
}

require_once 'header.php';
?>

<style>
    .export-card {
        background: white; padding: 30px; border-radius: 16px; 
        box-shadow: 0 4px 20px rgba(0,0,0,0.05); max-width: 600px; margin: 0 auto;
    }
    .form-group { margin-bottom: 20px; text-align: left; }
    .form-group label { display: block; font-weight: bold; color: #334155; margin-bottom: 8px; font-size: 1.1rem; }
    .form-group select {
        width: 100%; padding: 12px 15px; border-radius: 10px; border: 1px solid #cbd5e1; 
        font-size: 1.1rem; outline: none; box-sizing: border-box; font-family: inherit;
        background-color: #f8fafc; transition: 0.3s; cursor: pointer;
    }
    .form-group select:focus { border-color: #3b82f6; background-color: #fff; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
    
    .btn-export {
        width: 100%; padding: 15px; background: #10b981; color: white; border: none; 
        border-radius: 10px; cursor: pointer; font-size: 1.2rem; font-weight: bold; 
        transition: 0.3s; font-family: inherit; margin-top: 10px;
        display: flex; justify-content: center; align-items: center; gap: 10px;
        box-shadow: 0 4px 10px rgba(16,185,129,0.3);
    }
    .btn-export:hover { background: #059669; transform: translateY(-2px); box-shadow: 0 6px 15px rgba(16,185,129,0.4); }
</style>

<div style="text-align: center; margin-bottom: 20px;">
    <?php if ($_SESSION['role'] === 'developer'): ?>
        <a href="dashboard_dev.php" style="color: #64748b; text-decoration: none; font-weight: bold; font-size: 1.1rem;">⬅ กลับ Dev Dashboard</a>
    <?php else: ?>
        <a href="dashboard_teacher.php" style="color: #64748b; text-decoration: none; font-weight: bold; font-size: 1.1rem;">⬅ กลับ Teacher Dashboard</a>
    <?php endif; ?>
</div>

<div class="export-card">
    <div style="text-align: center; margin-bottom: 30px;">
        <span style="font-size: 4rem;">📥</span>
        <h2 style="margin: 10px 0 0 0; color: #0f172a;">ระบบดาวน์โหลดรายงาน</h2>
        <p style="color: #64748b; margin-top: 5px;">ส่งออกข้อมูลเป็นไฟล์ Excel (.csv) แยกตามห้องเรียน</p>
    </div>

    <form action="export_data.php" method="get" target="_blank">
        <input type="hidden" name="action" value="export">

        <div class="form-group">
            <label for="report_type">1. เลือกประเภทรายงานที่ต้องการ</label>
            <select name="report_type" id="report_type" required>
                <option value="students">👨‍🎓 รายงานบัญชีรายชื่อนักเรียนทั้งหมด</option>
                <option value="attendance">⏱️ รายงานประวัติการเข้าเรียน (เช็คชื่อ)</option>
            </select>
        </div>

        <div class="form-group">
            <label for="class_id">2. เลือกระดับชั้น / ห้องเรียน</label>
            <select name="class_id" id="class_id">
                <option value="0" style="font-weight: bold; color: #2563eb;">🌍 รวมทุกห้องเรียน (ทั้งหมด)</option>
                <?php foreach ($grouped_classes as $lvl => $rooms): ?>
                    <optgroup label="📚 ระดับชั้น <?= h($lvl) ?>">
                        <?php foreach ($rooms as $c): ?>
                            <option value="<?= h($c['id']) ?>">เฉพาะห้อง <?= h($c['class_name']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
            <small style="color: #94a3b8; display: block; margin-top: 8px;">* หากเลือก "รวมทุกห้องเรียน" ระบบจะดึงข้อมูลทั้งโรงเรียนมาให้ในไฟล์เดียวเรียงตามห้อง</small>
        </div>

        <button type="submit" class="btn-export">
            <span>📥</span> ดาวน์โหลดไฟล์ Excel (CSV)
        </button>
    </form>
</div>

<?php require_once 'footer.php'; ?>