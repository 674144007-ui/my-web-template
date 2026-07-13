<?php
/**
 * api_tenant.php — Tenant Management API
 * JSON only — no HTML output
 */
ob_start(); // บรรทัดแรกสุด ก่อนทุกอย่าง

// บอก PHP ว่านี่คือ API endpoint ก่อน require ใดๆ
// validateSessionIntegrity จะอ่าน SERVER var นี้เพื่อข้าม redirect
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php'; // session_start() อยู่ใน security.php
require_once __DIR__ . '/auth.php';     // validateSessionIntegrity อยู่ใน auth.php
require_once __DIR__ . '/db.php';       // db.php ต้องมา หลัง session start แล้ว

// เคลียร์ทุก buffer level ที่ซ้อนกัน
while (ob_get_level() > 0) { ob_end_clean(); }
ob_start(); // เริ่ม buffer ใหม่สะอาด
header('Content-Type: application/json; charset=utf-8');

// Global error → JSON เสมอ (ป้องกัน PHP fatal error ส่ง HTML แทน JSON)
set_exception_handler(function(Throwable $e) {
    while(ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'msg'=>'❌ Server: '.$e->getMessage()]);
    exit;
});
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])) {
        while(ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'msg'=>'❌ PHP Fatal: '.$err['message'].' line '.$err['line']]);
    }
});

function api_ok(array $data = []): void {
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}
function api_err(string $msg): void {
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit;
}

// Auth
if (!isLoggedIn() || ($_SESSION['role'] ?? '') !== 'developer') {
    api_err('❌ ไม่มีสิทธิ์เข้าถึง');
}

// CSRF — FormData ส่งผ่าน $_POST โดยตรง
$token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    api_err('❌ CSRF token ไม่ถูกต้อง (session: ' . (!empty($_SESSION['csrf_token'])?'ok':'empty') . ')');
}

$action = $_POST['action'] ?? '';

// ════════════════════════════════════════════════════════════
//  add_tenant
// ════════════════════════════════════════════════════════════
if ($action === 'add_tenant') {
    $code   = strtoupper(trim($_POST['school_code']   ?? ''));
    $name   = trim($_POST['school_name']              ?? '');
    $color  = trim($_POST['primary_color']            ?? '#1e3a8a');
    $adName = trim($_POST['admin_display']            ?? '');
    $adUser = trim($_POST['admin_username']           ?? '');
    $adPass = $_POST['admin_password']                ?? '';

    if (!$code)   api_err('❌ กรุณากรอกรหัสโรงเรียน');
    if (!$name)   api_err('❌ กรุณากรอกชื่อโรงเรียน');
    if (!$adUser) api_err('❌ กรุณากรอก Username Admin');
    if (!$adPass) api_err('❌ กรุณากรอก Password Admin');
    if (!preg_match('/^[A-Z0-9]{2,10}$/', $code)) api_err('❌ รหัสต้องเป็น A-Z/0-9 ยาว 2-10 ตัว');
    if (strlen($adPass) < 6) api_err('❌ Password ต้องมีอย่างน้อย 6 ตัวอักษร');
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) $color = '#1e3a8a';

    $db_name    = 'db_ssp_' . strtolower($code);
    $created_db = false;
    $inserted   = false;

    try {
        // ตรวจซ้ำ
        $chk = $master_pdo->prepare('SELECT id FROM sys_tenants WHERE school_code=? LIMIT 1');
        $chk->execute([$code]);
        if ($chk->fetch()) api_err("❌ รหัส '{$code}' มีอยู่แล้ว");

        $dbExists = $master_pdo->query(
            "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='{$db_name}'"
        )->fetchColumn();
        if ($dbExists) api_err("❌ Database '{$db_name}' มีอยู่แล้ว");

        // สร้าง DB
        $master_pdo->exec("CREATE DATABASE `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $created_db = true;

        // Clone
        api_cloneSchema($db_name);

        // ตรวจ clone
        $tblCount = (int)$master_pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$db_name}'"
        )->fetchColumn();
        if ($tblCount < 3) api_err("❌ Clone ไม่สมบูรณ์ (พบ {$tblCount} ตาราง)");

        // INSERT is_active=1
        $master_pdo->prepare('
            INSERT INTO sys_tenants (school_code, school_name, db_name, primary_color, is_active, created_at)
            VALUES (?, ?, ?, ?, 1, NOW())
        ')->execute([$code, $name, $db_name, $color]);
        $inserted = true;
        $new_id   = (int)$master_pdo->lastInsertId();

        // Admin user
        $dst  = api_connect($db_name);
        // ตรวจ username ซ้ำใน tenant DB ก่อน INSERT
        $uChk = $dst->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
        $uChk->execute([$adUser]);
        if ($uChk->fetch()) api_err("❌ Username '{$adUser}' มีอยู่แล้วใน database นี้");
        $rRow = $dst->query("SELECT id FROM sys_roles WHERE role_key='developer' LIMIT 1")->fetch();
        if (!$rRow) $rRow = $dst->query("SELECT id FROM sys_roles WHERE role_key='teacher' LIMIT 1")->fetch();
        $role_id = $rRow['id'] ?? 1;
        $hashed  = password_hash($adPass, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
        $dst->prepare('
            INSERT INTO users (username, password, display_name, role_id, is_first_login, is_deleted, created_at)
            VALUES (?, ?, ?, ?, 0, 0, NOW())
        ')->execute([$adUser, $hashed, $adName ?: $adUser, $role_id]);

        // อัปโหลด Logo (ถ้ามี)
        $logo_path = null;
        if (!empty($_FILES['school_logo']) && $_FILES['school_logo']['error'] === UPLOAD_ERR_OK) {
            $logo_path = api_saveLogo($_FILES['school_logo'], $code);
            if ($logo_path) {
                $master_pdo->prepare('UPDATE sys_tenants SET logo_path=? WHERE id=?')
                    ->execute([$logo_path, $new_id]);
            }
        }

        // สร้าง user_sessions table ใน tenant DB ใหม่ (ถ้าไม่มี)
        try {
            $dst->exec("CREATE TABLE IF NOT EXISTS user_sessions (
                session_token VARCHAR(128) NOT NULL,
                user_id INT(11) NOT NULL,
                school_code VARCHAR(20) NOT NULL,
                role_key VARCHAR(30) DEFAULT NULL,
                display_name VARCHAR(120) DEFAULT NULL,
                page_current VARCHAR(150) DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (session_token),
                KEY idx_school (school_code, last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) { error_log('[api_tenant] user_sessions: '.$e->getMessage()); }

        api_ok([
            'msg'    => "สร้าง {$name} ({$code}) สำเร็จ",
            'tenant' => [
                'id'           => $new_id,
                'school_code'  => $code,
                'school_name'  => $name,
                'db_name'      => $db_name,
                'primary_color'=> $color,
                'logo_path'    => $logo_path,
                'is_active'    => 1,
                'tbl_count'    => $tblCount,
            ]
        ]);

    } catch (Exception $e) {
        if ($created_db) try { $master_pdo->exec("DROP DATABASE IF EXISTS `{$db_name}`"); } catch(Exception $r){}
        if ($inserted)   try { $master_pdo->prepare('DELETE FROM sys_tenants WHERE school_code=?')->execute([$code]); } catch(Exception $r){}
        error_log('[api_tenant] ' . $e->getMessage());
        api_err('❌ ' . $e->getMessage());
    }
}

// ════════════════════════════════════════════════════════════
//  toggle_active
// ════════════════════════════════════════════════════════════
elseif ($action === 'toggle_active') {
    $tid = (int)($_POST['tenant_id'] ?? 0);
    if (!$tid) api_err('❌ tenant_id ไม่ถูกต้อง');
    try {
        $master_pdo->prepare('UPDATE sys_tenants SET is_active=IF(is_active=1,0,1) WHERE id=?')->execute([$tid]);
        $row       = $master_pdo->prepare('SELECT is_active FROM sys_tenants WHERE id=?');
        $row->execute([$tid]);
        $is_active = (int)($row->fetch()['is_active'] ?? 0);
        api_ok([
            'msg'       => $is_active ? 'เปิดใช้งานแล้ว' : 'ปิดชั่วคราวแล้ว',
            'is_active' => $is_active,
        ]);
    } catch (Exception $e) { api_err('❌ ' . $e->getMessage()); }
}

// ════════════════════════════════════════════════════════════
//  edit_tenant
// ════════════════════════════════════════════════════════════
elseif ($action === 'edit_tenant') {
    $tid   = (int)($_POST['tenant_id']    ?? 0);
    $ename = trim($_POST['school_name']   ?? '');
    $ecol  = trim($_POST['primary_color'] ?? '#1e3a8a');
    if (!$tid)   api_err('❌ tenant_id ไม่ถูกต้อง');
    if (!$ename) api_err('❌ กรุณากรอกชื่อโรงเรียน');
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $ecol)) $ecol = '#1e3a8a';
    // อัปโหลด logo ใหม่ถ้ามี
    $logo_path = null;
    if (!empty($_FILES['school_logo']) && $_FILES['school_logo']['error'] === UPLOAD_ERR_OK) {
        $row_sc = $master_pdo->prepare('SELECT school_code FROM sys_tenants WHERE id=? LIMIT 1');
        $row_sc->execute([$tid]);
        $row_sc = $row_sc->fetch();
        if ($row_sc) {
            $logo_path = api_saveLogo($_FILES['school_logo'], $row_sc['school_code']);
        }
    }
    try {
        if ($logo_path) {
            $master_pdo->prepare('UPDATE sys_tenants SET school_name=?, primary_color=?, logo_path=? WHERE id=?')
                ->execute([$ename, $ecol, $logo_path, $tid]);
        } else {
            $master_pdo->prepare('UPDATE sys_tenants SET school_name=?, primary_color=? WHERE id=?')
                ->execute([$ename, $ecol, $tid]);
        }
        api_ok(['msg' => 'อัปเดตข้อมูลสำเร็จ', 'logo_path' => $logo_path]);
    } catch (Exception $e) { api_err('❌ ' . $e->getMessage()); }
}

else {
    api_err("❌ action '{$action}' ไม่ถูกต้อง");
}

// ════════════════════════════════════════════════════════════
//  Helpers
// ════════════════════════════════════════════════════════════

function api_saveLogo(array $file, string $code) {
    // ตรวจขนาด
    if ($file['size'] > 2 * 1024 * 1024) return null;

    // ตรวจ extension (PHP 7 compatible - ไม่ใช้ match())
    $orig_ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $ext_map  = [
        'jpg'  => 'jpg', 'jpeg' => 'jpg',
        'png'  => 'png', 'gif'  => 'gif',
        'webp' => 'webp','svg'  => 'svg',
    ];
    if (!isset($ext_map[$orig_ext])) return null;
    $ext = $ext_map[$orig_ext];

    // ตรวจว่าเป็นรูปจริงด้วย getimagesize (ยกเว้น SVG)
    if ($ext !== 'svg') {
        $info = @getimagesize($file['tmp_name']);
        if (!$info) return null;
        // map type → ext
        if ($info[2] === IMAGETYPE_JPEG) $ext = 'jpg';
        elseif ($info[2] === IMAGETYPE_PNG)  $ext = 'png';
        elseif ($info[2] === IMAGETYPE_GIF)  $ext = 'gif';
        elseif (defined('IMAGETYPE_WEBP') && $info[2] === IMAGETYPE_WEBP) $ext = 'webp';
    }

    // สร้างโฟลเดอร์
    $dir = __DIR__ . '/uploads/logos/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    // ป้องกัน PHP execution
    $htaccess = $dir . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, 'Options -ExecCGI' . "\n" . '<FilesMatch "\.php$">' . "\n" . '  Require all denied' . "\n" . '</FilesMatch>');
    }

    // บันทึกไฟล์
    $safe_code = strtolower(preg_replace('/[^a-z0-9]/i', '_', $code));
    $filename  = 'logo_' . $safe_code . '.' . $ext;
    $dest      = $dir . $filename;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return 'uploads/logos/' . $filename;
    }
    return null;
}

function api_connect(string $db): PDO {
    return new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            env('MASTER_DB_HOST','localhost'),
            (int)env('MASTER_DB_PORT',3306), $db),
        env('MASTER_DB_USER','root'),
        env('MASTER_DB_PASS',''),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
}

function api_cloneSchema(string $dst_db): void {
    global $master_pdo;
    $src_db = $master_pdo->query(
        "SELECT db_name FROM sys_tenants WHERE is_active=1 ORDER BY id ASC LIMIT 1"
    )->fetchColumn();
    if (!$src_db) throw new RuntimeException('ไม่พบโรงเรียนต้นแบบ');
    if ($src_db === $dst_db) throw new RuntimeException('src = dst');

    $src = api_connect($src_db);
    $dst = api_connect($dst_db);

    $copy_tables = [
        'sys_roles','sys_task_statuses','sys_chemical_states',
        'sys_attendance_statuses','chemicals','reactions',
        'time_slots','aksorn_quest_library','ipst_quest_library',
    ];

    $tables = $src->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if (empty($tables)) throw new RuntimeException("ไม่พบตารางใน {$src_db}");

    $dst->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        $row = $src->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
        $ddl = $row[1] ?? '';
        if (!$ddl) continue;
        $dst->exec("DROP TABLE IF EXISTS `{$table}`");
        $dst->exec($ddl);
        if (in_array($table, $copy_tables, true)) {
            $rows = $src->query("SELECT * FROM `{$table}`")->fetchAll();
            if (!$rows) continue;
            $cols   = array_keys($rows[0]);
            $colSql = implode(',', array_map(fn($c) => "`{$c}`", $cols));
            $ph     = implode(',', array_fill(0, count($cols), '?'));
            $ins    = $dst->prepare("INSERT IGNORE INTO `{$table}` ({$colSql}) VALUES ({$ph})");
            foreach ($rows as $r) $ins->execute(array_values($r));
        }
    }
    $dst->exec('SET FOREIGN_KEY_CHECKS=1');
}
