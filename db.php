<?php
/**
 * db.php — Multi-Tenant Database Router (Clean Architecture)
 * ────────────────────────────────────────────────────────────
 * Flow:
 *   1. เชื่อมต่อ Master DB (db_ssp_master)
 *   2. อ่าน school_code จาก session หรือ POST
 *   3. Lookup db_name จาก sys_tenants
 *   4. สร้าง PDO ใหม่ไปยัง Tenant DB
 *   5. โค้ดที่เหลือทั้งหมดใช้ $pdo (tenant) ตามปกติ
 *
 * ตัวแปรที่ export ออกไป:
 *   $pdo         → PDO ของ Tenant DB (ใช้ในทุกหน้า)
 *   $master_pdo  → PDO ของ Master DB (ใช้เฉพาะ login/admin)
 *   $tenant      → array ข้อมูลโรงเรียน (school_code, school_name, primary_color ฯลฯ)
 * ────────────────────────────────────────────────────────────
 */

if (defined('DB_LOADED')) return;
define('DB_LOADED', true);

require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/config.php';

// ── 1. เชื่อมต่อ Master DB ───────────────────────────────────
$master_pdo = null;
try {
    $master_pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            env('MASTER_DB_HOST', 'localhost'),
            (int) env('MASTER_DB_PORT', 3306),
            env('MASTER_DB_NAME', 'db_ssp_master')
        ),
        env('MASTER_DB_USER', 'root'),
        env('MASTER_DB_PASS', ''),
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    $master_pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (PDOException $e) {
    error_log('[db] Master DB connection failed: ' . $e->getMessage());
    http_response_code(503);
    die(_dbErrorPage('ระบบกำลังปิดปรับปรุง กรุณาลองใหม่ภายหลัง'));
}

// ── 2. Developer bypass — เชื่อมต่อ tenant DB แรกที่ active ──
// developer มี master_pdo เสมอ แต่ต้อง connect tenant DB ด้วย
// เพราะทุก page query users/assignments/subjects ที่อยู่ใน tenant
if (!empty($_SESSION['role']) && $_SESSION['role'] === 'developer') {
    // ดึง tenant ที่ dev กำลัง work กับ (จาก session หรือ tenant แรก)
    $dev_school_code = $_SESSION['dev_school_code'] ?? null;
    
    $tenant = null;
    try {
        if ($dev_school_code) {
            $stmt = $master_pdo->prepare("SELECT * FROM sys_tenants WHERE school_code=? AND is_active=1 LIMIT 1");
            $stmt->execute([$dev_school_code]);
            $tenant = $stmt->fetch();
        }
        if (!$tenant) {
            // fallback: ใช้ tenant BKW หรือ tenant แรกในระบบ
            $tenant = $master_pdo->query("SELECT * FROM sys_tenants WHERE is_active=1 ORDER BY id ASC LIMIT 1")->fetch();
        }
    } catch (Exception $e) {
        error_log('[db.php] dev tenant lookup: ' . $e->getMessage());
    }

    if ($tenant) {
        // set dev_school_code ใน session
        if (!$dev_school_code) $_SESSION['dev_school_code'] = $tenant['school_code'];
        // เพิ่ม color ม่วง developer
        $tenant['primary_color'] = '#7c3aed';
        $tenant['school_name']   = 'Platform Developer · ' . $tenant['school_name'];
        
        // connect tenant DB
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    env('MASTER_DB_HOST','localhost'),
                    (int)env('MASTER_DB_PORT',3306),
                    $tenant['db_name']
                ),
                env('MASTER_DB_USER','root'),
                env('MASTER_DB_PASS',''),
                [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                 PDO::ATTR_EMULATE_PREPARES=>false]
            );
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("SET time_zone = '+07:00'");
        } catch (PDOException $e) {
            error_log('[db.php] dev tenant connect: ' . $e->getMessage());
            $pdo = $master_pdo; // fallback
        }
    } else {
        // ไม่มี tenant → ใช้ master (สำหรับหน้า tenant_manager เท่านั้น)
        $pdo    = $master_pdo;
        $tenant = [
            'school_code'   => 'MASTER',
            'school_name'   => 'Platform Developer',
            'db_name'       => env('MASTER_DB_NAME','db_ssp_master'),
            'primary_color' => '#7c3aed',
            'is_active'     => 1,
        ];
    }
    return;
}

// ── 3. หา school_code สำหรับ user ทั่วไป ─────────────────────
$school_code = null;

if (!empty($_SESSION['school_code'])) {
    $school_code = strtoupper(trim($_SESSION['school_code']));
} elseif (!empty($_POST['school_code'])) {
    $school_code = strtoupper(trim($_POST['school_code']));
}

if (!$school_code) {
    $pdo    = null;
    $tenant = null;
    return;
}

// ── 4. Lookup Tenant ─────────────────────────────────────────
$tenant = null;
try {
    $stmt = $master_pdo->prepare(
        "SELECT * FROM sys_tenants WHERE school_code = ? AND is_active = 1 LIMIT 1"
    );
    $stmt->execute([$school_code]);
    $tenant = $stmt->fetch();
} catch (PDOException $e) {
    error_log('[db] Tenant lookup failed: ' . $e->getMessage());
}

if (!$tenant) {
    // developer ไม่มี school_code → ไม่ต้อง redirect
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'developer') {
        $pdo    = null;
        $tenant = null;
        return;
    }
    $_SESSION = [];
    session_destroy();
    header('Location: index.php?error=invalid_school');
    exit;
}

// ── 5. เชื่อมต่อ Tenant DB ──────────────────────────────────
$pdo = null;
try {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            env('MASTER_DB_HOST', 'localhost'),
            (int) env('MASTER_DB_PORT', 3306),
            $tenant['db_name']
        ),
        env('MASTER_DB_USER', 'root'),
        env('MASTER_DB_PASS', ''),
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
        ]
    );
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET time_zone = '+07:00'");
} catch (PDOException $e) {
    error_log('[db] Tenant DB connection failed (' . $tenant['db_name'] . '): ' . $e->getMessage());
    http_response_code(503);
    $detail = (APP_ENV === 'development') ? $e->getMessage() : '';
    die(_dbErrorPage('ระบบฐานข้อมูลขัดข้อง กรุณาติดต่อผู้ดูแลระบบ', $detail));
}

// ── 6. ล้าง credentials ──────────────────────────────────────
// (ตัวแปร env ยังอยู่ใน $_ENV แต่ไม่ expose ผ่านตัวแปร PHP)

// ── Helper: DB Error Page ─────────────────────────────────────
function _dbErrorPage(string $message, string $detail = ''): string {
    $detail_html = ($detail && defined('APP_ENV') && APP_ENV === 'development')
        ? '<pre style="text-align:left;font-size:.8rem;color:#888;overflow:auto;max-width:600px">'
          . htmlspecialchars($detail) . '</pre>'
        : '';
    return <<<HTML
    <!DOCTYPE html>
    <html lang="th">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ระบบขัดข้อง</title>
        <style>
            body{font-family:'Prompt',sans-serif;display:flex;justify-content:center;
                 align-items:center;height:100vh;margin:0;background:#f1f5f9;}
            .card{background:#fff;padding:40px;border-radius:12px;text-align:center;
                  box-shadow:0 4px 20px rgba(0,0,0,.1);max-width:480px;
                  border-top:5px solid #ef4444;}
            h2{color:#ef4444;} p{color:#555;} a{color:#3b82f6;}
        </style>
    </head>
    <body>
        <div class="card">
            <h2>🚧 ระบบขัดข้อง</h2>
            <p>{$message}</p>
            {$detail_html}
            <p><a href="index.php">← กลับหน้าหลัก</a></p>
        </div>
    </body>
    </html>
    HTML;
}
