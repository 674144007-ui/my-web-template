<?php
// dev_lab_config.php - Advanced Lab Configurator (Phase 2)
// ระบบจัดการสารเคมีและปฏิกิริยาสำหรับ Developer
require_once __DIR__ . '/env_loader.php';
require_once 'config.php';
require_once 'db.php';
require_once 'security.php';
require_once 'auth.php';
require_once 'logger.php';

// บังคับสิทธิ์ต้องเป็น Developer เท่านั้น
requireRole(['developer']);
$user = currentUser();
$csrf = generate_csrf_token();
$message = '';
$msg_type = '';

// =========================================================
// 1. จัดการ POST Requests (CRUD สำหรับ Chemicals และ Reactions)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("CSRF Token Verification Failed.");
    }

    $action = $_POST['action'] ?? '';

    // -------- หมวดสารเคมี (Chemicals) --------
    if ($action === 'add_chem') {
        $name = trim($_POST['name']);
        $formula = trim($_POST['formula']);
        $state = $_POST['state'];
        $molarity = floatval($_POST['molarity']);
        $type = $_POST['type'];
        $color = $_POST['color_neutral'];
        $toxicity = intval($_POST['toxicity']);

        if (!empty($name)) {
            $stmt = $pdo->prepare("INSERT INTO chemicals (name, formula, state, molarity, type, color_neutral, toxicity) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $formula, $state, $molarity, $type, $color, $toxicity]);
            if ($stmt->execute()) {
                $message = "✅ เพิ่มสารเคมีใหม่: {$name} เรียบร้อยแล้ว";
                $msg_type = "success";
                systemLog($user['id'], 'LAB_CONFIG', "Added chemical: {$name}");
            } else {
                $message = "❌ เกิดข้อผิดพลาด: " . $stmt->error;
                $msg_type = "error";
            }
            
        }
    } elseif ($action === 'edit_chem') {
        $id = intval($_POST['chem_id']);
        $name = trim($_POST['name']);
        $formula = trim($_POST['formula']);
        $state = $_POST['state'];
        $molarity = floatval($_POST['molarity']);
        $type = $_POST['type'];
        $color = $_POST['color_neutral'];
        $toxicity = intval($_POST['toxicity']);

        $stmt = $pdo->prepare("UPDATE chemicals SET name=?, formula=?, state=?, molarity=?, type=?, color_neutral=?, toxicity=? WHERE id=?");
        $stmt->execute([$name, $formula, $state, $molarity, $type, $color, $toxicity, $id]);
        if ($stmt->execute()) {
            $message = "✅ อัปเดตข้อมูลสารเคมี: {$name} เรียบร้อยแล้ว";
            $msg_type = "success";
            systemLog($user['id'], 'LAB_CONFIG', "Updated chemical ID: {$id}");
        }
        
    } elseif ($action === 'del_chem') {
        $id = intval($_POST['chem_id']);
        // ลบสารเคมี (หมายเหตุ: ควรเช็คว่ามีปฏิกิริยาผูกอยู่ไหม แต่ที่นี่เราใช้ ON DELETE CASCADE หรือปล่อยให้แอดมินรับผิดชอบ)
        $stmt = $pdo->prepare("DELETE FROM chemicals WHERE id=?");
        $stmt->execute([$id]);
        if ($stmt->execute()) {
            $message = "🗑️ ลบสารเคมีออกจากระบบเรียบร้อย";
            $msg_type = "success";
            systemLog($user['id'], 'LAB_CONFIG', "Deleted chemical ID: {$id}");
        }
        
    }

    // -------- หมวดปฏิกิริยา (Reactions) --------
    elseif ($action === 'add_reaction') {
        $chem1 = intval($_POST['chem1_id']);
        $chem2 = intval($_POST['chem2_id']);
        $product = trim($_POST['product_name']);
        $res_color = $_POST['result_color'];
        $res_state = $_POST['result_state'];
        $precipitate = trim($_POST['result_precipitate']) ?: 'ไม่มีตะกอน';
        $gas = trim($_POST['result_gas']) ?: 'ไม่มีแก๊ส';
        $heat = floatval($_POST['heat_level']);
        $is_exp = isset($_POST['is_explosive']) ? 1 : 0;
        $tox_bonus = intval($_POST['toxicity_bonus']);

        if ($chem1 > 0 && $chem2 > 0 && !empty($product)) {
            $stmt = $pdo->prepare("INSERT INTO reactions (chem1_id, chem2_id, product_name, result_color, result_state, result_precipitate, result_gas, heat_level, is_explosive, toxicity_bonus) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$chem1, $chem2, $product, $res_color, $res_state, $precipitate, $gas, $heat, $is_exp, $tox_bonus]);
            if ($stmt->execute()) {
                $message = "✅ ผูกสมการปฏิกิริยาใหม่เรียบร้อยแล้ว";
                $msg_type = "success";
                systemLog($user['id'], 'LAB_CONFIG', "Added new reaction between Chem {$chem1} and Chem {$chem2}");
            } else {
                $message = "❌ เกิดข้อผิดพลาด: " . $stmt->error;
                $msg_type = "error";
            }
            
        }
    } elseif ($action === 'del_reaction') {
        $id = intval($_POST['reaction_id']);
        $stmt = $pdo->prepare("DELETE FROM reactions WHERE id=?");
        $stmt->execute([$id]);
        if ($stmt->execute()) {
            $message = "🗑️ ลบปฏิกิริยาออกจากระบบเรียบร้อย";
            $msg_type = "success";
        }
        
    }
}

// =========================================================
// 2. ดึงข้อมูลมาแสดงผลในตาราง
// =========================================================

// ดึงสารเคมีทั้งหมด
$chemicals = [];
$res_c = $pdo->query("SELECT * FROM chemicals ORDER BY id DESC");
if ($res_c) {
    while ($row = $res_c->fetch()) {
        $chemicals[] = $row;
    }
}

// ดึงปฏิกิริยาทั้งหมด พร้อม Join ชื่อสารเคมี
$reactions = [];
$res_r = $pdo->query("
    SELECT r.*, c1.name as c1_name, c2.name as c2_name 
    FROM reactions r
    LEFT JOIN chemicals c1 ON r.chem1_id = c1.id
    LEFT JOIN chemicals c2 ON r.chem2_id = c2.id
    ORDER BY r.id DESC
");
if ($res_r) {
    while ($row = $res_r->fetch()) {
        $reactions[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lab Configurator | Developer</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600;700&family=Orbitron:wght@500;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --bg-dark: #020617;
            --bg-panel: rgba(15, 23, 42, 0.85);
            --border-glass: rgba(148, 163, 184, 0.2);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --neon-blue: #38bdf8;
            --neon-purple: #c084fc;
            --neon-green: #4ade80;
            --neon-red: #f87171;
            --neon-orange: #fb923c;
        }

        body {
            margin: 0; padding: 0; font-family: 'Prompt', sans-serif;
            background-color: var(--bg-dark); color: var(--text-main);
            background-image: radial-gradient(circle at 50% 0%, rgba(56, 189, 248, 0.1), transparent 50%);
            min-height: 100vh; display: flex; flex-direction: column;
        }

        /* Topbar */
        .topbar {
            height: 70px; background: rgba(2, 6, 23, 0.9); backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border-glass);
            display: flex; justify-content: space-between; align-items: center; padding: 0 30px;
            position: sticky; top: 0; z-index: 100;
        }
        .topbar h1 { margin: 0; font-family: 'Orbitron', sans-serif; font-size: 1.5rem; color: var(--neon-blue); text-shadow: 0 0 10px rgba(56,189,248,0.5); }
        .btn-back { color: var(--text-muted); text-decoration: none; font-weight: bold; border: 1px solid var(--border-glass); padding: 8px 15px; border-radius: 8px; transition: 0.3s; }
        .btn-back:hover { background: rgba(255,255,255,0.1); color: white; }

        /* Container & Tabs */
        .container { max-width: 1400px; margin: 30px auto; padding: 0 20px; flex: 1; width: 100%; box-sizing: border-box; }
        
        .alert-box { padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; animation: fadeIn 0.5s; border: 1px solid transparent; }
        .alert-success { background: rgba(74, 222, 128, 0.1); color: var(--neon-green); border-color: rgba(74, 222, 128, 0.3); }
        .alert-error { background: rgba(248, 113, 113, 0.1); color: var(--neon-red); border-color: rgba(248, 113, 113, 0.3); }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

        .tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid var(--border-glass); padding-bottom: 10px; }
        .tab-btn { background: transparent; color: var(--text-muted); border: none; padding: 10px 25px; font-size: 1.1rem; font-weight: bold; cursor: pointer; border-radius: 10px 10px 0 0; transition: 0.3s; font-family: inherit; position: relative;}
        .tab-btn:hover { color: white; background: rgba(255,255,255,0.05); }
        .tab-btn.active { color: var(--neon-blue); background: rgba(56, 189, 248, 0.1); }
        .tab-btn.active::after { content: ''; position: absolute; bottom: -12px; left: 0; width: 100%; height: 2px; background: var(--neon-blue); box-shadow: 0 0 10px var(--neon-blue); }

        .tab-content { display: none; animation: fadeIn 0.4s; }
        .tab-content.active { display: block; }

        /* Panel Controls */
        .panel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; background: var(--bg-panel); padding: 20px; border-radius: 12px; border: 1px solid var(--border-glass); }
        .panel-header h2 { margin: 0; color: white; }
        .btn-primary { background: linear-gradient(135deg, #0ea5e9, #2563eb); color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.3s; font-family: inherit; box-shadow: 0 4px 15px rgba(14, 165, 233, 0.4); display: flex; align-items: center; gap: 8px;}
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(14, 165, 233, 0.6); }

        .btn-danger { background: rgba(239, 68, 68, 0.1); color: var(--neon-red); border: 1px solid var(--neon-red); padding: 5px 10px; border-radius: 6px; cursor: pointer; transition: 0.2s; font-family: inherit; }
        .btn-danger:hover { background: var(--neon-red); color: white; box-shadow: 0 0 10px rgba(239,68,68,0.5); }
        .btn-edit { background: rgba(56, 189, 248, 0.1); color: var(--neon-blue); border: 1px solid var(--neon-blue); padding: 5px 10px; border-radius: 6px; cursor: pointer; transition: 0.2s; font-family: inherit; }
        .btn-edit:hover { background: var(--neon-blue); color: var(--bg-dark); box-shadow: 0 0 10px rgba(56,189,248,0.5); }

        /* Data Tables */
        .table-container { background: var(--bg-panel); border: 1px solid var(--border-glass); border-radius: 12px; overflow: auto; max-height: 70vh; scrollbar-width: thin; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        thead th { background: rgba(255,255,255,0.05); color: var(--text-muted); font-family: 'Share Tech Mono', monospace; padding: 15px; position: sticky; top: 0; z-index: 10; backdrop-filter: blur(5px); border-bottom: 1px solid var(--border-glass);}
        tbody td { padding: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); color: var(--text-main); font-size: 0.95rem; }
        tbody tr:hover { background: rgba(255,255,255,0.02); }
        
        .color-dot { display: inline-block; width: 15px; height: 15px; border-radius: 50%; border: 1px solid rgba(255,255,255,0.5); vertical-align: middle; margin-right: 8px; box-shadow: 0 0 5px currentColor;}
        .badge { padding: 3px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: bold; background: rgba(255,255,255,0.1); }
        .badge.solid { color: #a8a29e; } .badge.liquid { color: #38bdf8; } .badge.gas { color: #c084fc; }
        .badge.explosive { background: rgba(2ef, 68, 68, 0.2); color: var(--neon-red); border: 1px solid var(--neon-red); animation: pulseExplosive 1.5s infinite;}
        
        @keyframes pulseExplosive { 0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); } 70% { box-shadow: 0 0 0 5px rgba(239, 68, 68, 0); } 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); } }

        /* Modals */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(2, 6, 23, 0.85); backdrop-filter: blur(5px); z-index: 9999; display: none; align-items: center; justify-content: center; }
        .modal-box { background: #0f172a; width: 100%; max-width: 600px; border-radius: 16px; border: 1px solid var(--neon-blue); box-shadow: 0 20px 50px rgba(0,0,0,0.8), 0 0 30px rgba(56,189,248,0.1); transform: scale(0.95); opacity: 0; transition: 0.3s; display: flex; flex-direction: column; max-height: 90vh; }
        .modal-box.show { transform: scale(1); opacity: 1; }
        
        .modal-header { padding: 20px 25px; border-bottom: 1px solid var(--border-glass); display: flex; justify-content: space-between; align-items: center; background: rgba(56, 189, 248, 0.05); border-radius: 16px 16px 0 0;}
        .modal-header h2 { margin: 0; font-family: 'Orbitron'; color: var(--neon-blue); font-size: 1.4rem; }
        .btn-close { background: transparent; color: var(--text-muted); border: none; font-size: 1.5rem; cursor: pointer; transition: 0.2s; }
        .btn-close:hover { color: white; transform: scale(1.2); }

        .modal-body { padding: 25px; overflow-y: auto; flex: 1; scrollbar-width: thin; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; color: var(--text-muted); margin-bottom: 8px; font-size: 0.9rem; }
        .form-control { width: 100%; padding: 12px 15px; border-radius: 8px; border: 1px solid var(--border-glass); background: #1e293b; color: white; outline: none; font-family: inherit; font-size: 1rem; box-sizing: border-box; transition: 0.3s; }
        .form-control:focus { border-color: var(--neon-blue); box-shadow: inset 0 0 10px rgba(56,189,248,0.2); }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        
        .color-picker-wrapper { display: flex; align-items: center; gap: 10px; background: #1e293b; padding: 8px 15px; border-radius: 8px; border: 1px solid var(--border-glass); }
        .color-picker-wrapper input[type="color"] { background: transparent; border: none; width: 40px; height: 30px; cursor: pointer; padding: 0; }
        .color-picker-wrapper input[type="text"] { background: transparent; border: none; color: white; outline: none; flex: 1; font-family: 'Share Tech Mono'; }

        .modal-footer { padding: 20px 25px; border-top: 1px solid var(--border-glass); display: flex; justify-content: flex-end; gap: 15px; background: rgba(0,0,0,0.2); border-radius: 0 0 16px 16px;}
        .btn-cancel { background: transparent; color: var(--text-muted); border: 1px solid var(--text-muted); padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: bold; }
        .btn-cancel:hover { background: rgba(255,255,255,0.1); color: white; }
    </style>
</head>
<body>

    <header class="topbar">
        <h1>⚙️ Advanced Lab Configurator</h1>
        <a href="dashboard_dev.php" class="btn-back">⬅ Back to Dashboard</a>
    </header>

    <div class="container">
        <?php if ($message): ?>
            <div class="alert-box alert-<?= $msg_type ?>">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('tab-chemicals', this)">🧪 จัดการสารเคมี (Chemicals Base)</button>
            <button class="tab-btn" onclick="switchTab('tab-reactions', this)">💥 กฎปฏิกิริยา (Reaction Matrix)</button>
        </div>

        <div id="tab-chemicals" class="tab-content active">
            <div class="panel-header">
                <div>
                    <h2>คลังข้อมูลสารเคมีและธาตุ</h2>
                    <p style="color:var(--text-muted); margin:5px 0 0 0; font-size:0.9rem;">เพิ่ม ลบ หรือแก้ไขสารเคมีที่จะนำไปใช้ในห้องทดลองเสมือนจริง</p>
                </div>
                <button class="btn-primary" onclick="openChemModal()">➕ เพิ่มสารเคมีใหม่</button>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th width="50">ID</th>
                            <th>สารเคมี / ชื่อธาตุ (Name)</th>
                            <th>สูตร (Formula)</th>
                            <th>สถานะ</th>
                            <th>Molarity</th>
                            <th>สี (Color)</th>
                            <th>ความพิษ</th>
                            <th width="120">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($chemicals as $c): ?>
                        <tr>
                            <td style="color:var(--text-muted);">#<?= $c['id'] ?></td>
                            <td style="font-weight:bold; color:var(--neon-blue);"><?= htmlspecialchars($c['name']) ?></td>
                            <td style="font-family:'Share Tech Mono'; color:var(--neon-purple);"><?= htmlspecialchars($c['formula'] ?? '-') ?></td>
                            <td><span class="badge <?= $c['state'] ?>"><?= strtoupper($c['state']) ?></span></td>
                            <td><?= $c['molarity'] ?> M</td>
                            <td>
                                <span class="color-dot" style="background-color: <?= $c['color_neutral'] ?>; color: <?= $c['color_neutral'] ?>;"></span>
                                <span style="font-family:'Share Tech Mono'; font-size:0.8rem;"><?= $c['color_neutral'] ?></span>
                            </td>
                            <td>
                                <div style="width:60px; height:6px; background:#334155; border-radius:3px; overflow:hidden;">
                                    <div style="height:100%; width:<?= $c['toxicity'] ?>%; background: <?= $c['toxicity'] > 50 ? 'var(--neon-red)' : 'var(--neon-green)' ?>;"></div>
                                </div>
                            </td>
                            <td>
                                <div style="display:flex; gap:5px;">
                                    <button class="btn-edit" onclick='editChem(<?= json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>✎</button>
                                    <form method="POST" style="margin:0;" onsubmit="return confirm('ยืนยันการลบสารเคมีนี้? (อาจกระทบต่อสมการปฏิกิริยา)');">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="action" value="del_chem">
                                        <input type="hidden" name="chem_id" value="<?= $c['id'] ?>">
                                        <button type="submit" class="btn-danger">🗑️</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="tab-reactions" class="tab-content">
            <div class="panel-header">
                <div>
                    <h2>กฎปฏิกิริยาเคมี (Reaction Matrix)</h2>
                    <p style="color:var(--text-muted); margin:5px 0 0 0; font-size:0.9rem;">จับคู่สารเคมี A + B เพื่อระบุผลลัพธ์ (ผลิตภัณฑ์, สี, แก๊ส, การระเบิด)</p>
                </div>
                <button class="btn-primary" onclick="openReactionModal()" style="background: linear-gradient(135deg, #c084fc, #9333ea);">➕ สร้างปฏิกิริยาใหม่</button>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th width="50">ID</th>
                            <th>สมการ (Reactants)</th>
                            <th>ผลลัพธ์ (Product)</th>
                            <th>สีใหม่</th>
                            <th>ตะกอน / แก๊ส</th>
                            <th>อุณหภูมิ</th>
                            <th>สถานะพิเศษ</th>
                            <th width="60">ลบ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reactions as $r): ?>
                        <tr>
                            <td style="color:var(--text-muted);">#<?= $r['id'] ?></td>
                            <td>
                                <strong style="color:var(--neon-blue);"><?= htmlspecialchars($r['c1_name']) ?></strong> 
                                <span style="color:var(--text-muted);">+</span> 
                                <strong style="color:var(--neon-purple);"><?= htmlspecialchars($r['c2_name']) ?></strong>
                            </td>
                            <td style="font-weight:bold; color:var(--neon-green);"><?= htmlspecialchars($r['product_name']) ?></td>
                            <td>
                                <span class="color-dot" style="background-color: <?= $r['result_color'] ?>; color: <?= $r['result_color'] ?>;"></span>
                            </td>
                            <td style="font-size:0.85rem; color:var(--text-muted);">
                                ⬇️ <?= htmlspecialchars($r['result_precipitate']) ?><br>
                                ☁️ <?= htmlspecialchars($r['result_gas']) ?>
                            </td>
                            <td><span style="<?= $r['heat_level'] > 0 ? 'color:var(--neon-orange);' : '' ?>">+<?= $r['heat_level'] ?> °C</span></td>
                            <td>
                                <?php if($r['is_explosive']): ?>
                                    <span class="badge explosive">💥 ระเบิด!</span>
                                <?php else: ?>
                                    <span style="color:var(--text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" style="margin:0;" onsubmit="return confirm('ยืนยันการลบสมการปฏิกิริยานี้?');">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                    <input type="hidden" name="action" value="del_reaction">
                                    <input type="hidden" name="reaction_id" value="<?= $r['id'] ?>">
                                    <button type="submit" class="btn-danger">🗑️</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <div class="modal-overlay" id="modalChemOverlay">
        <div class="modal-box" id="modalChemBox">
            <form method="POST" action="dev_lab_config.php">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" id="chemAction" value="add_chem">
                <input type="hidden" name="chem_id" id="chemId" value="0">

                <div class="modal-header">
                    <h2 id="chemModalTitle">ADD CHEMICAL</h2>
                    <button type="button" class="btn-close" onclick="closeChemModal()">✖</button>
                </div>
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>ชื่อสารเคมี (Name) *</label>
                        <input type="text" name="name" id="c_name" class="form-control" required placeholder="เช่น กรดไฮโดรคลอริก">
                    </div>
                    
                    <div class="grid-2">
                        <div class="form-group">
                            <label>สูตรเคมี (Formula)</label>
                            <input type="text" name="formula" id="c_formula" class="form-control" placeholder="เช่น HCl (เว้นว่างได้)">
                        </div>
                        <div class="form-group">
                            <label>สถานะทางกายภาพ (State) *</label>
                            <select name="state" id="c_state" class="form-control" required>
                                <option value="liquid">💧 ของเหลว (Liquid)</option>
                                <option value="solid">🧊 ของแข็ง/ผง (Solid)</option>
                                <option value="gas">☁️ แก๊ส (Gas)</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid-2">
                        <div class="form-group">
                            <label>ความเข้มข้น (Molarity)</label>
                            <input type="number" step="0.01" name="molarity" id="c_molarity" class="form-control" value="0.1">
                        </div>
                        <div class="form-group">
                            <label>ประเภท (Type/Class)</label>
                            <input type="text" name="type" id="c_type" class="form-control" placeholder="เช่น acid, base, indicator">
                        </div>
                    </div>

                    <div class="grid-2">
                        <div class="form-group">
                            <label>สีตั้งต้น (Neutral Color Hex) *</label>
                            <div class="color-picker-wrapper">
                                <input type="color" id="c_color_picker" value="#ffffff" onchange="document.getElementById('c_color').value = this.value">
                                <input type="text" name="color_neutral" id="c_color" value="#ffffff" required onchange="document.getElementById('c_color_picker').value = this.value">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>ระดับความเป็นพิษ (0-100)</label>
                            <input type="number" name="toxicity" id="c_tox" class="form-control" value="0" min="0" max="100">
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeChemModal()">Cancel</button>
                    <button type="submit" class="btn-primary">💾 Save Chemical</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="modalReactionOverlay">
        <div class="modal-box" id="modalReactionBox">
            <form method="POST" action="dev_lab_config.php">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="add_reaction">

                <div class="modal-header" style="background: rgba(192, 132, 252, 0.05); border-bottom-color: rgba(192, 132, 252, 0.2);">
                    <h2 style="color: var(--neon-purple);">CREATE REACTION MATRIX</h2>
                    <button type="button" class="btn-close" onclick="closeReactionModal()">✖</button>
                </div>
                
                <div class="modal-body">
                    <div class="grid-2" style="background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px; border: 1px dashed var(--border-glass);">
                        <div class="form-group" style="margin:0;">
                            <label>สารตั้งต้น 1 (Chem A) *</label>
                            <select name="chem1_id" class="form-control" required>
                                <option value="">-- เลือกสาร 1 --</option>
                                <?php foreach ($chemicals as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label>สารตั้งต้น 2 (Chem B) *</label>
                            <select name="chem2_id" class="form-control" required>
                                <option value="">-- เลือกสาร 2 --</option>
                                <?php foreach ($chemicals as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; 
    } catch (\PDOException $e) {
        error_log('[db_error] ' . $e->getMessage() . ' in ' . __FILE__ . ':' . __LINE__);
        if (!headers_sent()) {
            http_response_code(500);
        }
        $is_dev = defined('APP_ENV') && APP_ENV === 'development' && defined('APP_DEBUG') && APP_DEBUG;
        $msg = $is_dev ? htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') : 'กรุณาลองใหม่อีกครั้ง';
        die('<div style="font-family:sans-serif;text-align:center;padding:3rem">'
          . '<h3 style="color:#ef4444">เกิดข้อผิดพลาดในระบบฐานข้อมูล</h3>'
          . '<p style="color:#6b7280">' . $msg . '</p>'
          . '<a href="index.php" style="color:#3b82f6">← กลับหน้าหลัก</a>'
          . '</div>');
    }

?>

try {
                            </select>
                        </div>
                    </div>

                    <div style="text-align:center; margin: 15px 0; color: var(--text-muted);">⬇️ ทำปฏิกิริยากันได้ผลลัพธ์เป็น ⬇️</div>

                    <div class="form-group">
                        <label>ชื่อผลิตภัณฑ์ที่เกิดขึ้น (Product Name) *</label>
                        <input type="text" name="product_name" class="form-control" required placeholder="เช่น ตะกอนกำมะถัน + SO2">
                    </div>

                    <div class="grid-2">
                        <div class="form-group">
                            <label>สีใหม่ที่เปลี่ยนไป (Result Color) *</label>
                            <div class="color-picker-wrapper">
                                <input type="color" id="r_color_picker" value="#ffffff" onchange="document.getElementById('r_color').value = this.value">
                                <input type="text" name="result_color" id="r_color" value="#ffffff" required onchange="document.getElementById('r_color_picker').value = this.value">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>สถานะรวม (Result State)</label>
                            <select name="result_state" class="form-control">
                                <option value="liquid">Liquid (สารละลาย)</option>
                                <option value="gas">Gas (ระเหยหมด)</option>
                                <option value="solid">Solid (แข็งตัว)</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid-2">
                        <div class="form-group">
                            <label>ตะกอนที่เกิด (ถ้ามี)</label>
                            <input type="text" name="result_precipitate" class="form-control" placeholder="ค่าเริ่มต้น: ไม่มีตะกอน">
                        </div>
                        <div class="form-group">
                            <label>แก๊สที่เกิด (ถ้ามี)</label>
                            <input type="text" name="result_gas" class="form-control" placeholder="ค่าเริ่มต้น: ไม่มีแก๊ส">
                        </div>
                    </div>

                    <div class="grid-2" style="margin-top: 10px;">
                        <div class="form-group">
                            <label>ความร้อนที่คายออกมา (+°C)</label>
                            <input type="number" step="0.1" name="heat_level" class="form-control" value="0">
                        </div>
                        <div class="form-group" style="display:flex; flex-direction:column; justify-content:center;">
                            <label style="color:var(--neon-red); font-weight:bold; display:flex; align-items:center; gap:10px; cursor:pointer;">
                                <input type="checkbox" name="is_explosive" value="1" style="width:20px; height:20px; accent-color:var(--neon-red);">
                                💥 เกิดการระเบิดรุนแรง!
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>โบนัสความพิษเมื่อผสมเสร็จ (Toxicity Bonus)</label>
                        <input type="number" name="toxicity_bonus" class="form-control" value="0">
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeReactionModal()">Cancel</button>
                    <button type="submit" class="btn-primary" style="background: linear-gradient(135deg, #c084fc, #9333ea); box-shadow: 0 4px 15px rgba(192, 132, 252, 0.4);">⚡ Bind Reaction</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Tab System
        function switchTab(tabId, btn) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            btn.classList.add('active');
        }

        // Chemical Modal Logic
        const chemOverlay = document.getElementById('modalChemOverlay');
        const chemBox = document.getElementById('modalChemBox');

        function openChemModal() {
            document.getElementById('chemAction').value = 'add_chem';
            document.getElementById('chemModalTitle').innerText = 'ADD NEW CHEMICAL';
            document.getElementById('chemId').value = '0';
            
            // Clear Form
            document.getElementById('c_name').value = '';
            document.getElementById('c_formula').value = '';
            document.getElementById('c_state').value = 'liquid';
            document.getElementById('c_molarity').value = '0.1';
            document.getElementById('c_type').value = '';
            document.getElementById('c_color').value = '#ffffff';
            document.getElementById('c_color_picker').value = '#ffffff';
            document.getElementById('c_tox').value = '0';

            chemOverlay.style.display = 'flex';
            setTimeout(() => { chemBox.classList.add('show'); }, 10);
        }

        function editChem(data) {
            document.getElementById('chemAction').value = 'edit_chem';
            document.getElementById('chemModalTitle').innerText = 'EDIT CHEMICAL: ' + data.name;
            document.getElementById('chemId').value = data.id;
            
            // Fill Form
            document.getElementById('c_name').value = data.name;
            document.getElementById('c_formula').value = data.formula;
            document.getElementById('c_state').value = data.state;
            document.getElementById('c_molarity').value = data.molarity;
            document.getElementById('c_type').value = data.type;
            
            let hex = data.color_neutral || '#ffffff';
            document.getElementById('c_color').value = hex;
            document.getElementById('c_color_picker').value = hex;
            document.getElementById('c_tox').value = data.toxicity;

            chemOverlay.style.display = 'flex';
            setTimeout(() => { chemBox.classList.add('show'); }, 10);
        }

        function closeChemModal() {
            chemBox.classList.remove('show');
            setTimeout(() => { chemOverlay.style.display = 'none'; }, 300);
        }

        // Reaction Modal Logic
        const reactOverlay = document.getElementById('modalReactionOverlay');
        const reactBox = document.getElementById('modalReactionBox');

        function openReactionModal() {
            reactOverlay.style.display = 'flex';
            setTimeout(() => { reactBox.classList.add('show'); }, 10);
        }

        function closeReactionModal() {
            reactBox.classList.remove('show');
            setTimeout(() => { reactOverlay.style.display = 'none'; }, 300);
        }

        // Close on outside click
        window.onclick = function(event) {
            if (event.target === chemOverlay) closeChemModal();
            if (event.target === reactOverlay) closeReactionModal();
        }
    </script>
</body>
</html>