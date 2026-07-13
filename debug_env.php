<?php
/**
 * debug_env.php — วางไฟล์นี้ในโฟลเดอร์เดียวกับ index.php แล้วเปิด
 * http://localhost/my-web-template-main/debug_env.php
 * เพื่อตรวจสอบว่า .env ถูกโหลดหรือไม่
 */

$dir = __DIR__;
$env_path = $dir . '/.env';

echo "<pre style='font-family:monospace; font-size:14px; padding:20px;'>";
echo "<h2>🔍 Debug: Environment Check</h2>";

// 1. ตรวจว่า .env มีอยู่จริงไหม
echo "<b>📁 โฟลเดอร์ปัจจุบัน:</b> $dir\n\n";

echo "<b>📄 ตรวจหาไฟล์ .env:</b>\n";
if (file_exists($env_path)) {
    echo "  ✅ พบไฟล์ .env\n";
    echo "  📏 ขนาด: " . filesize($env_path) . " bytes\n";
    echo "\n  <b>เนื้อหาใน .env (ซ่อน password):</b>\n";
    $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (str_starts_with($line, '#')) continue;
        // ซ่อน password
        if (stripos($line, 'PASS') !== false || stripos($line, 'TOKEN') !== false) {
            [$k] = explode('=', $line, 2);
            echo "  $k=***\n";
        } else {
            echo "  $line\n";
        }
    }
} else {
    echo "  ❌ ไม่พบไฟล์ .env ที่: $env_path\n";
    echo "\n  <b>รายการไฟล์ทั้งหมดในโฟลเดอร์:</b>\n";
    $files = scandir($dir);
    foreach ($files as $f) {
        echo "  - $f\n";
    }
}

// 2. โหลด .env แล้วตรวจค่า
echo "\n<b>⚙️ ค่าหลังโหลด env_loader.php:</b>\n";
if (file_exists($dir . '/env_loader.php')) {
    require_once $dir . '/env_loader.php';
    $keys = ['APP_ENV','APP_DEBUG','LOCAL_DB_HOST','LOCAL_DB_PORT','LOCAL_DB_NAME','LOCAL_DB_USER'];
    foreach ($keys as $k) {
        $v = $_ENV[$k] ?? getenv($k) ?: '(ไม่มีค่า)';
        $ok = ($v !== '(ไม่มีค่า)') ? '✅' : '❌';
        echo "  $ok $k = $v\n";
    }
} else {
    echo "  ❌ ไม่พบ env_loader.php\n";
}

// 3. ทดสอบ connect MySQL
echo "\n<b>🗄️ ทดสอบ connect MySQL:</b>\n";
if (file_exists($dir . '/env_loader.php')) {
    $host = $_ENV['LOCAL_DB_HOST'] ?? getenv('LOCAL_DB_HOST') ?: 'localhost';
    $port = $_ENV['LOCAL_DB_PORT'] ?? getenv('LOCAL_DB_PORT') ?: 3306;
    $name = $_ENV['LOCAL_DB_NAME'] ?? getenv('LOCAL_DB_NAME') ?: 'classroom_mgmt';
    $user = $_ENV['LOCAL_DB_USER'] ?? getenv('LOCAL_DB_USER') ?: 'root';
    $pass = $_ENV['LOCAL_DB_PASS'] ?? getenv('LOCAL_DB_PASS') ?: '';

    try {
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass);
        echo "  ✅ เชื่อมต่อ MySQL สำเร็จ! ($host:$port / $name)\n";
    } catch (PDOException $e) {
        echo "  ❌ เชื่อมต่อไม่ได้: " . $e->getMessage() . "\n";
        echo "\n  💡 สาเหตุที่พบบ่อย:\n";
        echo "     - รหัสผ่านผิด (XAMPP มักไม่มี password → เว้นว่าง)\n";
        echo "     - Port ผิด (XAMPP=3306, MAMP=8889)\n";
        echo "     - ยังไม่ได้ Start MySQL service\n";
    }
}

echo "</pre>";
?>
