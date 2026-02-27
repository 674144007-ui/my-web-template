<?php
// import_users.php - ระบบนำเข้าผู้ใช้งานด้วย CSV (Phase 2 - Smart Parsing)
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once 'logger.php';

requireRole(['developer']);

$page_title = "นำเข้าผู้ใช้งานด้วย CSV";
$csrf = generate_csrf_token();
$msg = "";
$msg_type = "";
$import_report = []; 

// 1. ดาวน์โหลดไฟล์ต้นแบบ
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="template_import_users.csv"');
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Username', 'Password', 'Display Name', 'Role', 'Class Name']);
    fputcsv($output, ['stu_somchai', 'bankha1234', 'ด.ช.สมชาย เรียนดี', 'student', 'ม.1/1']);
    fputcsv($output, ['stu_somying', '', 'ด.ญ.สมหญิง รักเรียน', 'student', '1/2']); // ทดสอบพิมพ์แค่ 1/2
    fputcsv($output, ['teacher_a', 'pass5555', 'ครูสมศรี ใจดี', 'teacher', '']);
    fclose($output);
    exit;
}

// 2. ดึงข้อมูลชั้นเรียนทั้งหมดมาเก็บไว้ใน Array
$classes_cache = [];
$res_classes = $conn->query("SELECT id, class_name FROM classes");
if ($res_classes) {
    while ($row = $res_classes->fetch_assoc()) {
        $classes_cache[$row['class_name']] = $row['id'];
    }
}

// 3. จัดการการอัปโหลด CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $tmp_name = $_FILES['csv_file']['tmp_name'];
        $file_name = $_FILES['csv_file']['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        if ($file_ext !== 'csv') {
            $msg = "❌ กรุณาอัปโหลดไฟล์นามสกุล .csv เท่านั้น";
            $msg_type = "error";
        } else {
            if (($handle = fopen($tmp_name, "r")) !== FALSE) {
                $row_count = 0;
                $success_count = 0;
                $fail_count = 0;

                $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ?");
                $stmt_insert = $conn->prepare("INSERT INTO users (username, password, display_name, role, class_id) VALUES (?, ?, ?, ?, ?)");

                while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
                    $row_count++;
                    if ($row_count === 1) {
                        if (strpos($data[0], "\xEF\xBB\xBF") === 0) $data[0] = substr($data[0], 3);
                        continue;
                    }

                    if (empty(array_filter($data))) continue;

                    $csv_username = trim($data[0] ?? '');
                    $csv_password = trim($data[1] ?? '');
                    $csv_display  = trim($data[2] ?? '');
                    $csv_role     = strtolower(trim($data[3] ?? 'student'));
                    $csv_class    = trim($data[4] ?? '');

                    if (empty($csv_username) || empty($csv_display)) {
                        $import_report[] = ["row" => $row_count, "user" => $csv_username, "status" => "error", "note" => "ข้อมูลไม่ครบ (Username/ชื่อหาย)"];
                        $fail_count++;
                        continue;
                    }

                    if (!in_array($csv_role, ['student', 'teacher', 'parent', 'developer'])) $csv_role = 'student';

                    // --- ระบบ SMART PARSING (แปลง 1/1 เป็น ม.1/1 อัตโนมัติ) ---
                    $final_class_id = NULL;
                    if (!empty($csv_class) && ($csv_role === 'student' || $csv_role === 'teacher')) {
                        // ลบช่องว่างและคำว่า 'ม.' ออกทั้งหมด เพื่อให้เหลือแต่ตัวเลขเช่น "1/1"
                        $clean_str = str_replace([' ', 'ม.', 'ม'], '', mb_strtolower($csv_class, 'UTF-8'));
                        
                        $parsed_level = NULL;
                        $parsed_room = NULL;
                        $formatted_class_name = $csv_class;

                        // ถ้าตรงกับแพทเทิร์น ตัวเลข/ตัวเลข เช่น 1/2
                        if (preg_match('/^([1-6])\/([0-9]+)$/', $clean_str, $matches)) {
                            $parsed_level = 'ม.' . $matches[1];
                            $parsed_room = intval($matches[2]);
                            $formatted_class_name = $parsed_level . '/' . $parsed_room; // จะได้ ม.1/2 เสมอ
                        }

                        if (isset($classes_cache[$formatted_class_name])) {
                            $final_class_id = $classes_cache[$formatted_class_name];
                        } else {
                            // ถ้าไม่พบในฐานข้อมูล ให้สร้างห้องนี้ขึ้นมาใหม่เลย พร้อมแยกระดับชั้น
                            $stmt_new_class = $conn->prepare("INSERT INTO classes (class_name, level, room) VALUES (?, ?, ?)");
                            $stmt_new_class->bind_param("ssi", $formatted_class_name, $parsed_level, $parsed_room);
                            $stmt_new_class->execute();
                            $final_class_id = $stmt_new_class->insert_id;
                            $classes_cache[$formatted_class_name] = $final_class_id;
                            $stmt_new_class->close();
                            systemLog($_SESSION['user_id'], 'AUTO_CREATE_CLASS', "Smart created class: $formatted_class_name");
                        }
                    }

                    // จัดการรหัสผ่าน
                    $plain_password = $csv_password;
                    $is_random = false;
                    if (empty($plain_password)) {
                        $plain_password = "bankha" . rand(1000, 9999);
                        $is_random = true;
                    }
                    $hashed_password = password_hash($plain_password, PASSWORD_DEFAULT);

                    // เช็คและเพิ่มข้อมูล
                    $stmt_check->bind_param("s", $csv_username);
                    $stmt_check->execute();
                    $stmt_check->store_result();

                    if ($stmt_check->num_rows > 0) {
                        $import_report[] = ["row" => $row_count, "user" => $csv_username, "status" => "error", "note" => "Username ซ้ำในระบบ"];
                        $fail_count++;
                    } else {
                        $stmt_insert->bind_param("ssssi", $csv_username, $hashed_password, $csv_display, $csv_role, $final_class_id);
                        if ($stmt_insert->execute()) {
                            $note = "✔ สำเร็จ";
                            if ($is_random) $note .= " (รหัส: $plain_password)";
                            $import_report[] = ["row" => $row_count, "user" => $csv_username, "status" => "success", "note" => $note];
                            $success_count++;
                        } else {
                            $import_report[] = ["row" => $row_count, "user" => $csv_username, "status" => "error", "note" => "Insert Error"];
                            $fail_count++;
                        }
                    }
                }

                fclose($handle);
                $stmt_check->close();
                $stmt_insert->close();

                $msg = "📊 นำเข้าข้อมูลเสร็จสิ้น: สำเร็จ {$success_count} รายการ, ล้มเหลว {$fail_count} รายการ";
                $msg_type = ($fail_count > 0) ? "error" : "success";
                systemLog($_SESSION['user_id'], 'BULK_IMPORT', "Imported CSV: Success $success_count, Failed $fail_count");

            } else {
                $msg = "❌ ไม่สามารถเปิดไฟล์ได้";
                $msg_type = "error";
            }
        }
    }
}

require_once 'header.php';
?>

<style>
    .import-container { display: flex; gap: 20px; flex-wrap: wrap; }
    .panel { background: white; padding: 25px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); flex: 1; min-width: 320px; }
    .file-drop-area { border: 2px dashed #94a3b8; border-radius: 12px; padding: 40px 20px; text-align: center; background: #f8fafc; cursor: pointer; transition: 0.3s; margin-bottom: 20px; }
    .file-drop-area:hover { background: #f1f5f9; border-color: #3b82f6; }
    .report-table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 0.9em; }
    .report-table th, .report-table td { padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: left; }
    .report-table th { background: #f1f5f9; color: #475569; }
    .report-table tr:hover { background: #f8fafc; }
    .txt-success { color: #166534; font-weight: bold; }
    .txt-error { color: #b91c1c; font-weight: bold; }
</style>

<div style="margin-bottom: 20px;">
    <a href="user_manager.php" style="color: #64748b; text-decoration: none; font-weight: bold;">⬅ กลับหน้ารายชื่อผู้ใช้</a>
</div>

<h2>📂 นำเข้าผู้ใช้งานด้วยไฟล์ (Bulk Import CSV)</h2>
<p style="color: #64748b; margin-top: -10px; margin-bottom: 25px;">อัปโหลดรายชื่อจากไฟล์ Excel ระบบจำจัดการแปลข้อความระดับชั้นให้อัตโนมัติ (เช่น พิมพ์ 1/1 ระบบจะแปลงเป็น ม.1/1)</p>

<?php if ($msg): ?>
    <div class="msg <?= h($msg_type) ?>" style="font-size: 1.1rem; padding: 15px;"><?= h($msg) ?></div>
<?php endif; ?>

<div class="import-container">
    
    <div class="panel" style="flex: 1;">
        <h3 style="margin-top:0; color:#0f172a;">1. เตรียมไฟล์ข้อมูล</h3>
        <p style="color: #475569; font-size: 0.95em;">พิมพ์ข้อมูลแล้วบันทึก (Save As) เป็นนามสกุล <code>CSV UTF-8 (Comma delimited)</code></p>
        <a href="import_users.php?action=download_template" class="btn-primary" style="background: #f59e0b; color: white; text-decoration: none; display: inline-block; margin-bottom: 25px;">
            📥 ดาวน์โหลดไฟล์ต้นแบบ
        </a>

        <h3 style="margin-top:0; color:#0f172a; border-top: 1px solid #e2e8f0; padding-top: 20px;">2. อัปโหลดเข้าสู่ระบบ</h3>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div class="file-drop-area" onclick="document.getElementById('csv_file').click();">
                <span style="font-size: 3rem;">📄</span><br>
                <strong style="color: #3b82f6; font-size: 1.1rem;">คลิกเพื่อเลือกไฟล์ .csv</strong><br>
                <span id="file_name_display" style="color: #64748b; margin-top: 10px; display: inline-block;">ยังไม่ได้เลือกไฟล์</span>
            </div>
            <input type="file" name="csv_file" id="csv_file" accept=".csv" required style="display: none;">
            <button type="submit" class="btn-primary" style="width: 100%; background: #10b981; font-size: 1.1rem; padding: 15px;">🚀 เริ่มนำเข้าข้อมูล</button>
        </form>
    </div>

    <div class="panel" style="flex: 1.5;">
        <h3 style="margin-top:0; color:#0f172a;">📊 รายงานผลการดำเนินการ</h3>
        <?php if (count($import_report) > 0): ?>
            <div style="max-height: 500px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px;">
                <table class="report-table">
                    <thead>
                        <tr><th width="10%">แถว</th><th width="25%">Username</th><th width="15%">สถานะ</th><th width="50%">หมายเหตุ / รหัสผ่านใหม่</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($import_report as $rep): ?>
                            <tr>
                                <td><?= $rep['row'] ?></td>
                                <td><?= h($rep['user']) ?></td>
                                <td><?= $rep['status'] === 'success' ? '<span class="txt-success">✅ สำเร็จ</span>' : '<span class="txt-error">❌ ล้มเหลว</span>' ?></td>
                                <td><?= h($rep['note']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="color: #f59e0b; font-size: 0.9em; margin-top: 10px;">⚠️ <strong>ข้อควรระวัง:</strong> หากระบบสุ่มรหัสให้ กรุณาก๊อปปี้รายงานนี้ไว้ก่อนปิดหน้าต่าง!</p>
        <?php else: ?>
            <div style="text-align: center; color: #94a3b8; padding: 40px 0;"><span style="font-size: 3rem;">📝</span><br>รายงานจะแสดงที่นี่หลังจากกดนำเข้า</div>
        <?php endif; ?>
    </div>

</div>

<script>
    document.getElementById('csv_file').addEventListener('change', function() {
        const display = document.getElementById('file_name_display');
        display.innerHTML = this.files.length > 0 ? `<strong style="color: #0f172a;">ไฟล์:</strong> ${this.files[0].name}` : "ยังไม่ได้เลือกไฟล์";
    });
</script>

<?php require_once 'footer.php'; ?>