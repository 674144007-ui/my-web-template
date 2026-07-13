<?php
// download_schedule_template.php - ดาวน์โหลดแบบฟอร์ม Excel สำหรับนำเข้าตารางสอน
require_once __DIR__ . '/env_loader.php';
require_once 'config.php';
require_once 'auth.php';

// สงวนสิทธิ์เฉพาะผู้พัฒนาและแอดมิน
requireRole(['developer', 'developer']);

// ตั้งค่า Header ให้เบราว์เซอร์ดาวน์โหลดไฟล์เป็น CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="Template_Class_Schedules.csv"');

// สร้าง Output Stream
$output = fopen('php://output', 'w');

// 🚨 ใส่รหัส BOM (Byte Order Mark) เพื่อให้ Microsoft Excel อ่านภาษาไทยได้สมบูรณ์
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// 1. เขียนหัวตาราง (Headers)
// คำแนะนำ: ใช้ข้อมูลที่มนุษย์อ่านเข้าใจง่าย เพื่อลดข้อผิดพลาดในการกรอก
fputcsv($output, ['class_name', 'subject_code', 'teacher_username']);

// 2. เขียนข้อมูลตัวอย่าง (Examples)
fputcsv($output, ['4/1', 'ว30221', 'tea001']);
fputcsv($output, ['4/2', 'ว30221', 'tea001']);
fputcsv($output, ['5/1', 'ค30101', 'tea002']);

// ปิดการเขียนไฟล์
fclose($output);
exit;
?>