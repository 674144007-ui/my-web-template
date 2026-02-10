<?php
// import_users.php - นำเข้ารายชื่อนักเรียนจากไฟล์ CSV (รหัสผ่าน = วันเกิด)
if (ob_get_level() == 0) ob_start();
session_start();
require_once 'auth.php';
requireRole(['developer', 'admin']);
require_once 'db.php';

$msg = "";
$msg_type = "";
$success_count = 0;
$fail_count = 0;
$duplicate_count = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    
    // ตรวจสอบนามสกุลไฟล์
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        $msg = "❌ กรุณาอัปโหลดไฟล์นามสกุล .csv เท่านั้น";
        $msg_type = "error";
    } else {
        if (($handle = fopen($file['tmp_name'], "r")) !== FALSE) {
            
            // ข้ามบรรทัดหัวตาราง (Header) 1 บรรทัด
            fgetcsv($handle, 1000, ","); 

            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                // คาดหวังข้อมูล 4 คอลัมน์:
                // [0] รหัสนักเรียน
                // [1] ชื่อ-นามสกุล
                // [2] ชั้น/ห้อง
                // [3] วันเกิด (เช่น 09/12/2548)

                if (count($data) < 2) continue; // ข้ามถ้าข้อมูลไม่ครบ

                $username = trim($data[0]);
                $display_name = trim($data[1]);
                $class_level = isset($data[2]) ? trim($data[2]) : '';
                $raw_dob = isset($data[3]) ? trim($data[3]) : '';

                // --- Logic แปลงวันเกิดเป็นรหัสผ่าน ---
                // ลบเครื่องหมาย / - . หรือช่องว่างออก ให้เหลือแค่ตัวเลข
                // ตัวอย่าง: 09/12/2548 -> 09122548
                $password_text = preg_replace('/[^0-9]/', '', $raw_dob);

                // ถ้าในไฟล์ไม่มีวันเกิด หรือฟอร์แมตผิด ให้ใช้ค่า Default: 12345678
                if (empty($password_text) || strlen($password_text) < 6) {
                    $password_text = '12345678';
                }

                $password_hash = password_hash($password_text, PASSWORD_DEFAULT);
                $role = 'student';

                // --- บันทึกลงฐานข้อมูล ---
                if (!empty($username) && !empty($display_name)) {
                    // 1. เช็คว่ามี Username นี้หรือยัง
                    $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
                    $check->bind_param("s", $username);
                    $check->execute();
                    $result = $check->get_result();

                    if ($result->num_rows > 0) {
                        $duplicate_count++;
                    } else {
                        // 2. เพิ่มข้อมูล (ใช้ $password_hash ที่เจนมาจากวันเกิด)
                        $insert = $conn->prepare("INSERT INTO users (username, password, display_name, role, class_level) VALUES (?, ?, ?, ?, ?)");
                        $insert->bind_param("sssss", $username, $password_hash, $display_name, $role, $class_level);
                        
                        if ($insert->execute()) {
                            $success_count++;
                        } else {
                            $fail_count++;
                        }
                    }
                }
            }
            fclose($handle);

            $msg = "📊 สรุปผลการนำเข้า:<br>
                    ✅ สำเร็จ: $success_count คน<br>
                    ⚠️ ซ้ำ (ข้าม): $duplicate_count คน<br>
                    ❌ ผิดพลาด: $fail_count คน";
            $msg_type = ($success_count > 0) ? "success" : "warning";
        } else {
            $msg = "ไม่สามารถเปิดไฟล์ได้"; $msg_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Import Users</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Sarabun', sans-serif; background: #f1f5f9; padding: 20px; }
    .container { max-width: 700px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
    h2 { text-align: center; color: #1e293b; border-bottom: 2px solid #e2e8f0; padding-bottom: 15px; }
    
    .guide-box { background: #e0f2fe; border: 1px solid #7dd3fc; border-radius: 8px; padding: 15px; margin-bottom: 20px; color: #0c4a6e; font-size: 0.95rem; }
    .guide-box strong { color: #0284c7; }
    
    .btn { width: 100%; padding: 12px; background: #10b981; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.2s; font-size: 1rem; }
    .btn:hover { background: #059669; }
    .btn-back { background: transparent; color: #64748b; border: 1px solid #cbd5e1; margin-top: 10px; width: 100%; display: block; text-align: center; text-decoration: none; padding: 12px 0; border-radius: 8px; box-sizing: border-box; }
    .btn-back:hover { background: #f1f5f9; color: #1e293b; }

    .alert { padding: 15px; margin-bottom: 20px; border-radius: 8px; text-align: center; line-height: 1.6; }
    .success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
    .warning { background: #fef9c3; color: #854d0e; border: 1px solid #fde047; }
    .error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

    table.example { width: 100%; border-collapse: collapse; margin-top: 10px; background: white; }
    table.example th, table.example td { border: 1px solid #cbd5e1; padding: 8px; text-align: left; font-size: 0.9rem; }
    table.example th { background: #f1f5f9; }
</style>
</head>
<body>

<div class="container">
    <h2>📂 นำเข้ารายชื่อนักเรียน (Excel/CSV)</h2>
    
    <?php if ($msg): ?>
        <div class="alert <?= $msg_type ?>"><?= $msg ?></div>
    <?php endif; ?>

    <div class="guide-box">
        <strong>📌 วิธีเตรียมไฟล์ Excel:</strong>
        <ol style="margin: 5px 0 10px 20px; padding:0;">
            <li>สร้างไฟล์ Excel โดยมี 4 คอลัมน์ เรียงตามนี้:</li>
            <li><strong>A: รหัสนักเรียน</strong> (ใช้เป็น Username)</li>
            <li><strong>B: ชื่อ-นามสกุล</strong></li>
            <li><strong>C: ชั้น/ห้อง</strong> (เช่น ม.6/1)</li>
            <li><strong>D: วันเดือนปีเกิด</strong> (เช่น 09/12/2548 หรือ 09122548) <br>
                <span style="color:red; font-size:0.85rem;">*ระบบจะนำเลขวันเกิดมาเป็นรหัสผ่านโดยอัตโนมัติ</span>
            </li>
            <li>บันทึกไฟล์เป็นนามสกุล <strong>CSV (Comma delimited) (*.csv)</strong></li>
        </ol>
        
        <strong>ตัวอย่างข้อมูลในไฟล์:</strong>
        <table class="example">
            <tr><th>A (รหัส)</th><th>B (ชื่อ-สกุล)</th><th>C (ห้อง)</th><th>D (วันเกิด/รหัสผ่าน)</th></tr>
            <tr><td>66001</td><td>ด.ช.มานะ ขยัน</td><td>ม.6/1</td><td>09/12/2548</td></tr>
            <tr><td>66002</td><td>ด.ญ.มานี ใจดี</td><td>ม.6/1</td><td>25/01/2549</td></tr>
        </table>
        <small style="color:#64748b; display:block; margin-top:5px;">*ถ้านักเรียนคนไหนไม่มีข้อมูลวันเกิด ระบบจะตั้งรหัสผ่านเป็น <code>12345678</code> ให้แทน</small>
    </div>

    <form method="post" enctype="multipart/form-data">
        <div style="margin-bottom: 20px;">
            <label style="font-weight:bold; display:block; margin-bottom:5px;">เลือกไฟล์ CSV</label>
            <input type="file" name="csv_file" accept=".csv" required style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px;">
        </div>

        <button type="submit" class="btn" onclick="return confirm('ยืนยันการนำเข้าข้อมูล?');">🚀 นำเข้าข้อมูล</button>
        <a href="user_manager.php" class="btn-back">⬅ กลับหน้าจัดการผู้ใช้</a>
    </form>
</div>

</body>
</html>