<?php
/**
 * env_loader.php — ระบบอ่านค่าสภาพแวดล้อม (Environment Variables)
 * ────────────────────────────────────────────────────────────
 * ทำหน้าที่อ่านไฟล์ .env และแปลงค่ามาเก็บไว้ในระบบ
 * เพื่อไม่ให้ต้อง Hardcode รหัสผ่านฐานข้อมูลลงในไฟล์ PHP โดยตรง
 * ────────────────────────────────────────────────────────────
 */

if (!function_exists('env')) {
    /**
     * ดึงค่าจากตัวแปรสภาพแวดล้อม
     *
     * @param string $key ชื่อตัวแปร (เช่น MASTER_DB_USER)
     * @param mixed $default ค่าเริ่มต้นหากหาตัวแปรไม่พบ
     * @return mixed
     */
    function env(string $key, $default = null) {
        $value = getenv($key);
        
        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        }

        // แปลงค่า boolean หรือ null ที่เป็น string ให้เป็น Type ที่ถูกต้อง
        switch (strtolower((string)$value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'empty':
            case '(empty)':
                return '';
            case 'null':
            case '(null)':
                return null;
        }

        return $value;
    }
}

// พาธของไฟล์ .env (อ้างอิงจากตำแหน่งโฟลเดอร์ปัจจุบัน)
$envFilePath = __DIR__ . '/.env';

if (file_exists($envFilePath)) {
    // อ่านไฟล์ทีละบรรทัด โดยข้ามบรรทัดว่างและบรรทัดที่ขึ้นต้นด้วย # (Comment)
    $lines = file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, '#') === 0 || empty($line)) {
            continue;
        }

        // แยก key และ value ด้วยเครื่องหมาย = ตัวแรก
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            // ตัดเครื่องหมายคำพูดออก (ถ้ามี)
            $value = trim($value, '"\'');

            // บันทึกตัวแปรเข้าระบบ
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
} else {
    // กรณีหาไฟล์ .env ไม่เจอ ให้บันทึกลง Log (ไม่ควรแสดงบนหน้าเว็บตรงๆ)
    error_log("⚠️ Warning: ไม่พบไฟล์ .env ในโฟลเดอร์ " . __DIR__);
}