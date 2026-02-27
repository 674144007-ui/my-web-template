<?php
// generate_lab_report.php - สร้างรายงานผลการทดลองสำหรับสั่งพิมพ์ (Print/PDF)
require_once 'config.php';
require_once 'auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("ไม่อนุญาตให้เข้าถึงหน้านี้โดยตรง");
}

verify_csrf_token($_POST['csrf_token'] ?? '');

$student_name = $_SESSION['display_name'];
$class_level = $_SESSION['class_level'] ?? 'ไม่ระบุชั้น';
$date_now = date('d / m / Y (H:i น.)');

$product_name = trim($_POST['product_name'] ?? 'ไม่ทราบข้อมูล');
$precipitate = trim($_POST['precipitate'] ?? 'ไม่มีข้อมูล');
$gas = trim($_POST['gas'] ?? 'ไม่มีข้อมูล');
$color = trim($_POST['color'] ?? '#FFFFFF');

?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>รายงานผลการทดลอง - <?= h($student_name) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
<style>
    /* ตั้งค่าฟอนต์สารบรรณให้เหมือนเอกสารราชการ/โรงเรียน */
    body { font-family: 'Sarabun', sans-serif; color: #000; background: #525659; margin: 0; padding: 20px; }
    
    /* กรอบกระดาษ A4 */
    .a4-page {
        background: white;
        width: 210mm;
        min-height: 297mm;
        margin: 0 auto;
        padding: 20mm;
        box-sizing: border-box;
        box-shadow: 0 0 10px rgba(0,0,0,0.5);
    }
    
    .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 20px; }
    .header h1 { margin: 0; font-size: 24pt; }
    .header p { margin: 5px 0 0 0; font-size: 14pt; }
    
    .info-section { margin-bottom: 20px; font-size: 14pt; line-height: 1.8; }
    .result-section { border: 1px solid #000; padding: 20px; margin-bottom: 30px; }
    .result-section h2 { border-bottom: 1px dashed #ccc; padding-bottom: 10px; margin-top: 0; }
    
    .color-box { display: inline-block; width: 20px; height: 20px; border: 1px solid #000; vertical-align: middle; margin-left: 10px; }
    
    .signature { text-align: right; margin-top: 50px; padding-right: 30px; font-size: 14pt; }
    
    /* ซ่อนปุ่มกดตอนพริ้นต์ */
    @media print {
        body { background: white; padding: 0; }
        .a4-page { box-shadow: none; width: auto; min-height: auto; padding: 0; }
        .no-print { display: none; }
    }
    
    .print-btn {
        display: block; margin: 0 auto 20px auto; padding: 15px 30px;
        background: #2563eb; color: white; border: none; border-radius: 8px;
        font-size: 16px; cursor: pointer; font-family: 'Sarabun', sans-serif;
    }
</style>
</head>
<body>

<button class="print-btn no-print" onclick="window.print();">🖨️ พิมพ์เป็น PDF หรือกระดาษ</button>

<div class="a4-page">
    <div class="header">
        <h1>แบบบันทึกผลการทดลองเคมี (Virtual Lab)</h1>
        <p>โรงเรียนบ้านคาวิทยา (Bankha Withaya School)</p>
    </div>

    <div class="info-section">
        <strong>ชื่อ-นามสกุล ผู้ทดลอง:</strong> <?= h($student_name) ?> <br>
        <strong>ระดับชั้น:</strong> <?= h($class_level) ?> <br>
        <strong>วันและเวลาที่ทดลอง:</strong> <?= $date_now ?> <br>
    </div>

    <div class="result-section">
        <h2>📊 สรุปผลการทดลอง</h2>
        <p style="font-size: 14pt; line-height: 1.8;">
            <strong>ชื่อผลิตภัณฑ์ที่ได้จากปฏิกิริยา:</strong> <?= h($product_name) ?> <br>
            <strong>สีของสารละลายที่สังเกตได้:</strong> 
            <span class="color-box" style="background-color: <?= h($color) ?>;"></span> 
            (รหัสสี: <?= h($color) ?>) <br>
            <strong>การเกิดตะกอน:</strong> <?= h($precipitate) ?> <br>
            <strong>การเกิดแก๊ส:</strong> <?= h($gas) ?>
        </p>
    </div>

    <div class="info-section" style="border-top: 1px solid #000; padding-top: 20px;">
        <strong>สรุปและวิจารณ์ผลการทดลอง:</strong><br>
        ................................................................................................................................................<br>
        ................................................................................................................................................<br>
        ................................................................................................................................................<br>
    </div>

    <div class="signature">
        ลงชื่อ ....................................................... ผู้ทดลอง <br>
        ( <?= h($student_name) ?> ) <br>
        ........ / ........ / ........
    </div>
</div>

<script>
    // สั่งเปิดหน้าต่าง Print อัตโนมัติเมื่อโหลดเสร็จ (ถ้าต้องการให้ทำงานทันทีเอา comment ออก)
    // window.onload = function() { window.print(); }
</script>
</body>
</html>