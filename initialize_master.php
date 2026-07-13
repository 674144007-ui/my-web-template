<?php
/**
 * initialize_master.php — สคริปต์ติดตั้งและเริ่มต้นระบบฐานข้อมูลกลาง (Master DB)
 * ─────────────────────────────────────────────────────────────────────────────
 * วิธีใช้งาน: รันไฟล์นี้ผ่านเบราว์เซอร์หรือ CLI ในครั้งแรกเพื่อเตรียมฐานข้อมูลควบคุมระบบ
 * ─────────────────────────────────────────────────────────────────────────────
 */

// กำหนดพารามิเตอร์การแสดงผล Error สำหรับโหมดพัฒนา
ini_set('display_errors', 1);
error_reporting(E_ALL);

// โหลดตัวจัดการสภาพแวดล้อมระบบ
require_once __DIR__ . '/env_loader.php';

$host = env('MASTER_DB_HOST', 'localhost');
$port = (int) env('MASTER_DB_PORT', 3306);
$user = env('MASTER_DB_USER', 'root');
$pass = env('MASTER_DB_PASS', '');
$dbname = env('MASTER_DB_NAME', 'db_ssp_master');

try {
    // 1. เชื่อมต่อ MySQL Server โดยยังไม่ระบุชื่อดีบีเพื่อทำการสร้างใหม่
    $raw_pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    // 2. สร้างฐานข้อมูล Master
    $raw_pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "✅ สร้างหรือตรวจสอบฐานข้อมูล `$dbname` สำเร็จ<br>";

    // 3. สลับไปใช้งานฐานข้อมูล Master
    $raw_pdo->exec("USE `$dbname`");

    // 4. สร้างตาราง sys_tenants
    $table_tenants_sql = "
    CREATE TABLE IF NOT EXISTS `sys_tenants` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `school_code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'รหัสโรงเรียน เช่น BKW',
      `school_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ชื่อโรงเรียนภาษาไทย',
      `db_name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ชื่อ Database เช่น db_ssp_bankha',
      `logo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'path รูปโลโก้',
      `primary_color` varchar(7) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#1e3a8a' COMMENT 'สีหลัก hex',
      `is_active` tinyint(1) NOT NULL DEFAULT '1',
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_school_code` (`school_code`),
      UNIQUE KEY `uq_db_name` (`db_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='รายชื่อโรงเรียนที่ใช้แพลตฟอร์ม';
    ";
    $raw_pdo->exec($table_tenants_sql);
    echo "✅ สร้างหรือตรวจสอบตาราง `sys_tenants` สำเร็จ<br>";

    // 5. สร้างตาราง sys_platform_admins
    $table_admins_sql = "
    CREATE TABLE IF NOT EXISTS `sys_platform_admins` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `username` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
      `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Argon2id hash',
      `display_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
      `is_active` tinyint(1) NOT NULL DEFAULT '1',
      `last_login_at` datetime DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_username` (`username`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Super Admin ระดับแพลตฟอร์ม';
    ";
    $raw_pdo->exec($table_admins_sql);
    echo "✅ สร้างหรือตรวจสอบตาราง `sys_platform_admins` สำเร็จ<br>";

    // 6. เพิ่มข้อมูล Tenant แรก (โรงเรียนบ้านคาวิทยา) หากยังไม่มีในระบบ
    $check_tenant = $raw_pdo->prepare("SELECT id FROM sys_tenants WHERE school_code = ?");
    $check_tenant->execute(['BKW']);
    if (!$check_tenant->fetch()) {
        $insert_tenant = $raw_pdo->prepare("
            INSERT INTO sys_tenants (school_code, school_name, db_name, primary_color, is_active)
            VALUES (?, ?, ?, ?, 1)
        ");
        $insert_tenant->execute(['BKW', 'โรงเรียนบ้านคาวิทยา', 'db_ssp_bankha', '#1e3a8a']);
        echo "🎉 ซี้ดข้อมูลโรงเรียน 'โรงเรียนบ้านคาวิทยา (BKW)' เข้าสู่ระบบสำเร็จ<br>";
    }

    // 7. เพิ่มข้อมูลบัญชี Platform Owner สูงสุด หากยังไม่มีในระบบ
    $check_admin = $raw_pdo->prepare("SELECT id FROM sys_platform_admins WHERE username = ?");
    $check_admin->execute(['PichayaY.']);
    if (!$check_admin->fetch()) {
        // ใช้รหัสผ่านที่ถูกแฮชด้วย Argon2id ตามนโยบายความปลอดภัยสูงสุดของระบบ
        $hashed_password = '$argon2id$v=19$m=65536,t=4,p=2$bnNXSlBIU3hqbzRPc1JmSg$Xoghp9n9vCskghuUgVe3MKZeyEkzwagQ0xdeE1pMthc';
        
        $insert_admin = $raw_pdo->prepare("
            INSERT INTO sys_platform_admins (username, password, display_name, is_active)
            VALUES (?, ?, ?, 1)
        ");
        $insert_admin->execute(['PichayaY.', $hashed_password, 'พิชญะ ยาเครือ (Platform Owner)']);
        echo "🎉 ซี้ดข้อมูลผู้ดูแลระบบ 'PichayaY.' เข้าสู่ระบบสำเร็จ<br>";
    }

    echo "<br>⭐ <b>การติดตั้งและซ่อมแซมฐานข้อมูลแกนหลักระยะที่ 1 เสร็จสมบูรณ์!</b>";

} catch (PDOException $e) {
    die("❌ เกิดข้อผิดพลาดในการติดตั้งฐานข้อมูลกลาง: " . $e->getMessage());
}