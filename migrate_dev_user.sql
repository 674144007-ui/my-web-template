-- ════════════════════════════════════════════════════════════
-- migrate_dev_user.sql
-- ย้าย PichayaY. จาก tenant db ไปเป็น dev_users ใน master DB
-- รันใน phpMyAdmin → เลือก db_ssp_master แล้ว import ไฟล์นี้
-- ════════════════════════════════════════════════════════════

USE db_ssp_master;

-- 1. สร้างตาราง dev_users ถ้ายังไม่มี
CREATE TABLE IF NOT EXISTS dev_users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(60)  NOT NULL UNIQUE,
    password        VARCHAR(255) NOT NULL,
    display_name    VARCHAR(120) NOT NULL,
    email           VARCHAR(180) DEFAULT NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at   DATETIME     DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. ดึง password hash ของ PichayaY. จาก tenant BKW มาใส่ dev_users
-- (ถ้า INSERT ซ้ำ → UPDATE password ให้ใหม่)
INSERT INTO dev_users (username, password, display_name, is_active)
SELECT 
    u.username,
    u.password,
    u.display_name,
    1
FROM db_ssp_bankha.users u
JOIN db_ssp_bankha.sys_roles r ON r.id = u.role_id
WHERE u.username = 'PichayaY.' AND r.role_key = 'developer'
ON DUPLICATE KEY UPDATE 
    password     = VALUES(password),
    display_name = VALUES(display_name),
    is_active    = 1;

-- 3. ตรวจสอบผล
SELECT id, username, display_name, is_active, last_login_at 
FROM dev_users;
