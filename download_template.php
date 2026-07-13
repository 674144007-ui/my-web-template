<?php
// download_template.php - ดาวน์โหลดแบบฟอร์ม Excel สำหรับเพิ่มรายชื่อผู้ใช้
require_once __DIR__ . '/env_loader.php';
require_once 'config.php';
require_once 'auth.php';

// สงวนสิทธิ์เฉพาะผู้พัฒนาและแอดมิน
requireRole(['developer', 'developer']);

// ตั้งค่า Header ให้เบราว์เซอร์ดาวน์โหลดไฟล์เป็น CSV (Excel อ่านได้)
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="Template_Add_Users.csv"');

// สร้าง Output Stream
$output = fopen('php://output', 'w');

// 🚨 ใส่รหัส BOM (Byte Order Mark) เพื่อให้ Microsoft Excel อ่านภาษาไทยได้โดยไม่เป็นภาษาต่างดาว
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// 1. เขียนหัวตาราง (Headers)
fputcsv($output, ['username', 'display_name', 'role', 'class_id']);

// 2. เขียนข้อมูลตัวอย่าง (Examples) เพื่อให้ผู้ใช้เข้าใจวิธีพิมพ์
fputcsv($output, ['stu101', 'ด.ช.ตัวอย่าง เรียนดี', 'student', '1']);
fputcsv($output, ['stu102', 'ด.ญ.ทดสอบ ตั้งใจ', 'student', '1']);
fputcsv($output, ['tea002', 'ครูสมปอง สอนดี', 'teacher', '']);

// ปิดการเขียนไฟล์
fclose($output);
exit;
?>