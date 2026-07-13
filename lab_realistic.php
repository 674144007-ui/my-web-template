<?php
/**
 * lab_realistic.php — ห้องทดลองเคมีเสมือนจริง (Clean Architecture - ระยะที่ 4)
 * ────────────────────────────────────────────────────────────
 * Bug ที่แก้:
 *   - ลบ error_reporting / isset($pdo) / SHOW COLUMNS ทุกจุด
 *   - ลบ hardcode tea001/stu001 จาก $is_admin check
 *   - schema ล็อกแล้ว: chemicals มี formula และ boiling_point เสมอ
 *   - ใช้ role จาก session แทน
 * ────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

requireLogin();

$role     = $_SESSION['role']     ?? '';
$class_id = $_SESSION['class_id'] ?? 0;
$user_id  = $_SESSION['user_id'];
$is_admin = in_array($role, ['developer', 'teacher']);

if (!$is_admin) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(cs.id)
            FROM class_schedules cs
            JOIN subjects s ON cs.subject_id = s.id
            WHERE cs.class_id = ?
              AND (s.subject_name LIKE '%เคมี%' OR s.subject_code LIKE '%ว30221%')
        ");
        $stmt->execute([$class_id]);
        if ($stmt->fetchColumn() == 0) {
            header('Location: dashboard_student.php?error=access_denied_lab');
            exit;
        }
    } catch (PDOException $e) {
        error_log('[lab_realistic] access check: ' . $e->getMessage());
    }
}

// โหลดข้อมูลเควส
$assignment_id    = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;
$quest_id         = isset($_GET['quest_id'])      ? (int)$_GET['quest_id']      : 0;
$assignment_title = $is_admin ? '⚙️ Sandbox Mode (โหมดผู้พัฒนา)' : '🧪 โหมดการทดลองอิสระ (Free Play)';
$game_settings_json = '{}';
$quest_data_json    = 'null';
$all_quests_json    = '[]';
$tutorial_json      = '[]';

// โหลด assignment
if ($assignment_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT title, game_settings FROM assignments WHERE id = ? AND game_type = 'chem_lab'");
        $stmt->execute([$assignment_id]);
        $assign_data = $stmt->fetch();
        if ($assign_data) {
            $assignment_title = 'ภารกิจ: ' . h($assign_data['title']);
            if (!empty($assign_data['game_settings'])) {
                $game_settings_json = $assign_data['game_settings'];
            }
        }
    } catch (PDOException $e) {
        error_log('[lab_realistic] load assignment: ' . $e->getMessage());
    }
}

// โหลด quest เฉพาะ (ถ้ามี quest_id)
if ($quest_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, title, description, quest_type, difficulty,
                   steps_json, hints_json, target_result,
                   intro_text, success_text, xp_reward, bonus_xp
            FROM lab_quests WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$quest_id]);
        $qrow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($qrow) {
            $quest_data_json = json_encode($qrow, JSON_UNESCAPED_UNICODE);
        }
    } catch (PDOException $e) {
        error_log('[lab_realistic] load quest: ' . $e->getMessage());
    }
}

// โหลด quest ทั้งหมด (สำหรับ quest browser)
try {
    $stmt = $pdo->query("
        SELECT id, title, description, quest_type, difficulty,
               xp_reward, intro_text, success_text, steps_json, hints_json, target_result
        FROM lab_quests
        WHERE is_active = 1
        ORDER BY difficulty ASC, id ASC
    ");
    $all_quests_json = json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log('[lab_realistic] load all quests: ' . $e->getMessage());
}

// โหลด tutorial chapters
try {
    $stmt = $pdo->query("
        SELECT chapter_no, title, icon, content_json
        FROM lab_tutorial_chapters
        ORDER BY sort_order ASC, chapter_no ASC
    ");
    $tutorial_json = json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log('[lab_realistic] load tutorial: ' . $e->getMessage());
}

$page_title = $is_admin ? 'Developer Lab - Smart School Plus' : 'Virtual Chemistry Lab';
$csrf       = generate_csrf_token();

// ดึงข้อมูลสารเคมีและปฏิกิริยา
$all_chemicals  = [];
$solids         = [];
$liquids        = [];
$reactions_list = [];

try {
    // JOIN sys_chemical_states — ดึงครบทุก field รวม real_world_example, acid_base_explain ฯลฯ
    $res = $pdo->query("
        SELECT c.id, c.name, c.formula, c.type, c.color_neutral, c.boiling_point,
               c.toxicity, c.ph_value, c.hazard_level, c.description,
               c.real_world_example, c.acid_base_explain, c.texture_type,
               c.viscosity_override, c.dissolve_rate_override,
               c.color_change_acid, c.color_change_base,
               cs.state_key AS state
        FROM chemicals c
        JOIN sys_chemical_states cs ON cs.id = c.state_id
        ORDER BY c.name ASC
    ");
    $seen_ids = [];
    while ($row = $res->fetch()) {
        // dedup: ข้ามถ้าเจอ id ซ้ำ (เช่น id 4 และ 11 เป็น HCl เหมือนกัน)
        if (isset($seen_ids[$row['id']])) continue;
        $seen_ids[$row['id']] = true;

        $row['type']         = !empty($row['type']) ? $row['type'] : 'other';
        $row['boiling_point']= floatval($row['boiling_point'] ?? 100.0);
        $row['ph_value']     = $row['ph_value'] !== null ? floatval($row['ph_value']) : null;
        $row['hazard_level'] = intval($row['hazard_level'] ?? 0);
        $row['toxicity']     = intval($row['toxicity'] ?? 0);

        $all_chemicals[$row['id']] = $row['name'];
        if ($row['state'] === 'solid') {
            $solids[] = $row;
        } else {
            $liquids[] = $row;
        }
    }

    // ส่ง full chemical data (ทุก field) ไปยัง JS เพื่อ Info Panel, Tooltip ฯลฯ
    $chemicals_full = array_merge($solids, $liquids);

    $reactions_list = $pdo->query("SELECT * FROM reactions")->fetchAll();

} catch (PDOException $e) {
    error_log('[lab_realistic] chemicals: ' . $e->getMessage());
    $chemicals_full = [];
}

require_once 'header.php';
?>

<link rel="shortcut icon" href="data:image/x-icon;," type="image/x-icon">

<script>
    window._labRoomTemp = 25.0;  // room temperature — controlled by AC/Heater
    window.LAB_CONFIG = {
        isAdmin:          <?= $is_admin ? 'true' : 'false' ?>,
        assignmentId:     <?= $assignment_id ?>,
        questId:          <?= $quest_id ?>,
        csrfToken:        '<?= $csrf ?>',
        chemDictionary:   <?= json_encode($all_chemicals, JSON_UNESCAPED_UNICODE) ?>,
        reactionsData:    <?= json_encode($reactions_list, JSON_UNESCAPED_UNICODE) ?>,
        chemicalsData:    <?= json_encode($chemicals_full ?? [], JSON_UNESCAPED_UNICODE) ?>,
        questData:        <?= $quest_data_json ?>,
        allQuests:        <?= $all_quests_json ?>,
        tutorialChapters: <?= $tutorial_json ?>
    };
</script>

<script id="labSettingsData" type="application/json">
    <?= $game_settings_json ?>
</script>

<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Orbitron:wght@400;700&family=Itim&family=Prompt:wght@400;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    /* ============================================================
       📍 UI ARCHITECTURE (CSS ENGINE) 
       ============================================================ */
    :root {
        --bg-lab: #020617;
        --panel-bg: #0f172a;
        --border-color: #1e293b;
        --primary: #38bdf8; 
        --primary-glow: rgba(56, 189, 248, 0.4);
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --poison: #84cc16; 
        --text-main: #f8fafc;
        --text-muted: #94a3b8;
    }

    body.admin-theme {
        --primary: #8b5cf6; 
        --primary-glow: rgba(139, 92, 246, 0.4);
        --success: #34d399;
        --dev-color: #ec4899; 
    }

    body { 
        background-color: var(--bg-lab); 
        color: var(--text-main); 
        font-family: 'Prompt', 'Itim', sans-serif; 
        margin: 0 !important; 
        padding: 0 !important; 
        overflow: hidden; 
    }

    input, textarea { 
        user-select: text !important; 
        pointer-events: auto !important; 
    }

    .container { 
        max-width: none !important; 
        width: 100% !important; 
        margin: 0 !important; 
        padding: 0 !important; 
    }
    
    @keyframes screenShake { 
        0%, 100% { transform: translate(0, 0) rotate(0deg); } 
        10%, 30%, 50%, 70%, 90% { transform: translate(-3px, -3px) rotate(-1deg); } 
        20%, 40%, 60%, 80% { transform: translate(3px, 3px) rotate(1deg); } 
    }

    .shake-active { 
        animation: screenShake 0.5s cubic-bezier(0.36, 0.07, 0.19, 0.97) both; 
    }

    @keyframes boilingShake { 
        0%, 100% { transform: translateX(0) rotate(0); } 
        25% { transform: translateX(-2px) rotate(-1deg); } 
        75% { transform: translateX(2px) rotate(1deg); } 
    }

    .beaker-boiling { 
        animation: boilingShake 0.4s infinite; 
        border-color: #f87171 !important; 
    }

    /* ============================================================
       🏷️ TOP HEADER
       ============================================================ */
    .lab-subheader { 
        background: #020617; 
        border-bottom: 2px solid var(--primary); 
        padding: 10px 25px; 
        display: flex; 
        justify-content: space-between; 
        align-items: center; 
        box-shadow: 0 4px 30px var(--primary-glow); 
        z-index: 1000; 
        position: relative; 
        height: 50px; 
        user-select: none; 
        transition: all 0.5s ease;
    }

    .lab-title { 
        display: flex; 
        align-items: center; 
        gap: 15px; 
    }

    .lab-title-icon { 
        font-size: 2rem; 
        filter: drop-shadow(0 0 10px var(--primary)); 
        transition: filter 0.5s; 
    }

    .lab-title-text { 
        font-size: 1.2rem; 
        font-weight: 700; 
        color: var(--primary); 
        line-height: 1.2; 
        text-transform: uppercase; 
        letter-spacing: 1px; 
        transition: color 0.5s;
    }

    .lab-version { 
        font-size: 0.75rem; 
        color: #cbd5e1; 
        font-family: 'Orbitron', sans-serif; 
        letter-spacing: 2px; 
        background: rgba(255, 255, 255, 0.1); 
        padding: 2px 8px; 
        border-radius: 10px; 
        border: 1px solid var(--primary); 
        transition: all 0.5s;
    }

    .header-controls { 
        display: flex; 
        gap: 15px; 
        align-items: center; 
    }
    
    .btn-dev-tools { 
        background: linear-gradient(135deg, var(--dev-color, #ec4899), #be185d); 
        color: white; 
        border: none; 
        padding: 8px 20px; 
        border-radius: 8px; 
        cursor: pointer; 
        font-family: 'Prompt'; 
        font-weight: bold; 
        font-size: 0.9rem; 
        display: flex; 
        align-items: center; 
        gap: 8px; 
        box-shadow: 0 4px 10px rgba(236, 72, 153, 0.4); 
        transition: 0.3s; 
        user-select: none;
    }

    .btn-dev-tools:hover { 
        transform: translateY(-2px); 
        box-shadow: 0 6px 15px rgba(236, 72, 153, 0.6); 
    }

    .btn-periodic { 
        background: linear-gradient(135deg, #0ea5e9, #2563eb); 
        color: white; 
        border: none; 
        padding: 8px 20px; 
        border-radius: 8px; 
        cursor: pointer; 
        font-family: 'Prompt'; 
        font-weight: bold; 
        font-size: 0.9rem; 
        transition: 0.3s; 
        user-select: none;
    }

    .btn-periodic:hover { 
        transform: translateY(-2px); 
        box-shadow: 0 6px 15px rgba(14, 165, 233, 0.4); 
    }
    
    .btn-audio { 
        background: transparent; 
        color: var(--text-muted); 
        border: 1px solid var(--border-color); 
        padding: 8px 15px; 
        border-radius: 8px; 
        cursor: pointer; 
        font-family: 'Prompt'; 
        font-size: 0.9rem; 
        transition: 0.3s; 
        user-select: none;
    }

    .btn-audio.muted { 
        color: var(--danger); 
        border-color: var(--danger); 
    }

    .hp-wrapper { 
        display: flex; 
        align-items: center; 
        gap: 15px; 
        background: rgba(0,0,0,0.5); 
        padding: 6px 20px; 
        border-radius: 30px; 
        border: 1px solid var(--primary); 
        width: 250px; 
        user-select: none; 
        box-shadow: inset 0 0 10px var(--primary-glow); 
        transition: all 0.5s;
    }

    .hp-label { 
        font-weight: 900; 
        font-family: 'Orbitron'; 
        font-size: 1rem; 
        color: var(--danger); 
        letter-spacing: 1px;
    }

    body.admin-theme .hp-label { 
        color: #c4b5fd; 
        font-size: 0.9rem;
    }

    .hp-bar-bg { 
        flex: 1; 
        height: 12px; 
        background: #1e293b; 
        border-radius: 10px; 
        overflow: hidden; 
        border: 1px solid #000; 
    }

    .hp-bar-fill { 
        height: 100%; 
        width: 100%; 
        background: linear-gradient(90deg, #22c55e, #10b981); 
        transition: width 0.3s, background 0.3s; 
    }

    body.admin-theme .hp-bar-fill { 
        background: linear-gradient(90deg, #8b5cf6, #d946ef); 
    }

    .hp-value { 
        font-family: 'Share Tech Mono', monospace; 
        font-size: 1.1rem; 
        color: var(--success); 
        min-width: 45px; 
        text-align: right; 
    }

    body.admin-theme .hp-value { 
        color: #d946ef; 
        font-weight: bold; 
        font-size: 1.5rem; 
        line-height: 0.8; 
    }

    /* ============================================================
       🗄️ MAIN LAYOUT & PANELS
       ============================================================ */
    .lab-main-container { 
        display: flex; 
        height: calc(100vh - 70px); 
        width: 100vw; 
        position: relative; 
        background: var(--bg-lab); 
    }

    /* ── Mobile: stack layout ────────────────────────────────── */
    @media (max-width: 768px) {
        .lab-main-container {
            flex-direction: column;
            height: auto;
            min-height: 100vh;
            overflow-y: auto;
        }
        .panel-side {
            width: 100% !important;
            height: auto !important;
            flex-shrink: 1;
        }
        .panel-content { max-height: 220px; }
        .panel-right .panel-content { max-height: 180px; }
        /* panel-left เป็น accordion บน mobile */
        .panel-left .panel-inner { overflow: hidden; }
        .panel-left.collapsed .panel-content { display: none; }
        .panel-collapse-btn {
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 16px; cursor: pointer;
            background: var(--panel-bg); border-bottom: 1px solid var(--border-color);
            color: var(--primary); font-weight: 600; font-size: 0.9rem;
        }
        .panel-collapse-btn .arrow { transition: transform 0.2s; }
        .panel-left.collapsed .panel-collapse-btn .arrow { transform: rotate(-90deg); }
        /* Beaker ใหญ่ขึ้นบน mobile */
        #beakerSection { min-height: 260px; }
        .chem-item { padding: 10px 12px; }
        /* ปุ่ม action เล็กลง */
        .btn-action-main, .btn-submit-report, .btn-dispose { font-size: 0.85rem; padding: 8px 14px; }
        /* hide ข้อความ version บน mobile */
        .lab-version { display: none; }
        /* HP bar ขนาดเล็กลง */
        .hp-wrapper { transform: scale(0.85); }
    }
    @media (max-width: 480px) {
        .panel-content { max-height: 160px; }
        .lab-subheader { padding: 8px 12px; gap: 8px; }
        .lab-title-icon { font-size: 1.2rem; }
        .header-controls { gap: 6px; }
        .btn-periodic { padding: 5px 10px; font-size: 0.8rem; }
    }

    .panel-side { 
        width: 320px; 
        height: 100%; 
        z-index: 500; 
        flex-shrink: 0; 
        background: var(--panel-bg); 
        display: flex; 
        flex-direction: column; 
        min-height: 0; 
        transition: background 0.5s;
    }

    .panel-left { 
        border-right: 2px solid var(--border-color); 
        box-shadow: 5px 0 20px rgba(0,0,0,0.8); 
    }

    .panel-right { 
        border-left: 2px solid var(--border-color); 
        box-shadow: -5px 0 20px rgba(0,0,0,0.8); 
    }

    .panel-inner { 
        width: 100%; 
        height: 100%; 
        display: flex; 
        flex-direction: column; 
        min-height: 0; 
    }

    .panel-content { 
        padding: 20px; 
        overflow-y: auto; 
        flex: 1; 
        min-height: 0; 
        scrollbar-width: thin; 
        scrollbar-color: var(--primary) transparent; 
        overscroll-behavior: contain; 
    }

    .panel-title { 
        color: var(--primary); 
        font-size: 1.1rem; 
        margin: 0 0 15px 0; 
        padding-bottom: 10px; 
        border-bottom: 1px dashed var(--border-color); 
        display: flex; 
        align-items: center; 
        gap: 8px; 
        user-select: none; 
        transition: color 0.5s;
    }

    /* Inventory */
    .search-box { 
        position: sticky; 
        top: 0; 
        background: var(--panel-bg); 
        padding-bottom: 15px; 
        border-bottom: 1px solid var(--border-color); 
        z-index: 10; 
        margin-top: -5px; 
        padding-top: 5px; 
        transition: background 0.5s;
    }

    .search-box input { 
        width: 100%; 
        padding: 12px 15px; 
        border-radius: 8px; 
        border: 1px solid #334155; 
        background: #020617; 
        color: white; 
        font-family: 'Prompt'; 
        outline: none; 
        transition: all 0.3s;
    }

    .search-box input:focus { 
        border-color: var(--primary); 
        box-shadow: 0 0 10px var(--primary-glow);
    }

    .chem-group-title { 
        color: #94a3b8; 
        font-size: 0.85rem; 
        font-weight: bold; 
        margin: 20px 0 10px 0; 
        text-transform: uppercase; 
        letter-spacing: 1px; 
        user-select: none;
    }

    .chem-list { 
        display: flex; 
        flex-direction: column; 
        gap: 10px; 
    }

    .chem-item { 
        background: #020617; 
        padding: 12px 15px; 
        border-radius: 12px; 
        cursor: pointer; 
        border: 1px solid var(--border-color); 
        display: flex; 
        align-items: center; 
        gap: 15px; 
        transition: 0.2s; 
        user-select: none; 
        touch-action: manipulation; 
        margin-bottom: 10px;
    }

    .chem-item:hover { 
        border-color: var(--primary); 
        transform: translateX(5px); 
        box-shadow: 0 5px 15px rgba(0,0,0,0.5); 
        background: #1e293b;
    }

    .chem-item:active { 
        transform: scale(0.95); 
    }

    /* ═══ Phase 1: ระบบภาชนะสาร (Container Shelf) ═══ */
    .container-shelf {
        position: absolute; bottom: calc(25vh - 20px); left: 24px;
        width: 220px; min-height: 96px; max-height: 200px;
        z-index: 22;
        display: flex; flex-wrap: wrap; align-content: flex-end; align-items: flex-end; gap: 9px;
        padding: 10px 12px; overflow-x: hidden; overflow-y: auto;
        scrollbar-width: thin;
        pointer-events: auto;
        background: linear-gradient(180deg, rgba(15,23,42,0.1) 0%, rgba(15,23,42,0.4) 100%);
        border-radius: 12px;
        border-bottom: 2px solid rgba(56,189,248,0.2);
    }
    .container-shelf:empty { display: none; }
    .container-shelf::-webkit-scrollbar { height: 5px; }
    .container-shelf::-webkit-scrollbar-thumb { background: rgba(56,189,248,0.3); border-radius: 3px; }
    .chem-container {
        position: relative; flex-shrink: 0;
        width: 60px; height: 96px;
        cursor: pointer; transition: transform 0.15s;
        display: flex; flex-direction: column; align-items: center; justify-content: flex-end;
        filter: drop-shadow(0 2px 6px rgba(0,0,0,0.6));
    }
    .chem-container:hover { transform: translateY(-4px); }
    .chem-container svg { display: block; overflow: visible; }
    .cc-lid { transition: transform 0.25s cubic-bezier(0.34,1.56,0.64,1); transform-origin: center bottom; }
    .chem-container.lid-open .cc-lid { transform: translateY(-14px) rotate(-8deg); }
    .chem-container.lid-locked .cc-lid { transform: translateY(-16px) rotate(-12deg); }
    .cc-label {
        position: absolute; bottom: -13px; left: 50%; transform: translateX(-50%);
        font-size: 0.56rem; color: #e2e8f0; white-space: nowrap; font-weight: 500;
        background: rgba(15,23,42,0.92); padding: 2px 6px; border-radius: 4px;
        max-width: 78px; overflow: hidden; text-overflow: ellipsis;
        pointer-events: none; border: 0.5px solid rgba(56,189,248,0.2);
    }
    .chem-container.lid-locked::after {
        content: '🔓'; position: absolute; top: -10px; right: -6px;
        font-size: 0.6rem;
    }
    /* highlight เมื่อถือเครื่องมือแล้ว hover ภาชนะเปิด */
    /* ═══ Phase 5: cursor states + ไฮไลต์ ═══ */
    /* ภาชนะที่ตักได้ (state ตรงกับเครื่องมือ) */
    .chem-container.can-scoop {
        filter: drop-shadow(0 0 7px rgba(56,189,248,0.75));
        animation: ccPulse 1.8s ease-in-out infinite;
    }
    @keyframes ccPulse {
        0%,100% { filter: drop-shadow(0 0 5px rgba(56,189,248,0.5)); }
        50%     { filter: drop-shadow(0 0 11px rgba(56,189,248,0.85)); }
    }
    /* ภาชนะที่ตักไม่ได้ (state ไม่ตรง) → หรี่ลง */
    body.scoop-active .chem-container:not(.can-scoop) { opacity: 0.42; }
    /* cursor ตอนมีสารอยู่ → เอียงนิดหน่อย (เหมือนถือของ) */
    #scoopCursor.loaded { transform: translate(-50%, -70%) rotate(-8deg); }
    #scoopCursor .scoop-load {
        pointer-events: none;
    }
    #scoopCursor .scoop-grain {
        position: absolute; border-radius: 50%;
        box-shadow: inset -1px -1px 2px rgba(0,0,0,0.35), 0 0 2px rgba(255,255,255,0.4);
    }
    /* ปุ่มล้างภาชนะทั้งหมด (มุมขวาบนของ shelf) */
    #clearContainersBtn {
        position: absolute; bottom: calc(25vh + 78px); left: 232px; z-index: 46;
        width: 20px; height: 20px; border-radius: 50%;
        background: rgba(239,68,68,0.85); border: 1px solid rgba(255,255,255,0.2);
        color: #fff; font-size: 0.65rem; line-height: 1;
        display: none; align-items: center; justify-content: center;
        cursor: pointer; transition: .18s;
    }
    #clearContainersBtn.show { display: flex; }
    #clearContainersBtn:hover { background: #ef4444; transform: scale(1.12); }

    /* ═══ ภาชนะที่ลากออกมาวางบนโต๊ะ ═══ */
    .chem-container.cc-free {
        z-index: 25;
        cursor: grab;
    }
    .chem-container.cc-free:hover { transform: translateY(-3px); }
    .chem-container.cc-dragging {
        cursor: grabbing !important;
        transform: scale(1.06) !important;
        filter: drop-shadow(0 8px 16px rgba(0,0,0,0.7)) drop-shadow(0 0 10px rgba(56,189,248,0.35));
        transition: none !important;
        opacity: 0.94;
    }
    /* เงาใต้ภาชนะที่วางบนโต๊ะ */
    .chem-container.cc-free::before {
        content: ''; position: absolute; bottom: 2px; left: 50%;
        transform: translateX(-50%);
        width: 42px; height: 7px; border-radius: 50%;
        background: radial-gradient(ellipse, rgba(0,0,0,0.5) 0%, transparent 72%);
        pointer-events: none; z-index: -1;
    }
    body.scoop-active .chem-container { cursor: none !important; }


    /* ═══ Phase 2: เครื่องมือตัก (Scoop Tools) ═══ */
    .equip-slot.scoop-tool.active {
        background: #0c4a6e !important; border-color: #38bdf8 !important;
        box-shadow: 0 0 12px rgba(56,189,248,0.5);
    }
    /* cursor เป็นเครื่องมือ (ตัวชี้ตามเมาส์) */
    #scoopCursor {
        position: fixed; pointer-events: none; z-index: 9999;
        transform: translate(-50%, -70%);
        filter: drop-shadow(0 2px 4px rgba(0,0,0,0.6));
        display: none; transition: none;
    }
    #scoopCursor .scoop-icon { display: inline-block; }
    #scoopCursor .scoop-icon img { display: block; }
    #scoopCursor.active { display: block; }
    #scoopCursor .scoop-load {
        position: absolute; left: 27%; top: 60%; transform: translate(-50%,-50%);
        width: 40%; height: 32%; display: none;
    }
    #scoopCursor.loaded .scoop-load { display: block; }
    /* ป้ายสถานะ "กำลังถือ" */
    #scoopHUD {
        position: absolute; top: 12px; left: 50%; transform: translateX(-50%);
        z-index: 60;
        background: rgba(15,23,42,0.92); border: 1px solid rgba(56,189,248,0.4);
        border-radius: 20px; padding: 6px 16px;
        font-size: 0.72rem; color: #e2e8f0; white-space: nowrap;
        box-shadow: 0 4px 16px rgba(0,0,0,0.5);
        display: none; align-items: center; gap: 8px;
    }
    #scoopHUD.show { display: flex; }
    #scoopHUD .hud-dot { width: 12px; height: 12px; border-radius: 3px; border: 0.5px solid rgba(255,255,255,0.2); }
    #scoopHUD .hud-hint { color: #64748b; font-size: 0.66rem; }

    /* ═══ Phase 4: สารหกบนโต๊ะ + hint เทลงบีกเกอร์ ═══ */
    .desk-spill { will-change: transform, opacity; }
    /* เมื่อถือสาร + hover เหนือบีกเกอร์ → บีกเกอร์เรืองแสง */
    body.scoop-loaded .main-beaker { transition: box-shadow .2s; }
    body.scoop-loaded .main-beaker:hover,
    .main-beaker.pour-ready {
        box-shadow: 0 0 0 2px rgba(56,189,248,0.6), 0 0 24px rgba(56,189,248,0.45) !important;
    }
    /* body มีเครื่องมือ = ซ่อน cursor ปกติ */
    body.scoop-active, body.scoop-active * { cursor: none !important; }
    body.scoop-active .chem-container { cursor: none !important; }

    /* ═══ Phase 3: หน้าต่างเลือกปริมาณตักสาร ═══ */
    .scoop-box { background: #0f172a; width: 360px; border-radius: 20px; border: 2px solid #38bdf8;
        box-shadow: 0 20px 50px rgba(56,189,248,0.25); transform: translateY(40px); opacity: 0;
        transition: 0.28s; overflow: hidden; }
    .scoop-box.show { transform: translateY(0); opacity: 1; }
    .sa-header { padding: 16px 20px 12px; border-bottom: 1px solid #1e293b;
        display: flex; align-items: center; gap: 12px; }
    .sa-tool-icon { font-size: 1.8rem; }
    .sa-title { flex: 1; text-align: left; }
    .sa-chem { font-size: 1rem; color: #f1f5f9; font-weight: 600; }
    .sa-tool-name { font-size: 0.7rem; color: #64748b; margin-top: 1px; }
    .sa-body { padding: 18px 20px; }
    .sa-amount-display { font-family: 'Share Tech Mono', monospace; font-size: 2.4rem;
        color: #38bdf8; text-align: center; margin-bottom: 4px; letter-spacing: 1px; }
    .sa-unit { font-size: 1rem; color: #64748b; }
    .sa-cap { font-size: 0.66rem; color: #475569; text-align: center; margin-bottom: 14px; }
    .sa-slider { width: 100%; height: 6px; border-radius: 3px; background: #1e293b;
        outline: none; -webkit-appearance: none; margin-bottom: 14px; cursor: pointer; }
    .sa-slider::-webkit-slider-thumb { -webkit-appearance: none; width: 20px; height: 20px;
        border-radius: 50%; background: #38bdf8; cursor: pointer; box-shadow: 0 0 8px rgba(56,189,248,0.6); }
    .sa-slider::-moz-range-thumb { width: 20px; height: 20px; border-radius: 50%; background: #38bdf8;
        cursor: pointer; border: none; }
    .sa-quick { display: flex; gap: 6px; margin-bottom: 16px; }
    .sa-quick button { flex: 1; background: #1e293b; border: 1px solid #334155; color: #94a3b8;
        padding: 7px 0; border-radius: 8px; cursor: pointer; font-size: 0.75rem;
        font-family: 'Prompt'; transition: 0.15s; }
    .sa-quick button:hover { background: #334155; color: #e2e8f0; border-color: #38bdf8; }
    .sa-preview { display: flex; align-items: center; justify-content: center; gap: 8px;
        padding: 10px; background: #020617; border-radius: 10px; margin-bottom: 14px; }
    .sa-preview-dot { width: 26px; height: 26px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.15); }
    .sa-preview-txt { font-size: 0.72rem; color: #64748b; }
    .sa-actions { display: flex; gap: 8px; }
    .sa-actions button { flex: 1; padding: 11px; border-radius: 10px; cursor: pointer;
        font-family: 'Prompt'; font-weight: 600; font-size: 0.85rem; border: none; transition: 0.2s; }
    .sa-cancel { background: #1e293b; color: #94a3b8; }
    .sa-cancel:hover { background: #334155; }
    .sa-confirm { background: #38bdf8; color: #0f172a; }
    .sa-confirm:hover { background: #7dd3fc; }

    .chem-item.dragging { 
        opacity: 0.5; 
        border: 2px dashed var(--primary); 
    }

    .chem-color-indicator { 
        width: 20px; 
        height: 20px; 
        border-radius: 50%; 
        border: 2px solid rgba(255,255,255,0.5); 
        flex-shrink: 0; 
        pointer-events: none;
    }

    /* ── Chem Icon System ───────────────────────────────────── */
    .chem-icon {
        width: 34px; height: 34px;
        flex-shrink: 0;
        border-radius: 6px;
        position: relative;
        pointer-events: none;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.2rem;
    }

    /* SOLID icons แยกตาม type */
    .chem-icon--element::after { content: '' !important; }
    .chem-icon--element { border-radius: 6px; }
    .chem-icon--solid::after { content: '🪨'; }
    .chem-icon--solid[data-type="metal"]::after    { content: '🔩'; }
    .chem-icon--solid[data-type="salt"]::after     { content: '🧂'; }
    .chem-icon--solid[data-type="organic"]::after  { content: '🍬'; }
    .chem-icon--solid[data-type="catalyst"]::after { content: '⚫'; font-size:1rem; }
    .chem-icon--solid[data-type="cold"]::after     { content: '🧊'; }
    .chem-icon--solid[data-type="base"]::after     { content: '🫧'; }

    /* LIQUID icons แยกตาม type */
    .chem-icon--liquid {
        background: color-mix(in srgb, var(--chem-color, #38bdf8) 30%, transparent);
        border: 2px solid color-mix(in srgb, var(--chem-color, #38bdf8) 70%, transparent);
        border-radius: 0 0 10px 10px;
        border-top: none;
    }
    .chem-icon--liquid::after { content: '💧'; font-size: 1rem; }
    .chem-icon--liquid[data-type="acid"]::after      { content: '🧪'; }
    .chem-icon--liquid[data-type="base"]::after      { content: '🫧'; }
    .chem-icon--liquid[data-type="indicator"]::after { content: '🌈'; }
    .chem-icon--liquid[data-type="organic"]::after   { content: '🫙'; }
    .chem-icon--liquid[data-type="oxidizer"]::after  { content: '⚗️'; font-size:.9rem; }
    .chem-icon--liquid[data-type="polymer"]::after   { content: '🧴'; }
    .chem-icon--liquid[data-type="biological"]::after{ content: '🧫'; }
    .chem-icon--liquid[data-type="buffer"]::after    { content: '🩸'; }
    .chem-icon--liquid[data-type="dye"]::after       { content: '🎨'; }
    .chem-icon--liquid[data-type="solvent"]::after,
    .chem-icon--liquid[data-type="water"]::after     { content: '💧'; }
    .chem-icon--liquid[data-type="neutral"]::after   { content: '🥛'; }
    .chem-icon--liquid[data-type="complex"]::after   { content: '🔮'; }

    /* Hazard badge */
    .hazard-badge { font-size: 0.7rem; vertical-align: middle; }
    .chem-item.hazard-high { border-color: rgba(239,68,68,0.4); }
    .chem-item.hazard-high:hover { border-color: var(--danger); }
    .chem-item.hazard-low  { border-color: rgba(245,158,11,0.3); }

    .chem-info { 
        flex: 1; 
        overflow: hidden; 
        pointer-events: none;
    }

    .chem-name { 
        font-size: 0.95rem; 
        font-weight: bold; 
        color: var(--text-main); 
        white-space: nowrap; 
        overflow: hidden; 
        text-overflow: ellipsis; 
    }

    .chem-formula { 
        font-family: 'Share Tech Mono', monospace; 
        font-size: 0.8rem; 
        color: var(--primary); 
        transition: color 0.5s; 
    }

    .drag-clone-mobile { 
        position: fixed; 
        z-index: 9999; 
        pointer-events: none; 
        background: #0f172a; 
        padding: 10px 20px; 
        border-radius: 30px; 
        border: 2px solid var(--primary); 
        box-shadow: 0 10px 25px var(--primary-glow); 
        display: flex; 
        align-items: center; 
        gap: 10px; 
        font-weight: bold; 
        color: white; 
        transform: translate(-50%, -150%); 
        opacity: 0.9; 
        user-select: none;
    }

    /* ═══ ระบบแท็บแผงควบคุม ═══ */
    .ctrl-tabs { display: flex; gap: 4px; margin-bottom: 14px;
        background: #0f172a; padding: 4px; border-radius: 10px; }
    .ctrl-tab { flex: 1; padding: 8px 4px; border: none; border-radius: 7px;
        background: transparent; color: #64748b; font-family: 'Prompt'; font-weight: 600;
        font-size: 0.72rem; cursor: pointer; transition: 0.18s; white-space: nowrap; }
    .ctrl-tab:hover { color: #94a3b8; background: rgba(56,189,248,0.08); }
    .ctrl-tab.active { background: #1e293b; color: #38bdf8; box-shadow: 0 1px 4px rgba(0,0,0,0.3); }
    .ctrl-tab-panel { animation: tabFade 0.2s ease; }
    @keyframes tabFade { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
    /* panel ที่ย้ายเข้าแท็บ — ยกเลิก absolute */
    #tabData .live-data-hud, #tabTemp .sensor-panel {
        position: static !important; width: auto !important; margin: 0 !important;
        background: transparent !important; box-shadow: none !important; border: none !important;
        backdrop-filter: none !important; padding: 0 !important;
    }

    /* ═══ ปุ่มเปิด/ปิด 3 แผง (คลังสาร / ควบคุม / เครื่องมือ) ═══ */
    .panel-toggle-btn {
        position: fixed; z-index: 600;
        background: rgba(15,23,42,0.95); border: 1px solid rgba(56,189,248,0.5);
        color: #38bdf8; cursor: pointer; font-size: 1rem;
        display: flex; align-items: center; justify-content: center;
        transition: 0.25s; box-shadow: 0 3px 10px rgba(0,0,0,0.5);
    }
    .panel-toggle-btn:hover { background: rgba(56,189,248,0.25); }
    /* ปุ่มคลังสาร (ซ้าย) — ติดขอบ panel เลื่อนตาม */
    #toggleLeftBtn { top: 50%; left: var(--panel-w, 320px); transform: translateY(-50%);
        width: 24px; height: 64px; border-radius: 0 10px 10px 0; border-left: none;
        transition: left 0.32s cubic-bezier(.4,0,.2,1), background 0.2s; }
    #panelLeft.panel-hidden { margin-left: calc(-1 * var(--panel-w, 320px)); }
    body.left-hidden #toggleLeftBtn { left: 0; }
    /* ปุ่มแผงควบคุม (ขวา) — ติดขอบ panel เลื่อนตาม */
    #toggleRightBtn { top: 50%; right: var(--panel-w, 320px); transform: translateY(-50%);
        width: 24px; height: 64px; border-radius: 10px 0 0 10px; border-right: none;
        transition: right 0.32s cubic-bezier(.4,0,.2,1), background 0.2s; }
    #panelRight.panel-hidden { margin-right: calc(-1 * var(--panel-w, 320px)); }
    body.right-hidden #toggleRightBtn { right: 0; }
    /* ปุ่มแถบเครื่องมือ (ล่าง) — ลอยเหนือแถบ */
    #toggleBottomBtn { bottom: 92px; left: 50%; transform: translateX(-50%);
        width: 60px; height: 22px; border-radius: 10px 10px 0 0;
        transition: bottom 0.32s cubic-bezier(.4,0,.2,1), background 0.2s; }
    body.tray-hidden-state #toggleBottomBtn { bottom: 0; }
    #equipTray.tray-hidden { transform: translateY(100%); }

    .panel-side { transition: margin 0.32s cubic-bezier(.4,0,.2,1); }
    #equipTray { transition: transform 0.32s cubic-bezier(.4,0,.2,1); }

    /* Controls */    .btn-control { 
        background: #1e293b; 
        color: white; 
        border: 1px solid #334155; 
        padding: 15px; 
        border-radius: 12px; 
        font-weight: bold; 
        cursor: pointer; 
        width: 100%; 
        font-family: 'Prompt'; 
        transition: 0.3s; 
        margin-bottom:10px;
    }

    .btn-control:hover { 
        border-color: var(--primary); 
    }

    .btn-control.active { 
        background: var(--danger); 
        border-color: var(--danger); 
        box-shadow: 0 0 15px rgba(239, 68, 68, 0.5); 
    }

    .btn-ph-measure { 
        background: linear-gradient(135deg, #f59e0b, #d97706); 
        color: #000; 
        border: none; 
        padding: 15px; 
        border-radius: 12px; 
        font-weight: bold; 
        cursor: pointer; 
        width: 100%; 
        font-family: 'Prompt'; 
        margin-bottom: 15px; 
        transition: 0.3s;
    }
    
    .safety-box { 
        background: rgba(0,0,0,0.3); 
        border: 1px dashed #475569; 
        padding: 15px; 
        border-radius: 10px; 
        margin: 15px 0; 
        transition: 0.3s; 
        user-select: none;
    }

    .safety-box.secured { 
        background: rgba(16, 185, 129, 0.1); 
        border-color: var(--success); 
    }

    .safety-label { 
        display: flex; 
        align-items: center; 
        gap: 10px; 
        color: #cbd5e1; 
        cursor: pointer; 
        font-size: 0.95rem; 
        margin-bottom: 10px; 
        transition: 0.3s;
    }

    body.admin-theme .safety-box.secured .safety-label { 
        color: var(--primary); 
    }

    .safety-label:last-child { 
        margin-bottom: 0; 
    }

    .safety-label input { 
        width: 18px; 
        height: 18px; 
        cursor: pointer; 
        accent-color: var(--primary); 
        transition: 0.3s;
    }

    .log-zone { 
        height: 180px; 
        background: #020617; 
        border: 1px solid var(--border-color); 
        border-radius: 10px; 
        padding: 15px; 
        overflow-y: auto; 
        font-family: 'Share Tech Mono', monospace; 
        font-size: 0.8rem; 
        color: #94a3b8; 
        margin-bottom: 15px; 
        overscroll-behavior: contain;
    }

    .log-entry { 
        margin-bottom: 8px; 
        border-bottom: 1px solid #1e293b; 
        padding-bottom: 5px; 
        line-height: 1.4;
    }

    .log-time { 
        color: var(--primary); 
        margin-right: 8px; 
        transition: color 0.5s;
    }

    .btn-action-main { 
        background: linear-gradient(135deg, var(--primary), #6d28d9); 
        color: white; 
        border: none; 
        padding: 15px; 
        border-radius: 12px; 
        font-weight: bold; 
        width: 100%; 
        font-size: 1.1rem; 
        font-family: 'Prompt'; 
        transition: 0.3s; 
        margin-bottom: 10px; 
        cursor: pointer; 
        box-shadow: 0 5px 15px var(--primary-glow);
    }

    .btn-action-main:hover:not(:disabled) { 
        transform: translateY(-2px); 
        box-shadow: 0 8px 20px var(--primary-glow); 
    }

    .btn-action-main:disabled { 
        background: #475569; 
        color: #94a3b8; 
        box-shadow: none; 
        cursor: not-allowed; 
    }

    .btn-submit-report { 
        background: linear-gradient(135deg, var(--success), #059669); 
        color: white; 
        border: none; 
        padding: 15px; 
        border-radius: 12px; 
        font-weight: bold; 
        width: 100%; 
        font-size: 1.1rem; 
        font-family: 'Prompt'; 
        transition: 0.3s; 
        margin-bottom: 10px; 
        cursor: pointer; 
        box-shadow: 0 5px 15px rgba(16,185,129,0.4); 
        display: none; 
        animation: pulseBtn 2s infinite;
    }

    .btn-submit-report:hover { 
        transform: translateY(-2px); 
        box-shadow: 0 8px 20px rgba(16,185,129,0.6); 
        animation:none;
    }

    .btn-dispose { 
        background: transparent; 
        color: var(--danger); 
        border: 1px solid var(--danger); 
        padding: 12px; 
        border-radius: 10px; 
        font-weight: bold; 
        width: 100%; 
        font-family: 'Prompt'; 
        transition: 0.3s; 
        cursor: pointer; 
        user-select: none;
    }

    .btn-dispose:hover { 
        background: rgba(239, 68, 68, 0.1); 
    }

    body.admin-theme .btn-dispose:hover { 
        background: var(--danger); 
        color: white; 
        box-shadow: 0 5px 15px rgba(239,68,68,0.4); 
    }

    /* ============================================================
       🔬 WORKBENCH & PHYSICS UI
       ============================================================ */
    .workbench-wrapper { 
        flex: 1; 
        height: 100%; 
        position: relative; 
        background: #0a0e1a url('images/bg.png') center center / contain no-repeat;
        display: flex; 
        justify-content: center; 
        align-items: flex-end; 
        overflow: hidden; 
        user-select: none;
    }
    /* overlay มืดบางๆ ให้ panel/บีกเกอร์เด่น */
    .workbench-wrapper::before {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(180deg, rgba(2,6,23,0.20) 0%, rgba(2,6,23,0.05) 45%, rgba(2,6,23,0.30) 100%);
        pointer-events: none;
        z-index: 0;
    }

    .equation-board {
        position: absolute;
        top: 80px;
        left: 50%;
        transform: translateX(-50%);
        background: rgba(15, 23, 42, 0.95);
        border: 2px solid var(--primary);
        border-radius: 15px;
        padding: 20px 40px;
        box-shadow: 0 15px 40px rgba(0,0,0,0.8), inset 0 0 20px var(--primary-glow);
        z-index: 600;
        display: flex;
        flex-direction: column;
        align-items: center;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.5s ease-in-out, top 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    .equation-board.show { opacity: 1; top: 120px; }
    .eq-title { font-size: 0.85rem; color: var(--primary); letter-spacing: 3px; text-transform: uppercase; margin-bottom: 8px; font-weight: bold; }
    .eq-text { font-family: 'Share Tech Mono', monospace; font-size: 2rem; color: #fff; font-weight: bold; letter-spacing: 2px; text-shadow: 0 0 10px rgba(255,255,255,0.5); }
    .eq-desc { font-size: 1rem; color: var(--success); margin-top: 8px; }

    .ambient-lighting { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 1; background: radial-gradient(circle at 50% 80%, rgba(239, 68, 68, 0.15) 0%, transparent 60%); opacity: 0; transition: opacity 0.5s; }
    .heating-active .ambient-lighting { opacity: 1; }
    .desk-surface { position: absolute; bottom: 0; left: 0; width: 100%; height: 26vh; min-height: 185px; background: linear-gradient(to bottom, transparent 0%, rgba(10,14,26,0.4) 70%, rgba(10,14,26,0.7) 100%); z-index: 1; transition: box-shadow 0.5s; pointer-events: none; }
    .heating-active .desk-surface { box-shadow: inset 0 60px 100px -20px rgba(239, 68, 68, 0.15); border-top-color: #ef4444; }
    .desk-anchor { position: absolute; bottom: calc(25vh - 30px); left: 50%; width: 200px; height: 240px; transform: translateX(-50%); z-index: 10; }
    
    .main-beaker { position: absolute; bottom: 40px; left: 0; width: 100%; height: 100%; border: 4px solid rgba(255,255,255,0.95); border-top: none; border-radius: 0 0 30px 30px; background: linear-gradient(180deg, rgba(20,30,48,0.35) 0%, rgba(15,23,42,0.55) 100%); box-shadow: inset 0 -15px 40px rgba(0,0,0,0.5), 0 0 0 2px rgba(0,212,255,0.25), 0 8px 40px rgba(0,0,0,0.7), 0 0 60px rgba(0,180,255,0.15); backdrop-filter: blur(2px); z-index: 20; display: flex; align-items: flex-end; overflow: hidden; pointer-events: auto; transition: border-color 0.3s, transform 0.3s, box-shadow 0.3s;}
    .beaker-dropzone { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 100; pointer-events: auto; }
    .main-beaker.drag-over { border-color: var(--primary); background: rgba(var(--primary-glow), 0.15); transform: scale(1.05); box-shadow: 0 0 30px var(--primary-glow); }
    .heating-active .main-beaker { box-shadow: inset 0 -15px 40px rgba(0,0,0,0.5), 0 15px 40px rgba(249, 115, 22, 0.4); border-bottom-color: rgba(251, 146, 60, 0.8); }

    .liquid-canvas { position: absolute; bottom: 0; left: 0; width: 100%; height: 100%; z-index: 2; pointer-events: none; border-radius: 0 0 25px 25px;}
    .visual-thermometer { position: absolute; bottom: 40px; left: -50px; width: 18px; height: 220px; background: rgba(255,255,255,0.1); border: 2px solid rgba(255,255,255,0.5); border-radius: 10px; z-index: 15; display: flex; flex-direction: column; justify-content: flex-end; padding: 2px; box-shadow: 0 10px 20px rgba(0,0,0,0.5); pointer-events: none;}
    .thermo-mercury { width: 100%; height: 10%; background: linear-gradient(to top, var(--danger), #f87171); border-radius: 8px; transition: height 0.1s linear; }
    .thermo-bulb { position: absolute; bottom: -15px; left: -5px; width: 28px; height: 28px; background: var(--danger); border-radius: 50%; border: 2px solid rgba(255,255,255,0.5); box-shadow: 0 0 10px rgba(239,68,68,0.5); z-index: 2; }
    
    .stirring-rod { position: absolute; top: -120px; left: 45%; width: 10px; height: 350px; background: linear-gradient(to right, rgba(255,255,255,0.9), rgba(255,255,255,0.4)); border-radius: 10px; z-index: 30; display: none; transform-origin: top center; box-shadow: 2px 0 10px rgba(0,0,0,0.3); pointer-events: none;}
    .stirring-anim { display: block; animation: stirAction 0.6s infinite linear; }
    @keyframes stirAction { 0% { transform: rotate(-12deg) translateX(-25px); } 50% { transform: rotate(12deg) translateX(25px); } 100% { transform: rotate(-12deg) translateX(-25px); } }

    .digital-scale { display: none; position: absolute; bottom: -20px; left: -280px; width: 220px; height: 130px; background: #334155; border-radius: 12px; border: 3px solid #475569; box-shadow: 0 15px 25px rgba(0,0,0,0.8); flex-direction: column; align-items: center; justify-content: center; z-index: 15; cursor: pointer; transition: 0.2s;}
    .digital-scale:active { transform: scale(0.98); }
    .scale-plate { width: 160px; height: 15px; background: #94a3b8; border-radius: 50%; border: 2px solid #cbd5e1; position: absolute; top: -10px; z-index: 2; box-shadow: 0 4px 6px rgba(0,0,0,0.3); display: flex; justify-content: center; align-items: flex-end;}
    .scale-display { background: #020617; color: var(--primary); font-family: 'Share Tech Mono', monospace; font-size: 2rem; padding: 5px 15px; border-radius: 8px; width: 85%; text-align: right; box-shadow: inset 0 0 15px #000; border: 1px solid #1e293b; box-sizing: border-box; transition: color 0.2s; font-weight: bold; margin-top: 15px;}
    .scale-powder { width: 0px; height: 0px; background: transparent; border-radius: 50% 50% 10% 10%; transition: 0.3s; margin-bottom: 2px;}

    .heater-base { position: absolute; bottom: 0px; left: -30px; width: 260px; height: 40px; background: #1e293b; border-radius: 8px; border: 2px solid #334155; box-shadow: 0 12px 20px rgba(0,0,0,0.8); z-index: 10; transition: 0.3s; pointer-events: none;}
    .heating-active .heater-base { border-color: #ef4444; }
    .flame-container { position: absolute; bottom: 40px; left: 60px; width: 80px; height: 80px; display: none; justify-content: center; align-items: flex-end; z-index: 12; pointer-events: none;}
    .flame { position: absolute; bottom: 0; border-radius: 50% 50% 20% 20%; filter: blur(2px); }
    .flame-outer {
        width: 50px; height: 55px; left: 15px;
        background: radial-gradient(circle at 50% 80%, #ef4444 0%, #f97316 50%, transparent 100%);
        animation: flicker 0.12s infinite alternate;
        opacity: 0.85;
        box-shadow: 0 -10px 30px rgba(239,68,68,0.5);
    }
    .flame-mid {
        width: 34px; height: 42px; left: 23px;
        background: radial-gradient(circle at 50% 80%, #fbbf24 0%, #f97316 60%, transparent 100%);
        animation: flicker2 0.1s infinite alternate;
        opacity: 0.9;
    }
    .flame-inner {
        width: 18px; height: 28px; left: 31px;
        background: radial-gradient(circle at 50% 70%, #fef9c3 0%, #fbbf24 70%, transparent 100%);
        animation: flicker 0.08s infinite alternate-reverse;
        opacity: 1; filter: blur(1px);
    }
    
    .btn-repair { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: linear-gradient(135deg, #10b981, #059669); color: white; border: 2px solid white; padding: 15px 30px; border-radius: 30px; font-size: 1.5rem; font-family: 'Prompt'; font-weight: bold; cursor: pointer; box-shadow: 0 10px 30px rgba(16, 185, 129, 0.5); z-index: 500; display: none; animation: pulseBtn 2s infinite;}
    .btn-repair:hover { transform: translate(-50%, -52%); box-shadow: 0 15px 40px rgba(16, 185, 129, 0.8); animation: none;}

    .desk-burn { position: absolute; background: radial-gradient(circle, #18181b 0%, transparent 70%); border-radius: 50%; pointer-events: none; opacity: 0.85; z-index: 5; }

    /* Equipment Tray */
    #equipTray::-webkit-scrollbar { height: 3px; }
    #equipTray::-webkit-scrollbar-track { background: transparent; }
    #equipTray::-webkit-scrollbar-thumb { background: #334155; border-radius: 2px; }
    .equip-slot { cursor:pointer; }
    .equip-slot:hover { border-color: #38bdf8!important; background: #1e3a5f!important; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(56,189,248,0.2); }
    .equip-slot:active { transform: scale(0.95)!important; }
    .equip-slot.active-equip { border-color: #f59e0b!important; background: rgba(245,158,11,0.15)!important; box-shadow: 0 0 14px rgba(245,158,11,0.35); }
    .equip-slot.fume-active { border-color: #22c55e!important; background: rgba(34,197,94,0.12)!important; }

    /* Alcohol Lamp flame animations */
    @keyframes alcFlicker  { 0%{transform:scaleX(1) scaleY(1) rotate(-2deg)} 100%{transform:scaleX(0.9) scaleY(1.08) rotate(2deg)} }
    @keyframes alcFlicker2 { 0%{transform:scaleX(1.05) scaleY(0.95)} 100%{transform:scaleX(0.92) scaleY(1.06)} }

    /* Flame color overlay เมื่อ flame test */
    @keyframes flameColorPulse { 0%,100%{opacity:.7} 50%{opacity:1} }
    #flameColorOverlay {
        position:fixed;inset:0;z-index:8500;pointer-events:none;display:none;
        animation:flameColorPulse .4s ease-in-out infinite;
    }
    /* Fume hood overlay */
    #fumeHoodOverlay {
        position:fixed;inset:0;z-index:8100;pointer-events:none;display:none;
        background:rgba(34,197,94,0.06);border:3px solid rgba(34,197,94,0.3);
    }
    /* Body margin เพื่อไม่ให้ tray บังเนื้อหา */
    body { padding-bottom: 62px; }
    .toxic-cloud { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: radial-gradient(circle at center, transparent 20%, rgba(132, 204, 22, 0) 100%); z-index: 8000; pointer-events: none; transition: background 1s ease, opacity 1s ease; opacity: 0; }
    .toxic-cloud.toxic-active { background: radial-gradient(circle at center, rgba(132, 204, 22, 0.3) 0%, rgba(63, 98, 18, 0.7) 100%); animation: pulseToxic 3s infinite alternate; }
    @keyframes pulseToxic { 0% { opacity: 0.7; } 100% { opacity: 1; } }
    @keyframes toastIn { from { opacity:0; transform:translateX(-50%) translateY(-10px); } to { opacity:1; transform:translateX(-50%) translateY(0); } }

    /* ── Flame animations (flicker ที่ขาดหายไป) ───────────── */
    @keyframes flicker {
        0%   { transform: scaleX(1)   scaleY(1)   rotate(-2deg); opacity:0.9; }
        25%  { transform: scaleX(0.95) scaleY(1.05) rotate(1deg);  opacity:1; }
        50%  { transform: scaleX(1.05) scaleY(0.95) rotate(-1deg); opacity:0.85; }
        75%  { transform: scaleX(0.97) scaleY(1.03) rotate(2deg);  opacity:0.95; }
        100% { transform: scaleX(1)   scaleY(1)   rotate(-2deg); opacity:0.9; }
    }
    @keyframes flicker2 {
        0%,100% { transform: scaleX(0.8) scaleY(0.9) translateY(3px); opacity:0.7; }
        50%     { transform: scaleX(1.1) scaleY(1.1) translateY(-2px); opacity:0.9; }
    }

    /* ── Stir rod glow effect เมื่อกวน ─────────────────────── */
    .stirring-anim {
        display: block;
        animation: stirAction 0.6s infinite linear;
        filter: drop-shadow(0 0 6px rgba(56,189,248,0.8));
    }

    /* ── Dissolve pulse: solid ละลายค่อยๆ fade ──────────────── */
    @keyframes dissolvePulse {
        0%,100% { opacity: 1; }
        50%     { opacity: 0.5; filter: blur(1px); }
    }

    /* ── Chem item hover: icon jiggle ──────────────────────── */
    .chem-item:hover .chem-icon { animation: iconJiggle 0.3s ease; }
    @keyframes iconJiggle {
        0%,100% { transform: rotate(0deg); }
        25%     { transform: rotate(-8deg) scale(1.1); }
        75%     { transform: rotate(8deg)  scale(1.1); }
    }

    /* ── Pour stream CSS (สำหรับ non-canvas pour indicator) ─── */
    .pour-indicator {
        position: absolute;
        top: 0; left: 50%;
        width: 4px;
        transform: translateX(-50%);
        background: linear-gradient(to bottom, transparent, var(--pour-color, rgba(56,189,248,0.8)));
        border-radius: 0 0 4px 4px;
        z-index: 25;
        pointer-events: none;
        animation: pourDrop 0.5s ease-in forwards;
    }
    @keyframes pourDrop {
        from { height: 0; opacity: 1; }
        to   { height: 60%; opacity: 0; }
    }

    /* ── Toxic cloud upgrade: แยกสีตาม gas ─────────────────── */
    .toxic-cloud.gas-so2   { --toxic-tint: rgba(180,160,0,0.4); }
    .toxic-cloud.gas-cl2   { --toxic-tint: rgba(80,160,80,0.4); }
    .toxic-cloud.gas-nh3   { --toxic-tint: rgba(100,200,100,0.3); }
    .toxic-cloud.gas-hcl   { --toxic-tint: rgba(200,200,100,0.3); }
    .toxic-cloud.toxic-active {
        background: radial-gradient(circle at center, var(--toxic-tint, rgba(132,204,22,0.3)) 0%, rgba(20,40,0,0.6) 100%);
    }

    /* ============================================================
       🚨 ROLE-BASED HUDs
       ============================================================ */
    .quest-hud, .live-data-hud { position: absolute; top: 25px; left: 25px; width: 300px; background: rgba(15, 23, 42, 0.9); border-radius: 12px; padding: 15px; z-index: 300; backdrop-filter: blur(8px); transition: 0.3s;}
    .quest-hud { border: 2px solid var(--warning); box-shadow: 0 10px 30px rgba(0,0,0,0.6); }
    .quest-hud.all-done { border-color: var(--success); box-shadow: 0 0 20px rgba(16, 185, 129, 0.5); }
    .qh-header { border-bottom: 1px dashed var(--warning); padding-bottom: 10px; margin-bottom: 12px; transition: 0.3s;}
    .quest-hud.all-done .qh-header { border-bottom-color: var(--success); }
    .qh-title { color: #fef08a; font-weight: bold; font-size: 1rem; margin: 0; line-height: 1.4; transition: 0.3s;}
    .quest-hud.all-done .qh-title { color: var(--success); }
    .qh-checklist { list-style: none; padding: 0; margin: 0; }
    .qh-item { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 8px; font-size: 0.9rem; color: #cbd5e1; transition: 0.3s; }
    .qh-item.done { color: var(--success); font-weight: bold; animation: popDone 0.5s ease-out; }
    @keyframes popDone { 0% { transform: scale(1); } 50% { transform: scale(1.1); color: white;} 100% { transform: scale(1); } }

    .live-data-hud { border: 2px solid var(--primary); box-shadow: 0 10px 30px var(--primary-glow); }
    .ld-header { border-bottom: 1px dashed var(--primary); padding-bottom: 10px; margin-bottom: 12px; }
    .ld-title { color: #c4b5fd; font-weight: bold; font-size: 1rem; margin: 0; line-height: 1.4; display: flex; align-items: center; gap: 8px;}
    .ld-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.9rem; color: #cbd5e1; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 4px;}
    .ld-label { font-weight: bold; color: #94a3b8; }
    .ld-value { color: var(--primary); font-family: 'Share Tech Mono', monospace; font-size: 1rem; font-weight: bold;}

    .sensor-panel { position: absolute; top: 25px; right: 25px; background: rgba(15, 23, 42, 0.9); border: 1px solid var(--border-color); border-radius: 15px; padding: 15px 25px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.5); backdrop-filter: blur(5px); z-index: 300;}
    
    .explosion-overlay { position: fixed; top:0; left:0; width:100vw; height:100vh; background: transparent; z-index: 9999; pointer-events: none; opacity: 0; transition: opacity 0.1s;}
    .explosion-overlay.flash-red { background: white; opacity: 1; animation: flashFadeRed 2s forwards; }
    .explosion-overlay.flash-green { background: #86efac; opacity: 1; animation: flashFadeGreen 2s forwards; }
    @keyframes flashFadeRed { 0% {background: white; opacity: 1;} 10% {background: #ef4444; opacity: 0.8;} 100% {background: transparent; opacity: 0;} }
    @keyframes flashFadeGreen { 0% {background: #86efac; opacity: 1;} 10% {background: #22c55e; opacity: 0.8;} 100% {background: transparent; opacity: 0;} }

    /* ============================================================
       🛠️ MODALS 
       ============================================================ */
    .modal-backdrop { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(2, 6, 23, 0.9); z-index: 9999; display: none; align-items: center; justify-content: center; backdrop-filter: blur(8px); }
    .dev-box { background: #0f172a; width: 600px; border-radius: 20px; border: 2px solid var(--dev-color); box-shadow: 0 20px 50px rgba(236, 72, 153, 0.4); transform: translateY(50px); opacity: 0; transition: 0.3s; display: flex; flex-direction: column; overflow: hidden;}
    .dev-box.show { transform: translateY(0); opacity: 1; }
    .dev-header { background: rgba(236, 72, 153, 0.1); padding: 20px; border-bottom: 1px solid var(--border-color); color: var(--dev-color); font-size: 1.2rem; font-weight: bold; display: flex; justify-content: space-between;}
    .dev-body { padding: 20px; text-align: left; }
    .json-output { width: 100%; height: 250px; background: #020617; color: #a3e635; font-family: 'Share Tech Mono', monospace; font-size: 0.9rem; border: 1px solid #334155; border-radius: 10px; padding: 15px; box-sizing: border-box; margin-bottom: 15px; resize: none; outline: none;}
    .btn-copy { background: var(--dev-color); color: white; border: none; padding: 12px; border-radius: 10px; cursor: pointer; font-weight: bold; width: 100%; font-family: 'Prompt'; transition: 0.3s;}
    .btn-copy:hover { background: #be185d; }

    .ph-box, .toolbox-box { background: #0f172a; border-radius: 20px; box-shadow: 0 20px 50px rgba(0,0,0,0.8); transform: translateY(50px); opacity: 0; transition: 0.3s; text-align: center; overflow: hidden; }
    .ph-box { width: 400px; border: 2px solid #f59e0b; }
    .toolbox-box { width: 380px; border: 2px solid var(--primary); }
    .ph-box.show, .toolbox-box.show, .pt-box.show { transform: translateY(0) scale(1); opacity: 1; }
    .ph-header { background: rgba(245, 158, 11, 0.1); padding: 20px; border-bottom: 1px solid var(--border-color); color: #f59e0b; font-size: 1.2rem; font-weight: bold; }
    .ph-body { padding: 30px; }
    .ph-strip-container { height: 120px; display: flex; justify-content: center; margin-bottom: 20px; position: relative; }
    .ph-strip { width: 40px; height: 100%; background: #fef08a; border-radius: 4px; box-shadow: 0 5px 15px rgba(0,0,0,0.5); transition: background 1.5s ease-in-out; }
    .ph-result { font-family: 'Share Tech Mono'; font-size: 3rem; font-weight: bold; color: white; margin-bottom: 10px; opacity: 0; transition: 0.5s; }
    .ph-scale-guide { display: flex; height: 15px; width: 100%; border-radius: 10px; overflow: hidden; margin-top: 20px; }
    .ph-scale-guide div { flex: 1; }

    .tb-header { background: rgba(139, 92, 246, 0.1); padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); }
    .tb-title { margin: 0; font-size: 1.1rem; color: var(--primary); display: flex; align-items: center; gap: 10px;}
    .tb-close, .pt-close { background: none; border: none; color: var(--text-muted); font-size: 1.5rem; cursor: pointer; transition: 0.2s;}
    .tb-close:hover, .pt-close:hover { color: var(--danger); }
    .tb-body { padding: 20px; text-align: center; }
    .tb-input-group { background: #020617; border: 1px solid var(--border-color); border-radius: 12px; padding: 10px 20px; display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px;}
    .tb-input-group input { background: transparent; border: none; color: white; font-family: 'Share Tech Mono', monospace; font-size: 2.5rem; width: 60%; text-align: right; outline: none; pointer-events: auto !important;}
    .tb-input-group span { color: #64748b; font-size: 1.2rem; font-weight: bold; }
    .tb-quick-add { display: flex; gap: 10px; margin-bottom: 10px; justify-content: center;}
    .btn-quick { flex: 1; background: #1e293b; color: white; border: 1px solid #334155; padding: 10px 5px; border-radius: 8px; cursor: pointer; font-family: 'Share Tech Mono'; font-size: 1rem; transition: 0.2s; font-weight: bold;}
    .btn-quick:hover { background: #334155; border-color: var(--primary); color: var(--primary); }
    .btn-dropper { background: rgba(139, 92, 246, 0.15); border-color: var(--primary); color: var(--primary); }
    .btn-pour { background: linear-gradient(135deg, var(--primary), #6d28d9); color: white; border: none; padding: 15px; border-radius: 12px; font-weight: bold; cursor: pointer; width: 100%; font-size: 1.2rem; font-family: 'Prompt'; transition: 0.3s; box-shadow: 0 5px 15px rgba(139, 92, 246, 0.4); margin-top: 15px;}

    /* ============================================================
       📊 PERIODIC TABLE STYLES
       ============================================================ */
    .pt-box { background: linear-gradient(160deg, #0d1424 0%, #0a0f1e 100%); width: 96%; max-width: 1500px; height: 92vh; border-radius: 20px; border: 1px solid rgba(56,189,248,0.15); box-shadow: 0 20px 60px rgba(0,0,0,0.85), inset 0 1px 0 rgba(255,255,255,0.05); display: flex; flex-direction: column; overflow: hidden; transform: scale(0.95); opacity: 0; transition: 0.3s;}
    .pt-header { background: linear-gradient(90deg, rgba(30,41,59,0.6), rgba(15,23,42,0.3)); padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(56,189,248,0.12); gap: 20px; flex-wrap: wrap; }
    .pt-title-group { display: flex; align-items: center; gap: 14px; }
    .pt-title-icon { width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #1e3a8a, #4c1d95); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; box-shadow: 0 4px 16px rgba(56,189,248,0.3); border: 1px solid rgba(56,189,248,0.3); }
    .pt-title-text h2 { font-size: 1.35rem; font-weight: 700; color: #f1f5f9; margin: 0; letter-spacing: -0.02em; }
    .pt-title-text p { font-size: 0.72rem; color: #64748b; margin: 2px 0 0; }
    .pt-filters { display: flex; gap: 6px; flex-wrap: wrap; }
    .pt-filter { padding: 7px 14px; border-radius: 20px; font-size: 0.72rem; font-weight: 500; cursor: pointer; border: 1px solid rgba(148,163,184,0.2); background: rgba(30,41,59,0.4); color: #94a3b8; transition: 0.15s; display: flex; align-items: center; gap: 5px; font-family: 'Prompt'; }
    .pt-filter:hover { border-color: rgba(56,189,248,0.4); color: #e2e8f0; }
    .pt-filter.active { background: rgba(56,189,248,0.15); border-color: #38bdf8; color: #7dd3fc; }
    .pt-filter .dot { width: 7px; height: 7px; border-radius: 50%; }
    .pt-header h2 { margin: 0; color: white; font-family: 'Orbitron'; letter-spacing: 1px; display: flex; align-items: center; gap: 10px;}
    .pt-body { padding: 20px; overflow: auto; flex: 1; position: relative; background: #020617;}
    .periodic-grid { display: grid; grid-template-columns: repeat(18, minmax(38px, 1fr)); grid-template-rows: repeat(10, minmax(48px, 1fr)); gap: 4px; min-width: 980px; max-width: 1150px; margin-right: 360px; padding-bottom: 40px;}
    .grid-cell { min-height: 50px; }
    .element-cell { background: #1e293b; border: 1px solid #334155; border-radius: 6px; padding: 5px; text-align: center; cursor: pointer; transition: 0.2s; position: relative; display: flex; flex-direction: column; justify-content: center; align-items: center; box-shadow: inset 0 0 10px rgba(0,0,0,0.5); min-height: 50px; }
    .element-cell:hover { transform: scale(1.15); z-index: 10; box-shadow: 0 10px 20px rgba(0,0,0,0.8); border-color: white !important; }
    .el-num { position: absolute; top: 3px; left: 4px; font-size: 0.65rem; font-family: 'Share Tech Mono'; opacity: 0.8;}
    .el-sym { font-size: 1.3rem; font-weight: bold; font-family: 'Orbitron'; margin-top: 5px; text-shadow: 1px 1px 2px rgba(0,0,0,0.8);}
    .el-name { font-size: 0.55rem; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; width: 100%; opacity: 0.9;}
    .type-nonmetal { background: #1e3a8a; border-color: #38bdf8; color: #bae6fd; }
    /* ── Element sprite image (sheet 6×5) ── */
    .el-sprite {
        width: 100%; aspect-ratio: 1/1; border-radius: 6px;
        background-repeat: no-repeat;
        background-size: 600% 500%;  /* 6 คอลัมน์ × 5 แถว */
        background-color: rgba(255,255,255,0.03);
        margin-bottom: 2px;
    }
    /* ในตาราง: รูปเล็กมุมบน */
    .element-cell .el-sprite-mini {
        position: absolute; top: 3px; right: 3px;
        width: 18px; height: 18px; border-radius: 3px;
        background-repeat: no-repeat; background-size: 600% 500%;
        opacity: 0.85; pointer-events: none;
    }
    /* ใน detail: รูปใหญ่ */
    .dt-sprite-lg {
        width: 90px; height: 90px; border-radius: 10px;
        background-repeat: no-repeat; background-size: 600% 500%;
        background-color: rgba(255,255,255,0.05);
        border: 1px solid rgba(56,189,248,0.2);
        flex-shrink: 0;
    }
    .type-noble { background: #4c1d95; border-color: #c084fc; color: #e9d5ff; }
    .type-alkali { background: #7f1d1d; border-color: #f87171; color: #fecaca; }
    .type-alkaline { background: #7c2d12; border-color: #fb923c; color: #fed7aa; }
    .type-metalloid { background: #14532d; border-color: #4ade80; color: #bbf7d0; }
    .type-halogen { background: #312e81; border-color: #818cf8; color: #c7d2fe; }
    .type-transition { background: #713f12; border-color: #facc15; color: #fef08a; }
    .type-post-transition { background: #3f6212; border-color: #a3e635; color: #d9f99d; }
    .type-lanthanide { background: #831843; border-color: #f472b6; color: #fbcfe8; }
    .type-actinide { background: #881337; border-color: #fb7185; color: #fecdd3; }
    .element-detail-card { position: absolute; top: 16px; right: 16px; width: 340px; max-height: calc(100% - 32px); overflow-y: auto; background: linear-gradient(160deg, rgba(15,23,42,0.97), rgba(10,15,30,0.97)); border: 1px solid rgba(56,189,248,0.2); border-radius: 14px; padding: 18px; box-shadow: 0 10px 40px rgba(0,0,0,0.85); display: none; backdrop-filter: blur(12px);}
    .element-detail-card::-webkit-scrollbar { width: 6px; }
    .element-detail-card::-webkit-scrollbar-thumb { background: rgba(56,189,248,0.3); border-radius: 3px; }
    /* Detail sections */
    .edc-sec { margin-top: 14px; }
    .edc-sec-title { font-size: 0.6rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 7px; font-weight: 600; }
    .edc-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .edc-prop { background: rgba(30,41,59,0.4); border: 1px solid rgba(148,163,184,0.1); border-radius: 8px; padding: 8px 10px; }
    .edc-prop-label { font-size: 0.58rem; color: #64748b; margin-bottom: 2px; }
    .edc-prop-val { font-size: 0.82rem; color: #e2e8f0; font-weight: 600; font-family: 'Share Tech Mono'; }
    .edc-chip { display: inline-flex; align-items: center; gap: 4px; background: rgba(30,41,59,0.5); border: 1px solid rgba(148,163,184,0.15); border-radius: 6px; padding: 5px 9px; font-size: 0.68rem; color: #cbd5e1; margin: 2px; }
    .edc-compound { display: flex; align-items: center; justify-content: space-between; background: rgba(30,41,59,0.4); border: 1px solid rgba(148,163,184,0.1); border-radius: 7px; padding: 7px 11px; margin-bottom: 5px; }
    .edc-compound-f { font-family: 'Share Tech Mono'; font-size: 0.8rem; color: #7dd3fc; font-weight: 600; }
    .edc-compound-n { font-size: 0.68rem; color: #94a3b8; }
    .edc-config { background: rgba(30,41,59,0.4); border: 1px solid rgba(148,163,184,0.1); border-radius: 8px; padding: 10px 12px; font-family: 'Share Tech Mono'; font-size: 0.9rem; color: #a5f3fc; text-align: center; letter-spacing: 1px; }
    .edc-hazard { padding: 8px 11px; border-radius: 8px; font-size: 0.72rem; font-weight: 500; }
    .edc-hazard.low { background: rgba(74,222,128,0.1); color: #4ade80; border: 1px solid rgba(74,222,128,0.25); }
    .edc-hazard.mid { background: rgba(245,158,11,0.1); color: #f59e0b; border: 1px solid rgba(245,158,11,0.25); }
    .edc-hazard.high { background: rgba(239,68,68,0.1); color: #f87171; border: 1px solid rgba(239,68,68,0.25); }
    .btn-add-pt-element { width: 100%; background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; padding: 12px; border-radius: 10px; font-family: 'Prompt'; font-weight: bold; margin-top: 20px; cursor: pointer; transition: 0.3s; box-shadow: 0 5px 15px rgba(16, 185, 129, 0.4);}
    .btn-add-pt-element:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(16, 185, 129, 0.6); }
</style>

<div class="toxic-cloud" id="toxicCloud"></div>
<div class="explosion-overlay" id="explosionOverlay"></div>

<div class="equation-board" id="eqBoard">
    <div class="eq-title">Chemical Reaction Detected</div>
    <div class="eq-text" id="eqText">A + B → C</div>
    <div class="eq-desc" id="eqDesc">Explanation</div>
</div>

<div class="lab-subheader">
    <div class="lab-title">
        <div class="lab-title-icon"><?= $is_admin ? '⚙️' : '🧪' ?></div>
        <div>
            <div class="lab-title-text"><?= $is_admin ? 'Sandbox Chemistry Lab' : 'Virtual Chemistry Lab' ?></div>
            <div class="lab-version"><?= $is_admin ? 'DEVELOPER & TEACHER MODE' : 'STUDENT MODE' ?></div>
        </div>
    </div>
    <div class="header-controls">
        <?php if($is_admin): ?>
            <button class="btn-dev-tools" id="btnDevTools">🛠️ สร้างโจทย์ (Export)</button>
        <?php endif; ?>
        <button class="btn-periodic" id="btnPeriodicTable">📊 ตารางธาตุ</button>
        <button class="btn-audio muted" id="btnToggleAudio">🔇 ปิดเสียง</button>
        <button onclick="window._openLabTutorial && window._openLabTutorial()" title="คู่มือการใช้งาน" style="background:rgba(56,189,248,0.1);border:1px solid rgba(56,189,248,0.3);color:#38bdf8;border-radius:8px;padding:5px 11px;cursor:pointer;font-family:'Prompt';font-size:0.75rem;font-weight:600;margin-left:6px;transition:0.2s;" onmouseover="this.style.background='rgba(56,189,248,0.2)'" onmouseout="this.style.background='rgba(56,189,248,0.1)'">❓ คู่มือ</button>
        <div class="hp-wrapper" id="hpContainer" title="<?= $is_admin ? 'โหมดพระเจ้า (God Mode)' : 'ความปลอดภัยของนักเรียน' ?>">
            <div class="hp-label"><?= $is_admin ? 'SYS' : 'HP' ?></div>
            <div class="hp-bar-bg"><div class="hp-bar-fill" id="hpBarFill"></div></div>
            <div class="hp-value" id="hpValueTxt"><?= $is_admin ? '∞' : '100%' ?></div>
        </div>
    </div>
</div>

<div class="lab-main-container">
    
    <div class="panel-side panel-left" id="panelLeft">
        <!-- Mobile collapse toggle -->
        <div class="panel-collapse-btn" onclick="togglePanel('panelLeft')" id="panelLeftToggle">
            <span>📦 คลังสาร (Chemicals)</span>
            <span class="arrow">▼</span>
        </div>
        <div class="panel-inner">
            <div class="panel-content">
                <h3 class="panel-title">📦 คลังสสาร (Chemicals)</h3>
                <div class="search-box">
                    <input type="text" id="chemSearch" placeholder="🔍 ค้นหาชื่อ หรือ สูตรเคมี..." autocomplete="off">
                </div>

                <div class="chem-group-title">🧊 ของแข็ง (Solid)</div>
                <div class="chem-list" id="solidList">
                    <?php foreach ($solids as $s):
                        $hazard = intval($s['hazard_level'] ?? 0);
                        $hazard_cls = $hazard >= 3 ? 'hazard-high' : ($hazard >= 1 ? 'hazard-low' : '');
                    ?>
                        <div class="chem-item chem-draggable <?= $hazard_cls ?>" draggable="true"
                             title="<?= h($s['description'] ?? $s['name']) ?>"
                             data-id="<?= $s['id'] ?>" data-name="<?= h($s['name']) ?>" data-state="solid"
                             data-color="<?= h($s['color_neutral']) ?>" data-type="<?= h($s['type']) ?>"
                             data-bp="<?= $s['boiling_point'] ?>" data-formula="<?= h($s['formula']) ?>"
                             data-toxicity="<?= intval($s['toxicity'] ?? 0) ?>"
                             data-hazard="<?= $hazard ?>"
                             data-ph="<?= $s['ph_value'] !== null ? $s['ph_value'] : '' ?>"
                             data-real-world="<?= h($s['real_world_example'] ?? '') ?>"
                             data-acid-explain="<?= h($s['acid_base_explain'] ?? '') ?>"
                             data-description="<?= h($s['description'] ?? '') ?>"
                             data-texture="<?= h($s['texture_type'] ?? '') ?>">
                            <div class="chem-icon chem-icon--solid" data-type="<?= h($s['type']) ?>"></div>
                            <div class="chem-info">
                                <div class="chem-name" data-chem-id="<?= $s['id'] ?>"><?= h($s['name']) ?><?= $hazard >= 3 ? ' <span class="hazard-badge">⚠️</span>' : '' ?></div>
                                <div class="chem-formula formula-display"><?= h($s['formula']) ?></div>
                                <?php if(!empty($s['ph_value'])): ?><div style="font-size:0.62rem;color:#64748b;margin-top:1px">pH <?= number_format($s['ph_value'],1) ?></div><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="chem-group-title">💧 ของเหลว (Liquid)</div>
                <div class="chem-list" id="liquidList">
                    <?php foreach ($liquids as $l):
                        $hazard = intval($l['hazard_level'] ?? 0);
                        $hazard_cls = $hazard >= 3 ? 'hazard-high' : ($hazard >= 1 ? 'hazard-low' : '');
                    ?>
                        <div class="chem-item chem-draggable <?= $hazard_cls ?>" draggable="true"
                             title="<?= h($l['description'] ?? $l['name']) ?>"
                             data-id="<?= $l['id'] ?>" data-name="<?= h($l['name']) ?>" data-state="liquid"
                             data-color="<?= h($l['color_neutral']) ?>" data-type="<?= h($l['type']) ?>"
                             data-bp="<?= $l['boiling_point'] ?>" data-formula="<?= h($l['formula']) ?>"
                             data-toxicity="<?= intval($l['toxicity'] ?? 0) ?>"
                             data-hazard="<?= $hazard ?>"
                             data-ph="<?= $l['ph_value'] !== null ? $l['ph_value'] : '' ?>"
                             data-real-world="<?= h($l['real_world_example'] ?? '') ?>"
                             data-acid-explain="<?= h($l['acid_base_explain'] ?? '') ?>"
                             data-description="<?= h($l['description'] ?? '') ?>"
                             data-texture="<?= h($l['texture_type'] ?? '') ?>"
                             data-viscosity="<?= $l['viscosity_override'] !== null ? $l['viscosity_override'] : '' ?>">
                            <div class="chem-icon chem-icon--liquid" data-type="<?= h($l['type']) ?>"
                                 style="--chem-color: <?= h($l['color_neutral']) ?>"></div>
                            <div class="chem-info">
                                <div class="chem-name" data-chem-id="<?= $l['id'] ?>"><?= h($l['name']) ?><?= $hazard >= 3 ? ' <span class="hazard-badge">⚠️</span>' : '' ?></div>
                                <div class="chem-formula formula-display"><?= h($l['formula']) ?></div>
                                <?php if(!empty($l['ph_value'])): ?><div style="font-size:0.62rem;color:#64748b;margin-top:1px">pH <?= number_format($l['ph_value'],1) ?></div><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="workbench-wrapper" id="workbenchScroll">
        <?php if($is_admin): ?>
            <div class="live-data-hud" id="liveDataHUD">
                <div class="ld-header"><h3 class="ld-title">📡 Live Data Monitor</h3></div>
                <div class="ld-row"><span class="ld-label">Volume:</span> <span class="ld-value" id="ld-vol">0.0 ml</span></div>
                <div class="ld-row"><span class="ld-label">Solid Mass:</span> <span class="ld-value" id="ld-mass">0.0 g</span></div>
                <div class="ld-row"><span class="ld-label">Current pH:</span> <span class="ld-value" id="ld-ph">7.0</span></div>
                <div class="ld-row"><span class="ld-label">Boiling Pt:</span> <span class="ld-value" id="ld-bp" style="color:var(--warning);">100.0 °C</span></div>
                <div style="margin-top: 10px;">
                    <span class="ld-label" style="font-size:0.8rem; color:#cbd5e1;">Contents in Beaker:</span>
                    <div id="ld-subs" style="color:#a855f7; font-size:0.85rem; max-height:80px; overflow-y:auto; margin-top:5px;">
                        <span style='color:#64748b'>Empty Beaker</span>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="quest-hud" id="questHUD">
                <div class="qh-header"><h3 class="qh-title" id="hudTitle"><?= $assignment_title ?></h3></div>
                <ul class="qh-checklist" id="hudChecklist"></ul>
            </div>
        <?php endif; ?>

        <div class="sensor-panel" style="display:flex; flex-direction:column; gap:5px;">
            <div class="sensor-label" style="color:var(--text-muted); font-size:0.75rem; letter-spacing:2px;">TEMPERATURE</div>
            <div class="sensor-value" id="valTemp" style="font-family:'Share Tech Mono'; font-size:2rem; font-weight:bold; color:var(--primary);">25.0 °C</div>
            <!-- Room Temp controls -->
            <div style="font-size:0.6rem;color:#334155;letter-spacing:.12em;text-transform:uppercase;margin-top:6px;margin-bottom:3px;">อุณหภูมิห้อง</div>
            <div style="display:flex;gap:5px;">
                <button id="btnAC" title="เปิดแอร์ (ลดอุณหภูมิห้อง)" style="flex:1;background:rgba(56,189,248,0.15);border:1px solid rgba(56,189,248,0.4);color:#38bdf8;border-radius:6px;cursor:pointer;font-size:0.75rem;padding:5px 0;font-family:'Prompt';font-weight:600;transition:0.2s;">❄️ AC</button>
                <button id="btnHeater2" title="เปิดฮีตเตอร์ (เพิ่มอุณหภูมิห้อง)" style="flex:1;background:rgba(251,146,60,0.15);border:1px solid rgba(251,146,60,0.4);color:#fb923c;border-radius:6px;cursor:pointer;font-size:0.75rem;padding:5px 0;font-family:'Prompt';font-weight:600;transition:0.2s;">🔆 Heater</button>
            </div>
            <div id="roomTempDisplay" style="font-size:0.7rem;color:#64748b;text-align:center;margin-top:3px;padding:2px 0;background:#0f172a;border-radius:4px;">🌡️ ห้อง: 25°C</div>
            <!-- Stopwatch -->
            <div style="background:#0f172a;border-radius:8px;padding:8px;margin-top:4px;border:1px solid #334155;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
                    <span style="font-size:0.65rem;color:#64748b;letter-spacing:2px;">STOPWATCH</span>
                    <div style="display:flex;gap:4px;">
                        <button id="btnTimerToggle" style="font-size:0.65rem;padding:2px 8px;border-radius:4px;background:rgba(34,197,94,0.2);border:1px solid rgba(34,197,94,0.4);color:#22c55e;cursor:pointer;">▶ Start</button>
                        <button id="btnTimerReset" style="font-size:0.65rem;padding:2px 6px;border-radius:4px;background:rgba(100,116,139,0.2);border:1px solid #334155;color:#64748b;cursor:pointer;">↺</button>
                        <button id="btnTimeSpeed" title="เร่งเวลา" style="font-size:0.65rem;padding:2px 6px;border-radius:4px;background:rgba(168,85,247,0.2);border:1px solid rgba(168,85,247,0.4);color:#a855f7;cursor:pointer;">1×</button>
                    </div>
                </div>
                <div id="timerDisplay" style="font-family:'Share Tech Mono';font-size:1.3rem;color:var(--primary);text-align:center;letter-spacing:2px;">00:00:00</div>
            </div>
            <?php if($is_admin): ?>
            <div style="font-size:0.6rem;color:#334155;letter-spacing:.12em;text-transform:uppercase;margin-top:8px;margin-bottom:3px;">🔧 Admin — อุณหภูมิ beaker</div>
            <div style="display:flex; gap:5px;">
                <button id="btnTempUp" style="flex:1; background:rgba(239,68,68,0.2); border:1px solid #ef4444; color:#ef4444; border-radius:5px; cursor:pointer; font-family:'Prompt'; font-weight:bold; padding:5px 0;">+50°C</button>
                <button id="btnTempDown" style="flex:1; background:rgba(56,189,248,0.2); border:1px solid #38bdf8; color:#38bdf8; border-radius:5px; cursor:pointer; font-family:'Prompt'; font-weight:bold; padding:5px 0;">-50°C</button>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="ambient-lighting" id="ambientLight"></div>
        <div class="desk-surface"></div>
        <div class="container-shelf" id="containerShelf"></div>
        <div id="clearContainersBtn" title="เก็บภาชนะทั้งหมด">✕</div>
        <div id="scoopCursor"><span class="scoop-icon"></span><span class="scoop-load"></span></div>
        <div id="scoopHUD"></div>
        
        <div class="desk-anchor" id="labAnchor">
            <div class="digital-scale" id="scaleObj" title="กดที่ฐานเพื่อตั้งศูนย์ (TARE)">
                <div class="scale-plate"><div class="scale-powder" id="scalePowder"></div></div>
                <div class="scale-display" id="scaleDisplay">0.00 g</div>
                <div style="font-size: 0.7rem; color: #64748b; font-weight: bold; margin-top: -2px; letter-spacing: 1px;">TARE BUTTON</div>
            </div>
            <div class="visual-thermometer" id="visualThermo">
                <div class="thermo-mercury" id="thermoBar"></div>
                <div class="thermo-bulb"></div>
            </div>
            <div class="heater-base" id="heaterBase"></div>
            <div class="flame-container" id="flameFire">
                <div class="flame flame-outer"></div>
                <div class="flame flame-mid"></div>
                <div class="flame flame-inner"></div>
            </div>
            
            <div class="main-beaker" id="beakerObj" style="position:relative;overflow:hidden;">
                <div class="beaker-dropzone" id="beakerDropzone"></div>
                <div class="stirring-rod" id="stirRod"></div>
                <canvas id="liquidCanvas" class="liquid-canvas" width="192" height="236"></canvas>
            </div>
            
            <?php if($is_admin): ?>
            <button class="btn-repair" id="btnRepairBeaker">🛠️ เบิกบีกเกอร์ใบใหม่ (ซ่อมแซม)</button>
            <?php endif; ?>

    <div id="alcLampOnDesk" style="display:none;position:absolute;z-index:25;bottom:10px;left:-50px;width:60px;height:80px;filter:drop-shadow(0 4px 8px rgba(0,0,0,0.5));" title="ตะเกียงแอลกอฮอล์">
        <div style="position:absolute;bottom:0;left:50%;transform:translateX(-50%);width:40px;height:48px;background:#f8fafc;border-radius:6px 6px 8px 8px;border:2px solid #cbd5e1;box-shadow:0 4px 12px rgba(0,0,0,0.4)"></div>
        <div style="position:absolute;bottom:44px;left:50%;transform:translateX(-50%);width:16px;height:14px;background:#e2e8f0;border-radius:4px 4px 0 0;border:1px solid #94a3b8"></div>
        <div id="alcWick" style="position:absolute;bottom:56px;left:50%;transform:translateX(-50%);width:8px;height:8px;background:#d97706;border-radius:2px"></div>
        <div id="alcFlame" style="display:none;position:absolute;bottom:60px;left:50%;transform:translateX(-50%);width:24px;height:36px">
            <div style="position:absolute;bottom:0;left:2px;width:20px;height:32px;background:radial-gradient(ellipse at 50% 90%,#1d4ed8 0%,#3b82f6 30%,#f59e0b 60%,transparent 100%);border-radius:50% 50% 30% 30%;filter:blur(1.5px);animation:alcFlicker .15s infinite alternate"></div>
            <div style="position:absolute;bottom:4px;left:5px;width:14px;height:22px;background:radial-gradient(ellipse at 50% 90%,#93c5fd 0%,#60a5fa 40%,transparent 100%);border-radius:50% 50% 30% 30%;filter:blur(1px);animation:alcFlicker2 .12s infinite alternate-reverse"></div>
        </div>
        <div id="alcCap" style="position:absolute;bottom:38px;left:50%;transform:translateX(-50%);width:22px;height:10px;background:#64748b;border-radius:3px;border:1px solid #475569;cursor:pointer;z-index:5" title="คลิกเพื่อเปิด/ปิดเปลว"></div>
        <div style="position:absolute;top:-16px;left:50%;transform:translateX(-50%);font-size:.58rem;color:#94a3b8;white-space:nowrap">ตะเกียงฯ</div>
    </div>
        </div>
    </div>

    <!-- ── Equipment Tray ── -->
    <div id="equipTray" style="
        position:fixed;bottom:0;left:0;right:0;z-index:600;
        background:#0a1628;border-top:1px solid #1e3a5f;
        padding:8px 12px;
        display:flex;align-items:center;gap:8px;overflow-x:auto;
        scrollbar-width:thin;scrollbar-color:#334155 transparent;
    ">
        <span style="font-size:.62rem;color:#475569;letter-spacing:.12em;text-transform:uppercase;white-space:nowrap;flex-shrink:0">อุปกรณ์</span>

        <!-- ═══ Phase 2: เครื่องมือตักสาร ═══ -->
        <div class="equip-slot scoop-tool" data-scoop="spatula" data-for="solid" title="ช้อนตวง — ตักของแข็ง สูงสุด 50 g" style="
            flex-shrink:0;background:#1e293b;border:1px solid #334155;border-radius:8px;
            padding:6px 10px;cursor:pointer;transition:.2s;text-align:center;min-width:72px;
        ">
            <div style="height:42px;display:flex;align-items:center;justify-content:center"><img src="images/weapon/spon.png" style="width:44px;height:44px;object-fit:contain" alt="ช้อนตวง"></div>
            <div style="font-size:.62rem;color:#94a3b8;margin-top:2px">ช้อนตวง</div>
            <div style="font-size:.58rem;color:#475569">≤50 g</div>
        </div>

        <div class="equip-slot scoop-tool" data-scoop="dropper" data-for="liquid" title="หลอดหยด — ดูดของเหลว สูงสุด 20 mL" style="
            flex-shrink:0;background:#1e293b;border:1px solid #334155;border-radius:8px;
            padding:6px 10px;cursor:pointer;transition:.2s;text-align:center;min-width:72px;
        ">
            <div style="height:42px;display:flex;align-items:center;justify-content:center"><img src="images/weapon/หลอดหยด.png" style="width:44px;height:44px;object-fit:contain" alt="หลอดหยด"></div>
            <div style="font-size:.62rem;color:#94a3b8;margin-top:2px">หลอดหยด</div>
            <div style="font-size:.58rem;color:#475569">≤20 mL</div>
        </div>

        <div style="width:1px;height:36px;background:#1e293b;flex-shrink:0;margin:0 2px"></div>

        <!-- ตะเกียงแอลกอฮอล์ -->
        <div class="equip-slot" id="equipAlcLamp" data-equip="alcohol_lamp" title="ตะเกียงแอลกอฮอล์ — 80–250°C" style="
            flex-shrink:0;background:#1e293b;border:1px solid #334155;border-radius:8px;
            padding:6px 10px;cursor:pointer;transition:.2s;text-align:center;min-width:72px;
        ">
            <div style="font-size:1.3rem;line-height:1.1">🔵</div>
            <div style="font-size:.62rem;color:#94a3b8;margin-top:2px">ตะเกียง</div>
            <div style="font-size:.58rem;color:#475569">80–250°C</div>
        </div>

        <!-- Bunsen Burner -->
        <div class="equip-slot" id="equipBunsen" data-equip="bunsen" title="Bunsen Burner — 300–600°C" style="
            flex-shrink:0;background:#1e293b;border:1px solid #334155;border-radius:8px;
            padding:6px 10px;cursor:pointer;transition:.2s;text-align:center;min-width:72px;
        ">
            <div style="font-size:1.3rem;line-height:1.1">🔥</div>
            <div style="font-size:.62rem;color:#94a3b8;margin-top:2px">Bunsen</div>
            <div style="font-size:.58rem;color:#475569">300–600°C</div>
        </div>

        <!-- Hot Plate -->
        <div class="equip-slot" id="equipHotPlate" data-equip="hot_plate" title="Hot Plate — อุณหภูมิแม่นยำ" style="
            flex-shrink:0;background:#1e293b;border:1px solid #334155;border-radius:8px;
            padding:6px 10px;cursor:pointer;transition:.2s;text-align:center;min-width:72px;
        ">
            <div style="font-size:1.3rem;line-height:1.1">⚡</div>
            <div style="font-size:.62rem;color:#94a3b8;margin-top:2px">Hot Plate</div>
            <div style="font-size:.58rem;color:#475569">25–500°C</div>
        </div>

        <div style="width:1px;height:36px;background:#1e293b;flex-shrink:0;margin:0 2px"></div>

        <!-- Flask -->
        <div class="equip-slot" data-equip="flask" title="Erlenmeyer Flask 250 mL" style="
            flex-shrink:0;background:#1e293b;border:1px solid #334155;border-radius:8px;
            padding:6px 10px;cursor:pointer;transition:.2s;text-align:center;min-width:64px;
        ">
            <div style="font-size:1.3rem;line-height:1.1">🔺</div>
            <div style="font-size:.62rem;color:#94a3b8;margin-top:2px">Flask</div>
            <div style="font-size:.58rem;color:#475569">250 mL</div>
        </div>

        <!-- Test Tube -->
        <div class="equip-slot" data-equip="test_tube" title="หลอดทดลอง" style="
            flex-shrink:0;background:#1e293b;border:1px solid #334155;border-radius:8px;
            padding:6px 10px;cursor:pointer;transition:.2s;text-align:center;min-width:64px;
        ">
            <div style="font-size:1.3rem;line-height:1.1">🧪</div>
            <div style="font-size:.62rem;color:#94a3b8;margin-top:2px">หลอด</div>
            <div style="font-size:.58rem;color:#475569">×3</div>
        </div>

        <!-- Evap Dish -->
        <div class="equip-slot" data-equip="evap_dish" title="จานระเหย" style="
            flex-shrink:0;background:#1e293b;border:1px solid #334155;border-radius:8px;
            padding:6px 10px;cursor:pointer;transition:.2s;text-align:center;min-width:64px;
        ">
            <div style="font-size:1.3rem;line-height:1.1">🫙</div>
            <div style="font-size:.62rem;color:#94a3b8;margin-top:2px">จานระเหย</div>
            <div style="font-size:.58rem;color:#475569">100 mL</div>
        </div>

        <div style="width:1px;height:36px;background:#1e293b;flex-shrink:0;margin:0 2px"></div>

        <!-- pH Meter -->
        <div class="equip-slot" data-equip="ph_meter" title="pH Meter ดิจิตอล" style="
            flex-shrink:0;background:#1e293b;border:1px solid #334155;border-radius:8px;
            padding:6px 10px;cursor:pointer;transition:.2s;text-align:center;min-width:64px;
        ">
            <div style="font-size:1.3rem;line-height:1.1">📟</div>
            <div style="font-size:.62rem;color:#94a3b8;margin-top:2px">pH Meter</div>
            <div style="font-size:.58rem;color:#475569">±0.01</div>
        </div>

        <!-- Gas Syringe -->
        <div class="equip-slot" data-equip="gas_syringe" title="Gas Syringe วัดก๊าซ" style="
            flex-shrink:0;background:#1e293b;border:1px solid #334155;border-radius:8px;
            padding:6px 10px;cursor:pointer;transition:.2s;text-align:center;min-width:64px;
        ">
            <div style="font-size:1.3rem;line-height:1.1">💉</div>
            <div style="font-size:.62rem;color:#94a3b8;margin-top:2px">Gas Syringe</div>
            <div style="font-size:.58rem;color:#475569">50 mL</div>
        </div>

        <!-- Fume Hood -->
        <div class="equip-slot" data-equip="fume_hood" title="ตู้ดูดควัน — ป้องกัน toxic gas 100%" style="
            flex-shrink:0;background:#1e293b;border:1px solid #334155;border-radius:8px;
            padding:6px 10px;cursor:pointer;transition:.2s;text-align:center;min-width:64px;
        ">
            <div style="font-size:1.3rem;line-height:1.1">🏭</div>
            <div style="font-size:.62rem;color:#94a3b8;margin-top:2px">Fume Hood</div>
            <div style="font-size:.58rem;color:#22c55e">ป้องกัน</div>
        </div>

        <div style="margin-left:auto;flex-shrink:0">
            <button id="btnCloseTray" style="
                background:none;border:1px solid #1e293b;color:#334155;
                border-radius:6px;padding:4px 8px;cursor:pointer;font-size:.65rem;
            ">▼</button>
        </div>
    </div>

    <div class="panel-side panel-right" id="panelRight">
        <div class="panel-inner">
            <div class="panel-content">
                <!-- ═══ ระบบแท็บ: Controls / ข้อมูล / อุณหภูมิ ═══ -->
                <div class="ctrl-tabs" id="ctrlTabs">
                    <button class="ctrl-tab active" data-tab="controls">🎮 ควบคุม</button>
                    <button class="ctrl-tab" data-tab="data">📊 ข้อมูล</button>
                    <button class="ctrl-tab" data-tab="temp">🌡️ อุณหภูมิ</button>
                </div>
                <div class="ctrl-tab-panel" id="tabData" style="display:none;"></div>
                <div class="ctrl-tab-panel" id="tabTemp" style="display:none;"></div>
                <div class="ctrl-tab-panel" id="tabControls">
                <h3 class="panel-title">🎮 แผงควบคุม (Controls)</h3>
                
                <button class="btn-ph-measure" id="btnMeasurePH">🔍 วิเคราะห์ค่า pH</button>
                
                <div class="safety-box" id="safetyBox">
                    <div style="font-size:0.7rem;color:#64748b;letter-spacing:2px;text-transform:uppercase;margin-bottom:8px;">PPE — อุปกรณ์ความปลอดภัย</div>
                    <div class="ppe-label" id="labelGoggles" style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:8px;padding:8px 10px;border-radius:8px;border:1px dashed #334155;transition:0.3s;">
                        <input type="checkbox" id="chkGoggles" style="display:none;">
                        <span class="ppe-icon-wrap" id="gogglesWrap" style="width:36px;height:36px;border-radius:50%;background:#1e293b;border:2px solid #334155;display:flex;align-items:center;justify-content:center;font-size:1.3rem;transition:0.3s;flex-shrink:0;">🥽</span>
                        <div>
                            <div style="font-size:0.82rem;font-weight:600;color:#94a3b8;" id="gogglesStatus">สวมแว่นตานิรภัย</div>
                            <div style="font-size:0.68rem;color:#475569;">ป้องกันสารเคมีกระเด็นเข้าตา</div>
                        </div>
                        <span style="margin-left:auto;font-size:0.65rem;padding:2px 7px;border-radius:10px;background:#1e293b;color:#475569;" id="gogglesTag">ถอด</span>
                    </div>
                    <div class="ppe-label" id="labelGloves" style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:8px 10px;border-radius:8px;border:1px dashed #334155;transition:0.3s;">
                        <input type="checkbox" id="chkGloves" style="display:none;">
                        <span class="ppe-icon-wrap" id="glovesWrap" style="width:36px;height:36px;border-radius:50%;background:#1e293b;border:2px solid #334155;display:flex;align-items:center;justify-content:center;font-size:1.3rem;transition:0.3s;flex-shrink:0;">🧤</span>
                        <div>
                            <div style="font-size:0.82rem;font-weight:600;color:#94a3b8;" id="glovesStatus">สวมถุงมือยาง</div>
                            <div style="font-size:0.68rem;color:#475569;">ป้องกันสารกัดกร่อนสัมผัสผิวหนัง</div>
                        </div>
                        <span style="margin-left:auto;font-size:0.65rem;padding:2px 7px;border-radius:10px;background:#1e293b;color:#475569;" id="glovesTag">ถอด</span>
                    </div>
                </div>
                <!-- Goggle overlay SVG - สมจริง ครอบหน้าจอ -->
                <div id="gogglesOverlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:9100;">
                    <svg id="gogglesSVG" xmlns="http://www.w3.org/2000/svg"
                         viewBox="0 0 1000 600"
                         style="position:absolute;top:0;left:0;width:100%;height:100%;"
                         preserveAspectRatio="none">
                        <defs>
                            <mask id="goggleMask">
                                <rect x="0" y="0" width="1000" height="600" fill="white"/>
                                <!-- เลนส์ซ้าย: cx=320, cy=300 -->
                                <ellipse id="lensL" cx="320" cy="300" rx="145" ry="130" fill="black"/>
                                <!-- เลนส์ขวา: cx=680, cy=300 -->
                                <ellipse id="lensR" cx="680" cy="300" rx="145" ry="130" fill="black"/>
                            </mask>
                        </defs>
                        <!-- พื้นมืดรอบๆ (frame) ตัด mask เป็นช่องเลนส์ -->
                        <rect x="0" y="0" width="1000" height="600" fill="rgba(5,10,25,0.88)" mask="url(#goggleMask)"/>
                        <!-- ขอบเลนส์ซ้าย -->
                        <ellipse cx="320" cy="300" rx="145" ry="130"
                                 fill="rgba(56,189,248,0.05)"
                                 stroke="rgba(56,189,248,0.6)" stroke-width="4"/>
                        <!-- ขอบเลนส์ขวา -->
                        <ellipse cx="680" cy="300" rx="145" ry="130"
                                 fill="rgba(56,189,248,0.05)"
                                 stroke="rgba(56,189,248,0.6)" stroke-width="4"/>
                        <!-- ไฮไลต์สะท้อนแสง ซ้าย -->
                        <ellipse cx="295" cy="258" rx="42" ry="32" fill="rgba(255,255,255,0.05)"/>
                        <!-- ไฮไลต์สะท้อนแสง ขวา -->
                        <ellipse cx="655" cy="258" rx="42" ry="32" fill="rgba(255,255,255,0.05)"/>
                        <!-- nose bridge (path ใช้ตัวเลข) -->
                        <path d="M 465 285 Q 500 320 535 285"
                              stroke="rgba(30,50,80,0.9)" stroke-width="18"
                              fill="none" stroke-linecap="round"/>
                        <!-- เส้นตรงกลาง bridge -->
                        <line x1="468" y1="300" x2="532" y2="300"
                              stroke="rgba(56,189,248,0.35)" stroke-width="4"
                              stroke-linecap="round"/>
                        <!-- สาย temple ซ้าย -->
                        <line x1="175" y1="300" x2="18" y2="290"
                              stroke="rgba(20,40,70,0.9)" stroke-width="16"
                              stroke-linecap="round"/>
                        <!-- สาย temple ขวา -->
                        <line x1="825" y1="300" x2="982" y2="290"
                              stroke="rgba(20,40,70,0.9)" stroke-width="16"
                              stroke-linecap="round"/>
                        <!-- กรอบด้านบน (วงคิ้วแว่น) -->
                        <path d="M 175 300 Q 320 155 465 285"
                              stroke="rgba(20,40,70,0.85)" stroke-width="14"
                              fill="none" stroke-linecap="round"/>
                        <path d="M 535 285 Q 680 155 825 300"
                              stroke="rgba(20,40,70,0.85)" stroke-width="14"
                              fill="none" stroke-linecap="round"/>
                    </svg>
                    <!-- badge ยืนยันการสวม -->
                    <div id="gogglesBadge" style="position:absolute;bottom:28px;left:50%;transform:translateX(-50%);background:rgba(16,185,129,0.92);color:#fff;font-family:'Prompt',sans-serif;font-size:0.78rem;font-weight:600;padding:7px 20px;border-radius:24px;letter-spacing:0.5px;border:1px solid rgba(16,185,129,0.5);backdrop-filter:blur(4px);white-space:nowrap;">
                        🛡️ สวมแว่นตานิรภัยแล้ว — ป้องกันสารกระเด็นเข้าตา
                    </div>
                </div>
                
                <div class="log-zone" id="labLog"></div>
                
                <?php if($is_admin): ?>
                    <button class="btn-dispose" id="btnWash" style="margin-top:10px;">⚡ ล้างบีกเกอร์ (Admin)</button>
                <?php else: ?>
                    <button class="btn-action-main" id="btnProcess">⚗️ ประมวลผลปฏิกิริยา</button>
                    <button class="btn-submit-report" id="btnSubmit">🚀 ส่งรายงานการทดลอง (AI)</button>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:10px;">
                        <button class="btn-dispose" id="btnUndo" title="เทสารล่าสุดออก 1 ชนิด">↩️ เทออก</button>
                        <button class="btn-dispose" id="btnWash">🛢️ ล้างบีกเกอร์</button>
                    </div>
                <?php endif; ?>
                </div><!-- /tabControls -->
            </div>
        </div>
    </div>
</div>

<!-- ═══ ปุ่มเปิด/ปิด 3 แผง ═══ -->
<button class="panel-toggle-btn" id="toggleLeftBtn" title="ซ่อน/แสดง คลังสาร">◀</button>
<button class="panel-toggle-btn" id="toggleRightBtn" title="ซ่อน/แสดง แผงควบคุม">▶</button>
<button class="panel-toggle-btn" id="toggleBottomBtn" title="ซ่อน/แสดง แถบเครื่องมือ">▼</button>

<?php if($is_admin): ?>
<div class="modal-backdrop" id="devModal">
    <div class="dev-box" id="devBox">
        <div class="dev-header"><span>⚙️ สร้างโจทย์การบ้านอัตโนมัติ (Generator)</span><button class="tb-close" id="btnCloseDev">✖</button></div>
        <div class="dev-body">
            <p style="color:var(--text-muted); font-size:0.9rem; margin-top:0;">ระบบได้แปลงสสารในบีกเกอร์ปัจจุบันของคุณเป็นโค้ด JSON สำหรับนำไปตั้งเป็นโจทย์ให้นักเรียนเรียบร้อยแล้ว!</p>
            <textarea class="json-output" id="devJsonOutput" readonly></textarea>
            <button class="btn-copy" id="btnCopyJson">📋 คัดลอกโค้ด JSON</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══ Phase 3: หน้าต่างเลือกปริมาณตักสาร ═══ -->
<div class="modal-backdrop" id="scoopAmountModal">
    <div class="scoop-box" id="scoopAmountBox">
        <div class="sa-header">
            <div class="sa-tool-icon" id="saToolIcon"></div>
            <div class="sa-title">
                <div class="sa-chem" id="saChemName">สาร</div>
                <div class="sa-tool-name" id="saToolName">ช้อนตวง</div>
            </div>
        </div>
        <div class="sa-body">
            <div class="sa-amount-display">
                <span id="saAmount">5.0</span><span class="sa-unit" id="saUnit">g</span>
            </div>
            <div class="sa-cap" id="saCapacity">ความจุสูงสุด 10 g</div>
            <input type="range" class="sa-slider" id="saSlider" min="0.5" max="10" step="0.5" value="5">
            <div class="sa-quick" id="saQuick"></div>
            <div class="sa-preview">
                <div class="sa-preview-dot" id="saPreviewDot"></div>
                <div class="sa-preview-txt" id="saPreviewTxt">สารจะอยู่บนช้อน</div>
            </div>
            <div class="sa-actions">
                <button class="sa-cancel" id="saCancel">ยกเลิก</button>
                <button class="sa-confirm" id="saConfirm">ตักสาร</button>
            </div>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="toolboxModal">
    <div class="toolbox-box" id="toolboxBox">
        <div class="tb-header">
            <h3 class="tb-title"><span style="font-size:1.5rem;">⚖️</span> <span id="tbChemName">ชื่อสารเคมี</span></h3>
            <button class="tb-close" id="btnCloseToolbox">✖</button>
        </div>
        <div class="tb-body">
            <div class="tb-input-group">
                <input type="number" id="tbInputAmt" value="0.0" min="0" step="0.1">
                <span id="tbInputUnit">ml</span>
            </div>
            <div class="tb-quick-add">
                <button class="btn-quick btn-add-amt" data-val="1">+1</button>
                <button class="btn-quick btn-add-amt" data-val="5">+5</button>
                <button class="btn-quick btn-add-amt" data-val="10">+10</button>
                <button class="btn-quick btn-add-amt" data-val="50">+50</button>
            </div>
            <div class="tb-quick-add">
                <button class="btn-quick btn-dropper btn-add-amt" data-val="0.1">💧 +0.1</button>
                <button class="btn-quick btn-dropper btn-add-amt" data-val="0.5">💧 +0.5</button>
            </div>
            <button class="btn-pour" id="btnConfirmPour">⬇️ เทสารลงบีกเกอร์</button>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="phModal">
    <div class="ph-box" id="phBox">
        <div class="ph-header">แถบทดสอบค่า pH (Universal Indicator)</div>
        <div class="ph-body">
            <div class="ph-strip-container"><div class="ph-strip" id="phStrip"></div></div>
            <div class="ph-result" id="phResultTxt">pH 7.0</div>
            <div style="color:var(--text-muted); font-size:0.9rem;" id="phStatusTxt">เป็นกลาง (Neutral)</div>
            <div class="ph-scale-guide">
                <div style="background:#ef4444;"></div>
                <div style="background:#f97316;"></div>
                <div style="background:#eab308;"></div>
                <div style="background:#22c55e;"></div>
                <div style="background:#0ea5e9;"></div>
                <div style="background:#3b82f6;"></div>
                <div style="background:#8b5cf6;"></div>
            </div>
            <button onclick="document.getElementById('phModal').style.display='none'" style="margin-top:20px; background:#334155; color:white; border:none; padding:10px 20px; border-radius:8px; cursor:pointer; font-family:'Prompt';">ปิดหน้าต่าง</button>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="ptModal">
    <div class="pt-box" id="ptBox">
        <div class="pt-header">
            <div class="pt-title-group">
                <div class="pt-title-icon">⚛️</div>
                <div class="pt-title-text">
                    <h2>Periodic Table Explorer</h2>
                    <p>118 Elements · Interactive Database</p>
                </div>
            </div>
            <div class="pt-filters" id="ptFilters">
                <div class="pt-filter active" data-filter="all">ทั้งหมด</div>
                <div class="pt-filter" data-filter="metal"><span class="dot" style="background:#facc15"></span>โลหะ</div>
                <div class="pt-filter" data-filter="nonmetal"><span class="dot" style="background:#4ade80"></span>อโลหะ</div>
                <div class="pt-filter" data-filter="noble"><span class="dot" style="background:#c084fc"></span>ก๊าซเฉื่อย</div>
                <div class="pt-filter" data-filter="metalloid"><span class="dot" style="background:#38bdf8"></span>กึ่งโลหะ</div>
                <div class="pt-filter" data-filter="radioactive"><span class="dot" style="background:#f87171"></span>กัมมันตรังสี</div>
            </div>
            <button class="pt-close" id="btnClosePT">✖</button>
        </div>
        <div class="pt-body">
            <div class="periodic-grid" id="periodicGrid"></div>
            <div class="element-detail-card" id="elDetailCard">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px;">
                    <div>
                        <div id="dtNum" style="font-size:0.8rem; color:#64748b; font-family:'Share Tech Mono';">1</div>
                        <div id="dtSym" style="font-size:3rem; font-weight:bold; font-family:'Orbitron'; color:white; line-height:1;">H</div>
                        <div id="dtName" style="font-size:1.05rem; color:white; margin-top:4px; font-weight:600;">Hydrogen</div>
                        <div id="dtMass" style="font-size:0.78rem; color:#64748b; font-family:'Share Tech Mono';">1.008 g/mol</div>
                        <div id="dtType" style="display:inline-block; margin-top:8px; padding:4px 11px; border-radius:20px; font-size:0.68rem; font-weight:600; text-transform:capitalize;">Nonmetal</div>
                    </div>
                    <div id="dtSpriteLg" class="dt-sprite-lg"></div>
                </div>
                <div id="dtSections"></div>
                <button class="btn-add-pt-element" id="btnAddPTElement" style="margin-top:14px;">➕ นำไปใช้ทดลอง</button>
            </div>
        </div>
    </div>
</div>

<script>
'use strict';

// โหลดข้อมูลละเอียด 118 ธาตุ (PT_DETAIL)
</script>
<script src="js/periodic_data.js"></script>
<script>
// ── Element sprite system (6×5 grid; แผ่น1=29 ธาตุ, แผ่น2-4=30 ธาตุ) ──
const SPRITE_COLS = 6, SPRITE_ROWS = 5;
const SHEET_MAX = 4;  // มีภาพ 4 แผ่น (ครอบ 118 ธาตุ)
// การกระจาย: แผ่น1=1-29, แผ่น2=30-59, แผ่น3=60-89, แผ่น4=90-118
function getElementSprite(z) {
    let sheetIdx, posInSheet;
    if (z <= 29) { sheetIdx = 1; posInSheet = z - 1; }        // 0-28
    else { const off = z - 30; sheetIdx = 2 + Math.floor(off / 30); posInSheet = off % 30; }
    const col = posInSheet % SPRITE_COLS;               // 0-5
    const row = Math.floor(posInSheet / SPRITE_COLS);   // 0-4
    const posX = col * (100 / (SPRITE_COLS - 1));
    const posY = row * (100 / (SPRITE_ROWS - 1));
    return {
        sheet: `images/elements/sheet${sheetIdx}.png`,
        posX: posX.toFixed(2),
        posY: posY.toFixed(2),
        exists: sheetIdx <= SHEET_MAX
    };
}
// สร้าง style string สำหรับ sprite
function spriteStyle(z) {
    const s = getElementSprite(z);
    if (!s.exists) return '';
    return `background-image:url('${s.sheet}');background-position:${s.posX}% ${s.posY}%;`;
}

const PeriodicElementsData = [
    { z:1, sym:"H", name:"Hydrogen", mass:1.008, type:"nonmetal", c:1, r:1, desc:"ก๊าซไวไฟ เบาที่สุดในเอกภพ เป็นองค์ประกอบหลักของน้ำ" },
    { z:2, sym:"He", name:"Helium", mass:4.002, type:"noble", c:18, r:1, desc:"ก๊าซเฉื่อย ไม่ทำปฏิกิริยา เบากว่าอากาศ ใช้บรรจุลูกโป่ง" },
    { z:3, sym:"Li", name:"Lithium", mass:6.94, type:"alkali", c:1, r:2, desc:"โลหะอัลคาไลน้ำหนักเบา ไวต่อปฏิกิริยากับน้ำ ใช้ทำแบตเตอรี่" },
    { z:4, sym:"Be", name:"Beryllium", mass:9.012, type:"alkaline", c:2, r:2, desc:"โลหะอัลคาไลน์เอิร์ท แข็ง น้ำหนักเบา เป็นพิษเมื่อสูดดมผง" },
    { z:5, sym:"B", name:"Boron", mass:10.81, type:"metalloid", c:13, r:2, desc:"กึ่งโลหะสีดำแข็ง ใช้ในอุตสาหกรรมกระจกและเซรามิก" },
    { z:6, sym:"C", name:"Carbon", mass:12.011, type:"nonmetal", c:14, r:2, desc:"อโลหะพื้นฐานของสิ่งมีชีวิตทั้งหมด มีหลายรูปเช่น เพชร, แกรไฟต์" },
    { z:7, sym:"N", name:"Nitrogen", mass:14.007, type:"nonmetal", c:15, r:2, desc:"ก๊าซไม่มีสี/กลิ่น เป็นองค์ประกอบ 78% ของบรรยากาศโลก" },
    { z:8, sym:"O", name:"Oxygen", mass:15.999, type:"nonmetal", c:16, r:2, desc:"ก๊าซที่จำเป็นต่อการหายใจและการเผาไหม้" },
    { z:9, sym:"F", name:"Fluorine", mass:18.998, type:"halogen", c:17, r:2, desc:"ก๊าซฮาโลเจนสีเหลืองอ่อน มีความไวต่อปฏิกิริยาสูงสุด" },
    { z:10, sym:"Ne", name:"Neon", mass:20.180, type:"noble", c:18, r:2, desc:"ก๊าซเฉื่อย เปล่งแสงสีส้มแดงเมื่อมีกระแสไฟฟ้าไหลผ่าน" },
    { z:11, sym:"Na", name:"Sodium", mass:22.990, type:"alkali", c:1, r:3, desc:"โลหะอ่อนสีเงิน ตัดได้ด้วยมีด ทำปฏิกิริยารุนแรงกับน้ำ" },
    { z:12, sym:"Mg", name:"Magnesium", mass:24.305, type:"alkaline", c:2, r:3, desc:"โลหะสีเงิน เผาไหม้ให้แสงสว่างจ้ามาก" },
    { z:13, sym:"Al", name:"Aluminum", mass:26.982, type:"post-transition", c:13, r:3, desc:"โลหะน้ำหนักเบา ไม่เป็นสนิม ใช้กว้างขวางในอุตสาหกรรม" },
    { z:14, sym:"Si", name:"Silicon", mass:28.085, type:"metalloid", c:14, r:3, desc:"กึ่งโลหะ องค์ประกอบหลักของทรายและอุปกรณ์อิเล็กทรอนิกส์" },
    { z:15, sym:"P", name:"Phosphorus", mass:30.974, type:"nonmetal", c:15, r:3, desc:"อโลหะไวไฟสูง (โดยเฉพาะฟอสฟอรัสขาว) ใช้ทำไม้ขีดไฟ" },
    { z:16, sym:"S", name:"Sulfur", mass:32.06, type:"nonmetal", c:16, r:3, desc:"อโลหะสีเหลือง มีกลิ่นฉุนเวลาเผาไหม้ ใช้ทำดินปืนและกรด" },
    { z:17, sym:"Cl", name:"Chlorine", mass:35.45, type:"halogen", c:17, r:3, desc:"ก๊าซสีเขียวอมเหลือง เป็นพิษ ใช้ฆ่าเชื้อโรคในน้ำ" },
    { z:18, sym:"Ar", name:"Argon", mass:39.95, type:"noble", c:18, r:3, desc:"ก๊าซเฉื่อยที่พบมากที่สุดในอากาศ ใช้หล่อเย็นและในหลอดไฟ" },
    { z:19, sym:"K", name:"Potassium", mass:39.098, type:"alkali", c:1, r:4, desc:"โลหะที่ไวต่อปฏิกิริยามาก เกิดเปลวไฟสีม่วงเมื่อโดนน้ำ" },
    { z:20, sym:"Ca", name:"Calcium", mass:40.078, type:"alkaline", c:2, r:4, desc:"โลหะสีเงิน องค์ประกอบสำคัญของกระดูกและหินปูน" },
    { z:21, sym:"Sc", name:"Scandium", mass:44.956, type:"transition", c:3, r:4, desc:"โลหะทรานซิชัน น้ำหนักเบา ใช้ทำโครงสร้างยานอวกาศ" },
    { z:22, sym:"Ti", name:"Titanium", mass:47.867, type:"transition", c:4, r:4, desc:"โลหะแข็งแกร่ง ทนการกัดกร่อน ใช้ทำข้อเทียมและเครื่องบิน" },
    { z:23, sym:"V", name:"Vanadium", mass:50.942, type:"transition", c:5, r:4, desc:"โลหะแข็งสีเงิน ใช้ผสมเหล็กเพื่อเพิ่มความเหนียวแข็ง" },
    { z:24, sym:"Cr", name:"Chromium", mass:51.996, type:"transition", c:6, r:4, desc:"โลหะเงางาม ใช้ชุบกันสนิมและทำสแตนเลส" },
    { z:25, sym:"Mn", name:"Manganese", mass:54.938, type:"transition", c:7, r:4, desc:"โลหะสีเงินแกมชมพู แข็งแต่เปราะ สำคัญในอุตสาหกรรมเหล็ก" },
    { z:26, sym:"Fe", name:"Iron", mass:55.845, type:"transition", c:8, r:4, desc:"โลหะที่มีความสำคัญทางแม่เหล็กและก่อสร้าง เป็นสนิมได้ง่าย" },
    { z:27, sym:"Co", name:"Cobalt", mass:58.933, type:"transition", c:9, r:4, desc:"โลหะแม่เหล็กสีน้ำเงินเงิน ใช้ทำแม่เหล็กถาวรและสี" },
    { z:28, sym:"Ni", name:"Nickel", mass:58.693, type:"transition", c:10, r:4, desc:"โลหะทนการกัดกร่อน ใช้ทำเหรียญกษาปณ์และโลหะผสม" },
    { z:29, sym:"Cu", name:"Copper", mass:63.546, type:"transition", c:11, r:4, desc:"โลหะสีทองแดง นำไฟฟ้า/ความร้อนได้ดีเยี่ยม" },
    { z:30, sym:"Zn", name:"Zinc", mass:65.38, type:"transition", c:12, r:4, desc:"โลหะสีน้ำเงินขาว ใช้ชุบกันสนิมให้เหล็ก (Galvanize)" },
    { z:31, sym:"Ga", name:"Gallium", mass:69.723, type:"post-transition", c:13, r:4, desc:"โลหะที่หลอมเหลวได้ในฝ่ามือ ใช้ทำเซมิคอนดักเตอร์" },
    { z:32, sym:"Ge", name:"Germanium", mass:72.630, type:"metalloid", c:14, r:4, desc:"กึ่งโลหะสีเทาขาว สำคัญในยุคแรกของการทำทรานซิสเตอร์" },
    { z:33, sym:"As", name:"Arsenic", mass:74.922, type:"metalloid", c:15, r:4, desc:"สารหนู มีความเป็นพิษสูง" },
    { z:34, sym:"Se", name:"Selenium", mass:78.971, type:"nonmetal", c:16, r:4, desc:"อโลหะนำไฟฟ้าได้ดีเมื่อโดนแสง ใช้ในเครื่องถ่ายเอกสาร" },
    { z:35, sym:"Br", name:"Bromine", mass:79.904, type:"halogen", c:17, r:4, desc:"อโลหะสถานะของเหลวสีแดงระเหยง่าย มีกลิ่นฉุนและเป็นพิษ" },
    { z:36, sym:"Kr", name:"Krypton", mass:83.798, type:"noble", c:18, r:4, desc:"ก๊าซเฉื่อย ใช้ในหลอดไฟฟลูออเรสเซนต์และเลเซอร์" },
    { z:37, sym:"Rb", name:"Rubidium", mass:85.468, type:"alkali", c:1, r:5, desc:"โลหะที่นิ่ม ไวต่อปฏิกิริยาสูง ลุกไหม้เองในอากาศได้" },
    { z:38, sym:"Sr", name:"Strontium", mass:87.62, type:"alkaline", c:2, r:5, desc:"โลหะอัลคาไลน์เอิร์ท เผาไหม้ให้สีแดงสด (ใช้ทำพลุ)" },
    { z:39, sym:"Y", name:"Yttrium", mass:88.906, type:"transition", c:3, r:5, desc:"โลหะที่ใช้ในเลเซอร์และฟอสเฟอร์หน้าจอทีวีสี" },
    { z:40, sym:"Zr", name:"Zirconium", mass:91.224, type:"transition", c:4, r:5, desc:"ทนความร้อนสูงมาก ไม่ดูดซับนิวตรอน (ใช้ในเตาปฏิกรณ์)" },
    { z:41, sym:"Nb", name:"Niobium", mass:92.906, type:"transition", c:5, r:5, desc:"ใช้ผสมเหล็กกล้าทนความร้อน และทำแม่เหล็กตัวนำยวดหยิ่ง" },
    { z:42, sym:"Mo", name:"Molybdenum", mass:95.95, type:"transition", c:6, r:5, desc:"ทนความร้อนสูง ใช้ทำชิ้นส่วนเครื่องยนต์และขีปนาวุธ" },
    { z:43, sym:"Tc", name:"Technetium", mass:98, type:"transition", c:7, r:5, desc:"ธาตุกัมมันตรังสีสังเคราะห์ตัวแรก ใช้สแกนอวัยวะทางการแพทย์" },
    { z:44, sym:"Ru", name:"Ruthenium", mass:101.07, type:"transition", c:8, r:5, desc:"โลหะมีค่ากลุ่มแพลทินัม ทนการกัดกร่อน ใช้เคลือบหน้าสัมผัสไฟฟ้า" },
    { z:45, sym:"Rh", name:"Rhodium", mass:102.91, type:"transition", c:9, r:5, desc:"โลหะหายาก สะท้อนแสงได้ดี ใช้ในเครื่องฟอกไอเสียรถยนต์" },
    { z:46, sym:"Pd", name:"Palladium", mass:106.42, type:"transition", c:10, r:5, desc:"โลหะมีค่า สามารถดูดซับก๊าซไฮโดรเจนได้ปริมาณมหาศาล" },
    { z:47, sym:"Ag", name:"Silver", mass:107.87, type:"transition", c:11, r:5, desc:"เงิน นำไฟฟ้าและความร้อนได้ดีที่สุดในบรรดาธาตุทั้งหมด" },
    { z:48, sym:"Cd", name:"Cadmium", mass:112.41, type:"transition", c:12, r:5, desc:"โลหะเป็นพิษ ใช้ในแบตเตอรี่และสีผสม" },
    { z:49, sym:"In", name:"Indium", mass:114.82, type:"post-transition", c:13, r:5, desc:"โลหะนิ่มมาก ส่งเสียงร้องเวลาดัดงอ ใช้ทำหน้าจอสัมผัส (ITO)" },
    { z:50, sym:"Sn", name:"Tin", mass:118.71, type:"post-transition", c:14, r:5, desc:"ดีบุก ไม่เป็นสนิม ใช้เคลือบกระป๋องบรรจุอาหาร" },
    { z:51, sym:"Sb", name:"Antimony", mass:121.76, type:"metalloid", c:15, r:5, desc:"พลวง กึ่งโลหะเปราะ ใช้ผสมตะกั่วทำแบตเตอรี่รถยนต์" },
    { z:52, sym:"Te", name:"Tellurium", mass:127.60, type:"metalloid", c:16, r:5, desc:"กึ่งโลหะสีเงินเปราะ ใช้ทำแผ่นซีดีแบบเขียนซ้ำได้" },
    { z:53, sym:"I", name:"Iodine", mass:126.90, type:"halogen", c:17, r:5, desc:"อโลหะระเหิดเป็นไอสีม่วง จำเป็นต่อการสร้างฮอร์โมนไทรอยด์" },
    { z:54, sym:"Xe", name:"Xenon", mass:131.29, type:"noble", c:18, r:5, desc:"ก๊าซเฉื่อย ให้แสงสีฟ้าสว่างจ้า ใช้ในแฟลชกล้องและไฟรถ" },
    { z:55, sym:"Cs", name:"Cesium", mass:132.91, type:"alkali", c:1, r:6, desc:"โลหะที่ไวต่อปฏิกิริยาสุดๆ ใช้กำหนดเวลาในนาฬิกาอะตอม" },
    { z:56, sym:"Ba", name:"Barium", mass:137.33, type:"alkaline", c:2, r:6, desc:"สารประกอบของแบเรียมใช้กลืนเพื่อถ่ายเอกซเรย์ทางเดินอาหาร" },
    { z:57, sym:"La", name:"Lanthanum", mass:138.91, type:"lanthanide", c:4, r:8, desc:"โลหะหายากจุดเริ่มต้นของกลุ่มแลนทาไนด์ ใช้ทำเลนส์กล้องคุณภาพสูง" },
    { z:58, sym:"Ce", name:"Cerium", mass:140.12, type:"lanthanide", c:5, r:8, desc:"ใช้ในหินเหล็กไฟของไฟแช็กและขัดผิวแก้ว" },
    { z:59, sym:"Pr", name:"Praseodymium", mass:140.91, type:"lanthanide", c:6, r:8, desc:"ใช้ผสมทำแว่นตากันแสงเชื่อมโลหะ" },
    { z:60, sym:"Nd", name:"Neodymium", mass:144.24, type:"lanthanide", c:7, r:8, desc:"ใช้ทำแม่เหล็กถาวรที่ทรงพลังที่สุด (Neodymium magnet)" },
    { z:61, sym:"Pm", name:"Promethium", mass:145, type:"lanthanide", c:8, r:8, desc:"ธาตุกัมมันตรังสี ใช้ในแบตเตอรี่พลังงานนิวเคลียร์ขนาดเล็ก" },
    { z:62, sym:"Sm", name:"Samarium", mass:150.36, type:"lanthanide", c:9, r:8, desc:"ใช้ทำแม่เหล็กทนความร้อนสูงในกีตาร์และหูฟัง" },
    { z:63, sym:"Eu", name:"Europium", mass:151.96, type:"lanthanide", c:10, r:8, desc:"ใช้ทำสารเรืองแสงสีแดงในหน้าจอโทรทัศน์และธนบัตรป้องกันการปลอม" },
    { z:64, sym:"Gd", name:"Gadolinium", mass:157.25, type:"lanthanide", c:11, r:8, desc:"ดูดซับนิวตรอนได้ดีมาก ใช้เป็นสารทึบรังสีในการทำ MRI" },
    { z:65, sym:"Tb", name:"Terbium", mass:158.93, type:"lanthanide", c:12, r:8, desc:"ใช้ทำสารเรืองแสงสีเขียวและอุปกรณ์โซนาร์ทหาร" },
    { z:66, sym:"Dy", name:"Dysprosium", mass:162.50, type:"lanthanide", c:13, r:8, desc:"ใช้ผสมในแม่เหล็กนีโอไดเมียมเพื่อให้ทนความร้อน" },
    { z:67, sym:"Ho", name:"Holmium", mass:164.93, type:"lanthanide", c:14, r:8, desc:"มีอำนาจแม่เหล็กสูงที่สุด ใช้สร้างสนามแม่เหล็กเทียม" },
    { z:68, sym:"Er", name:"Erbium", mass:167.26, type:"lanthanide", c:15, r:8, desc:"ใช้ผสมในใยแก้วนำแสงเพื่อขยายสัญญาณอินเทอร์เน็ต" },
    { z:69, sym:"Tm", name:"Thulium", mass:168.93, type:"lanthanide", c:16, r:8, desc:"แลนทาไนด์ที่หายากที่สุด ใช้ทำเครื่องเอกซเรย์ขนาดพกพา" },
    { z:70, sym:"Yb", name:"Ytterbium", mass:173.05, type:"lanthanide", c:17, r:8, desc:"ใช้ปรับปรุงคุณสมบัติของสแตนเลสและเลเซอร์" },
    { z:71, sym:"Lu", name:"Lutetium", mass:174.97, type:"lanthanide", c:18, r:8, desc:"แพงและหายาก ใช้ในการรักษามะเร็งแบบพุ่งเป้า" },
    { z:72, sym:"Hf", name:"Hafnium", mass:178.49, type:"transition", c:4, r:6, desc:"ใช้ในแท่งควบคุมเตาปฏิกรณ์นิวเคลียร์และชิปคอมพิวเตอร์" },
    { z:73, sym:"Ta", name:"Tantalum", mass:180.95, type:"transition", c:5, r:6, desc:"ทนการกัดกร่อนอย่างยิ่ง ใช้ทำชิ้นส่วนอิเล็กทรอนิกส์ในมือถือ" },
    { z:74, sym:"W", name:"Tungsten", mass:183.84, type:"transition", c:6, r:6, desc:"มีจุดหลอมเหลวสูงสุด (3422°C) ใช้ทำไส้หลอดไฟและหัวเจาะ" },
    { z:75, sym:"Re", name:"Rhenium", mass:186.21, type:"transition", c:7, r:6, desc:"เพิ่มความทนทานให้ใบพัดเครื่องยนต์เจ็ทของเครื่องบิน" },
    { z:76, sym:"Os", name:"Osmium", mass:190.23, type:"transition", c:8, r:6, desc:"ธาตุที่มีความหนาแน่นที่สุดในโลก เหม็นและเป็นพิษร้ายแรง" },
    { z:77, sym:"Ir", name:"Iridium", mass:192.22, type:"transition", c:9, r:6, desc:"ทนการกัดกร่อนที่สุด พบมากในอุกกาบาต (หลักฐานไดโนเสาร์สูญพันธุ์)" },
    { z:78, sym:"Pt", name:"Platinum", mass:195.08, type:"transition", c:10, r:6, desc:"แพลทินัม โลหะมีค่า ใช้ในเครื่องประดับและตัวเร่งปฏิกิริยา" },
    { z:79, sym:"Au", name:"Gold", mass:196.97, type:"transition", c:11, r:6, desc:"ทองคำ ไม่ทำปฏิกิริยาเกิดสนิม นำไฟฟ้าดีและมีค่ามาก" },
    { z:80, sym:"Hg", name:"Mercury", mass:200.59, type:"transition", c:12, r:6, desc:"ปรอท โลหะชนิดเดียวที่เป็นของเหลวที่อุณหภูมิห้อง เป็นพิษ" },
    { z:81, sym:"Tl", name:"Thallium", mass:204.38, type:"post-transition", c:13, r:6, desc:"โลหะเป็นพิษร้ายแรงมาก อดีตใช้ทำยาเบื่อหนู" },
    { z:82, sym:"Pb", name:"Lead", mass:207.2, type:"post-transition", c:14, r:6, desc:"ตะกั่ว โลหะหนักทึบรังสี ใช้บังรังสีเอกซเรย์" },
    { z:83, sym:"Bi", name:"Bismuth", mass:208.98, type:"post-transition", c:15, r:6, desc:"โลหะตกผลึกสวยงาม ใช้อยู่ในยารักษาโรคกระเพาะ" },
    { z:84, sym:"Po", name:"Polonium", mass:209, type:"metalloid", c:16, r:6, desc:"ธาตุกัมมันตรังสีหายาก ถูกค้นพบโดยมารี กูรี" },
    { z:85, sym:"At", name:"Astatine", mass:210, type:"halogen", c:17, r:6, desc:"ฮาโลเจนกัมมันตรังสี มีปริมาณน้อยมากในเปลือกโลก" },
    { z:86, sym:"Rn", name:"Radon", mass:222, type:"noble", c:18, r:6, desc:"ก๊าซเฉื่อยกัมมันตรังสี ซึมขึ้นมาจากดินและเป็นสาเหตุมะเร็งปอด" },
    { z:87, sym:"Fr", name:"Francium", mass:223, type:"alkali", c:1, r:7, desc:"โลหะอัลคาไลกัมมันตรังสี ไวต่อปฏิกิริยาที่สุดแต่สลายตัวเร็วมาก" },
    { z:88, sym:"Ra", name:"Radium", mass:226, type:"alkaline", c:2, r:7, desc:"เรืองแสงในที่มืด อดีตเคยใช้ทาหน้าปัดนาฬิกาก่อนรู้ว่าเป็นพิษ" },
    { z:89, sym:"Ac", name:"Actinium", mass:227, type:"actinide", c:4, r:9, desc:"ต้นกำเนิดกลุ่มแอกทิไนด์ เรืองแสงสีฟ้าอ่อนในที่มืด" },
    { z:90, sym:"Th", name:"Thorium", mass:232.04, type:"actinide", c:5, r:9, desc:"กัมมันตรังสีที่ใช้ทำไส้ตะเกียงแก๊ส และวิจัยพลังงานอนาคต" },
    { z:91, sym:"Pa", name:"Protactinium", mass:231.04, type:"actinide", c:6, r:9, desc:"สลายตัวเป็นแอกทิเนียม หายากและเป็นพิษมาก" },
    { z:92, sym:"U", name:"Uranium", mass:238.03, type:"actinide", c:7, r:9, desc:"เชื้อเพลิงหลักในโรงไฟฟ้านิวเคลียร์และอาวุธปรมาณู" },
    { z:93, sym:"Np", name:"Neptunium", mass:237, type:"actinide", c:8, r:9, desc:"ธาตุสังเคราะห์หลังยูเรเนียมตัวแรก ใช้เป็นเชื้อเพลิงอวกาศ" },
    { z:94, sym:"Pu", name:"Plutonium", mass:244, type:"actinide", c:9, r:9, desc:"เชื้อเพลิงนิวเคลียร์ทรงพลัง ถูกใช้ในระเบิดนิวเคลียร์" },
    { z:95, sym:"Am", name:"Americium", mass:243, type:"actinide", c:10, r:9, desc:"ใช้ปริมาณน้อยมากๆ ในเครื่องตรวจจับควันตามบ้าน" },
    { z:96, sym:"Cm", name:"Curium", mass:247, type:"actinide", c:11, r:9, desc:"ตั้งชื่อตามมารี กูรี ใช้สร้างพลังงานให้ยานสำรวจดาวอังคาร" },
    { z:97, sym:"Bk", name:"Berkelium", mass:247, type:"actinide", c:12, r:9, desc:"ธาตุสังเคราะห์ สร้างขึ้นที่ห้องทดลองเบิร์กลีย์" },
    { z:98, sym:"Cf", name:"Californium", mass:251, type:"actinide", c:13, r:9, desc:"สร้างนิวตรอนได้ดี ใช้จุดระเบิดเตาปฏิกรณ์และหาน้ำมัน" },
    { z:99, sym:"Es", name:"Einsteinium", mass:252, type:"actinide", c:14, r:9, desc:"ตั้งชื่อตามไอน์สไตน์ ถูกค้นพบจากซากการทดลองระเบิดไฮโดรเจน" },
    { z:100, sym:"Fm", name:"Fermium", mass:257, type:"actinide", c:15, r:9, desc:"ธาตุสังเคราะห์มวลหนักที่สุดที่เกิดจากการยิงนิวตรอน" },
    { z:101, sym:"Md", name:"Mendelevium", mass:258, type:"actinide", c:16, r:9, desc:"ตั้งชื่อตามเมนเดเลเยฟ (บิดาแห่งตารางธาตุ)" },
    { z:102, sym:"No", name:"Nobelium", mass:259, type:"actinide", c:17, r:9, desc:"ธาตุสังเคราะห์อายุสั้นมาก ตั้งชื่อตามอัลเฟรด โนเบล" },
    { z:103, sym:"Lr", name:"Lawrencium", mass:266, type:"actinide", c:18, r:9, desc:"ธาตุสุดท้ายของกลุ่มแอกทิไนด์" },
    { z:104, sym:"Rf", name:"Rutherfordium", mass:267, type:"transition", c:4, r:7, desc:"ธาตุสังเคราะห์มวลหนักมาก อายุสั้น" },
    { z:105, sym:"Db", name:"Dubnium", mass:268, type:"transition", c:5, r:7, desc:"ธาตุสังเคราะห์ ตั้งชื่อตามศูนย์วิจัยดุบนาในรัสเซีย" },
    { z:106, sym:"Sg", name:"Seaborgium", mass:269, type:"transition", c:6, r:7, desc:"ตั้งชื่อตามเกลนน์ ซีบอร์ก นักเคมีที่ยังมีชีวิตอยู่ตอนตั้งชื่อ" },
    { z:107, sym:"Bh", name:"Bohrium", mass:270, type:"transition", c:7, r:7, desc:"ตั้งชื่อตามนีลส์ บอร์ นักฟิสิกส์ควอนตัม" },
    { z:108, sym:"Hs", name:"Hassium", mass:277, type:"transition", c:8, r:7, desc:"ธาตุสังเคราะห์ อายุสั้นเพียงไม่กี่วินาที" },
    { z:109, sym:"Mt", name:"Meitnerium", mass:278, type:"transition", c:9, r:7, desc:"ตั้งชื่อตามลิซ ไมต์เนอร์ ผู้บุกเบิกฟิสิกส์นิวเคลียร์" },
    { z:110, sym:"Ds", name:"Darmstadtium", mass:281, type:"transition", c:10, r:7, desc:"สร้างโดยการยิงไอออนนิกเกิลใส่ตะกั่ว" },
    { z:111, sym:"Rg", name:"Roentgenium", mass:282, type:"transition", c:11, r:7, desc:"ตั้งชื่อตามวิลเฮล์ม เรินต์เกน ผู้ค้นพบรังสีเอกซ์" },
    { z:112, sym:"Cn", name:"Copernicium", mass:285, type:"transition", c:12, r:7, desc:"ตั้งชื่อตามนิโคเลาส์ โคเปอร์นิคัส" },
    { z:113, sym:"Nh", name:"Nihonium", mass:286, type:"post-transition", c:13, r:7, desc:"ธาตุแรกที่ค้นพบโดยนักวิทยาศาสตร์เอเชีย (ญี่ปุ่น)" },
    { z:114, sym:"Fl", name:"Flerovium", mass:289, type:"post-transition", c:14, r:7, desc:"ธาตุสังเคราะห์ อยู่ในบริเวณ 'เกาะแห่งความเสถียร'" },
    { z:115, sym:"Mc", name:"Moscovium", mass:290, type:"post-transition", c:15, r:7, desc:"ตั้งชื่อตามกรุงมอสโก รัสเซีย" },
    { z:116, sym:"Lv", name:"Livermorium", mass:293, type:"post-transition", c:16, r:7, desc:"สังเคราะห์ที่ศูนย์ลอว์เรนซ์ลิเวอร์มอร์ สหรัฐฯ" },
    { z:117, sym:"Ts", name:"Tennessine", mass:294, type:"halogen", c:17, r:7, desc:"ฮาโลเจนสังเคราะห์ที่หนักที่สุด" },
    { z:118, sym:"Og", name:"Oganesson", mass:294, type:"noble", c:18, r:7, desc:"ธาตุหนักที่สุดในตารางธาตุ คาดว่าเป็นก๊าซเฉื่อยแต่เป็นของแข็ง" }
];

class AudioManager {
    constructor() {
        this.muted = true;

        // ── ไฟล์เสียงจริง (ถ้ามี) ────────────────────────────
        this.soundPaths = {
            click:              'assets/sounds/click.mp3',
            pour_liquid_thin:   'assets/sounds/pour_liquid.mp3',
            pour_liquid_thick:  'assets/sounds/pour_liquid.mp3',
            pour_solid:         'assets/sounds/pour_solid.mp3',
            pour_metal:         'assets/sounds/glass_tap.mp3',
            wipe:               'assets/sounds/wipe.mp3',
            boil:               'assets/sounds/boil.mp3',
            dissolve:           'assets/sounds/dissolve.mp3',
            reaction_mild:      'assets/sounds/reaction_mild.mp3',
            reaction_strong:    'assets/sounds/reaction_strong.mp3',
            explosion:          'assets/sounds/explosion.mp3',
            success:            'assets/sounds/success.mp3',
            heater_on:          'assets/sounds/heater_click.mp3',
            heater_off:         'assets/sounds/heater_click.mp3',
            stirring:           'assets/sounds/stir.mp3',
            toxic_warning:      'assets/sounds/toxic_warning.mp3',
            glass_tap:          'assets/sounds/glass_tap.mp3',
            // legacy aliases
            drop_solid:         'assets/sounds/pour_solid.mp3',
            drop_liquid:        'assets/sounds/pour_liquid.mp3',
            pour:               'assets/sounds/pour_liquid.mp3',
        };

        this.sounds    = {};  // Audio element cache
        this.loopSounds = new Set(['boil', 'stirring']);

        // Web Audio context สำหรับ synthesize เสียงถ้าไม่มีไฟล์
        this._ctx = null;
        this._gainNode = null;
        this._activeNodes = {};  // loop nodes ที่กำลัง play อยู่
    }

    // ── lazy-init Web Audio Context ───────────────────────────
    _getCtx() {
        if (!this._ctx) {
            try {
                this._ctx = new (window.AudioContext || window.webkitAudioContext)();
                this._gainNode = this._ctx.createGain();
                this._gainNode.gain.value = 0.35;
                this._gainNode.connect(this._ctx.destination);
            } catch(e) { this._ctx = null; }
        }
        return this._ctx;
    }

    // ── Web Audio Synthesizer — ถ้าไม่มีไฟล์ .mp3 ────────────
    _synth(type) {
        const ctx = this._getCtx();
        if (!ctx) return;
        if (ctx.state === 'suspended') ctx.resume();

        const now = ctx.currentTime;

        switch(type) {
            case 'click':
            case 'heater_on':
            case 'heater_off': {
                // เสียง click กลิ้งเล็กน้อย
                const o = ctx.createOscillator();
                const g = ctx.createGain();
                o.connect(g); g.connect(this._gainNode);
                o.frequency.setValueAtTime(800, now);
                o.frequency.exponentialRampToValueAtTime(400, now + 0.06);
                g.gain.setValueAtTime(0.4, now);
                g.gain.exponentialRampToValueAtTime(0.001, now + 0.08);
                o.start(now); o.stop(now + 0.1);
                break;
            }
            case 'pour_liquid_thin':
            case 'drop_liquid':
            case 'pour': {
                // เสียงน้ำไหล — white noise burst สั้น
                const buf = ctx.createBuffer(1, ctx.sampleRate * 0.4, ctx.sampleRate);
                const data = buf.getChannelData(0);
                for (let i = 0; i < data.length; i++) data[i] = (Math.random() * 2 - 1) * Math.exp(-i / (ctx.sampleRate * 0.15));
                const src = ctx.createBufferSource();
                src.buffer = buf;
                const filt = ctx.createBiquadFilter();
                filt.type = 'bandpass'; filt.frequency.value = 1800; filt.Q.value = 0.5;
                const g = ctx.createGain();
                g.gain.setValueAtTime(0.5, now);
                g.gain.exponentialRampToValueAtTime(0.001, now + 0.35);
                src.connect(filt); filt.connect(g); g.connect(this._gainNode);
                src.start(now); src.stop(now + 0.4);
                break;
            }
            case 'pour_liquid_thick': {
                // เสียงกาวเท — glug glug
                for (let i = 0; i < 3; i++) {
                    const o = ctx.createOscillator();
                    const g = ctx.createGain();
                    o.type = 'sine';
                    o.frequency.setValueAtTime(220 - i*20, now + i*0.12);
                    o.frequency.exponentialRampToValueAtTime(110, now + i*0.12 + 0.1);
                    g.gain.setValueAtTime(0.35, now + i*0.12);
                    g.gain.exponentialRampToValueAtTime(0.001, now + i*0.12 + 0.12);
                    o.connect(g); g.connect(this._gainNode);
                    o.start(now + i*0.12); o.stop(now + i*0.12 + 0.15);
                }
                break;
            }
            case 'pour_solid':
            case 'drop_solid': {
                // เสียงผงหรือเม็ดตก — noise granular
                const buf = ctx.createBuffer(1, ctx.sampleRate * 0.25, ctx.sampleRate);
                const data = buf.getChannelData(0);
                for (let i = 0; i < data.length; i++) {
                    const env = Math.exp(-i / (ctx.sampleRate * 0.05));
                    data[i] = (Math.random() * 2 - 1) * env * 0.6;
                }
                const src = ctx.createBufferSource();
                src.buffer = buf;
                const filt = ctx.createBiquadFilter();
                filt.type = 'highpass'; filt.frequency.value = 3000;
                src.connect(filt); filt.connect(this._gainNode);
                src.start(now); src.stop(now + 0.25);
                break;
            }
            case 'pour_metal':
            case 'glass_tap': {
                // เสียงโลหะกระทบแก้ว — ping
                const o = ctx.createOscillator();
                const g = ctx.createGain();
                o.type = 'sine';
                o.frequency.setValueAtTime(1200, now);
                o.frequency.exponentialRampToValueAtTime(900, now + 0.3);
                g.gain.setValueAtTime(0.5, now);
                g.gain.exponentialRampToValueAtTime(0.001, now + 0.4);
                o.connect(g); g.connect(this._gainNode);
                o.start(now); o.stop(now + 0.45);
                break;
            }
            case 'dissolve': {
                // เสียงฟู่เบาๆ — soft noise
                const buf = ctx.createBuffer(1, ctx.sampleRate * 0.5, ctx.sampleRate);
                const data = buf.getChannelData(0);
                for (let i = 0; i < data.length; i++) {
                    const env = Math.min(i / (ctx.sampleRate * 0.05), 1) * Math.exp(-i / (ctx.sampleRate * 0.3));
                    data[i] = (Math.random() * 2 - 1) * env * 0.3;
                }
                const src = ctx.createBufferSource();
                src.buffer = buf;
                const filt = ctx.createBiquadFilter();
                filt.type = 'bandpass'; filt.frequency.value = 2500; filt.Q.value = 1.5;
                src.connect(filt); filt.connect(this._gainNode);
                src.start(now); src.stop(now + 0.5);
                break;
            }
            case 'reaction_mild':
            case 'success': {
                // เสียงฟู่ + chord สั้น
                [0, 0.08, 0.16].forEach((t, i) => {
                    const o = ctx.createOscillator();
                    const g = ctx.createGain();
                    o.type = 'sine';
                    o.frequency.value = [523, 659, 784][i];
                    g.gain.setValueAtTime(0.2, now + t);
                    g.gain.exponentialRampToValueAtTime(0.001, now + t + 0.25);
                    o.connect(g); g.connect(this._gainNode);
                    o.start(now + t); o.stop(now + t + 0.3);
                });
                break;
            }
            case 'reaction_strong': {
                // เสียงปฏิกิริยารุนแรง — noise burst ใหญ่
                const buf = ctx.createBuffer(1, ctx.sampleRate * 0.6, ctx.sampleRate);
                const data = buf.getChannelData(0);
                for (let i = 0; i < data.length; i++) {
                    const env = Math.exp(-i / (ctx.sampleRate * 0.1));
                    data[i] = (Math.random() * 2 - 1) * env;
                }
                const src = ctx.createBufferSource();
                src.buffer = buf;
                const filt = ctx.createBiquadFilter();
                filt.type = 'lowpass'; filt.frequency.value = 1200;
                const g = ctx.createGain();
                g.gain.value = 0.6;
                src.connect(filt); filt.connect(g); g.connect(this._gainNode);
                src.start(now); src.stop(now + 0.6);
                break;
            }
            case 'explosion': {
                // เสียงระเบิด — deep boom + noise
                const buf = ctx.createBuffer(1, ctx.sampleRate * 1.5, ctx.sampleRate);
                const data = buf.getChannelData(0);
                for (let i = 0; i < data.length; i++) {
                    const env = Math.exp(-i / (ctx.sampleRate * 0.3));
                    data[i] = (Math.random() * 2 - 1) * env;
                }
                const src = ctx.createBufferSource();
                src.buffer = buf;
                const o = ctx.createOscillator();
                const og = ctx.createGain();
                o.type = 'sine'; o.frequency.setValueAtTime(80, now);
                o.frequency.exponentialRampToValueAtTime(20, now + 0.8);
                og.gain.setValueAtTime(0.8, now);
                og.gain.exponentialRampToValueAtTime(0.001, now + 1.0);
                const filt = ctx.createBiquadFilter();
                filt.type = 'lowpass'; filt.frequency.value = 500;
                const g = ctx.createGain(); g.gain.value = 0.7;
                src.connect(filt); filt.connect(g); g.connect(this._gainNode);
                o.connect(og); og.connect(this._gainNode);
                src.start(now); o.start(now); src.stop(now + 1.5); o.stop(now + 1.2);
                break;
            }
            case 'boil': {
                // เสียงเดือด loop — ใช้ BufferSource loop (แทน ScriptProcessor ที่ deprecated)
                if (this._activeNodes['boil']) return;
                const sr = ctx.sampleRate;
                const dur = 1.0; // วินาที ต่อ buffer
                const buf = ctx.createBuffer(1, sr * dur, sr);
                const data = buf.getChannelData(0);
                for (let i = 0; i < data.length; i++) data[i] = (Math.random() * 2 - 1);
                const src = ctx.createBufferSource();
                src.buffer = buf; src.loop = true;
                const filt = ctx.createBiquadFilter();
                filt.type = 'bandpass'; filt.frequency.value = 600; filt.Q.value = 0.3;
                const g = ctx.createGain(); g.gain.value = 0.12;
                src.connect(filt); filt.connect(g); g.connect(this._gainNode);
                src.start();
                this._activeNodes['boil'] = { src, filt, g };
                break;
            }
            case 'stirring': {
                // เสียงกวน loop — noise ความถี่ต่ำ (ใช้ BufferSource แทน ScriptProcessor)
                if (this._activeNodes['stirring']) return;
                const sr = ctx.sampleRate;
                const dur = 1.0;
                const buf = ctx.createBuffer(1, sr * dur, sr);
                const data = buf.getChannelData(0);
                for (let i = 0; i < data.length; i++) {
                    data[i] = (Math.random() * 2 - 1) * (Math.sin(i * 0.05 * (2 * Math.PI / sr)) * 0.5 + 0.5);
                }
                const src = ctx.createBufferSource();
                src.buffer = buf; src.loop = true;
                const filt = ctx.createBiquadFilter();
                filt.type = 'bandpass'; filt.frequency.value = 300; filt.Q.value = 2;
                const g = ctx.createGain(); g.gain.value = 0.08;
                src.connect(filt); filt.connect(g); g.connect(this._gainNode);
                src.start();
                this._activeNodes['stirring'] = { src, filt, g };
                break;
            }
            case 'toxic_warning': {
                // เสียงเตือน — ไซเรนสั้น
                [0, 0.25].forEach(t => {
                    const o = ctx.createOscillator();
                    const g = ctx.createGain();
                    o.type = 'square';
                    o.frequency.setValueAtTime(880, now + t);
                    o.frequency.exponentialRampToValueAtTime(440, now + t + 0.2);
                    g.gain.setValueAtTime(0.25, now + t);
                    g.gain.exponentialRampToValueAtTime(0.001, now + t + 0.22);
                    o.connect(g); g.connect(this._gainNode);
                    o.start(now + t); o.stop(now + t + 0.25);
                });
                break;
            }
            case 'wipe': {
                // เสียงล้าง — noise sweep
                const buf = ctx.createBuffer(1, ctx.sampleRate * 0.4, ctx.sampleRate);
                const data = buf.getChannelData(0);
                for (let i = 0; i < data.length; i++) {
                    const t2 = i / ctx.sampleRate;
                    data[i] = (Math.random() * 2 - 1) * Math.sin(Math.PI * t2 / 0.4) * 0.5;
                }
                const src = ctx.createBufferSource();
                src.buffer = buf;
                const filt = ctx.createBiquadFilter();
                filt.type = 'bandpass'; filt.frequency.setValueAtTime(3000, now);
                filt.frequency.linearRampToValueAtTime(800, now + 0.4);
                src.connect(filt); filt.connect(this._gainNode);
                src.start(now); src.stop(now + 0.4);
                break;
            }
        }
    }

    _stopSynth(type) {
        const node = this._activeNodes[type];
        if (!node) return;
        try {
            if (node.src)  { node.src.stop(); node.src.disconnect(); }
            if (node.proc) { node.proc.disconnect(); } // legacy fallback
            if (node.filt) node.filt.disconnect();
            if (node.g)    node.g.disconnect();
        } catch(e) {}
        delete this._activeNodes[type];
    }

    // ── Public API ─────────────────────────────────────────────
    play(type) {
        if (this.muted) return;

        const ctx = this._getCtx();
        if (ctx && ctx.state === 'suspended') ctx.resume();

        const path = this.soundPaths[type];
        if (!path) { this._synth(type); return; }

        // ถ้าเคยตรวจสอบแล้ว
        if (this.sounds[type] === null) {
            // ไม่มีไฟล์ — ใช้ synth
            this._synth(type);
            return;
        }
        if (this.sounds[type] instanceof Audio) {
            // มีไฟล์แล้ว — play ทันที
            this.sounds[type].currentTime = 0;
            const p = this.sounds[type].play();
            if (p !== undefined) p.catch(() => this._synth(type));
            return;
        }

        // ยังไม่เคยตรวจ — probe ด้วย fetch HEAD ก่อน (ไม่ทำให้เกิด 404 noise ใน console)
        fetch(path, { method: 'HEAD' })
            .then(r => {
                if (!r.ok) throw new Error('not found');
                const audio = new Audio(path);
                if (this.loopSounds.has(type)) audio.loop = true;
                this.sounds[type] = audio;
                audio.currentTime = 0;
                const p = audio.play();
                if (p !== undefined) p.catch(() => this._synth(type));
            })
            .catch(() => {
                this.sounds[type] = null;  // mark ว่าไม่มีไฟล์
                this._synth(type);
            });
    }

    stop(type) {
        if (this.sounds[type]) {
            this.sounds[type].pause();
            this.sounds[type].currentTime = 0;
        }
        this._stopSynth(type);
    }

    toggleMute(btnEl) {
        this.muted = !this.muted;
        if (this.muted) {
            this.stop('boil');
            this.stop('stirring');
            if (btnEl) { btnEl.classList.add('muted'); btnEl.innerHTML = '🔇 ปิดเสียง'; }
        } else {
            if (btnEl) { btnEl.classList.remove('muted'); btnEl.innerHTML = '🔊 เปิดเสียง'; }
            // resume AudioContext เมื่อ user interact
            const ctx = this._getCtx();
            if (ctx && ctx.state === 'suspended') ctx.resume();
        }
    }

    // ── เลือกเสียงเทตามชนิดสาร ───────────────────────────────
    playPour(state, type, viscosity) {
        if (state === 'solid') {
            if (type === 'metal') return this.play('pour_metal');
            return this.play('pour_solid');
        }
        // liquid
        if (viscosity > 0.5) return this.play('pour_liquid_thick');
        return this.play('pour_liquid_thin');
    }

    // ── เลือกเสียง reaction ตาม type ─────────────────────────
    playReaction(rxnType, isExplosive) {
        if (isExplosive) return this.play('explosion');
        const strong = ['metal_water', 'hazardous_mix', 'acid_metal'];
        if (strong.includes(rxnType)) return this.play('reaction_strong');
        return this.play('reaction_mild');
    }
}

class VirtualLabEngine {
    constructor() {
        this.config = window.LAB_CONFIG; 
        this.audio = new AudioManager();
        
        this.questSettings = {};
        if (!this.config.isAdmin) {
            try {
                let rawData = document.getElementById('labSettingsData').innerText;
                if(rawData && rawData.trim() !== '') this.questSettings = JSON.parse(rawData);
            } catch(e) {}
        }

        this.state = {
            hp: 100, volume: 0, maxVolume: 200, solidWeight: 0, tareOffset: 0,
            temperature: 25.0, targetTemp: 25.0, currentBP: 100.0, 
            isHeating: false, isStirring: false, isExploded: false,
            contents: [], activeTool: { id: null, name: '', type:'', state: '', color: '', amount: 0.0, bp: 100.0, formula: '' },
            wavePhase: 0, waveAmplitude: 0, targetWaveAmplitude: 0, 
            bubbles: [], precipitates: [], currentColor: [56, 189, 248, 0.1], targetColor: [56, 189, 248, 0.1], currentPH: 7.0,
            telemetry: { spillCount: 0, safetyViolations: 0, didStir: false, reactionProcessed: false, boilingExplosions: 0, chemicalExplosions: 0, toxicExposures: 0 }
        };

        this.reactionCooldown = 0;
        this.eqTimeout = null; 
        
        this.getEl = (id) => document.getElementById(id);
        
        this.ui = {
            logZone: this.getEl('labLog'), searchBox: this.getEl('chemSearch'), draggables: document.querySelectorAll('.chem-draggable'),
            beaker: this.getEl('beakerObj'), beakerDropzone: this.getEl('beakerDropzone'), liquidCanvas: this.getEl('liquidCanvas'), 
            scaleObj: this.getEl('scaleObj'), scaleDisplay: this.getEl('scaleDisplay'), scalePowder: this.getEl('scalePowder'),
            valTemp: this.getEl('valTemp'), thermoBar: this.getEl('thermoBar'), flameFire: this.getEl('flameFire'), 
            stirRod: this.getEl('stirRod'), explosionOverlay: this.getEl('explosionOverlay'), btnRepair: this.getEl('btnRepairBeaker'),
            btnWash: this.getEl('btnWash'), btnHeater: this.getEl('btnHeater'), btnStir: this.getEl('btnStir'), 
            btnProcess: this.getEl('btnProcess'), btnUndo: this.getEl('btnUndo'), btnAudio: this.getEl('btnToggleAudio'), btnMeasurePH: this.getEl('btnMeasurePH'),
            btnPeriodic: this.getEl('btnPeriodicTable'), ptModal: this.getEl('ptModal'), ptBox: this.getEl('ptBox'), 
            btnClosePT: this.getEl('btnClosePT'), periodicGrid: this.getEl('periodicGrid'), elDetailCard: this.getEl('elDetailCard'),
            btnAddPTElement: this.getEl('btnAddPTElement'), toolboxModal: this.getEl('toolboxModal'), toolboxBox: this.getEl('toolboxBox'), 
            btnCloseToolbox: this.getEl('btnCloseToolbox'), tbChemName: this.getEl('tbChemName'), tbInputUnit: this.getEl('tbInputUnit'),
            tbInputAmt: this.getEl('tbInputAmt'), btnConfirmPour: this.getEl('btnConfirmPour'), addAmtBtns: document.querySelectorAll('.btn-add-amt'),
            phModal: this.getEl('phModal'), phBox: this.getEl('phBox'), phStrip: this.getEl('phStrip'), 
            phResultTxt: this.getEl('phResultTxt'), phStatusTxt: this.getEl('phStatusTxt'),
            chkGoggles: this.getEl('chkGoggles'), chkGloves: this.getEl('chkGloves'), safetyBox: this.getEl('safetyBox'),
            ldVol: this.getEl('ld-vol'), ldMass: this.getEl('ld-mass'), ldPh: this.getEl('ld-ph'), 
            ldSubs: this.getEl('ld-subs'), ldBp: this.getEl('ld-bp'), questHUD: this.getEl('questHUD'), 
            hudChecklist: this.getEl('hudChecklist'), btnSubmit: this.getEl('btnSubmit'), btnTempUp: this.getEl('btnTempUp'), 
            btnTempDown: this.getEl('btnTempDown'), btnDevTools: this.getEl('btnDevTools'), devModal: this.getEl('devModal'), 
            devBox: this.getEl('devBox'), btnCloseDev: this.getEl('btnCloseDev'), devJsonOutput: this.getEl('devJsonOutput'), 
            btnCopyJson: this.getEl('btnCopyJson'), eqBoard: this.getEl('eqBoard'), eqText: this.getEl('eqText'),
            eqDesc: this.getEl('eqDesc'), formulaDisplays: document.querySelectorAll('.formula-display')
        };

        this.mobileDragClone = null;
        this.isBoilSoundPlaying = false;
        
        this.colorParserCtx = document.createElement('canvas').getContext('2d');
        this.ctx = this.ui.liquidCanvas ? this.ui.liquidCanvas.getContext('2d') : null;

        this.init();
    }

    init() {
        let roleName = this.config.isAdmin ? '<span style="color:#ec4899">ผู้สอน (Admin God Mode)</span>' : '<span style="color:#38bdf8">นักเรียน (Student Mode)</span>';
        this.log(`🚀 โหลด Unified Engine [Final Masterpiece] โหมด: ${roleName} สำเร็จ`);
        
        this.formatAllFormulas();
        this.renderPeriodicTable();
        this.bindEvents(); 
        this.bindDragAndDrop();
        
        if (this.config.isAdmin) {
            this.updateLiveDataAdmin();
        } else {
            this.renderStudentQuestHUD();
            this.checkStudentQuestProgress();
            // Autosave quest progress ทุก 30 วิ ป้องกัน browser crash
            this._startAutosave();
        }
        
        this.startPhysicsLoop();
    }

    // ── Autosave: บันทึก quest progress ลง localStorage ────
    _startAutosave() {
        // restore ถ้ามีข้อมูลค้างอยู่
        try {
            const key = `lab_progress_${this.config.assignmentId}_${document.cookie.match(/PHPSESSID=([^;]+)/)?.[1] || 'anon'}`;
            const saved = localStorage.getItem(key);
            if (saved) {
                const parsed = JSON.parse(saved);
                // restore เฉพาะ telemetry (ไม่ restore beaker เพราะอาจสับสน)
                if (parsed.telemetry) {
                    Object.assign(this.state.telemetry, parsed.telemetry);
                    this.log('💾 <span style="color:#94a3b8;">โหลด progress ที่บันทึกไว้สำเร็จ</span>');
                }
            }
        } catch(e) { /* ถ้า localStorage ไม่ support ก็ข้ามได้ */ }

        // save ทุก 30 วิ — localStorage + heartbeat to server
        setInterval(() => {
            // 1. localStorage (offline fallback)
            try {
                const key = `lab_progress_${this.config.assignmentId}_${document.cookie.match(/PHPSESSID=([^;]+)/)?.[1] || 'anon'}`;
                localStorage.setItem(key, JSON.stringify({
                    telemetry: this.state.telemetry,
                    hp: this.state.hp,
                    ts: Date.now(),
                }));
            } catch(e) { /* silent fail */ }

            // 2. Server heartbeat พร้อม lab state
            fetch('api_heartbeat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    page: window.location.pathname,
                    lab_state: { hp: this.state.hp, telemetry: this.state.telemetry },
                }),
            }).catch(() => {});
        }, 30000);
    }

    // ── Safety Toast: แสดง warning แดงชั่วคราว ─────────────
    _showSafetyToast(message) {
        if (!message) return;
        // ลบ toast เดิมถ้ามี
        const old = document.getElementById('safetyToast');
        if (old) old.remove();

        const toast = document.createElement('div');
        toast.id = 'safetyToast';
        toast.style.cssText = `
            position:fixed; top:80px; left:50%; transform:translateX(-50%);
            background:#7f1d1d; border:2px solid var(--danger); color:#fca5a5;
            padding:12px 20px; border-radius:12px; z-index:9999;
            font-family:'Prompt',sans-serif; font-size:0.9rem; font-weight:bold;
            max-width:400px; text-align:center;
            box-shadow:0 8px 30px rgba(239,68,68,0.5);
            animation: toastIn 0.3s ease;
        `;
        toast.innerHTML = `⛔ ${message}`;
        document.body.appendChild(toast);

        // fade out หลัง 4 วิ
        setTimeout(() => {
            toast.style.transition = 'opacity 0.5s';
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 500);
        }, 4000);
    }

    formatFormula(str) {
        if (!str || str === '') return '';
        return str.replace(/([a-zA-Z])(\d+)/g, "$1<sub>$2</sub>");
    }

    formatAllFormulas() {
        if (this.ui.formulaDisplays) {
            this.ui.formulaDisplays.forEach(el => {
                let rawText = el.innerText;
                el.innerHTML = this.formatFormula(rawText);
            });
        }
    }

    showEquation(equationStr, descriptionStr) {
        if (this.ui.eqBoard && this.ui.eqText && this.ui.eqDesc) {
            this.ui.eqText.innerHTML = this.formatFormula(equationStr);
            this.ui.eqDesc.innerText = descriptionStr;
            this.ui.eqBoard.classList.add('show');
            clearTimeout(this.eqTimeout);
            this.eqTimeout = setTimeout(() => {
                this.ui.eqBoard.classList.remove('show');
            }, 6000); 
        }
    }

    log(message) {
        if (!this.ui.logZone) return;
        const now = new Date();
        const timeStr = now.getHours().toString().padStart(2, '0') + ':' + now.getMinutes().toString().padStart(2, '0');
        const entry = document.createElement('div');
        entry.className = 'log-entry';
        let prefix = this.config.isAdmin ? '<span style="color:var(--dev-color); font-weight:bold;">[SYS]</span> ' : '';
        let formattedMessage = message.replace(/([a-zA-Z])(\d+)/g, "$1<sub>$2</sub>");
        entry.innerHTML = `<span class="log-time">[${timeStr}]</span> ${prefix}${formattedMessage}`;
        this.ui.logZone.insertBefore(entry, this.ui.logZone.firstChild);
        this.ui.logZone.scrollTop = this.ui.logZone.scrollHeight;
    }

    takeDamage(amount, reason, type = 'normal') {
        if (this.config.isAdmin) return; 
        if (this.state.hp <= 0) return;
        this.state.hp -= amount;
        if (this.state.hp < 0) this.state.hp = 0;
        let hpVal = this.getEl('hpValueTxt');
        let hpBar = this.getEl('hpBarFill');
        let hpCon = this.getEl('hpContainer');
        if (hpVal) {
            hpVal.innerText = this.state.hp + '%';
            hpBar.style.width = this.state.hp + '%';
            hpCon.classList.add('hp-shake');
            setTimeout(() => hpCon.classList.remove('hp-shake'), 400);
            if (this.state.hp < 50) hpBar.style.background = 'var(--warning)';
            if (this.state.hp < 30) hpBar.style.background = 'var(--danger)';
        }
        if (this.ui.explosionOverlay) {
            if (type === 'poison') this.ui.explosionOverlay.className = 'explosion-overlay flash-green';
            else if (type === 'chem' || type === 'boil') this.ui.explosionOverlay.className = 'explosion-overlay flash-red';
            setTimeout(()=> { this.ui.explosionOverlay.className = 'explosion-overlay'; }, 800);
        }
        this.log(`<span style='color:${type==='poison'?'var(--poison)':'var(--danger)'}'>[Damage] -${amount} HP : ${reason}</span>`);
        this.checkStudentQuestProgress();
    }

    updateLiveDataAdmin() {
        if (!this.config.isAdmin) return;
        if(this.ui.ldVol) this.ui.ldVol.innerText = `${this.state.volume.toFixed(1)} ml`;
        if(this.ui.ldMass) this.ui.ldMass.innerText = `${this.state.solidWeight.toFixed(1)} g`;
        if(this.ui.ldBp) this.ui.ldBp.innerText = `${this.state.currentBP.toFixed(1)} °C`;
        if(this.ui.ldSubs) {
            if(this.state.contents.length === 0) {
                this.ui.ldSubs.innerHTML = "<span style='color:#64748b'>Empty Beaker</span>";
            } else {
                let subsHTML = "";
                this.state.contents.forEach(c => {
                    let icon = c.state === 'solid' ? '🧊' : '💧';
                    if(c.state === 'gas') icon = '💨';
                    if(c.type === 'product') icon = '✨';
                    // data-beaker-chem → hover tooltip ใน Step 2
                    let dissolveStatus = c.state === 'solid' ? `<span style="font-size:0.7rem; color:#94a3b8;"> (ละลายแล้ว: ${c.dissolvedAmt.toFixed(1)}g)</span>` : "";
                    let nameDisplay = this.formatFormula(c.name);
                    subsHTML += `<div style="margin-top:4px; padding:4px; background:rgba(255,255,255,0.05); border-radius:4px;">${icon} ${nameDisplay} <span style="float:right; color:var(--text-muted);">${c.amount.toFixed(1)} ${c.state==='solid'?'g':'ml'}</span><br>${dissolveStatus}</div>`;
                });
                this.ui.ldSubs.innerHTML = subsHTML;
            }
        }
    }

    renderStudentQuestHUD() {
        if (this.config.isAdmin || !this.ui.hudChecklist) return;
        this.ui.hudChecklist.innerHTML = '';
        const set = this.questSettings;
        if (Object.keys(set).length === 0) {
            this.ui.hudChecklist.innerHTML = '<li class="qh-item"><span class="qh-icon">💡</span> โหมดทดลองอิสระ (ไม่มีภารกิจ)</li>';
            return;
        }
        const dict = this.config.chemDictionary || {};
        if (set.target_chem1) {
            let name1 = dict[set.target_chem1] || 'สารเคมีปริศนา (Chem 1)';
            let amt1 = set.amount_chem1 ? ` ${set.amount_chem1} ml/g` : '';
            this.ui.hudChecklist.innerHTML += `<li class="qh-item pending" id="chk_chem1"><span class="qh-icon">⬜</span> เตรียม ${this.formatFormula(name1)}${amt1}</li>`;
        }
        if (set.target_chem2) {
            let name2 = dict[set.target_chem2] || 'สารเคมีปริศนา (Chem 2)';
            let amt2 = set.amount_chem2 ? ` ${set.amount_chem2} ml/g` : '';
            this.ui.hudChecklist.innerHTML += `<li class="qh-item pending" id="chk_chem2"><span class="qh-icon">⬜</span> ผสม ${this.formatFormula(name2)}${amt2}</li>`;
        }
        if (set.safety_goggles) this.ui.hudChecklist.innerHTML += `<li class="qh-item pending" id="chk_goggles"><span class="qh-icon">⬜</span> สวมแว่นตานิรภัย</li>`;
        if (set.safety_gloves) this.ui.hudChecklist.innerHTML += `<li class="qh-item pending" id="chk_gloves"><span class="qh-icon">⬜</span> สวมถุงมือยาง</li>`;
        if (set.required_stirring) this.ui.hudChecklist.innerHTML += `<li class="qh-item pending" id="chk_stir"><span class="qh-icon">⬜</span> คนสารให้เข้ากัน</li>`;
    }

    checkStudentQuestProgress() {
        if (this.config.isAdmin || !this.questSettings) return;
        const set = this.questSettings;
        let allDone = true;
        if (set.safety_goggles) {
            let isDone = this.ui.chkGoggles && this.ui.chkGoggles.checked;
            let el = this.getEl('chk_goggles'); if (el) el.className = isDone ? 'qh-item done' : 'qh-item pending';
            if(el && isDone) el.innerHTML = el.innerHTML.replace('⬜', '✅'); else if(el) el.innerHTML = el.innerHTML.replace('✅', '⬜');
            if (!isDone) allDone = false;
        }
        if (set.safety_gloves) {
            let isDone = this.ui.chkGloves && this.ui.chkGloves.checked;
            let el = this.getEl('chk_gloves'); if (el) el.className = isDone ? 'qh-item done' : 'qh-item pending';
            if(el && isDone) el.innerHTML = el.innerHTML.replace('⬜', '✅'); else if(el) el.innerHTML = el.innerHTML.replace('✅', '⬜');
            if (!isDone) allDone = false;
        }
        if (this.ui.safetyBox) {
            if ((!set.safety_goggles || this.ui.chkGoggles.checked) && (!set.safety_gloves || this.ui.chkGloves.checked)) this.ui.safetyBox.classList.add('secured');
            else this.ui.safetyBox.classList.remove('secured');
        }
        if (set.required_stirring) {
            let isDone = this.state.telemetry.didStir;
            let el = this.getEl('chk_stir'); if (el) el.className = isDone ? 'qh-item done' : 'qh-item pending';
            if(el && isDone) el.innerHTML = el.innerHTML.replace('⬜', '✅'); else if(el) el.innerHTML = el.innerHTML.replace('✅', '⬜');
            if (!isDone) allDone = false;
        }

        let tolerance = 0.05; 
        if (set.grading_rubric && set.grading_rubric.tolerance_percent) tolerance = set.grading_rubric.tolerance_percent / 100;
        let c1_done = false, c2_done = false;
        
        this.state.contents.forEach(item => {
            let rawId = item.id;
            if (typeof rawId === 'string' && rawId.startsWith('prod_')) rawId = parseInt(rawId.split('_')[1]);
            else if (typeof rawId === 'string' && rawId.startsWith('pt_')) rawId = parseInt(rawId.split('_')[1]);

            if (set.target_chem1 && parseInt(rawId) === parseInt(set.target_chem1)) {
                if (set.strict_amount == 1) {
                    let diff = Math.abs(item.amount - set.amount_chem1); if (diff <= (set.amount_chem1 * tolerance)) c1_done = true;
                } else c1_done = true;
            }
            if (set.target_chem2 && parseInt(rawId) === parseInt(set.target_chem2)) {
                if (set.strict_amount == 1) {
                    let diff = Math.abs(item.amount - set.amount_chem2); if (diff <= (set.amount_chem2 * tolerance)) c2_done = true;
                } else c2_done = true;
            }
        });

        if (set.target_chem1) { 
            let el = this.getEl('chk_chem1'); if (el) el.className = c1_done ? 'qh-item done' : 'qh-item pending'; 
            if(el && c1_done) el.innerHTML = el.innerHTML.replace('⬜', '✅'); else if(el) el.innerHTML = el.innerHTML.replace('✅', '⬜');
            if (!c1_done) allDone = false; 
        }
        if (set.target_chem2) { 
            let el = this.getEl('chk_chem2'); if (el) el.className = c2_done ? 'qh-item done' : 'qh-item pending'; 
            if(el && c2_done) el.innerHTML = el.innerHTML.replace('⬜', '✅'); else if(el) el.innerHTML = el.innerHTML.replace('✅', '⬜');
            if (!c2_done) allDone = false; 
        }

        if (Object.keys(set).length > 0) {
            if ((allDone || this.state.telemetry.reactionProcessed) && this.ui.btnSubmit && this.state.hp > 0) {
                this.ui.btnSubmit.style.display = 'block';
                if (allDone && this.ui.questHUD) this.ui.questHUD.classList.add('all-done');
            } else {
                if(this.ui.btnSubmit) this.ui.btnSubmit.style.display = 'none';
                if(this.ui.questHUD) this.ui.questHUD.classList.remove('all-done');
            }
        } else {
            if (this.state.contents.length > 0 && this.ui.btnSubmit) this.ui.btnSubmit.style.display = 'block';
        }
    }

    generateAssignmentJSON() {
        if (!this.config.isAdmin) return;
        this.log("⚙️ สร้างแพ็คเกจโจทย์การทดลองเป็น JSON...");
        let target1 = null, target2 = null; let amt1 = 0, amt2 = 0;
        if (this.state.contents.length > 0) { target1 = parseInt(this.state.contents[0].id); amt1 = parseFloat(this.state.contents[0].amount.toFixed(1)); }
        if (this.state.contents.length > 1) { target2 = parseInt(this.state.contents[1].id); amt2 = parseFloat(this.state.contents[1].amount.toFixed(1)); }
        const template = {
            "target_chem1": target1, "amount_chem1": amt1, "target_chem2": target2, "amount_chem2": amt2,
            "safety_goggles": this.ui.chkGoggles ? (this.ui.chkGoggles.checked ? 1 : 0) : 1,
            "safety_gloves": this.ui.chkGloves ? (this.ui.chkGloves.checked ? 1 : 0) : 1,
            "strict_amount": 1, "required_stirring": this.state.telemetry.didStir ? 1 : 0,
            "grading_rubric": { "tolerance_percent": 5 }
        };
        const jsonString = JSON.stringify(template, null, 2);
        if (this.ui.devJsonOutput) this.ui.devJsonOutput.value = jsonString;
        if (this.ui.devModal) {
            this.ui.devModal.style.display = 'flex';
            setTimeout(() => { if(this.ui.devBox) this.ui.devBox.classList.add('show'); }, 10);
        }
    }

    openToolbox(itemData) {
        if(!itemData) return;
        this.state.activeTool = { ...itemData, amount: 0.0, dissolvedAmt: 0.0 };
        if(this.ui.tbChemName) this.ui.tbChemName.innerHTML = this.formatFormula(itemData.name);
        if(this.ui.tbInputUnit) this.ui.tbInputUnit.innerText = itemData.state === 'solid' ? 'g' : 'ml';
        if(this.ui.tbInputAmt) this.ui.tbInputAmt.value = "0.0";
        if(this.ui.toolboxModal) this.ui.toolboxModal.style.display = 'flex';
        setTimeout(() => { if(this.ui.toolboxBox) this.ui.toolboxBox.classList.add('show'); }, 10);
    }

    closeToolbox() {
        if(this.ui.toolboxBox) this.ui.toolboxBox.classList.remove('show');
        setTimeout(() => { if(this.ui.toolboxModal) this.ui.toolboxModal.style.display = 'none'; }, 300);
    }

    executePour() {
        let amt = parseFloat(this.ui.tbInputAmt ? this.ui.tbInputAmt.value : 0);
        if (isNaN(amt) || amt <= 0) {
            alert("⚠️ ระบุปริมาณสารให้ถูกต้อง!");
            return;
        }

        const tool = this.state.activeTool;
        this.log(`💧 หยด/เท ${tool.name} จำนวน ${amt} ${tool.state==='solid' ? 'g' : 'ml'}`);

        // เสียงเทแยกตาม type + viscosity
        const profile = (typeof ChemVisualProfile !== 'undefined')
            ? ChemVisualProfile.get(tool.type, tool.name, tool.state)
            : { viscosity: 0 };
        this.audio.playPour(tool.state, tool.type, profile.viscosity || 0);

        if (!this.config.isAdmin) {
            let safe = true;
            if (this.questSettings.safety_goggles && (!this.ui.chkGoggles || !this.ui.chkGoggles.checked)) {
                this.log("⚠️ <span style='color:var(--warning)'>ลืมสวมแว่นตานิรภัยขณะปฏิบัติงาน</span>");
                this.state.telemetry.safetyViolations++; safe = false;
            }
            if (this.questSettings.safety_gloves && (!this.ui.chkGloves || !this.ui.chkGloves.checked)) {
                this.log("⚠️ <span style='color:var(--warning)'>ลืมสวมถุงมือยางขณะปฏิบัติงาน</span>");
                this.state.telemetry.safetyViolations++; safe = false;
            }
            if(!safe) this.takeDamage(5, "สัมผัสสารเคมีโดยตรง");
        }

        let newDissolved = tool.state === 'solid' ? 0 : amt;
        let existing = this.state.contents.find(i => i.id === tool.id);
        if (existing) { 
            existing.amount += amt; 
            existing.dissolvedAmt += newDissolved; 
        } else { 
            this.state.contents.push({ ...tool, amount: amt, dissolvedAmt: newDissolved }); 
        }

        this.calculateBoilingPoint();
        this.state.targetWaveAmplitude = Math.min(25, amt * 1.5);
        this.calculateLiquidColor();

        // Visual pour indicator (CSS stream บน beaker) — เสริม PourAnimator
        if (this.ui.beaker) {
            const ind = document.createElement('div');
            ind.className = 'pour-indicator';
            const rgb = (typeof ChemVisualProfile !== 'undefined')
                ? (() => { const p = this._parseColorFromTool(tool); return `rgba(${p[0]},${p[1]},${p[2]},0.85)`; })()
                : 'rgba(56,189,248,0.85)';
            ind.style.setProperty('--pour-color', rgb);
            ind.style.width = tool.state === 'solid' ? '8px' : '5px';
            this.ui.beaker.appendChild(ind);
            setTimeout(() => ind.remove(), 600);
        }

        if (tool.state === 'solid') {
            this.state.solidWeight += amt;
            if(this.ui.scalePowder) {
                let p_width = Math.min(100, this.state.solidWeight * 1.5); 
                let p_height = Math.min(40, this.state.solidWeight * 0.8);
                this.ui.scalePowder.style.width = p_width + "px"; 
                this.ui.scalePowder.style.height = p_height + "px";
                this.ui.scalePowder.style.background = tool.color || '#ffffff';
            }
        } else {
            this.state.volume += amt;
        }

        if(this.state.volume > this.state.maxVolume || this.state.solidWeight > this.state.maxVolume) {
            this.state.telemetry.spillCount++;
            if (this.state.volume > this.state.maxVolume) this.state.volume = this.state.maxVolume;
            if (this.state.solidWeight > this.state.maxVolume) this.state.solidWeight = this.state.maxVolume;
            
            this.calculatePH();
            let currentPH = this.state.currentPH;
            
            if (currentPH < 3 || currentPH > 11) {
                this.log("⚠️ <strong style='color:#ef4444;'>สารเคมีฤทธิ์กัดกร่อนล้นแก้ว! โต๊ะเกิดรอยไหม้!</strong>");
                this.takeDamage(15, "สารกัดกร่อนกระเด็นโดนร่างกาย", "chem");
                let mark = document.createElement('div');
                mark.className = 'desk-burn';
                let size = Math.random() * 50 + 30;
                mark.style.width = size + 'px';
                mark.style.height = (size / 2) + 'px';
                let offsetX = (Math.random() - 0.5) * 300; 
                let offsetY = (Math.random() * 50) - 25;
                mark.style.left = `calc(50% + ${offsetX}px)`;
                mark.style.bottom = `${10 + offsetY}px`;
                let labAnchor = document.getElementById('labAnchor');
                if (labAnchor) labAnchor.appendChild(mark);
            } else {
                this.takeDamage(5, "ทำสารเคมีล้นบีกเกอร์");
                this.log("⚠️ สารเคมีล้นบีกเกอร์!");
            }
        }

        if (this.config.isAdmin) this.updateLiveDataAdmin();
        else this.checkStudentQuestProgress();

        this.closeToolbox();
    }

    _parseColorFromTool(tool) {
        try {
            this.colorParserCtx.fillStyle = '#38bdf8';
            this.colorParserCtx.fillStyle = tool.color || '#38bdf8';
            const hex = this.colorParserCtx.fillStyle;
            if (hex.startsWith('#') && hex.length === 7) {
                return [parseInt(hex.slice(1,3),16), parseInt(hex.slice(3,5),16), parseInt(hex.slice(5,7),16)];
            }
        } catch(e) {}
        return [56, 189, 248];
    }

    calculateBoilingPoint() {
        if (this.state.contents.length === 0) { this.state.currentBP = 100.0; return; }
        let totalAmt = 0; let totalBP = 0;
        this.state.contents.forEach(item => {
            let bp = parseFloat(item.bp);
            if (isNaN(bp)) bp = 100.0;
            totalBP += (bp * item.amount);
            totalAmt += item.amount;
        });
        if (totalAmt > 0) this.state.currentBP = totalBP / totalAmt;
        else this.state.currentBP = 100.0;
    }

    parseColorToRgb(colorStr) {
        if (!colorStr) return [255, 255, 255];
        this.colorParserCtx.fillStyle = '#ffffff'; 
        this.colorParserCtx.fillStyle = colorStr;  
        let hex = this.colorParserCtx.fillStyle; 
        if (hex.startsWith('#') && hex.length === 7) {
            let r = parseInt(hex.slice(1, 3), 16); 
            let g = parseInt(hex.slice(3, 5), 16); 
            let b = parseInt(hex.slice(5, 7), 16);
            return [r, g, b];
        }
        return [255, 255, 255]; 
    }

    calculateLiquidColor() {
        if (this.state.contents.length === 0 || this.state.volume <= 0) { 
            this.state.targetColor = [56, 189, 248, 0.1]; 
            return; 
        }
        let totalR = 0, totalG = 0, totalB = 0, totalWeight = 0;
        this.state.contents.forEach(item => {
            let rgb = this.parseColorToRgb(fixChemColor(item.name, item.color)); 
            // ของเหลว = ใช้ปริมาณที่เท (amount), ของแข็ง = ใช้ที่ละลายแล้ว (dissolvedAmt)
            let weight;
            if (item.state === 'liquid' || item.state === 'aqueous') {
                weight = item.amount || 0;          // ของเหลวมีสีทันทีที่เท
            } else {
                weight = (item.dissolvedAmt || 0) * 10;  // ของแข็งมีสีเมื่อละลาย
            }
            totalR += rgb[0] * weight; totalG += rgb[1] * weight; totalB += rgb[2] * weight; totalWeight += weight;
        });
        if (totalWeight > 0) {
            this.state.targetColor = [Math.round(totalR / totalWeight), Math.round(totalG / totalWeight), Math.round(totalB / totalWeight), 0.85];
        } else {
            this.state.targetColor = [56, 189, 248, 0.1];
        }
    }

    getBasePHForType(type, name) {
        type = (type||'').toLowerCase(); name = (name||'').toLowerCase();
        if (name.includes('acid') || name.includes('กรด')) return 2.0;
        if (type === 'halogen' || type === 'nonmetal') return 4.0;
        if (name.includes('base') || name.includes('ด่าง') || name.includes('hydroxide')) return 13.0;
        if (type === 'alkali' || type === 'alkaline') return 11.0;
        return 7.0; 
    }

    calculatePH() {
        if (this.state.contents.length === 0 || this.state.volume <= 0) { this.state.currentPH = 7.0; return; }
        let totalPH_Weight = 0; let totalWeight = 0;
        this.state.contents.forEach(item => {
            let itemPH = this.getBasePHForType(item.type, item.name); let weight = item.dissolvedAmt; 
            totalPH_Weight += (itemPH * weight); totalWeight += weight;
        });
        if (totalWeight > 0) {
            let waterWeight = Math.max(0, this.state.volume - totalWeight); 
            totalPH_Weight += (7.0 * waterWeight); totalWeight += waterWeight;
            this.state.currentPH = totalPH_Weight / totalWeight;
        } else { this.state.currentPH = 7.0; }
    }

    getPHColor(ph) {
        let hue = 0;
        if (ph <= 7) hue = (ph - 1) * (120 / 6); else hue = 120 + ((ph - 7) * (160 / 7)); 
        return `hsl(${Math.max(0, hue)}, 90%, 50%)`;
    }

    measurePH() {
        if (this.state.contents.length === 0) return alert("ในบีกเกอร์ยังไม่มีสารละลาย");
        this.log("🔍 ประมวลผลกระดาษทดสอบลิตมัส...");
        this.calculatePH();
        
        let hue = 0;
        let ph = this.state.currentPH;
        if (ph <= 7) hue = (ph - 1) * (120 / 6); else hue = 120 + ((ph - 7) * (160 / 7)); 
        let targetColor = `hsl(${Math.max(0, hue)}, 90%, 50%)`;
        
        if (this.ui.phStrip) this.ui.phStrip.style.background = '#fef08a'; 
        if (this.ui.phResultTxt) this.ui.phResultTxt.style.opacity = '0';
        if (this.ui.phModal) this.ui.phModal.style.display = 'flex'; 
        
        setTimeout(() => { if(this.ui.phBox) this.ui.phBox.classList.add('show'); }, 10);

        setTimeout(() => {
            if(this.ui.phStrip) this.ui.phStrip.style.background = targetColor;
            setTimeout(() => {
                if(this.ui.phResultTxt) {
                    this.ui.phResultTxt.innerText = `pH ${this.state.currentPH.toFixed(1)}`;
                    this.ui.phResultTxt.style.color = targetColor;
                    this.ui.phResultTxt.style.opacity = '1';
                }
                let status = "เป็นกลาง (Neutral)";
                if(this.state.currentPH < 6.5) status = "มีฤทธิ์เป็นกรด (Acidic)";
                if(this.state.currentPH > 7.5) status = "มีฤทธิ์เป็นด่าง (Alkaline)";
                if(this.ui.phStatusTxt) this.ui.phStatusTxt.innerText = status;
                this.log(`✅ ผลการวิเคราะห์ pH: ${this.state.currentPH.toFixed(1)} (${status})`);
            }, 1000);
        }, 500);
    }

    triggerExplosion(reason, type = 'boil') {
        if (this.state.isExploded) return;
        this.state.isExploded = true;
        this.state.telemetry.explosionTriggered = true;
        
        if (type === 'boil') this.state.telemetry.boilingExplosions++;
        else this.state.telemetry.chemicalExplosions++;

        let totalMass = this.state.volume + this.state.solidWeight;
        let dmg = 100; 
        if (totalMass < 20) dmg = 30; 
        else if (totalMass < 50) dmg = 60; 
        else dmg = 100; 

        this.audio.play('explosion');
        
        document.body.classList.add('shake-active');
        if (this.ui.beaker) this.ui.beaker.style.display = 'none';
        if (this.ui.flameFire) this.ui.flameFire.style.display = 'none';
        if (this.state.isHeating && this.ui.btnHeater) {
            this.ui.btnHeater.style.background = ''; this.ui.btnHeater.innerHTML = '🔥 เปิดเตา';
            document.body.classList.remove('heating-active'); 
            this.state.isHeating = false;
        }
        
        if (this.config.isAdmin) {
            this.log(`💥 <strong style='color:#ec4899'>${reason} (มวล ${totalMass.toFixed(1)}g)</strong>`);
            if (this.ui.btnRepair) this.ui.btnRepair.style.display = 'block';
            if (this.ui.explosionOverlay) {
                this.ui.explosionOverlay.className = 'explosion-overlay flash-red';
                setTimeout(()=> { this.ui.explosionOverlay.className = 'explosion-overlay'; }, 800);
            }
        } else {
            this.takeDamage(dmg, `${reason} (Damage: ${dmg})`, type);
        }

        setTimeout(()=> { document.body.classList.remove('shake-active'); }, 800);
    }

    checkReactions(bypassHeat = false) {
        if (this.state.isExploded || this.state.contents.length < 2) return;
        let contents = this.state.contents;
        let dbReactions = this.config.reactionsData;

        // ── Thermal Decomposition: solid ที่ heat_level > 0 trigger ได้โดยลำพัง ──
        if (!bypassHeat) {
            for (let rxn of dbReactions) {
                if (!rxn.heat_level || parseFloat(rxn.heat_level) <= 0) continue;
                if (rxn.reaction_type !== 'decomposition' && rxn.reaction_type !== 'dissolution') continue;
                const singleChem = contents.find(c =>
                    (c.id == rxn.chem1_id || c.id == rxn.chem2_id) && c.amount > 0.01
                );
                if (singleChem && this.state.temperature >= parseFloat(rxn.heat_level)) {
                    this.log(`🌡️ อุณหภูมิสูงถึง ${this.state.temperature.toFixed(1)}°C — <strong>${singleChem.name}</strong> เริ่มสลายตัว`);
                    this.processReaction(rxn, singleChem, singleChem, 0, 0);
                    return;
                }
            }
        }

        for (let i = 0; i < contents.length; i++) {
            for (let j = i + 1; j < contents.length; j++) {
                let c1 = contents[i];
                let c2 = contents[j];

                let c1Ready = c1.state !== 'solid' || c1.dissolvedAmt >= (c1.amount * 0.1);
                let c2Ready = c2.state !== 'solid' || c2.dissolvedAmt >= (c2.amount * 0.1);

                if (!c1Ready || !c2Ready) continue;

                let rxn = this.findExactReaction(c1, c2, dbReactions);

                if (!rxn) {
                    rxn = this.findGenericReaction(c1, c2);
                }

                if (rxn) {
                    let heatRequired = parseFloat(rxn.heat_level) || 0;
                    let heatReady = bypassHeat || heatRequired <= 25 || this.state.temperature >= heatRequired;

                    if (heatReady) {
                        this.processReaction(rxn, c1, c2, i, j);
                        return; 
                    }
                }
            }
        }
    }

    findExactReaction(c1, c2, dbReactions) {
        let c1_id = typeof c1.id === 'string' ? c1.id.replace('prod_', '').replace('pt_', '') : c1.id;
        let c2_id = typeof c2.id === 'string' ? c2.id.replace('prod_', '').replace('pt_', '') : c2.id;

        let rxn = dbReactions.find(r => 
            (r.chem1_id == c1_id && r.chem2_id == c2_id) || 
            (r.chem1_id == c2_id && r.chem2_id == c1_id)
        );

        if (rxn) {
            rxn.equation = `${c1.formula || c1.name} + ${c2.formula || c2.name} → ${rxn.product_name}`;
        }
        return rxn;
    }

    findGenericReaction(c1, c2) {
        let t1 = (c1.type || '').toLowerCase();
        let t2 = (c2.type || '').toLowerCase();
        let n1 = (c1.name || '').toLowerCase();
        let n2 = (c2.name || '').toLowerCase();
        
        let f1 = c1.formula || c1.name;
        let f2 = c2.formula || c2.name;

        const isAcid      = (t, n) => t === 'acid'     || n.includes('กรด');
        const isBase      = (t, n) => t === 'base'     || t === 'alkaline' || n.includes('ด่าง') || n.includes('hydroxide');
        const isAlkali    = (t)    => t === 'alkali';
        const isWater     = (t, n) => t === 'solvent'  || t === 'water'   || n.includes('water') || n.includes('น้ำ');
        const isMetal     = (t)    => t === 'metal'    || t === 'transition';
        const isCarbonate = (n)    => n.includes('carbonate') || n.includes('bicarbonate') || n.includes('เบกกิ้งโซดา') || n.includes('nahco') || n.includes('caco');
        const isOxidizer  = (t, n) => t === 'oxidizer' || n.includes('peroxide') || n.includes('permanganate') || n.includes('เปอร์ออกไซด์');
        const isReducer   = (t, n) => t === 'reducer'  || n.includes('thiosulfate') || n.includes('ไทโอซัลเฟต');
        const isSalt      = (t)    => t === 'salt';
        const isComplex   = (t)    => t === 'complex';
        const isOrganic   = (t)    => t === 'organic';

        // ── 1. กรด + ด่าง → เกลือ + น้ำ (Neutralization) ────
        if ((isAcid(t1,n1) && isBase(t2,n2)) || (isAcid(t2,n2) && isBase(t1,n1))) {
            const acid = isAcid(t1,n1) ? f1 : f2;
            const base = isBase(t1,n1) ? f1 : f2;
            return {
                id: 'gen_acid_base',
                product_name: 'สารละลายเกลือ + น้ำ',
                result_state: 'liquid',
                result_color: '#f0f9ff',
                result_gas: 'ไม่มีแก๊ส',
                result_precipitate: 'ไม่มีตะกอน',
                heat_level: 0, is_explosive: 0, toxicity_bonus: 0,
                exothermic_heat: 30,
                observation_th: 'ไม่มีสีเปลี่ยน ความร้อนเพิ่มขึ้นเล็กน้อย ถ้าใช้ indicator จะเปลี่ยนสี',
                safety_warning: null,
                reaction_type: 'neutralization',
                equation: `${acid} + ${base} → Salt + H₂O`
            };
        }

        // ── 2. โลหะอัลคาไล + น้ำ → ระเบิด ─────────────────
        if ((isAlkali(t1) && isWater(t2,n2)) || (isAlkali(t2) && isWater(t1,n1))) {
            const metal = isAlkali(t1) ? f1 : f2;
            return {
                id: 'gen_alkali_water',
                product_name: 'โลหะไฮดรอกไซด์ + H₂',
                result_state: 'liquid',
                result_color: '#f0fdf4',
                result_gas: 'H2',
                result_precipitate: 'ไม่มีตะกอน',
                heat_level: 0, is_explosive: 1, toxicity_bonus: 10,
                exothermic_heat: 150,
                observation_th: 'โลหะวิ่งบนผิวน้ำ เกิดเปลวไฟ เกิดฟอง H₂ อย่างรุนแรง',
                safety_warning: 'อันตรายสูง! ห้ามใช้มือสัมผัส อาจระเบิดได้',
                reaction_type: 'metal_water',
                equation: `2${metal} + 2H₂O → 2${metal}OH + H₂↑`
            };
        }

        // ── 3. กรด + โลหะ → เกลือ + H₂ ─────────────────────
        if ((isAcid(t1,n1) && isMetal(t2)) || (isAcid(t2,n2) && isMetal(t1))) {
            const acid  = isAcid(t1,n1) ? f1 : f2;
            const metal = isMetal(t1)   ? f1 : f2;
            return {
                id: 'gen_acid_metal',
                product_name: 'เกลือโลหะ + H₂',
                result_state: 'liquid',
                result_color: '#e0f2fe',
                result_gas: 'H2',
                result_precipitate: 'ไม่มีตะกอน',
                heat_level: 0, is_explosive: 0, toxicity_bonus: 0,
                exothermic_heat: 15,
                observation_th: 'โลหะค่อยๆ ละลาย เกิดฟอง H₂ สารละลายเปลี่ยนสีตามโลหะ',
                safety_warning: 'ระวังแก๊ส H₂ ที่เกิดขึ้น ติดไฟได้',
                reaction_type: 'acid_metal',
                equation: `2${acid} + ${metal} → Salt + H₂↑`
            };
        }

        // ── 4. กรด + คาร์บอเนต → CO₂ ────────────────────────
        if ((isAcid(t1,n1) && isCarbonate(n2)) || (isAcid(t2,n2) && isCarbonate(n1))) {
            const acid = isAcid(t1,n1) ? f1 : f2;
            const carb = isCarbonate(n1) ? f1 : f2;
            return {
                id: 'gen_acid_carbonate',
                product_name: 'เกลือ + CO₂ + H₂O',
                result_state: 'liquid',
                result_color: '#f8fafc',
                result_gas: 'CO2',
                result_precipitate: 'ไม่มีตะกอน',
                heat_level: 0, is_explosive: 0, toxicity_bonus: 0,
                exothermic_heat: 5,
                observation_th: 'เกิดฟอง CO₂ ทันที ของเหลวดูเหมือนน้ำโซดา ฟองพุ่งขึ้นรวดเร็ว',
                safety_warning: null,
                reaction_type: 'acid_carbonate',
                equation: `${acid} + ${carb} → Salt + CO₂↑ + H₂O`
            };
        }

        // ── 5. Oxidizer + Reducer → color change ─────────────
        if ((isOxidizer(t1,n1) && isReducer(t2,n2)) || (isOxidizer(t2,n2) && isReducer(t1,n1))) {
            return {
                id: 'gen_redox',
                product_name: 'ผลิตภัณฑ์รีดอกซ์',
                result_state: 'liquid',
                result_color: '#fef3c7',
                result_gas: 'ไม่มีแก๊ส',
                result_precipitate: 'ไม่มีตะกอน',
                heat_level: 0, is_explosive: 0, toxicity_bonus: 5,
                exothermic_heat: 10,
                observation_th: 'สีของสารละลายเปลี่ยนแปลงอย่างชัดเจน เกิดปฏิกิริยาออกซิเดชัน-รีดักชัน',
                safety_warning: null,
                reaction_type: 'redox',
                equation: `Oxidizer + Reducer → Products (redox)`
            };
        }

        // ── 6. Oxidizer + Organic → combustion-like ──────────
        if ((isOxidizer(t1,n1) && isOrganic(t2)) || (isOxidizer(t2,n2) && isOrganic(t1))) {
            return {
                id: 'gen_oxidation_organic',
                product_name: 'สารออกซิไดซ์ผลิตภัณฑ์',
                result_state: 'liquid',
                result_color: '#fef08a',
                result_gas: 'CO2',
                result_precipitate: 'ไม่มีตะกอน',
                heat_level: 0, is_explosive: 0, toxicity_bonus: 10,
                exothermic_heat: 20,
                observation_th: 'สารอินทรีย์ถูกออกซิไดซ์ สีเปลี่ยน เกิดความร้อน',
                safety_warning: 'ระวัง! สารออกซิไดเซอร์ทำปฏิกิริยารุนแรงกับสารอินทรีย์',
                reaction_type: 'oxidation',
                equation: `Oxidizer + Organic → Oxidized products`
            };
        }

        // ── 7. Salt + Salt → precipitation (generic) ─────────
        if (isSalt(t1) && isSalt(t2)) {
            return {
                id: 'gen_double_displacement',
                product_name: 'อาจเกิดตะกอน (Double Displacement)',
                result_state: 'liquid',
                result_color: '#f1f5f9',
                result_gas: 'ไม่มีแก๊ส',
                result_precipitate: 'ตะกอนขาว (สังเกตความขุ่น)',
                heat_level: 0, is_explosive: 0, toxicity_bonus: 0,
                exothermic_heat: 2,
                observation_th: 'สารละลายอาจขุ่นขึ้นหรือมีตะกอนเกิดขึ้น ขึ้นกับความสามารถในการละลายของผลิตภัณฑ์',
                safety_warning: null,
                reaction_type: 'precipitation',
                equation: `${f1} + ${f2} → สารละลายใหม่ ± ตะกอน`
            };
        }

        // ── 8. Complex formation ──────────────────────────────
        if (isComplex(t1) || isComplex(t2)) {
            return {
                id: 'gen_complex',
                product_name: 'สารประกอบเชิงซ้อน',
                result_state: 'liquid',
                result_color: '#fbbf24',
                result_gas: 'ไม่มีแก๊ส',
                result_precipitate: 'ไม่มีตะกอน',
                heat_level: 0, is_explosive: 0, toxicity_bonus: 5,
                exothermic_heat: 5,
                observation_th: 'สีของสารละลายเปลี่ยนอย่างรวดเร็ว เกิดสารประกอบเชิงซ้อนที่มีสีสวยงาม',
                safety_warning: null,
                reaction_type: 'complex_formation',
                equation: `${f1} + ${f2} → [Complex]`
            };
        }

        return null;
    }

    processReaction(rxn, c1, c2, idx1, idx2) {
        this.log(`⚗️ เกิดปฏิกิริยาเคมี: <strong>สร้าง ${rxn.product_name}</strong>`);

        // แสดง observation_th จาก DB ถ้ามี
        if (rxn.observation_th) {
            this.log(`🔬 <span style="color:#7dd3fc;">สังเกต: ${rxn.observation_th}</span>`);
        }

        // แสดง safety_warning จาก DB — toast แดง
        if (rxn.safety_warning) {
            this.log(`⚠️ <span style="color:var(--danger); font-weight:bold;">⛔ ความปลอดภัย: ${rxn.safety_warning}</span>`);
            this._showSafetyToast(rxn.safety_warning);
        }
        
        let eqStr = rxn.equation || `${c1.formula||c1.name} + ${c2.formula||c2.name} → ${rxn.product_name}`;
        this.showEquation(eqStr, `พบปฏิกิริยาเคมี (สร้าง ${rxn.product_name})`);

        let reactAmount = Math.min(c1.amount, c2.amount);
        c1.amount -= reactAmount; 
        c2.amount -= reactAmount;
        
        if (c1.state === 'solid') c1.dissolvedAmt = Math.max(0, c1.dissolvedAmt - reactAmount);
        if (c2.state === 'solid') c2.dissolvedAmt = Math.max(0, c2.dissolvedAmt - reactAmount);

        this.state.contents = this.state.contents.filter(c => c.amount > 0.05);

        let pState = rxn.result_state || 'liquid';
        let pColor = rxn.result_color || '#ffffff';
        let pAmount = reactAmount * 2; 
        
        let genericId = rxn.id ? rxn.id : 'prod_' + Math.floor(Math.random() * 1000);

        this.state.contents.push({
            id: typeof rxn.id === 'string' ? rxn.id : 'prod_' + rxn.id, 
            name: rxn.product_name, 
            state: pState, 
            color: pColor,
            type: 'product', 
            amount: pAmount, 
            dissolvedAmt: pState === 'solid' ? 0 : pAmount, 
            bp: 100.0,
            formula: rxn.product_name
        });

        if (rxn.is_explosive == 1) {
            this.triggerExplosion(`ปฏิกิริยาเคมีรุนแรงจนเกิดการระเบิดจาก ${rxn.product_name}!`, 'chem');
            return;
        }

        let toxLevel = parseInt(rxn.toxicity_bonus) || 0;
        if (toxLevel > 0 && !this.config.isAdmin) {
            let safeFromGas = true;
            if (!this.ui.chkGoggles.checked || !this.ui.chkGloves.checked) safeFromGas = false;
            if (!safeFromGas) {
                this.state.telemetry.toxicExposures++;
                this.audio.play('toxic_warning');
                this.takeDamage(toxLevel, `สูดดม/สัมผัสสารพิษจากปฏิกิริยา ${rxn.product_name}`, 'poison');
            }
        }

        if (rxn.result_gas && rxn.result_gas !== 'ไม่มีแก๊ส') {
            this.log(`💨 เกิดแก๊ส: <span style="color:#a3e635;">${rxn.result_gas}</span>`);
            // สีฟองแยกตาม gas type
            const gasColorMap = {
                'H2':    'rgba(200,230,255,0.7)',
                'CO2':   'rgba(220,220,220,0.6)',
                'SO2':   'rgba(230,220,100,0.6)',
                'Cl2':   'rgba(150,230,150,0.6)',
                'O2':    'rgba(230,245,255,0.7)',
                'NH3':   'rgba(200,255,200,0.6)',
                'HCl':   'rgba(240,240,200,0.6)',
                'Steam': 'rgba(200,210,230,0.5)',
            };
            let gasKey = Object.keys(gasColorMap).find(k =>
                rxn.result_gas.toUpperCase().includes(k.toUpperCase())
            );
            let bubbleColor = gasColorMap[gasKey] || 'rgba(255,255,255,0.6)';
            for(let i=0; i<50; i++) {
                this.state.bubbles.push({
                    x: Math.random() * 192,
                    y: 236,
                    radius: Math.random() * 5 + 2,
                    speed: Math.random() * 4 + 2,
                    color: bubbleColor,
                });
            }
            if (this.state.volume > 0) this.state.volume = Math.max(0, this.state.volume - (pAmount * 0.1));

            // toxic gas visual: ปรับ toxic cloud class ตามชนิด
            if (this.ui.toxicCloud && rxn.toxicity_bonus > 0) {
                const gasClassMap = { 'SO2':'gas-so2','Cl2':'gas-cl2','NH3':'gas-nh3','HCl':'gas-hcl' };
                const gClass = Object.keys(gasClassMap).find(k => rxn.result_gas.toUpperCase().includes(k.toUpperCase()));
                if (gClass) {
                    this.ui.toxicCloud.classList.remove('gas-so2','gas-cl2','gas-nh3','gas-hcl');
                    this.ui.toxicCloud.classList.add(gasClassMap[gClass]);
                }
            }
        }

        if (rxn.result_precipitate && rxn.result_precipitate !== 'ไม่มีตะกอน') {
            this.log(`🧊 เกิดตะกอน: <span style="color:#fcd34d;">${rxn.result_precipitate}</span>`);
            // ใช้สีตะกอนจาก result_color ของ DB ถ้ามี ไม่เช่นนั้นใช้ค่า default
            const precipColor = (rxn.result_color && rxn.result_color !== '#ffffff')
                ? rxn.result_color : '#cbd5e1';
            const precipShape = (rxn.reaction_type === 'precipitation') ? 'square' : 'dot';
            for(let i=0; i<120; i++) {
                this.state.precipitates.push({
                    x: Math.random() * 160 + 16,
                    y: Math.random() * 100 + 50,
                    size: Math.random() * 2.5 + 1,
                    speed: Math.random() * 1.5 + 0.5,
                    color: precipColor,
                    shape: precipShape,
                    settled: false
                });
            }
        }

        // แก้: ใช้ temperature_change จาก DB (+ = exothermic, - = endothermic)
        let deltaT = parseFloat(rxn.temperature_change) || parseFloat(rxn.heat_level) || 0;
        if (deltaT !== 0) {
            this.state.targetTemp += deltaT;
            if (deltaT > 0) {
                this.log(`🔥 <span style="color:#f97316">Exothermic: +${deltaT.toFixed(1)}°C — ปฏิกิริยาคายความร้อน!</span>`);
            } else {
                this.log(`🧊 <span style="color:#38bdf8">Endothermic: ${deltaT.toFixed(1)}°C — ปฏิกิริยาดูดความร้อน!</span>`);
            }
        }

        if (rxn.result_color) {
            let rgb = this.parseColorToRgb(rxn.result_color);
            this.state.targetColor = [rgb[0], rgb[1], rgb[2], 0.9];
        }

        this.state.telemetry.reactionProcessed = true;
        // เสียง reaction แยกตาม type
        this.audio.playReaction(rxn.reaction_type || '', rxn.is_explosive == 1);
        // dissolve sound ถ้ามีของแข็งละลาย
        if (c1.state === 'solid' || c2.state === 'solid') this.audio.play('dissolve');

        // Reaction Flash: overlay brief color burst บน beaker
        this._triggerReactionFlash(rxn);
        
        this.calculateBoilingPoint();
        this.calculatePH();
        
        if (this.config.isAdmin) this.updateLiveDataAdmin();
        else this.checkStudentQuestProgress();
    }

    // ── Reaction Flash Animation ──────────────────────────────
    _triggerReactionFlash(rxn) {
        if (!this.ui.liquidCanvas) return;

        // เลือกสีและ intensity ตาม reaction type
        const flashMap = {
            'neutralization':    { color: 'rgba(255,255,255,0.7)',  dur: 300 },
            'precipitation':     { color: 'rgba(255,230,100,0.6)',  dur: 400 },
            'redox':             { color: 'rgba(255,165,0,0.65)',   dur: 350 },
            'acid_metal':        { color: 'rgba(200,230,255,0.6)',  dur: 300 },
            'metal_water':       { color: 'rgba(255,100,50,0.8)',   dur: 500 },
            'acid_carbonate':    { color: 'rgba(240,255,240,0.6)',  dur: 300 },
            'decomposition':     { color: 'rgba(255,200,50,0.7)',   dur: 400 },
            'complex_formation': { color: 'rgba(200,100,255,0.65)', dur: 350 },
            'hazardous_mix':     { color: 'rgba(255,50,50,0.8)',    dur: 500 },
            'dissolution':       { color: 'rgba(150,255,200,0.5)',  dur: 250 },
        };

        const fx = flashMap[rxn.reaction_type] || { color: 'rgba(255,255,255,0.5)', dur: 300 };

        // สร้าง overlay div ชั่วคราวทับบน beaker
        const beakerEl = this.ui.beaker;
        if (!beakerEl) return;
        const overlay = document.createElement('div');
        overlay.style.cssText = `
            position:absolute; inset:0; border-radius:inherit;
            background:${fx.color};
            z-index:50; pointer-events:none;
            transition: opacity ${fx.dur}ms ease-out;
        `;
        beakerEl.appendChild(overlay);
        requestAnimationFrame(() => {
            requestAnimationFrame(() => { overlay.style.opacity = '0'; });
        });
        setTimeout(() => overlay.remove(), fx.dur + 50);

        // Golden Rain special effect สำหรับ PbI₂
        if (rxn.reaction_type === 'precipitation' &&
            rxn.result_color && rxn.result_color.toLowerCase() === '#ffd700') {
            this._triggerGoldenRain();
        }
    }

    // ── Golden Rain Effect (PbI₂) ─────────────────────────────
    _triggerGoldenRain() {
        const canvas = this.ui.liquidCanvas;
        if (!canvas || !this.ctx) return;
        const w = canvas.width, h = canvas.height;

        // spawn golden crystals จากหลายจุด พร้อมกัน
        for (let i = 0; i < 60; i++) {
            setTimeout(() => {
                this.state.precipitates.push({
                    x: Math.random() * (w - 20) + 10,
                    y: Math.random() * 40 + 10,   // เริ่มจากด้านบน
                    size: Math.random() * 3 + 1.5,
                    speed: Math.random() * 2 + 1.5,
                    color: `hsl(${45 + Math.random()*20}, 100%, ${50+Math.random()*20}%)`,
                    shape: 'square',
                    settled: false,
                });
            }, Math.random() * 800);
        }
        this.log('✨ <span style="color:#ffd700;font-weight:bold;">Golden Rain! ผลึก PbI₂ ตกลงมาเหมือนทองคำ</span>');
    }

    formResidue() {
        if (this.state.contents.length === 0) return;
        this.log("⚠️ น้ำระเหยแห้งหมด บีกเกอร์เหลือเพียงคราบตะกอน (Residue)!");
        let hasResidue = false;
        
        this.state.contents.forEach(c => {
            if (c.dissolvedAmt > 0 || c.state === 'liquid') {
                hasResidue = true;
                c.state = 'solid';
                c.dissolvedAmt = 0;
                
                let precipColor = c.color || '#ffffff';
                let particleCount = Math.min(200, Math.floor(c.amount * 5));
                for(let i=0; i < particleCount; i++) {
                    this.state.precipitates.push({
                        x: Math.random() * 160 + 16,
                        y: 230 - (Math.random()*15), 
                        size: Math.random() * 3 + 1,
                        speed: 0,
                        color: precipColor,
                        settled: true
                    });
                }
            }
        });

        if (hasResidue) {
            this.audio.play('drop_solid'); 
        }

        this.calculateLiquidColor();
        if(this.config.isAdmin) this.updateLiveDataAdmin();
    }

    startPhysicsLoop() {
        const isMobile = window.innerWidth < 768;
        const frameInterval = isMobile ? 1000/30 : 1000/60;
        if (isMobile && typeof SteamSystem !== 'undefined') SteamSystem.maxParticles = 12;
        let lastTime = performance.now(), lastRenderTime = 0;
        const loop = (currentTime) => {
            const deltaTime = currentTime - lastTime; lastTime = currentTime;
            this.updatePhysics(deltaTime);
            if (currentTime - lastRenderTime >= frameInterval) {
                lastRenderTime = currentTime;
                this.renderCanvas();
            }
            requestAnimationFrame(loop);
        };
        requestAnimationFrame(loop);
    }

    updatePhysics(dt) {
        if (this.state.isExploded) return;

        this.reactionCooldown -= dt;
        if (this.reactionCooldown <= 0) {
            this.checkReactions(false);
            this.reactionCooldown = 1000;
        }

        // 🚨 ลดความเร็วในการต้ม (Nerf Heating Rate) ให้ผู้เล่นมีเวลาสังเกตมากขึ้น 🚨
        let totalMass = this.state.volume + this.state.solidWeight;
        let heatRate = 5.0 / (totalMass + 100.0); 

        // ดึง room temperature จาก patchRoomTemp (ค่า default 25 ถ้ายังไม่ได้ init)
        const _roomT = (typeof window._labRoomTemp !== 'undefined') ? window._labRoomTemp : 25.0;
        if (this.state.isHeating) {
            this.state.targetTemp = Math.min(250.0, this.state.targetTemp + (heatRate * 0.5));
        } else {
            let coolRate = heatRate * 0.3;
            // clamp ให้ไม่ต่ำกว่า roomTemp — ป้องกัน -50°C bug
            const minTemp = Math.max(15.0, _roomT);
            if (this.state.targetTemp > _roomT) {
                this.state.targetTemp = Math.max(minTemp, this.state.targetTemp - coolRate);
            } else if (this.state.targetTemp < _roomT) {
                this.state.targetTemp = Math.min(_roomT, this.state.targetTemp + coolRate);
            }
        }
        
        // 🚨 ทำให้ปรอทขึ้น-ลงนุ่มนวล สมูทมากยิ่งขึ้น 🚨
        this.state.temperature += (this.state.targetTemp - this.state.temperature) * 0.01;
        // clamp: อุณหภูมิจริงต้องอยู่ระหว่าง 10–300°C
        this.state.temperature = Math.max(10.0, Math.min(300.0, this.state.temperature));

        if (this.ui.valTemp) {
            this.ui.valTemp.innerText = this.state.temperature.toFixed(1) + " °C";
            if (this.state.temperature > 80) {
                this.ui.valTemp.style.color = 'var(--danger)';
                this.ui.valTemp.style.textShadow = '0 0 10px rgba(239,68,68,0.5)';
            } else if (this.state.temperature < 20) {
                this.ui.valTemp.style.color = '#38bdf8';
                this.ui.valTemp.style.textShadow = '0 0 8px rgba(56,189,248,0.4)';
            } else {
                this.ui.valTemp.style.color = 'var(--primary)';
                this.ui.valTemp.style.textShadow = 'none';
            }
        }
        if (this.ui.thermoBar) {
            // thermometer แสดง 10–150°C
            let percent = ((this.state.temperature - 10) / 140) * 100;
            this.ui.thermoBar.style.height = Math.max(2, Math.min(100, percent)) + '%';
            // สีปรอทเปลี่ยนตามอุณหภูมิ
            if (this.state.temperature > 80) {
                this.ui.thermoBar.style.background = 'linear-gradient(to top,#ef4444,#f97316)';
            } else if (this.state.temperature < 20) {
                this.ui.thermoBar.style.background = 'linear-gradient(to top,#38bdf8,#818cf8)';
            } else {
                this.ui.thermoBar.style.background = 'linear-gradient(to top,var(--danger),#f87171)';
            }
        }

        if (this.state.temperature >= this.state.currentBP && this.state.volume > 0) {
            if(this.ui.beaker) this.ui.beaker.classList.add('beaker-boiling');
            if(!this.isBoilSoundPlaying) { this.audio.play('boil'); this.isBoilSoundPlaying = true; }
            if (Math.random() < 0.4) {
                this.state.bubbles.push({ 
                    x: Math.random() * 192, 
                    y: 236, 
                    radius: Math.random() * 3 + 2, 
                    speed: Math.random() * 2 + 1,
                    color: 'rgba(255, 255, 255, 0.6)'
                });
            }
            
            this.state.volume -= 0.05; 
            if(this.state.volume <= 0) {
                this.state.volume = 0;
                this.formResidue(); 
            }
            
            if (this.config.isAdmin && Math.random() < 0.05) this.updateLiveDataAdmin();
            else if (!this.config.isAdmin && Math.random() < 0.05) this.checkStudentQuestProgress();
        } else {
            if(this.ui.beaker) this.ui.beaker.classList.remove('beaker-boiling');
            if(this.isBoilSoundPlaying) { this.audio.stop('boil'); this.isBoilSoundPlaying = false; }
        }

        let explosionThreshold = Math.max(150.0, this.state.currentBP + 50.0);
        if (this.state.temperature >= explosionThreshold && !this.state.isExploded) {
            this.triggerExplosion("บีกเกอร์แตกเนื่องจากการต้มให้ความร้อนสูงเกินพิกัด!", 'boil');
            return;
        }

        if (this.ui.scaleDisplay) {
            let displayMass = (this.state.solidWeight + this.state.volume) - this.state.tareOffset;
            if (Math.abs(displayMass) < 0.005) displayMass = 0;
            this.ui.scaleDisplay.innerText = displayMass.toFixed(2) + " g";
        }

        if (this.state.isStirring) {
            this.state.wavePhase += 0.3; if (this.state.targetWaveAmplitude < 5) this.state.targetWaveAmplitude = 5;
            let needsRecalculate = false;
            this.state.contents.forEach(c => {
                if (c.state === 'solid' && c.dissolvedAmt < c.amount && this.state.volume > 0) {
                    c.dissolvedAmt += (c.amount * 0.05) + 0.1; 
                    if (c.dissolvedAmt > c.amount) c.dissolvedAmt = c.amount;
                    needsRecalculate = true;
                }
            });
            if (needsRecalculate) {
                if(this.state.contents.find(i=>i.type !== 'product')) this.calculateLiquidColor();
                if(this.config.isAdmin) this.updateLiveDataAdmin();
            }

            this.state.precipitates.forEach(p => {
                if (p.settled) {
                    if (Math.random() < 0.1) {
                        p.settled = false;
                        p.y -= Math.random() * 20; 
                    }
                }
            });

        } else {
            this.state.wavePhase += 0.05; 
        }
        
        this.state.waveAmplitude += (this.state.targetWaveAmplitude - this.state.waveAmplitude) * 0.1;
        this.state.targetWaveAmplitude *= 0.9; 

        let groundLevel = 230;
        this.state.precipitates.forEach(p => {
            if (!p.settled) {
                p.y += p.speed;
                p.x += (Math.random() - 0.5) * 0.5; 
                if (p.y >= groundLevel - (Math.random()*10)) { 
                    p.y = groundLevel - (Math.random()*5);
                    p.settled = true;
                }
            }
        });

        for(let i=0; i<4; i++) {
            let diff = this.state.targetColor[i] - this.state.currentColor[i];
            if (!isNaN(diff)) this.state.currentColor[i] += diff * 0.05;
        }

        if(this.config.isAdmin) {
            if(this.ui.ldVol) this.ui.ldVol.innerText = `${this.state.volume.toFixed(1)} ml`;
            if(this.ui.ldMass) this.ui.ldMass.innerText = `${this.state.solidWeight.toFixed(1)} g`;
            this.calculatePH();
            if(this.ui.ldPh) this.ui.ldPh.innerText = this.state.currentPH.toFixed(1);
        }
    }

    renderCanvas() {
        if (!this.ctx || !this.ui.liquidCanvas || this.state.isExploded) return;
        // Performance: dirty flag — วาดใหม่เฉพาะเมื่อ state เปลี่ยน
        const stateHash = `${this.state.volume.toFixed(1)}_${this.state.currentColor.join(',')}_${this.state.temperature.toFixed(0)}_${this.state.contents.length}_${this.state.precipitates.length}_${this.state.bubbles.length}`;
        if (stateHash === this._lastRenderHash && !PourAnimator.active && SteamSystem.particles.length === 0 && !SolidParticleSystem.isActive()) return;
        this._lastRenderHash = stateHash;
        const width = this.ui.liquidCanvas.width; const height = this.ui.liquidCanvas.height;
        this.ctx.clearRect(0, 0, width, height);
        
        let totalUndissolved = 0;
        this.state.contents.forEach(c => {
            if (c.state === 'solid') {
                let undissolved = c.amount - c.dissolvedAmt;
                if (undissolved > 0) {
                    totalUndissolved += undissolved;
                    let pileHeight = Math.min(30, undissolved * 1.5); let pileWidth = Math.min(width - 20, undissolved * 3);
                    this.ctx.fillStyle = c.color || '#ffffff'; this.ctx.beginPath();
                    this.ctx.ellipse(width/2, height - 5 - (totalUndissolved/15), pileWidth/2, pileHeight/2, 0, 0, Math.PI*2);
                    this.ctx.fill();
                }
            }
        });

        if (this.state.volume <= 0) {
            this.state.precipitates.forEach(p => {
                this.ctx.fillStyle = p.color;
                this.ctx.beginPath();
                this.ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
                this.ctx.fill();
            });
            return;
        }

        let fillHeight = (this.state.volume / this.state.maxVolume) * height; fillHeight = Math.min(fillHeight, height); 
        let topY = height - fillHeight;
        const [r, g, b, a] = this.state.currentColor;
        this.ctx.fillStyle = `rgba(${Math.round(r||56)}, ${Math.round(g||189)}, ${Math.round(b||248)}, ${a||0.1})`;

        this.ctx.beginPath(); this.ctx.moveTo(0, height);
        for(let x = 0; x <= width; x += 5) {
            let y = topY + Math.sin(x * 0.05 + this.state.wavePhase) * this.state.waveAmplitude;
            this.ctx.lineTo(x, y);
        }
        this.ctx.lineTo(width, height); this.ctx.closePath(); this.ctx.fill();

        this.ctx.fillStyle = `rgba(255, 255, 255, 0.15)`; 
        this.ctx.fillRect(width - 30, topY + 10, 10, fillHeight - 20);

        this.state.precipitates.forEach(p => {
            this.ctx.fillStyle = p.color;
            this.ctx.beginPath();
            this.ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
            this.ctx.fill();
        });

        for(let i = this.state.bubbles.length - 1; i >= 0; i--) {
            let bub = this.state.bubbles[i];
            this.ctx.fillStyle = bub.color || 'rgba(255, 255, 255, 0.6)';
            this.ctx.beginPath(); 
            this.ctx.arc(bub.x, bub.y, bub.radius, 0, Math.PI * 2); 
            this.ctx.fill();
            bub.y -= bub.speed; 
            bub.x += Math.sin(bub.y * 0.1) * 1; 
            if(bub.y < topY) this.state.bubbles.splice(i, 1);
        }
    }

    submitLabReport() {
        if (this.config.isAdmin) return;
        
        let reportData = {
            assignment_id: this.config.assignmentId,
            csrf_token: this.config.csrfToken,
            telemetry: {
                hp_remaining: this.state.hp,
                actions: this.state.telemetry,
                beaker_items: this.state.contents.map(c => ({id: c.id, name: c.name, amount: c.amount}))
            }
        };

        this.ui.btnSubmit.disabled = true;
        this.ui.btnSubmit.innerHTML = "⏳ กำลังส่งประเมิน...";

        fetch('api_auto_grade.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(reportData)
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                Swal.fire({
                    icon: 'success',
                    title: `ได้รับเกรด: ${data.grade}`,
                    html: `คุณได้คะแนน <b>${data.final_score}/100</b><br><br>📝 AI ตรวจสอบพฤติกรรมของคุณแล้ว สามารถดูผลลัพธ์ย้อนหลังได้ในหน้า <b>"รายงานผล"</b>`,
                    confirmButtonText: 'กลับหน้าหลัก',
                    confirmButtonColor: '#10b981'
                }).then(() => {
                    window.location.href = 'student_reports.php';
                });
            } else {
                Swal.fire('ผิดพลาด', data.message, 'error');
                this.ui.btnSubmit.disabled = false;
                this.ui.btnSubmit.innerHTML = "🚀 ส่งรายงานการทดลอง (AI)";
            }
        })
        .catch(err => {
            Swal.fire('เชื่อมต่อล้มเหลว', 'ไม่สามารถส่งข้อมูลได้ กรุณาลองใหม่', 'error');
            this.ui.btnSubmit.disabled = false;
            this.ui.btnSubmit.innerHTML = "🚀 ส่งรายงานการทดลอง (AI)";
        });
    }

    bindEvents() {
        if (this.ui.btnAudio) this.ui.btnAudio.addEventListener('click', () => this.audio.toggleMute(this.ui.btnAudio));
        
        // ── Undo: เทสารล่าสุดออก 1 ชนิด ──────────────────────
        if (this.ui.btnUndo) {
            this.ui.btnUndo.addEventListener('click', () => {
                if (this.state.isExploded) return;
                // หาสารที่เพิ่งใส่ล่าสุด (ไม่ใช่ product)
                const pourable = this.state.contents.filter(c => c.type !== 'product');
                if (pourable.length === 0) {
                    this.log('⚠️ ไม่มีสารที่สามารถเทออกได้');
                    return;
                }
                const last = pourable[pourable.length - 1];
                // คืน volume/solidWeight
                if (last.state === 'solid') {
                    this.state.solidWeight = Math.max(0, this.state.solidWeight - last.amount);
                } else {
                    this.state.volume = Math.max(0, this.state.volume - last.amount);
                }
                this.state.contents = this.state.contents.filter(c => c !== last);
                this.log(`↩️ เทออก: <strong>${last.name}</strong> (${last.amount.toFixed(1)} ${last.state==='solid'?'g':'ml'})`);
                this.calculateBoilingPoint();
                this.calculateLiquidColor();
                this.calculatePH();
                if (this.config.isAdmin) this.updateLiveDataAdmin();
                else this.checkStudentQuestProgress();
            });
        }
        
        if (this.ui.btnSubmit) {
            this.ui.btnSubmit.addEventListener('click', () => {
                if(this.state.hp <= 0) return alert("คุณไม่สามารถส่งงานได้เนื่องจากได้รับบาดเจ็บสาหัส (HP = 0) กรุณาล้างโต๊ะและเริ่มใหม่");
                this.submitLabReport();
            });
        }

        if (this.ui.btnProcess) {
            this.ui.btnProcess.addEventListener('click', () => {
                if (this.state.contents.length < 2) return alert("ต้องมีสารเคมีในบีกเกอร์อย่างน้อย 2 ชนิดครับ");
                this.audio.play('click');
                this.log("⚗️ ระบบกำลังประมวลผลปฏิกิริยาเคมี...");
                this.ui.btnProcess.disabled = true; this.ui.btnProcess.innerText = "⏳ รอสักครู่...";
                setTimeout(() => {
                    this.checkReactions(true);
                    this.state.telemetry.reactionProcessed = true;
                    this.ui.btnProcess.innerText = "⚗️ ประมวลผลสำเร็จ"; this.ui.btnProcess.style.background = 'var(--success)';
                    if(!this.config.isAdmin) this.checkStudentQuestProgress();
                    setTimeout(() => { 
                        this.ui.btnProcess.disabled = false; 
                        this.ui.btnProcess && (this.ui.btnProcess.innerText = "⚗️ ประมวลผลปฏิกิริยา"); 
                        this.ui.btnProcess.style.background = ''; 
                    }, 3000);
                }, 1000);
            });
        }
        
        if (this.ui.btnWash) {
            this.ui.btnWash.addEventListener('click', () => {
                let proceed = this.config.isAdmin ? true : confirm("ต้องการเทสารละลายทั้งหมดทิ้งและล้างบีกเกอร์ใช่หรือไม่?");
                if (proceed) {
                    this.audio.play('wipe');
                    this.state.volume          = 0;
                    this.state.solidWeight     = 0;
                    this.state.tareOffset      = 0;
                    this.state.contents        = [];
                    this.state.precipitates    = [];
                    this.state.temperature     = 25.0;
                    this.state.targetTemp      = 25.0;   // ← reset ป้องกัน -50°C bug
                    this.state.currentBP       = 100.0;
                    this.state.bubbles         = [];
                    this.state.currentColor    = [56, 189, 248, 0.1];
                    this.state.targetColor     = [56, 189, 248, 0.1];
                    this.state.currentPH       = 7.0;
                    this.state.isExploded      = false;
                    this.state.waveAmplitude   = 0;
                    this.state.targetWaveAmplitude = 0;
                    this.reactionCooldown      = 0;
                    // reset slow reaction tracking เมื่อล้างบีกเกอร์
                    if (typeof slowReactionChecked !== 'undefined') {
                        slowReactionChecked = {};
                    }
                    
                    this.state.telemetry = { spillCount: 0, safetyViolations: 0, didStir: false, reactionProcessed: false, boilingExplosions: 0, chemicalExplosions: 0, toxicExposures: 0 };

                    if(this.state.isHeating && this.ui.btnHeater) {
                        this.ui.btnHeater.style.background = ''; this.ui.btnHeater.innerHTML = '🔥 เปิดเตา';
                        document.body.classList.remove('heating-active'); 
                        if(this.ui.flameFire) this.ui.flameFire.style.display = 'none';
                        this.state.isHeating = false;
                    }
                    if(this.state.isStirring && this.ui.btnStir) {
                        this.ui.btnStir.style.background = '';
                        if(this.ui.stirRod) { this.ui.stirRod.classList.remove('stirring-anim'); this.ui.stirRod.style.display='none'; }
                        this.state.isStirring = false;
                        this.audio.stop('stirring');
                    }
                    
                    if (this.ui.beaker) { this.ui.beaker.style.display = 'flex'; this.ui.beaker.classList.remove('beaker-boiling'); }
                    if (this.ctx) this.ctx.clearRect(0,0, this.ui.liquidCanvas.width, this.ui.liquidCanvas.height);
                    if(this.ui.scalePowder) { this.ui.scalePowder.style.width = '0px'; this.ui.scalePowder.style.height = '0px'; }
                    if(this.ui.btnRepair) this.ui.btnRepair.style.display = 'none';
                    if (this.ui.explosionOverlay) this.ui.explosionOverlay.className = 'explosion-overlay';
                    if (this.ui.toxicCloud) { this.ui.toxicCloud.classList.remove('toxic-active'); this.ui.toxicCloud.style.opacity = 0; }

                    document.querySelectorAll('.desk-burn').forEach(burn => burn.remove());
                    if (this.ui.eqBoard) this.ui.eqBoard.classList.remove('show');

                    this.log(this.config.isAdmin ? "⚡ Instant Clear: ล้างโต๊ะเรียบร้อย" : "🛢️ ทำความสะอาดอุปกรณ์สำเร็จ");
                    if (this.config.isAdmin) this.updateLiveDataAdmin();
                    else this.checkStudentQuestProgress();
                }
            });
        }
        
        if (this.ui.btnCloseToolbox) this.ui.btnCloseToolbox.addEventListener('click', () => this.closeToolbox());
        
        if (this.ui.addAmtBtns) {
            this.ui.addAmtBtns.forEach(btn => { 
                btn.addEventListener('click', (e) => { 
                    this.state.activeTool.amount += parseFloat(e.currentTarget.dataset.val);
                    if (this.state.activeTool.amount < 0) this.state.activeTool.amount = 0;
                    if(this.ui.tbInputAmt) this.ui.tbInputAmt.value = this.state.activeTool.amount.toFixed(1);
                }); 
            });
        }

        if (this.ui.tbInputAmt) { 
            this.ui.tbInputAmt.addEventListener('input', (e) => { 
                let val = parseFloat(e.target.value); 
                this.state.activeTool.amount = (isNaN(val) || val < 0) ? 0 : val; 
            }); 
        }

        if (this.ui.btnConfirmPour) this.ui.btnConfirmPour.addEventListener('click', () => this.executePour());
        
        // Phase 1: คลิกสาร → ใช้ event delegation (ดู DOMContentLoaded ด้านล่าง)

        if (this.ui.btnPeriodic) {
            this.ui.btnPeriodic.addEventListener('click', () => {
                if(this.ui.ptModal) this.ui.ptModal.style.display = 'flex';
                setTimeout(() => { if(this.ui.ptBox) this.ui.ptBox.classList.add('show'); }, 10);
            });
        }
        
        if (this.ui.btnClosePT) {
            this.ui.btnClosePT.addEventListener('click', () => {
                if(this.ui.ptBox) this.ui.ptBox.classList.remove('show');
                if(this.ui.elDetailCard) this.ui.elDetailCard.style.display = 'none';
                setTimeout(() => { if(this.ui.ptModal) this.ui.ptModal.style.display = 'none'; }, 300);
            });
        }

        if (this.ui.btnAddPTElement) {
            this.ui.btnAddPTElement.addEventListener('click', () => {
                if (!this.currentPTElement) return;
                const el = this.currentPTElement;
                const gases = [1, 2, 7, 8, 9, 10, 17, 18, 36, 54, 86, 118];
                const liquids = [35, 80];
                let elemState = 'solid';
                if (gases.includes(el.z)) elemState = 'gas';
                if (liquids.includes(el.z)) elemState = 'liquid';

                const colors = { 'nonmetal': '#38bdf8', 'noble': '#c084fc', 'alkali': '#f87171', 'alkaline': '#fb923c', 'metalloid': '#4ade80', 'halogen': '#818cf8', 'transition': '#facc15', 'post-transition': '#a3e635', 'lanthanide': '#f472b6', 'actinide': '#fb7185' };
                let elemColor = colors[el.type] || '#ffffff';

                if(this.ui.ptBox) this.ui.ptBox.classList.remove('show');
                setTimeout(() => {
                    if(this.ui.ptModal) this.ui.ptModal.style.display = 'none';
                    if(this.ui.elDetailCard) this.ui.elDetailCard.style.display = 'none';
                    this.openToolbox({
                        id: 'pt_' + el.z, name: el.name + ' (' + el.sym + ')', state: elemState,
                        color: elemColor, type: el.type, bp: 100.0, formula: el.sym
                    });
                }, 350); 
            });
        }

        if (this.ui.chkGoggles) this.ui.chkGoggles.addEventListener('change', () => { if(!this.config.isAdmin) this.checkStudentQuestProgress(); });
        if (this.ui.chkGloves) this.ui.chkGloves.addEventListener('change', () => { if(!this.config.isAdmin) this.checkStudentQuestProgress(); });
        
        if (this.ui.searchBox) {
            this.ui.searchBox.addEventListener('input', (e) => {
                const keyword = e.target.value.toLowerCase().trim();
                this.ui.draggables.forEach(item => {
                    const name = item.dataset.name ? item.dataset.name.toLowerCase() : '';
                    const formulaEl = item.querySelector('.chem-formula');
                    const formula = formulaEl ? formulaEl.innerText.toLowerCase() : '';
                    item.style.display = (name.includes(keyword) || formula.includes(keyword)) ? 'flex' : 'none';
                });
            });
        }

        if (this.ui.btnMeasurePH) this.ui.btnMeasurePH.addEventListener('click', () => this.measurePH());
        
        if (this.ui.scaleObj) {
            this.ui.scaleObj.addEventListener('click', () => {
                this.audio.play('click');
                this.state.tareOffset = this.state.solidWeight + this.state.volume;
                if(this.ui.scaleDisplay) { 
                    this.ui.scaleDisplay.style.color = 'var(--primary)'; 
                    setTimeout(() => { if(this.ui.scaleDisplay) this.ui.scaleDisplay.style.color = 'var(--success)'; }, 200); 
                }
                this.log("⚖️ ตั้งศูนย์เครื่องชั่ง (TARE) สำเร็จ");
            });
        }

        if (this.ui.btnHeater) {
            this.ui.btnHeater.addEventListener('click', () => {
                if(this.state.isExploded) return;
                this.state.isHeating = !this.state.isHeating;
                if (this.state.isHeating) {
                    this.audio.play('heater_on'); 
                    this.ui.btnHeater.style.background = 'var(--danger)'; this.ui.btnHeater.innerHTML = '🔥 ปิดเตา';
                    document.body.classList.add('heating-active'); 
                    if(this.ui.flameFire) this.ui.flameFire.style.display = 'flex';
                    this.log("🔥 เปิดเตาให้ความร้อน");
                } else {
                    this.audio.play('heater_off'); 
                    this.ui.btnHeater.style.background = ''; this.ui.btnHeater.innerHTML = '🔥 เปิดเตา';
                    document.body.classList.remove('heating-active'); 
                    if(this.ui.flameFire) this.ui.flameFire.style.display = 'none';
                    this.log("❌ ปิดเตาความร้อน");
                }
            });
        }

        if (this.ui.btnStir) {
            this.ui.btnStir.addEventListener('click', () => {
                if(this.state.isExploded) return;
                this.state.isStirring = !this.state.isStirring;
                if (this.state.isStirring) {
                    this.audio.play('stirring');
                    this.ui.btnStir.style.background = 'var(--primary)';
                    this.ui.btnStir.style.boxShadow = '0 0 15px rgba(56,189,248,0.6)';
                    if(this.ui.stirRod) { this.ui.stirRod.style.display = 'block'; this.ui.stirRod.classList.add('stirring-anim'); }
                    this.state.targetWaveAmplitude = 10;
                    this.state.telemetry.didStir = true;
                    // เสียง dissolve ถ้ามี solid ในบีกเกอร์
                    const hasSolid = this.state.contents.some(c => c.state === 'solid' && c.amount > 0.05);
                    if (hasSolid) setTimeout(() => this.audio.play('dissolve'), 400);
                    if(!this.config.isAdmin) this.checkStudentQuestProgress();
                    this.log("🥄 เริ่มคนสารละลาย");
                } else {
                    this.audio.stop('stirring');
                    this.audio.play('click');
                    this.ui.btnStir.style.background = '';
                    this.ui.btnStir.style.boxShadow = '';
                    if(this.ui.stirRod) { this.ui.stirRod.classList.remove('stirring-anim'); setTimeout(()=> this.ui.stirRod.style.display='none', 200); }
                    this.log("หยุดคนสารละลาย");
                }
            });
        }

        if (this.ui.btnTempUp) {
            this.ui.btnTempUp.addEventListener('click', () => {
                if(this.state.isExploded) return;
                this.state.targetTemp = Math.min(250, this.state.targetTemp + 50);
                this.log(`⏩ เร่งอุณหภูมิไปที่: ${this.state.targetTemp}°C`);
            });
        }
        if (this.ui.btnTempDown) {
            this.ui.btnTempDown.addEventListener('click', () => {
                if(this.state.isExploded) return;
                this.state.targetTemp = Math.max(20, this.state.targetTemp - 50);
                this.log(`⏪ ลดอุณหภูมิไปที่: ${this.state.targetTemp}°C`);
            });
        }
        if (this.ui.btnRepair) {
            this.ui.btnRepair.addEventListener('click', () => {
                this.audio.play('click');
                if(this.ui.btnWash) this.ui.btnWash.click(); 
                this.log("🛠️ เสกบีกเกอร์ใบใหม่เรียบร้อยแล้ว!");
            });
        }
        if (this.ui.btnDevTools) {
            this.ui.btnDevTools.addEventListener('click', () => this.generateAssignmentJSON());
        }
        if (this.ui.btnCloseDev) {
            this.ui.btnCloseDev.addEventListener('click', () => {
                if(this.ui.devBox) this.ui.devBox.classList.remove('show');
                setTimeout(() => { if(this.ui.devModal) this.ui.devModal.style.display = 'none'; }, 300);
            });
        }
        if (this.ui.btnCopyJson) {
            this.ui.btnCopyJson.addEventListener('click', () => {
                if(this.ui.devJsonOutput) {
                    this.ui.devJsonOutput.select();
                    document.execCommand('copy');
                    this.ui.btnCopyJson.innerText = "✅ ก๊อปปี้สำเร็จ!";
                    setTimeout(() => this.ui.btnCopyJson.innerText = "📋 คัดลอกโค้ด JSON", 2000);
                }
            });
        }
    }

    bindDragAndDrop() {
        window.addEventListener('dragover', (e) => { e.preventDefault(); });
        window.addEventListener('drop', (e) => { e.preventDefault(); });

        // Phase 0: ปิด drag (ใช้ระบบภาชนะแทน)
        if (false) this.ui.draggables.forEach(item => {
            item.addEventListener('dragstart', (e) => {
                e.dataTransfer.effectAllowed = 'copy';
                const dragData = {
                    id:          item.dataset.id,
                    name:        item.dataset.name,
                    state:       item.dataset.state,
                    color:       item.dataset.color,
                    type:        item.dataset.type,
                    bp:          parseFloat(item.dataset.bp) || 100.0,
                    formula:     item.dataset.formula || item.dataset.name,
                    ph_value:    item.dataset.ph !== '' ? parseFloat(item.dataset.ph) : null,
                    real_world:  item.dataset.realWorld  || '',
                    acid_explain:item.dataset.acidExplain || '',
                    description: item.dataset.description || '',
                    texture:     item.dataset.texture || '',
                    hazard:      parseInt(item.dataset.hazard) || 0,
                    toxicity:    parseInt(item.dataset.toxicity) || 0,
                };
                e.dataTransfer.setData('text/plain', JSON.stringify(dragData));
                item.classList.add('dragging');
            });
            item.addEventListener('dragend', () => item.classList.remove('dragging'));

            item.addEventListener('touchstart', (e) => {
                if(window.innerWidth > 768 || this.state.isExploded) return; 
                const touch = e.touches[0];
                if (this.mobileDragClone) this.mobileDragClone.remove(); 
                this.mobileDragClone = document.createElement('div');
                this.mobileDragClone.className = 'drag-clone-mobile';
                this.mobileDragClone.innerHTML = `<div class="chem-color-indicator" style="background:${item.dataset.color}"></div> ${item.dataset.name}`;
                this.mobileDragClone.dataset.id = item.dataset.id; this.mobileDragClone.dataset.name = item.dataset.name; 
                this.mobileDragClone.dataset.state = item.dataset.state; this.mobileDragClone.dataset.color = item.dataset.color; 
                this.mobileDragClone.dataset.type = item.dataset.type; this.mobileDragClone.dataset.bp = item.dataset.bp;
                this.mobileDragClone.dataset.formula = item.dataset.formula || item.dataset.name;
                document.body.appendChild(this.mobileDragClone);
                this.moveClone(touch.clientX, touch.clientY);
                e.preventDefault(); 
            }, {passive: false});

            item.addEventListener('touchmove', (e) => {
                if(!this.mobileDragClone) return; e.preventDefault();
                const touch = e.touches[0]; this.moveClone(touch.clientX, touch.clientY);
                if(this.isOverBeaker(touch.clientX, touch.clientY) && this.ui.beaker) this.ui.beaker.classList.add('drag-over');
                else if (this.ui.beaker) this.ui.beaker.classList.remove('drag-over');
            }, {passive: false});

            item.addEventListener('touchend', (e) => {
                if(!this.mobileDragClone) return;
                if (this.ui.beaker) this.ui.beaker.classList.remove('drag-over');
                const touch = e.changedTouches[0];
                if(this.isOverBeaker(touch.clientX, touch.clientY)) {
                    this.openToolbox({ 
                        id: this.mobileDragClone.dataset.id, name: this.mobileDragClone.dataset.name, 
                        state: this.mobileDragClone.dataset.state, color: this.mobileDragClone.dataset.color, 
                        type: this.mobileDragClone.dataset.type, bp: parseFloat(this.mobileDragClone.dataset.bp) || 100.0,
                        formula: this.mobileDragClone.dataset.formula || this.mobileDragClone.dataset.name
                    });
                }
                this.mobileDragClone.remove(); this.mobileDragClone = null;
            });
        });

        if (this.ui.beakerDropzone) {
            this.ui.beakerDropzone.addEventListener('dragenter', (e) => { 
                e.preventDefault(); e.stopPropagation(); 
                if(this.state.isExploded) return; 
                if (this.ui.beaker) this.ui.beaker.classList.add('drag-over'); 
            });
            
            this.ui.beakerDropzone.addEventListener('dragover', (e) => { 
                e.preventDefault(); e.stopPropagation(); 
                if(this.state.isExploded) return; 
                e.dataTransfer.dropEffect = 'copy'; 
                if (this.ui.beaker) this.ui.beaker.classList.add('drag-over'); 
            });
            
            this.ui.beakerDropzone.addEventListener('dragleave', (e) => { 
                e.preventDefault(); e.stopPropagation(); 
                if (this.ui.beaker) this.ui.beaker.classList.remove('drag-over'); 
            });
            
            // Phase 0: ปิด drop เก่า (ใช้ระบบภาชนะแทน)
        }
    }

    moveClone(x, y) { if (this.mobileDragClone) { this.mobileDragClone.style.left = x + 'px'; this.mobileDragClone.style.top = y + 'px'; } }
    
    isOverBeaker(x, y) {
        if(!this.ui.beaker) return false;
        const rect = this.ui.beaker.getBoundingClientRect();
        return (x >= rect.left - 60 && x <= rect.right + 60 && y >= rect.top - 60 && y <= rect.bottom + 60);
    }

    renderPeriodicTable() {
        const grid = this.ui.periodicGrid;
        if (!grid) return;
        grid.innerHTML = '';
        
        for (let r = 1; r <= 10; r++) {
            for (let c = 1; c <= 18; c++) {
                let cell = document.createElement('div');
                cell.className = `grid-cell r${r}-c${c}`;
                cell.style.gridColumn = c;
                cell.style.gridRow = r;
                grid.appendChild(cell);
            }
        }
        
        PeriodicElementsData.forEach(el => {
            const cell = grid.querySelector(`.r${el.r}-c${el.c}`);
            if (cell) {
                cell.className = `element-cell type-${el.type}`;
                cell.setAttribute('data-z', el.z);
                cell.setAttribute('data-type', el.type);
                const sp = spriteStyle(el.z);
                cell.innerHTML = `
                    ${sp ? `<div class="el-sprite-mini" style="${sp}"></div>` : ''}
                    <div class="el-num">${el.z}</div>
                    <div class="el-sym">${el.sym}</div>
                    <div class="el-name">${el.name}</div>
                `;
            }
        });

        // ── Filter buttons ──
        const filterBar = document.getElementById('ptFilters');
        if (filterBar && !filterBar._bound) {
            filterBar._bound = true;
            const metalTypes = ['alkali','alkaline','transition','post-transition','lanthanide','actinide','metal'];
            const radioactiveZ = [43,61,84,85,86,87,88,89,90,91,92,93,94,95,96,97,98,99,100,101,102,103,104,105,106,107,108,109,110,111,112,113,114,115,116,117,118];
            filterBar.addEventListener('click', (e) => {
                const btn = e.target.closest('.pt-filter');
                if (!btn) return;
                filterBar.querySelectorAll('.pt-filter').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const f = btn.dataset.filter;
                grid.querySelectorAll('.element-cell').forEach(cell => {
                    const t = cell.dataset.type;
                    const z = parseInt(cell.dataset.z);
                    let show = true;
                    if (f === 'metal') show = metalTypes.includes(t);
                    else if (f === 'nonmetal') show = (t === 'nonmetal' || t === 'halogen');
                    else if (f === 'noble') show = (t === 'noble');
                    else if (f === 'metalloid') show = (t === 'metalloid');
                    else if (f === 'radioactive') show = radioactiveZ.includes(z);
                    cell.style.opacity = show ? '1' : '0.15';
                    cell.style.filter = show ? 'none' : 'grayscale(0.8)';
                });
            });
        }

        grid.addEventListener('click', (e) => {
            const cell = e.target.closest('.element-cell');
            if (cell) {
                const targetZ = parseInt(cell.getAttribute('data-z'));
                const targetEl = PeriodicElementsData.find(item => item.z === targetZ);
                if (targetEl) {
                    this.showElementDetail(targetEl);
                }
            }
        });
    }

    showElementDetail(el) {
        if(!this.ui.elDetailCard) return;
        this.currentPTElement = el; 

        let dtSym = document.getElementById('dtSym');
        let dtNum = document.getElementById('dtNum');
        let dtName = document.getElementById('dtName');
        let dtMass = document.getElementById('dtMass');
        let dtDesc = document.getElementById('dtDesc');
        let dtState = document.getElementById('dtState');
        let dtType = document.getElementById('dtType');

        if(dtSym) dtSym.innerText = el.sym;
        // รูปใหญ่ sprite
        const dtSprite = document.getElementById('dtSpriteLg');
        if (dtSprite) {
            const sp = spriteStyle(el.z);
            if (sp) { dtSprite.style.cssText = sp + 'width:90px;height:90px;border-radius:10px;background-repeat:no-repeat;background-size:600% 500%;border:1px solid rgba(56,189,248,0.2);flex-shrink:0;'; dtSprite.style.display='block'; }
            else { dtSprite.style.display='none'; }
        }
        if(dtNum) dtNum.innerText = el.z;
        if(dtName) dtName.innerText = el.name;
        if(dtMass) dtMass.innerText = `Mass: ${el.mass}`;
        if(dtDesc) dtDesc.innerText = el.desc;
        
        const gases = [1, 2, 7, 8, 9, 10, 17, 18, 36, 54, 86, 118];
        const liquids = [35, 80];
        let stateStr = "Solid (ของแข็ง)";
        if (gases.includes(el.z)) stateStr = "Gas (ก๊าซ)";
        if (liquids.includes(el.z)) stateStr = "Liquid (ของเหลว)";
        
        if(dtState) dtState.innerHTML = `สถานะ: <span style="font-weight:bold; color:white;">${stateStr}</span>`;

        if(dtType) {
            dtType.innerText = el.type;
            const colors = { 'nonmetal': '#38bdf8', 'noble': '#c084fc', 'alkali': '#f87171', 'alkaline': '#fb923c', 'metalloid': '#4ade80', 'halogen': '#818cf8', 'transition': '#facc15', 'post-transition': '#a3e635', 'lanthanide': '#f472b6', 'actinide': '#fb7185' };
            let hexStr = colors[el.type] || '#ffffff';
            this.colorParserCtx.fillStyle = '#ffffff'; this.colorParserCtx.fillStyle = hexStr;
            let hex = this.colorParserCtx.fillStyle; 
            let rgbStr = "255,255,255";
            if (hex.startsWith('#') && hex.length === 7) { rgbStr = `${parseInt(hex.slice(1, 3), 16)}, ${parseInt(hex.slice(3, 5), 16)}, ${parseInt(hex.slice(5, 7), 16)}`; }
            dtType.style.background = `rgba(${rgbStr}, 0.2)`;
            dtType.style.color = hexStr;
            dtType.style.border = `1px solid ${hexStr}`;
        }

        // ── Rich sections จาก PT_DETAIL ──
        const sec = document.getElementById('dtSections');
        if (sec && typeof PT_DETAIL !== 'undefined' && PT_DETAIL[el.z]) {
            const d = PT_DETAIL[el.z];
            const fmt = (v, unit) => (v === null || v === undefined) ? '—' : v + (unit || '');
            // hazard level
            let hzClass = 'low';
            if (/ร้ายแรง|ก่อมะเร็ง|พิษร้าย|ระเบิด|กัมมันตรังสี/.test(d.hazard)) hzClass = 'high';
            else if (/พิษ|กัดกร่อน|ไวไฟ|ระคาย/.test(d.hazard)) hzClass = 'mid';

            let html = '';
            // Electron config
            html += `<div class="edc-sec"><div class="edc-sec-title">การจัดเรียงอิเล็กตรอน</div><div class="edc-config">${d.config}</div></div>`;
            // Physical properties
            html += `<div class="edc-sec"><div class="edc-sec-title">คุณสมบัติทางกายภาพ</div><div class="edc-grid2">
                <div class="edc-prop"><div class="edc-prop-label">🌡️ จุดหลอมเหลว</div><div class="edc-prop-val">${fmt(d.mp,'°C')}</div></div>
                <div class="edc-prop"><div class="edc-prop-label">💨 จุดเดือด</div><div class="edc-prop-val">${fmt(d.bp,'°C')}</div></div>
                <div class="edc-prop"><div class="edc-prop-label">⚖️ ความหนาแน่น</div><div class="edc-prop-val">${fmt(d.density,' g/cm³')}</div></div>
                <div class="edc-prop"><div class="edc-prop-label">⚡ EN (Pauling)</div><div class="edc-prop-val">${fmt(d.en)}</div></div>
            </div></div>`;
            // Key info
            html += `<div class="edc-sec"><div class="edc-sec-title">ข้อมูลสำคัญ</div><div class="edc-grid2">
                <div class="edc-prop"><div class="edc-prop-label">เลขออกซิเดชัน</div><div class="edc-prop-val" style="font-size:0.72rem">${d.oxid}</div></div>
                <div class="edc-prop"><div class="edc-prop-label">รัศมีอะตอม</div><div class="edc-prop-val">${fmt(d.radius,' pm')}</div></div>
                <div class="edc-prop"><div class="edc-prop-label">โครงสร้างผลึก</div><div class="edc-prop-val" style="font-size:0.68rem">${d.crystal}</div></div>
                <div class="edc-prop"><div class="edc-prop-label">Block</div><div class="edc-prop-val" style="font-size:0.72rem">${d.block}</div></div>
            </div></div>`;
            // Discovery
            html += `<div class="edc-sec"><div class="edc-sec-title">การค้นพบ</div><div style="font-size:0.75rem;color:#cbd5e1">${d.discover}</div></div>`;
            // Uses
            if (d.uses && d.uses.length) {
                html += `<div class="edc-sec"><div class="edc-sec-title">การใช้งาน</div><div>${d.uses.map(u=>`<span class="edc-chip">✓ ${u}</span>`).join('')}</div></div>`;
            }
            // Safety
            html += `<div class="edc-sec"><div class="edc-sec-title">ความปลอดภัย</div><div class="edc-hazard ${hzClass}">${hzClass==='high'?'⚠️':hzClass==='mid'?'⚡':'✅'} ${d.hazard}</div></div>`;
            // Compounds
            if (d.compounds && d.compounds.length) {
                html += `<div class="edc-sec"><div class="edc-sec-title">สารประกอบที่พบบ่อย</div>${d.compounds.map(([f,n])=>`<div class="edc-compound"><span class="edc-compound-f">${f}</span><span class="edc-compound-n">${n}</span></div>`).join('')}</div>`;
            }
            // Description
            if (el.desc) {
                html += `<div class="edc-sec"><div class="edc-sec-title">คำอธิบาย</div><div style="font-size:0.75rem;color:#cbd5e1;line-height:1.5">${el.desc}</div></div>`;
            }
            sec.innerHTML = html;
        } else if (sec) {
            sec.innerHTML = `<div class="edc-sec"><div style="font-size:0.8rem;color:#cbd5e1;line-height:1.5">${el.desc||''}</div></div>`;
        }

        this.ui.elDetailCard.style.display = 'block';
        this.ui.elDetailCard.scrollTop = 0;
    }
}

// 🚀 Boot Sequence
document.addEventListener('DOMContentLoaded', () => {
    window.LabApp = new VirtualLabEngine();
});

// ── Mobile Panel Accordion ─────────────────────────────────


// ═══════════════════════════════════════════════════════════════
//  ใส่รูปธาตุจากตารางธาตุ ให้สารในคลังที่เป็นธาตุ
// ═══════════════════════════════════════════════════════════════
function applyElementIcons() {
    // map สัญลักษณ์ธาตุ → เลขอะตอม (1-118)
    const SYM2Z = {
        H:1,He:2,Li:3,Be:4,B:5,C:6,N:7,O:8,F:9,Ne:10,Na:11,Mg:12,Al:13,Si:14,P:15,S:16,Cl:17,Ar:18,
        K:19,Ca:20,Sc:21,Ti:22,V:23,Cr:24,Mn:25,Fe:26,Co:27,Ni:28,Cu:29,Zn:30,Ga:31,Ge:32,As:33,Se:34,Br:35,Kr:36,
        Rb:37,Sr:38,Y:39,Zr:40,Nb:41,Mo:42,Tc:43,Ru:44,Rh:45,Pd:46,Ag:47,Cd:48,In:49,Sn:50,Sb:51,Te:52,I:53,Xe:54,
        Cs:55,Ba:56,La:57,Ce:58,Pr:59,Nd:60,Pm:61,Sm:62,Eu:63,Gd:64,Tb:65,Dy:66,Ho:67,Er:68,Tm:69,Yb:70,Lu:71,
        Hf:72,Ta:73,W:74,Re:75,Os:76,Ir:77,Pt:78,Au:79,Hg:80,Tl:81,Pb:82,Bi:83,Po:84,At:85,Rn:86,
        Fr:87,Ra:88,Ac:89,Th:90,Pa:91,U:92,Np:93,Pu:94,Am:95,Cm:96,Bk:97,Cf:98,Es:99,Fm:100,Md:101,No:102,Lr:103,
        Rf:104,Db:105,Sg:106,Bh:107,Hs:108,Mt:109,Ds:110,Rg:111,Cn:112,Nh:113,Fl:114,Mc:115,Lv:116,Ts:117,Og:118
    };
    // ชื่ออังกฤษธาตุ → เลขอะตอม (ธาตุใน DB เก็บชื่อไทย(อังกฤษ) แต่ formula ว่าง)
    const NAME2Z = {
        hydrogen:1,helium:2,lithium:3,beryllium:4,boron:5,carbon:6,nitrogen:7,oxygen:8,fluorine:9,neon:10,
        sodium:11,magnesium:12,aluminium:13,aluminum:13,silicon:14,phosphorus:15,sulfur:16,sulphur:16,chlorine:17,argon:18,
        potassium:19,calcium:20,scandium:21,titanium:22,vanadium:23,chromium:24,manganese:25,iron:26,cobalt:27,nickel:28,
        copper:29,zinc:30,gallium:31,germanium:32,arsenic:33,selenium:34,bromine:35,krypton:36,rubidium:37,strontium:38,
        yttrium:39,zirconium:40,niobium:41,molybdenum:42,technetium:43,ruthenium:44,rhodium:45,palladium:46,silver:47,cadmium:48,
        indium:49,tin:50,antimony:51,tellurium:52,iodine:53,xenon:54,cesium:55,caesium:55,barium:56,lanthanum:57,cerium:58,
        praseodymium:59,neodymium:60,promethium:61,samarium:62,europium:63,gadolinium:64,terbium:65,dysprosium:66,holmium:67,
        erbium:68,thulium:69,ytterbium:70,lutetium:71,hafnium:72,tantalum:73,tungsten:74,rhenium:75,osmium:76,iridium:77,
        platinum:78,gold:79,mercury:80,thallium:81,lead:82,bismuth:83,polonium:84,astatine:85,radon:86,francium:87,radium:88,
        actinium:89,thorium:90,protactinium:91,uranium:92,neptunium:93,plutonium:94,americium:95,curium:96,berkelium:97,
        californium:98,einsteinium:99,fermium:100,mendelevium:101,nobelium:102,lawrencium:103,rutherfordium:104,dubnium:105,
        seaborgium:106,bohrium:107,hassium:108,meitnerium:109,darmstadtium:110,roentgenium:111,copernicium:112,nihonium:113,
        flerovium:114,moscovium:115,livermorium:116,tennessine:117,oganesson:118
    };

    function findZ(item) {
        // 1) จาก formula (ถ้าตรงสัญลักษณ์ธาตุ)
        const f = (item.dataset.formula || '').trim();
        if (SYM2Z[f]) return SYM2Z[f];
        // 2) จากชื่ออังกฤษในวงเล็บ เช่น "กำมะถัน (Sulfur)"
        const name = item.dataset.name || '';
        const m = name.match(/\(([^)]+)\)/);
        if (m) {
            const eng = m[1].trim().toLowerCase();
            // เอาคำแรก (กัน "Copper Plate" → copper)
            const first = eng.split(/[\s\/]+/)[0];
            if (NAME2Z[eng]) return NAME2Z[eng];
            if (NAME2Z[first]) return NAME2Z[first];
        }
        return null;
    }

    document.querySelectorAll('.chem-draggable').forEach(item => {
        const z = findZ(item);
        if (!z) return;             // ไม่ใช่ธาตุ → คงไอคอน emoji เดิม
        if (typeof getElementSprite !== 'function') return;

        const sp = getElementSprite(z);
        if (!sp || !sp.exists) return;

        const icon = item.querySelector('.chem-icon');
        if (!icon) return;
        // ใส่รูป sprite (ทับ emoji)
        icon.classList.add('chem-icon--element');
        icon.style.backgroundImage = `url('${sp.sheet}')`;
        icon.style.backgroundPosition = `${sp.posX}% ${sp.posY}%`;
        icon.style.backgroundSize = '600% 500%';
        icon.style.backgroundRepeat = 'no-repeat';
    });
}

// ═══════════════════════════════════════════════════════════════
//  ระบบแท็บแผงควบคุม — ย้าย Live Data + Temperature เข้าแท็บ
// ═══════════════════════════════════════════════════════════════
function initControlTabs() {
    // ย้าย element เข้าแท็บ (ถ้ายังไม่ย้าย)
    const ld = document.getElementById('liveDataHUD');
    const tabData = document.getElementById('tabData');
    if (ld && tabData && ld.parentElement !== tabData) {
        tabData.appendChild(ld);
    }
    // quest HUD (โหมดนักเรียน) ก็ย้ายเข้าแท็บข้อมูลด้วย
    const qh = document.getElementById('questHUD');
    if (qh && tabData && qh.parentElement !== tabData) {
        tabData.appendChild(qh);
    }
    const sensor = document.querySelector('.sensor-panel');
    const tabTemp = document.getElementById('tabTemp');
    if (sensor && tabTemp && sensor.parentElement !== tabTemp) {
        tabTemp.appendChild(sensor);
    }

    // สลับแท็บ
    const tabs = document.querySelectorAll('.ctrl-tab');
    const panels = {
        controls: document.getElementById('tabControls'),
        data: document.getElementById('tabData'),
        temp: document.getElementById('tabTemp'),
    };
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.tab;
            tabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            Object.keys(panels).forEach(k => {
                if (panels[k]) panels[k].style.display = (k === target) ? 'block' : 'none';
            });
            if (window.LabApp && LabApp.audio) LabApp.audio.play('click');
        });
    });

    // ── ปุ่มเปิด/ปิด 3 แผง ──
    const setupToggle = (btnId, panelId, cls, bodyCls, openIcon, closeIcon) => {
        const btn = document.getElementById(btnId);
        const panel = document.getElementById(panelId);
        if (!btn || !panel) return;
        btn.addEventListener('click', () => {
            const hidden = panel.classList.toggle(cls);
            document.body.classList.toggle(bodyCls, hidden);
            btn.textContent = hidden ? closeIcon : openIcon;
            if (window.LabApp && LabApp.audio) LabApp.audio.play('click');
        });
    };
    setupToggle('toggleLeftBtn',   'panelLeft',  'panel-hidden', 'left-hidden',       '◀', '▶');
    setupToggle('toggleRightBtn',  'panelRight', 'panel-hidden', 'right-hidden',      '▶', '◀');
    setupToggle('toggleBottomBtn', 'equipTray',  'tray-hidden',  'tray-hidden-state', '▼', '▲');
}


function togglePanel(id) {
    const panel = document.getElementById(id);
    if (!panel) return;
    panel.classList.toggle('collapsed');
}

// ── Auto-collapse panels on small screens ─────────────────
if (window.innerWidth < 768) {
    document.addEventListener('DOMContentLoaded', () => {
        // collapse chem panel by default on mobile (save space)
        const left = document.getElementById('panelLeft');
        if (left) left.classList.add('collapsed');
    });
}



/* ═══════════ PHASE 5: 3D ENGINE + MOL VIEWER + PERIODIC INTERACTIVE ═══════════ */
/**
 * ============================================================
 * PHASE 5 — 3D Visual Engine + Molecular Viewer + Periodic Table Interactive
 * ============================================================
 * วิธีใช้: แทรกก่อน <\/script> สุดท้ายใน lab_realistic.php
 *
 * ครอบคลุม:
 *  A. 3D Mode Toggle — สลับ 2D ↔ 3D ด้วย Three.js (CDN r128)
 *  B. Liquid shader ใน 3D — สีเปลี่ยนตาม beaker state, viscosity wave
 *  C. Solid rendering ใน 3D — crystal/powder/metal chunk แยกตาม type
 *  D. Gas particles ใน 3D — สีแยก H2/CO2/SO2/Cl2/O2
 *  E. Reaction Flash ใน 3D — color burst + camera shake
 *  F. Molecular Viewer (3Dmol.js CDN) — popup โมเลกุล 3D หลัง reaction
 *  G. Periodic Table Interactive — คลิก element → เพิ่มสารลงบีกเกอร์ได้เลย
 * ============================================================
 */

// ═══════════════════════════════════════════════════════════════
// STEP 6 — Lab3DManager อัปเกรด: LatheGeometry Beaker + Lab Room
//           Multi-vessel + Particle Pool + Flame Light
// ═══════════════════════════════════════════════════════════════

// ─── Particle Pool (reuse แทน GC) ──────────────────────────────
const _ParticlePool = {
    _pool: [],
    get() {
        if (this._pool.length > 0) return this._pool.pop();
        return null;
    },
    release(p) {
        if (this._pool.length < 200) {
            p.visible = false;
            this._pool.push(p);
        }
    },
};

// ─── Color Hex → THREE.Color helper ────────────────────────────
function hexToThreeColor(hex) {
    if (!hex || hex === 'ใส') return new THREE.Color(0.22, 0.74, 0.97);
    if (hex.startsWith('#') && hex.length === 7) return new THREE.Color(hex);
    return new THREE.Color(0.67, 0.84, 0.97);
}

// ─── LatheGeometry Beaker Profile ──────────────────────────────
function buildBeakerGeometry() {
    // Profile จากซ้ายล่าง (ก้น) → ขวาบน (ปาก) เป็น [radius, y]
    const pts = [
        [2.60, 0.00],  // ก้นใน
        [2.62, 0.15],  // ก้นหนา
        [2.68, 0.80],  // รัศมีขยายเล็กน้อย
        [2.80, 3.50],  // ลำตัว
        [2.85, 5.20],  // ใกล้ปาก
        [2.90, 6.50],  // ปาก
        [3.00, 6.80],  // ขอบ spout บน
        [3.10, 7.00],  // ปลาย spout
    ];
    const points = pts.map(([r, y]) => new THREE.Vector2(r, y));
    return new THREE.LatheGeometry(points, 48);
}

// ─── Lab3DManager (อัปเกรดทั้งหมด) ────────────────────────────
const Lab3DManager = {
    active:       false,
    scene:        null,
    camera:       null,
    renderer:     null,
    animId:       null,
    shakeFrames:  0,
    _camOrigin:   null,
    _container:   null,
    _syncCounter: 0,

    // Beaker objects
    beakerGroup:  null,
    liquidMesh:   null,
    liquidMat:    null,
    surfaceMesh:  null,
    solidGroup:   null,
    _lastSolidKey: '',

    // Particles (active list — pool ใน _ParticlePool)
    _particles:   [],

    // Lights
    _flamePt:     null,   // PointLight สีส้ม (ตะเกียง)
    _spotLight:   null,

    // ── Boot ──────────────────────────────────────────────────
    init(container) {
        if (!container) return;
        this._container = container;
        if (typeof THREE !== 'undefined') {
            // THREE.js พร้อมแล้ว
            this._boot(container);
        } else if (window._threeJSLoaded) {
            // preloaded แต่ global ยังไม่ assign — รอสักครู่
            setTimeout(() => this._boot(container), 100);
        } else {
            // โหลด THREE.js
            const s = document.createElement('script');
            s.src = 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js';
            s.onload  = () => this._boot(container);
            s.onerror = () => {
                console.error('[3D] THREE.js load failed');
                const c3d = document.getElementById('lab3DContainer');
                if (c3d) c3d.innerHTML = '<div style="color:#ef4444;padding:20px;text-align:center;font-family:Prompt">❌ โหลด Three.js ไม่ได้<br><small style="color:#475569">ตรวจสอบ internet connection</small></div>';
            };
            document.head.appendChild(s);
        }
    },

    _boot(container) {
        try {
            this.scene = new THREE.Scene();
            this.scene.background = new THREE.Color(0x111e2e);  // lab room background
            this.scene.fog = new THREE.FogExp2(0x111e2e, 0.004);  // fog บางมาก

            // container เป็น position:fixed fullscreen — display:block แล้ว
            const w = Math.max(400, container.clientWidth  || window.innerWidth  || 1200);
            const h = Math.max(300, container.clientHeight || window.innerHeight || 800);
            console.log('[3D] Container size:', w, 'x', h);
            if (w < 10 || h < 10) {
                console.error('[3D] Container too small:', w, h, '— abort');
                return;
            }
            this.camera = new THREE.PerspectiveCamera(55, w / h, 0.1, 200);
            this.camera.position.set(0, 6, 16);
            this.camera.lookAt(0, 1, 0);
            this._camOrigin = this.camera.position.clone();

            this.renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false });
            this.renderer.setSize(w, h);
            this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
            this.renderer.toneMapping       = THREE.ACESFilmicToneMapping;
            this.renderer.toneMappingExposure = 1.15;
            this.renderer.shadowMap.enabled = true;
            this.renderer.shadowMap.type    = THREE.PCFSoftShadowMap;
            this.renderer.domElement.style.cssText =
                'position:absolute;top:0;left:0;width:100%;height:100%;touch-action:none;display:block;';
            // ล้าง loading indicator ก่อน append renderer
            container.innerHTML = '';
            container.appendChild(this.renderer.domElement);

            // Touch isolation
            ['touchstart','touchmove','touchend'].forEach(ev =>
                this.renderer.domElement.addEventListener(ev, e => e.stopPropagation(), { passive:false })
            );

            this._initOrbit();
            this._buildLights();
            this._buildLabRoom();
            this._buildBeaker();
            this._buildParticlePool();

            this._resizeFn = () => this._onResize();
            window.addEventListener('resize', this._resizeFn);

            this.active = true;      // ต้องตั้งก่อน _animate (ไม่งั้น loop ไม่เริ่ม → จอดำ)
            this._animate();
            console.log('[3D] ✅ Lab room scene ready (Step 6)');
        } catch(e) {
            console.error('[3D] Boot error:', e);
        }
    },

    // ── Manual Orbit (r128 compatible) ───────────────────────
    _initOrbit() {
        const dom = this.renderer.domElement;
        let isDown = false, lastX = 0, lastY = 0;
        let theta = 0, phi = Math.PI / 4.2, radius = 18;
        const target = new THREE.Vector3(0, 1.5, 0);

        const upd = () => {
            this.camera.position.set(
                target.x + radius * Math.sin(phi) * Math.sin(theta),
                target.y + radius * Math.cos(phi),
                target.z + radius * Math.sin(phi) * Math.cos(theta)
            );
            this._camOrigin = this.camera.position.clone();
            this.camera.lookAt(target);
        };
        upd();

        dom.addEventListener('mousedown',  e => { isDown=true; lastX=e.clientX; lastY=e.clientY; });
        dom.addEventListener('mousemove',  e => {
            if (!isDown) return;
            theta -= (e.clientX - lastX) * 0.009;
            phi    = Math.max(0.08, Math.min(Math.PI/2.1, phi + (e.clientY - lastY) * 0.009));
            lastX=e.clientX; lastY=e.clientY; upd();
        });
        dom.addEventListener('mouseup',    () => isDown=false);
        dom.addEventListener('mouseleave', () => isDown=false);
        dom.addEventListener('wheel',      e => { radius=Math.max(6,Math.min(28,radius+e.deltaY*0.012)); upd(); });

        let lx=0,ly=0,lp=0;
        dom.addEventListener('touchstart', e => {
            if (e.touches.length===1){lx=e.touches[0].clientX;ly=e.touches[0].clientY;}
            if (e.touches.length===2) lp=Math.hypot(e.touches[0].clientX-e.touches[1].clientX,e.touches[0].clientY-e.touches[1].clientY);
        },{passive:true});
        dom.addEventListener('touchmove', e => {
            if (e.touches.length===1){
                theta-=(e.touches[0].clientX-lx)*0.009;
                phi=Math.max(0.08,Math.min(Math.PI/2.1,phi+(e.touches[0].clientY-ly)*0.009));
                lx=e.touches[0].clientX;ly=e.touches[0].clientY;upd();
            }
            if (e.touches.length===2){
                const d=Math.hypot(e.touches[0].clientX-e.touches[1].clientX,e.touches[0].clientY-e.touches[1].clientY);
                radius=Math.max(6,Math.min(28,radius-(d-lp)*0.04));lp=d;upd();
            }
        },{passive:true});
    },

    // ── Lighting ──────────────────────────────────────────────
    _buildLights() {
        // Ambient
        this.scene.add(new THREE.AmbientLight(0xffffff, 1.0));   // ห้องสว่าง
        // Hemisphere: warm sky + cool ground
        this.scene.add(new THREE.HemisphereLight(0xfff8f0, 0x223344, 0.8));

        // Warm SpotLight จากบน (โคมไฟห้อง)
        this._spotLight = new THREE.SpotLight(0xfff5e0, 3.0, 80, Math.PI/3.5, 0.3, 1.0);
        this._spotLight.position.set(2, 14, 8);
        this._spotLight.target.position.set(0, 0, 0);
        this._spotLight.castShadow = true;
        this._spotLight.shadow.mapSize.width = this._spotLight.shadow.mapSize.height = 1024;
        this.scene.add(this._spotLight);
        this.scene.add(this._spotLight.target);

        // Directional fill light (จากด้านหน้า-บน)
        const dirFill = new THREE.DirectionalLight(0xd0e8ff, 0.9);
        dirFill.position.set(2, 12, 14);
        dirFill.castShadow = false;
        this.scene.add(dirFill);

        // Warm fill จากซ้าย
        const warmFill = new THREE.DirectionalLight(0xffe4c8, 0.4);
        warmFill.position.set(-10, 5, 3);
        this.scene.add(warmFill);

        // Cool RimLight (น้ำเงิน ด้านหลัง)
        const rim = new THREE.PointLight(0x3b82f6, 0.7, 25);
        rim.position.set(-8, 6, -8);
        this.scene.add(rim);

        // Flame PointLight (ส้ม — ปิดอยู่จนกว่าจะจุดตะเกียง)
        this._flamePt = new THREE.PointLight(0xff7a22, 0, 12, 2);
        this._flamePt.position.set(-4, 2, 0);
        this.scene.add(this._flamePt);
    },

    // ── Lab Room Scene ─────────────────────────────────────────
    _buildLabRoom() {
        // ── โต๊ะทดลอง (scale: 1 unit ≈ 10cm) ──
        // โต๊ะอยู่ที่ y=0 ผิวโต๊ะ = y=0.3 (ความหนา 0.3)
        const deskMat = new THREE.MeshStandardMaterial({ color:0x3d2b1a, roughness:0.8, metalness:0 });
        const top = new THREE.Mesh(new THREE.BoxGeometry(20, 0.4, 10), deskMat);
        top.position.set(0, 0.2, 0);
        top.receiveShadow = true;
        top.castShadow    = true;
        this.scene.add(top);

        // ขอบโต๊ะ (trim สีอ่อนกว่า)
        const trimMat = new THREE.MeshStandardMaterial({ color:0x4a3520, roughness:0.7 });
        [   // [x, y, z, w, h, d]
            [0, 0.41, -4.9,  20, 0.08, 0.2],  // ขอบหน้า
            [0, 0.41,  4.9,  20, 0.08, 0.2],  // ขอบหลัง
            [-9.9, 0.41, 0,  0.2, 0.08, 10],  // ขอบซ้าย
            [ 9.9, 0.41, 0,  0.2, 0.08, 10],  // ขอบขวา
        ].forEach(([x,y,z,w,h,d]) => {
            const m = new THREE.Mesh(new THREE.BoxGeometry(w,h,d), trimMat);
            m.position.set(x,y,z); this.scene.add(m);
        });

        // ขาโต๊ะ
        const legMat = new THREE.MeshStandardMaterial({ color:0x2a1a0a, roughness:0.9 });
        [[-8.5,-2.5,-3.8],[-8.5,-2.5,3.8],[8.5,-2.5,-3.8],[8.5,-2.5,3.8]].forEach(([x,y,z]) => {
            const leg = new THREE.Mesh(new THREE.BoxGeometry(0.5,5.5,0.5), legMat);
            leg.position.set(x,y,z); leg.castShadow = true;
            this.scene.add(leg);
        });

        // ── ผนังห้องแล็บ ──
        const wallMat = new THREE.MeshStandardMaterial({ color:0x1e2d3d, roughness:0.95 });
        // ผนังหลัง
        const wallBack = new THREE.Mesh(new THREE.BoxGeometry(30, 18, 0.3), wallMat);
        wallBack.position.set(0, 7, -8);
        this.scene.add(wallBack);
        // ผนังซ้าย-ขวา
        const wallL = new THREE.Mesh(new THREE.BoxGeometry(0.3, 18, 22), wallMat);
        wallL.position.set(-13, 7, 0); this.scene.add(wallL);
        const wallR = wallL.clone(); wallR.position.x = 13; this.scene.add(wallR);
        // เพดาน (สีอ่อนกว่า)
        const ceilMat = new THREE.MeshStandardMaterial({ color:0x2a3a4a, roughness:1 });
        const ceil = new THREE.Mesh(new THREE.BoxGeometry(30,0.3,22), ceilMat);
        ceil.position.set(0,16,0); this.scene.add(ceil);
        // พื้น
        const floorMat = new THREE.MeshStandardMaterial({ color:0x1a2535, roughness:0.9 });
        const floor = new THREE.Mesh(new THREE.BoxGeometry(30,0.2,22), floorMat);
        floor.position.set(0,-5.5,0); floor.receiveShadow=true; this.scene.add(floor);

        // ── Tile pattern บนพื้น ──
        for(let r=-2;r<=2;r++) for(let c=-3;c<=3;c++) {
            if((r+c)%2===0) continue;
            const tile = new THREE.Mesh(
                new THREE.BoxGeometry(2.9, 0.01, 2.9),
                new THREE.MeshStandardMaterial({color:0x243040, roughness:0.85})
            );
            tile.position.set(c*3, -5.38, r*3); this.scene.add(tile);
        }

        // ── ชั้นวางสาร (ผนังหลัง) ──
        const shelfMat = new THREE.MeshStandardMaterial({ color:0x3a4a5a, roughness:0.75 });
        [4, 7, 10].forEach(y => {
            const shelf = new THREE.Mesh(new THREE.BoxGeometry(16, 0.2, 2), shelfMat);
            shelf.position.set(0, y, -7.6);
            shelf.castShadow = shelf.receiveShadow = true;
            this.scene.add(shelf);
            // ขาชั้นวาง
            [-7.5, 7.5].forEach(x => {
                const leg = new THREE.Mesh(new THREE.BoxGeometry(0.15, 3, 0.15),
                    new THREE.MeshStandardMaterial({color:0x2a3545}));
                leg.position.set(x, y-1.5, -7.5); this.scene.add(leg);
            });
        });

        // ── ขวดสาร 6 ขวดบนชั้น (ใช้ CylinderGeometry เพื่อรอ LatheGeometry) ──
        const bottleColors = [0x3b82f6,0xef4444,0x10b981,0xf59e0b,0xa78bfa,0x22d3ee];
        [-6,-3.5,-1,1,3.5,6].forEach((x,i) => {
            const h = 1.5 + Math.random()*0.8;
            const bMat = new THREE.MeshPhysicalMaterial({
                color: bottleColors[i], transparent:true, opacity:0.65,
                roughness:0.08, transmission:0.40,
            });
            const bottle = new THREE.Mesh(new THREE.CylinderGeometry(0.3,0.35,h,12), bMat);
            bottle.position.set(x, 4+h/2, -7.3);
            bottle.castShadow = true;
            this.scene.add(bottle);
            // ฝา
            const cap = new THREE.Mesh(
                new THREE.CylinderGeometry(0.27,0.27,0.3,10),
                new THREE.MeshStandardMaterial({color:0x0f172a, roughness:0.4})
            );
            cap.position.set(x, 4+h+0.2, -7.3);
            this.scene.add(cap);
            // label (plane)
            const label = new THREE.Mesh(
                new THREE.PlaneGeometry(0.4, 0.5),
                new THREE.MeshBasicMaterial({color: bottleColors[i], transparent:true, opacity:0.6, side:THREE.DoubleSide})
            );
            label.position.set(x+0.31, 4+h*0.4, -7.3);
            label.rotation.y = -Math.PI/2;
            this.scene.add(label);
        });

        // ── โคมไฟเพดาน ──
        const lampGeo  = new THREE.CylinderGeometry(1.2, 0.8, 0.3, 20, 1, true);
        const lampMat  = new THREE.MeshStandardMaterial({color:0xffffff, emissive:0xffffdd, emissiveIntensity:0.8, side:THREE.DoubleSide});
        const lamp     = new THREE.Mesh(lampGeo, lampMat);
        lamp.position.set(0, 15.6, 2);
        this.scene.add(lamp);
        // ก้านโคม
        const rod = new THREE.Mesh(
            new THREE.CylinderGeometry(0.06,0.06,1.8,8),
            new THREE.MeshStandardMaterial({color:0x334155})
        );
        rod.position.set(0,15,2); this.scene.add(rod);

        // ── กระดานดำด้านหลัง ──
        const board = new THREE.Mesh(
            new THREE.BoxGeometry(8, 4, 0.15),
            new THREE.MeshStandardMaterial({color:0x0d2010, roughness:0.95})
        );
        board.position.set(-3, 9, -7.85); this.scene.add(board);
        const boardFrame = new THREE.Mesh(
            new THREE.BoxGeometry(8.3, 4.3, 0.08),
            new THREE.MeshStandardMaterial({color:0x3d2b1a, roughness:0.8})
        );
        boardFrame.position.set(-3, 9, -7.97); this.scene.add(boardFrame);
    },

    // ── Beaker (LatheGeometry PBR) ───────────────────────────
    _buildBeaker() {
        const group = new THREE.Group();
        group.name  = 'beaker_group';

        // Glass body — LatheGeometry
        const beakerGeo = buildBeakerGeometry();
        // r128 compatible: ไม่มี thickness/iridescence/attenuationColor
        const glassMat  = new THREE.MeshPhysicalMaterial({
            color:        0xffffff,
            metalness:    0.0,
            roughness:    0.04,
            transmission: 0.90,
            ior:          1.52,
            reflectivity: 0.15,
            transparent:  true,
            opacity:      0.25,
            depthWrite:   false,
            side:         THREE.DoubleSide,
        });
        const glass = new THREE.Mesh(beakerGeo, glassMat);
        glass.castShadow  = true;
        glass.renderOrder = 2;
        group.add(glass);

        // ก้นบีกเกอร์
        const botGeo = new THREE.CircleGeometry(2.60, 48);
        const bot    = new THREE.Mesh(botGeo, glassMat.clone());
        bot.rotation.x = -Math.PI/2;
        bot.position.y  = 0.02;
        bot.renderOrder = 2;
        group.add(bot);

        // Graduation marks
        for (let i=1; i<=5; i++) {
            const mk = new THREE.Mesh(
                new THREE.BoxGeometry(0.4, 0.04, 0.04),
                new THREE.MeshBasicMaterial({color:0x94a3b8})
            );
            mk.position.set(2.78, i*1.1, 0);
            mk.renderOrder = 3;
            group.add(mk);
        }

        // Liquid volume mesh
        const liquidGeo = new THREE.CylinderGeometry(2.58, 2.58, 0.1, 48);
        this.liquidMat   = new THREE.MeshPhysicalMaterial({
            color:       0x56bdf8,
            transparent: true,
            opacity:     0.82,
            roughness:   0.08,
            metalness:   0,
            transmission:0.35,
        });
        this.liquidMesh = new THREE.Mesh(liquidGeo, this.liquidMat);
        this.liquidMesh.name     = 'liquid';
        this.liquidMesh.position.y = 0.05;
        this.liquidMesh.renderOrder = 1;
        group.add(this.liquidMesh);

        // Surface PlaneGeometry (wave)
        const surfGeo = new THREE.PlaneGeometry(5.16, 5.16, 24, 24);
        this.surfaceMesh = new THREE.Mesh(surfGeo, new THREE.MeshPhysicalMaterial({
            color:       0x56bdf8,
            transparent: true,
            opacity:     0.55,
            roughness:   0.04,
            side:        THREE.DoubleSide,
        }));
        this.surfaceMesh.rotation.x = -Math.PI/2;
        this.surfaceMesh.name       = 'surface';
        this.surfaceMesh.renderOrder = 1;
        group.add(this.surfaceMesh);

        // Solid group
        this.solidGroup = new THREE.Group();
        this.solidGroup.name = 'solid_group';
        this.solidGroup.visible = false;
        group.add(this.solidGroup);

        // Gas group
        const gasG = new THREE.Group();
        gasG.name = 'gas_group';
        gasG.visible = false;
        group.add(gasG);
        this._gasGroup = gasG;

        this.beakerGroup = group;
        // beaker นั่งบนโต๊ะ: โต๊ะผิวอยู่ที่ y=0.4 → beaker base = y=0.4
        group.position.set(0, 0.4, 0);
        this.scene.add(group);
    },

    // ── Particle Pool (200 units) ─────────────────────────────
    _buildParticlePool() {
        const geo = new THREE.SphereGeometry(0.12, 6, 6);
        const mat = new THREE.MeshBasicMaterial({
            color: 0xffffff, transparent:true, opacity:0.6
        });
        for (let i=0; i<200; i++) {
            const m = new THREE.Mesh(geo, mat.clone());
            m.visible = false;
            this.scene.add(m);
            _ParticlePool.release(m);
        }
    },

    // ── Sync from LabApp state ────────────────────────────────
    syncFromLabState(state) {
        if (!this.active || !state) return;

        const vol    = Math.max(0, state.volume || 0);
        const maxVol = state.maxVolume || 200;
        const fillH  = Math.max(0.08, (vol / maxVol) * 7);

        // Liquid height
        if (this.liquidMesh) {
            this.liquidMesh.scale.y    = fillH;
            this.liquidMesh.position.y = fillH / 2;
            this.liquidMesh.visible    = vol > 0;
        }
        if (this.surfaceMesh) {
            this.surfaceMesh.position.y = fillH;
            this.surfaceMesh.visible    = vol > 0;
        }

        // Color lerp
        const [r,g,b] = state.currentColor || [56,189,248,0.5];
        const tCol = new THREE.Color(r/255, g/255, b/255);
        if (this.liquidMat) {
            this.liquidMat.color.lerp(tCol, 0.07);
            if (this.surfaceMesh) this.surfaceMesh.material.color.lerp(tCol, 0.07);
        }

        // Solids
        this._syncSolids(state.contents || []);

        // Temperature glow
        if (this.liquidMat && state.temperature > 55) {
            const heat = Math.min(1, (state.temperature-55)/120);
            this.liquidMat.emissive = new THREE.Color(heat*0.28, heat*0.04, 0);
        }

        // Boiling
        if (state.temperature >= (state.currentBP||100) - 5 && vol > 0) {
            this._spawnBubbles(2, 0xffffff);
        }

        // Flame light intensity (ตะเกียง)
        if (this._flamePt) {
            const lit = typeof _alcLampLit !== 'undefined' && _alcLampLit;
            const tgt = lit ? (1.2 + Math.sin(Date.now()*0.018)*0.3) : 0;
            this._flamePt.intensity += (tgt - this._flamePt.intensity) * 0.08;
        }
    },

    // ── Solid meshes ──────────────────────────────────────────
    _syncSolids(contents) {
        if (!this.solidGroup) return;
        const solids = contents.filter(c => c.state==='solid' && (c.amount-(c.dissolvedAmt||0))>0.05);

        if (!solids.length) { this.solidGroup.visible=false; return; }
        this.solidGroup.visible = true;

        const key = solids.map(c=>c.id+':'+Math.round((c.amount-(c.dissolvedAmt||0))*10)).join(',');
        if (this._lastSolidKey === key) return;
        this._lastSolidKey = key;

        while (this.solidGroup.children.length) {
            const ch = this.solidGroup.children[0];
            ch.geometry?.dispose(); ch.material?.dispose();
            this.solidGroup.remove(ch);
        }

        solids.forEach(c => {
            const undiss = Math.max(0.01,(c.amount||0)-(c.dissolvedAmt||0));
            const color  = hexToThreeColor(c.color || '#aaaaaa');
            const type   = (c.type||'').toLowerCase();
            // texture จาก ChemVisualProfile (ตรงกับโหมด 2D)
            let texture = 'powder';
            if (typeof ChemVisualProfile !== 'undefined') {
                const prof = ChemVisualProfile.get(c.type, c.name, 'solid');
                if (prof && prof.texture) texture = prof.texture;
            }

            // ── geometry + ขนาดเม็ด ตาม texture (เหมือนโหมด 2D) ──
            let geo, grainSize, count, rough, metal;
            switch (texture) {
                case 'fine_powder':
                    grainSize = 0.09; geo = new THREE.SphereGeometry(grainSize, 6, 5);
                    count = Math.min(90, Math.ceil(undiss * 1.2) + 12); rough = 0.95; metal = 0.0; break;
                case 'powder':
                    grainSize = 0.14; geo = new THREE.SphereGeometry(grainSize, 6, 5);
                    count = Math.min(70, Math.ceil(undiss * 0.9) + 10); rough = 0.9; metal = 0.02; break;
                case 'crystal':
                    grainSize = 0.22; geo = new THREE.IcosahedronGeometry(grainSize, 0);
                    count = Math.min(50, Math.ceil(undiss * 0.6) + 8); rough = 0.3; metal = 0.15; break;
                case 'coarse_crystal':
                    grainSize = 0.34; geo = new THREE.IcosahedronGeometry(grainSize, 0);
                    count = Math.min(30, Math.ceil(undiss * 0.35) + 5); rough = 0.25; metal = 0.2; break;
                case 'metal_chunk':
                    grainSize = 0.42; geo = new THREE.DodecahedronGeometry(grainSize, 0);
                    count = Math.min(20, Math.ceil(undiss * 0.22) + 3); rough = 0.18; metal = 0.85; break;
                case 'metal_plate':
                    grainSize = 0.5; geo = new THREE.BoxGeometry(1.0, 0.12, 0.6);
                    count = Math.min(8, Math.ceil(undiss * 0.12) + 2); rough = 0.15; metal = 0.9; break;
                case 'paste':
                    grainSize = 0.3; geo = new THREE.SphereGeometry(grainSize, 7, 6);
                    count = Math.min(18, Math.ceil(undiss * 0.3) + 3); rough = 0.8; metal = 0.0; break;
                default:
                    grainSize = 0.28; geo = new THREE.DodecahedronGeometry(grainSize, 0);
                    count = Math.min(35, Math.ceil(undiss * 0.5) + 5); rough = 0.6; metal = 0.05;
            }

            const mat = new THREE.MeshStandardMaterial({
                color, roughness: rough, metalness: metal,
                flatShading: (texture === 'crystal' || texture === 'coarse_crystal'),
            });

            // กองเป็นเนิน (กลางสูง ขอบเตี้ย)
            const pileR = Math.min(2.2, 1.0 + undiss * 0.04);
            for (let i=0; i<count; i++) {
                const m   = new THREE.Mesh(geo, mat.clone());
                const rng = n => { const x=Math.sin((c.id*100+i)*9301+n*7919)*43758.5; return x-Math.floor(x); };
                // กระจายแบบกอง: ใกล้กลาง = สูง
                const ang = rng(0) * Math.PI * 2;
                const rad = Math.pow(rng(1), 0.6) * pileR;
                const px = Math.cos(ang) * rad;
                const pz = Math.sin(ang) * rad;
                const heightAtR = (1 - rad/pileR) * Math.min(1.8, undiss*0.05);
                const py = 0.15 + rng(2) * (0.2 + heightAtR);
                m.position.set(px, py, pz);
                m.rotation.set(rng(3)*Math.PI, rng(4)*Math.PI, rng(6)*Math.PI);
                m.scale.setScalar(0.7 + rng(5)*0.6);
                m.castShadow = true;
                this.solidGroup.add(m);
            }
        });
    },

    // ── Particles (pool) ─────────────────────────────────────
    _spawnParticle(pos, color, vy, life, opts={}) {
        let m = _ParticlePool.get();
        if (!m) {
            m = new THREE.Mesh(
                new THREE.SphereGeometry(0.10+Math.random()*0.10, 5, 5),
                new THREE.MeshBasicMaterial({transparent:true})
            );
            this.scene.add(m);
        }
        m.visible = true;
        m.material.color.set(color);
        m.material.opacity = 0.65;
        m.position.copy(pos);
        m.userData = { vy, life, maxLife:life, ...opts };
        this._particles.push(m);
    },

    _spawnBubbles(count, color) {
        const h = (this.liquidMesh?.scale.y||1);
        for (let i=0; i<count; i++) {
            this._spawnParticle(
                new THREE.Vector3((Math.random()-.5)*4.2, .2+Math.random()*h, (Math.random()-.5)*4.2),
                color, .038+Math.random()*.028, 1.0, {type:'bubble'}
            );
        }
    },

    _spawnGas(gasName, count=40) {
        const GAS_COLORS = {
            H2:0xd0e8ff, CO2:0xcccccc, SO2:0xeedd88,
            Cl2:0x88cc88, O2:0xe8f4ff, NH3:0xaaffaa, HCl:0xeeeebb,
        };
        const k   = Object.keys(GAS_COLORS).find(k=>(gasName||'').toUpperCase().includes(k));
        const col = GAS_COLORS[k] || 0xdddddd;
        const h   = (this.liquidMesh?.scale.y||1);
        for (let i=0; i<count; i++) {
            this._spawnParticle(
                new THREE.Vector3((Math.random()-.5)*4.2, h+Math.random()*4, (Math.random()-.5)*4.2),
                col, .025+Math.random()*.035, 2.0, {type:'gas'}
            );
        }
        if (this._gasGroup) this._gasGroup.visible = true;
    },

    // ── Reaction Flash ────────────────────────────────────────
    triggerReactionFlash(rxnType, isExplosive, resultColor) {
        if (!this.active) return;
        this.shakeFrames = isExplosive ? 55 : 18;

        // Liquid color flash
        if (resultColor && this.liquidMat) {
            const orig = this.liquidMat.color.clone();
            this.liquidMat.color.set(new THREE.Color(resultColor));
            setTimeout(() => { if(this.liquidMat) this.liquidMat.color.copy(orig); }, 380);
        }

        // Precipitation (golden rain)
        if (rxnType==='precipitation' && resultColor==='#FFD700') {
            const geo = new THREE.BoxGeometry(0.14,0.14,0.14);
            const mat = new THREE.MeshStandardMaterial({color:0xffd700,roughness:.18,metalness:.85});
            for (let i=0; i<36; i++) {
                setTimeout(() => {
                    if (!this.scene) return;
                    const p = new THREE.Mesh(geo.clone(), mat.clone());
                    p.position.set((Math.random()-.5)*4.5, 8+Math.random()*2, (Math.random()-.5)*4.5);
                    p.userData = { vy:-.06-Math.random()*.04, life:3.0, maxLife:3.0, type:'precip' };
                    this.scene.add(p);
                    this._particles.push(p);
                }, Math.random()*900);
            }
        }

        // Explosion
        if (isExplosive) {
            const col = new THREE.Color(0xff4400);
            for (let i=0; i<70; i++) {
                const spd = .12+Math.random()*.22;
                const ang = Math.random()*Math.PI*2;
                this._spawnParticle(
                    new THREE.Vector3((Math.random()-.5)*2, 4+Math.random()*2, (Math.random()-.5)*2),
                    col, spd+Math.random()*.1, 1.4,
                    { type:'explode', vx:(Math.random()-.5)*spd*2.2, vz:(Math.random()-.5)*spd*2.2 }
                );
            }
        }
    },

    // ── Surface Wave ──────────────────────────────────────────
    _animateSurface(t) {
        if (!this.surfaceMesh?.geometry) return;
        const pos = this.surfaceMesh.geometry.attributes.position;
        if (!pos) return;
        const amp = 0.07;
        for (let i=0; i<pos.count; i++) {
            const x=pos.getX(i), z=pos.getZ(i);
            pos.setY(i, Math.sin(x*.85+t*2.0)*amp + Math.cos(z*.85+t*1.6)*amp*.55);
        }
        pos.needsUpdate = true;
        this.surfaceMesh.geometry.computeVertexNormals();
    },

    // ── Main Animate Loop ─────────────────────────────────────
    _animate() {
        if (!this.active) return;
        this.animId = requestAnimationFrame(() => this._animate());
        const t = performance.now() * .001;

        // Camera shake
        if (this.shakeFrames > 0 && this._camOrigin) {
            const mag = (this.shakeFrames/55) * .38;
            this.camera.position.set(
                this._camOrigin.x + (Math.random()-.5)*mag,
                this._camOrigin.y + (Math.random()-.5)*mag,
                this._camOrigin.z + (Math.random()-.5)*mag
            );
            this.shakeFrames--;
        }

        this._animateSurface(t);

        // Particle update
        for (let i=this._particles.length-1; i>=0; i--) {
            const p = this._particles[i];
            const d = p.userData;

            d.life -= d.type==='explode' ? .022 : d.type==='precip' ? .008 : .010;

            if (d.type!=='precip' || p.position.y > .5) p.position.y += d.vy||.03;
            if (d.vx) p.position.x += d.vx;
            if (d.vz) p.position.z += d.vz;
            if (d.type==='explode') d.vy = (d.vy||0) - .005; // gravity

            if (p.material) p.material.opacity = Math.max(0, d.life / (d.maxLife||1)) * .7;

            if (d.life <= 0) {
                this.scene.remove(p);
                _ParticlePool.release(p);
                this._particles.splice(i,1);
            }
        }

        // Sync LabApp state ทุก 3 frame
        if (++this._syncCounter % 3 === 0 && window.LabApp) {
            this.syncFromLabState(window.LabApp.state);
        }

        if (this.renderer && this.scene && this.camera) {
            this.renderer.render(this.scene, this.camera);
        }
    },

    // ── Destroy ───────────────────────────────────────────────
    destroy() {
        this.active = false;
        if (this.animId) { cancelAnimationFrame(this.animId); this.animId=null; }
        this._particles.forEach(p => { this.scene?.remove(p); p.geometry?.dispose(); p.material?.dispose(); });
        this._particles = [];
        if (this.scene) {
            this.scene.traverse(o => {
                o.geometry?.dispose();
                if (Array.isArray(o.material)) o.material.forEach(m=>m.dispose());
                else o.material?.dispose();
            });
            this.scene.clear();
        }
        if (this.renderer) {
            this.renderer.dispose();
            try { this.renderer.forceContextLoss(); } catch(e) {}
            this.renderer.domElement?.remove();
            this.renderer = null;
        }
        if (this._resizeFn) window.removeEventListener('resize', this._resizeFn);
        this.scene = this.camera = this.beakerGroup = this.liquidMesh = null;
    },

    // ── Helpers ───────────────────────────────────────────────
    _onResize() {
        if (!this.renderer || !this.camera) return;
        const w = window.innerWidth, h = window.innerHeight;
        this.camera.aspect = w/h;
        this.camera.updateProjectionMatrix();
        this.renderer.setSize(w, h);
    },
};


// ─────────────────────────────────────────────────────────────
// B. 3D MODE UI — Toggle button + Container
// ─────────────────────────────────────────────────────────────
const Lab3DUI = {
    _injected: false,

    inject() {
        if (this._injected) return;
        this._injected = true;

        // 3D container — position:fixed ครอบทั้งหน้าจอ (ไม่ถูกจำกัดโดย parent overflow)
        const c3d = document.createElement('div');
        c3d.id = 'lab3DContainer';
        c3d.style.cssText = [
            'position:fixed',
            'top:0', 'left:0', 'width:100vw', 'height:100vh',
            'display:none',
            'z-index:8888',
            'background:#0a1628',
            'overflow:hidden',
        ].join(';');
        document.body.appendChild(c3d);

        // ปุ่ม 3D อยู่บน workbench — position:fixed
        const wrap = document.querySelector('.workbench-wrapper');
        const wRect = wrap?.getBoundingClientRect();

        const btn = document.createElement('button');
        btn.id = 'btn3DToggle';
        btn.textContent = '🔭 3D';
        btn.style.cssText = [
            'position:fixed',
            `top:${(wRect?.top||60)+10}px`,
            `right:${window.innerWidth - (wRect?.right||window.innerWidth) + 10}px`,
            'z-index:8889',
            'background:rgba(15,23,42,.92)',
            'color:#38bdf8',
            'border:1px solid rgba(56,189,248,0.55)',
            'border-radius:8px',
            'padding:6px 14px',
            'font-size:.78rem',
            'font-weight:700',
            'cursor:pointer',
            "font-family:'Prompt'",
            'transition:.2s',
            'backdrop-filter:blur(6px)',
            'letter-spacing:.05em',
            'box-shadow:0 2px 12px rgba(56,189,248,0.2)',
        ].join(';');
        btn.addEventListener('click', () => this.toggle());
        document.body.appendChild(btn);

        // ปุ่ม Mol
        const btnMol = document.createElement('button');
        btnMol.id = 'btnMolViewer';
        btnMol.textContent = '⚛️ Mol';
        btnMol.style.cssText = [
            'position:fixed',
            `top:${(wRect?.top||60)+10}px`,
            `right:${window.innerWidth - (wRect?.right||window.innerWidth) + 80}px`,
            'z-index:8889', 'display:none',
            'background:rgba(15,23,42,.92)', 'color:#a78bfa',
            'border:1px solid rgba(167,139,250,0.5)', 'border-radius:8px',
            'padding:6px 14px', 'font-size:.78rem', 'font-weight:700',
            'cursor:pointer', "font-family:'Prompt'",
        ].join(';');
        btnMol.addEventListener('click', () =>
            MolViewer3D.show(window._lastReactionFormula || 'water')
        );
        document.body.appendChild(btnMol);
    },

    toggle() {
        const c3d = document.getElementById('lab3DContainer');
        const btn = document.getElementById('btn3DToggle');
        if (!c3d) return;

        if (Lab3DManager.active) {
            // ── ปิด 3D ──
            Lab3DManager.destroy();
            c3d.style.display = 'none';
            if (btn) {
                btn.textContent = '🔭 3D';
                btn.style.color = '#38bdf8';
                btn.style.borderColor = 'rgba(56,189,248,0.55)';
                btn.style.background = 'rgba(15,23,42,.92)';
            }
        } else {
            // ── เปิด 3D ──
            // แสดง overlay ก่อน — ต้องแสดงก่อน init renderer
            // (display:none ทำให้ framebuffer size = 0)
            c3d.style.display = 'block';
            c3d.style.width   = window.innerWidth  + 'px';
            c3d.style.height  = window.innerHeight + 'px';

            // loading indicator
            c3d.innerHTML = '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;font-family:Prompt;color:#38bdf8"><div style="font-size:2.5rem;margin-bottom:14px">⚗️</div><div style="font-size:1.1rem;font-weight:700">กำลังโหลด 3D Lab Room...</div><div style="font-size:.8rem;color:#475569;margin-top:8px">Three.js r128</div></div>';

            if (btn) {
                btn.textContent = '⏳ โหลด...';
                btn.disabled = true;
                btn.style.color = '#94a3b8';
            }

            // รอ 1 frame ให้ browser paint container ก่อน init renderer
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    Lab3DManager.init(c3d);
                });
            });

            let waited = 0;
            const checkReady = setInterval(() => {
                waited += 200;
                if (Lab3DManager.active) {
                    clearInterval(checkReady);
                    // ล้าง loading indicator (renderer.domElement เข้าแทนแล้ว)
                    if (btn) {
                        btn.textContent = '✕ ปิด 3D';
                        btn.style.color = '#4ade80';
                        btn.style.borderColor = 'rgba(74,222,128,0.5)';
                        btn.style.background = 'rgba(15,23,42,.92)';
                        btn.disabled = false;
                    }
                    document.getElementById('btnMolViewer').style.display = 'block';
                } else if (waited > 10000) {
                    clearInterval(checkReady);
                    c3d.innerHTML = '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;font-family:Prompt"><div style="font-size:1rem;color:#ef4444;font-weight:600">❌ โหลด Three.js ไม่ได้</div><div style="font-size:.8rem;color:#475569;margin-top:8px">ต้องการ Internet Connection<br>หรือ Three.js CDN ล่มอยู่</div><button onclick=\"Lab3DUI.toggle()\" style=\"margin-top:16px;background:#ef4444;color:#fff;border:none;padding:8px 20px;border-radius:8px;cursor:pointer;font-family:Prompt\">ปิด</button></div>';
                    if (btn) { btn.textContent = '🔭 3D'; btn.disabled = false; btn.style.color='#38bdf8'; }
                }
            }, 200);
        }
    },
};


// ─────────────────────────────────────────────────────────────
// C. MOLECULAR VIEWER (3Dmol.js)
// ─────────────────────────────────────────────────────────────
const MolViewer3D = {
    _loaded: false,
    _modal:  null,
    _viewer: null,

    _smilesMap: {
        'NaCl':'[Na+].[Cl-]','HCl':'Cl','NaOH':'[Na+].[OH-]','H2O':'O',
        'H2O2':'OO','H2SO4':'OS(=O)(=O)O','HNO3':'O[N+](=O)[O-]','NH3':'N',
        'CO2':'O=C=O','CuSO4':'[Cu+2].[O-]S(=O)(=O)[O-]',
        'NaHCO3':'[Na+].OC([O-])=O','CH3COOH':'CC(O)=O',
        'H2':'[H][H]','Cl2':'ClCl','O2':'O=O','PbI2':'[Pb+2].[I-].[I-]',
        'AgCl':'[Ag+].[Cl-]','Fe(OH)3':'O[Fe](O)O','Cu(OH)2':'O[Cu]O',
        'MnO2':'O=[Mn]=O','KMnO4':'[K+].[O-][Mn](=O)(=O)=O',
        'water':'O','glucose':'OC[C@@H](O)[C@@H](O)[C@H](O)[C@@H](O)C=O',
    },

    _loadLib(cb) {
        if (window.$3Dmol) { cb(); return; }
        const s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/3Dmol/2.0.4/3Dmol-min.js';
        s.onload  = () => cb();
        s.onerror = () => console.error('[3Dmol] load failed');
        document.head.appendChild(s);
    },

    _ensureModal() {
        if (this._modal) return;
        const m = document.createElement('div');
        m.id = 'molViewerModal';
        m.style.cssText = 'position:fixed;inset:0;z-index:9998;display:none;background:rgba(0,0,0,.75);backdrop-filter:blur(6px);align-items:center;justify-content:center;';
        m.innerHTML = `
            <div style="background:#0f172a;border:1px solid #334155;border-radius:16px;width:480px;max-width:95vw;overflow:hidden;">
                <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #1e293b;">
                    <span style="color:#f1f5f9;font-weight:700;font-size:1.05rem;" id="molTitle">⚛️ โครงสร้างโมเลกุล 3D</span>
                    <button id="btnCloseMol" style="background:none;border:none;color:#94a3b8;font-size:1.3rem;cursor:pointer;">✕</button>
                </div>
                <div id="mol3dViewer" style="width:100%;height:360px;background:#000a1a;"></div>
                <div id="molInfo" style="padding:10px 20px;color:#64748b;font-size:.8rem;border-top:1px solid #1e293b;"></div>
            </div>`;
        document.body.appendChild(m);
        m.querySelector('#btnCloseMol').addEventListener('click', () => this.close());
        m.addEventListener('click', e => { if(e.target===m) this.close(); });
        this._modal = m;
    },

    show(formula, title) {
        this._ensureModal();
        this._loadLib(() => {
            if (!window.$3Dmol) return;
            const smiles  = this._smilesMap[formula] || formula;
            const titleEl = document.getElementById('molTitle');
            const infoEl  = document.getElementById('molInfo');
            const viewEl  = document.getElementById('mol3dViewer');
            if (titleEl) titleEl.textContent = `⚛️ ${title||formula}`;
            if (infoEl)  infoEl.textContent  = `SMILES: ${smiles}`;
            if (viewEl)  viewEl.innerHTML    = '';
            try {
                this._viewer = $3Dmol.createViewer(viewEl, {backgroundColor:'#000a1a'});
                this._viewer.addModel(smiles,'smi');
                this._viewer.setStyle({},{stick:{radius:.15,colorscheme:'Jmol'},sphere:{radius:.32,colorscheme:'Jmol'}});
                this._viewer.zoomTo(); this._viewer.spin('y',1); this._viewer.render();
            } catch(e) { if(infoEl) infoEl.textContent='ไม่สามารถแสดงโมเลกุลนี้ได้'; }
            this._modal.style.display = 'flex';
        });
    },

    close() {
        if (this._modal) this._modal.style.display='none';
        try { this._viewer?.clear(); } catch(e){}
    },
};


// ─────────────────────────────────────────────────────────────
// D. Periodic Table Interactive
// ─────────────────────────────────────────────────────────────
const PeriodicTableInteractive = {
    inject() {
        const tryInject = () => {
            const card = document.getElementById('elDetailCard');
            if (!card) { setTimeout(tryInject, 500); return; }
            if (card.querySelector('#btnAddToBkr')) return;

            const btn = document.createElement('button');
            btn.id = 'btnAddToBkr';
            btn.innerHTML = '🧪 ใส่ลงบีกเกอร์';
            btn.style.cssText = 'margin-top:12px;width:100%;background:#38bdf8;color:#0f172a;border:none;border-radius:8px;padding:8px 0;font-weight:700;cursor:pointer;font-family:"Prompt";font-size:.9rem;';
            btn.addEventListener('click', () => this._addCurrentElement());
            card.appendChild(btn);

            const btnMol = document.createElement('button');
            btnMol.innerHTML = '⚛️ ดูโมเลกุล 3D';
            btnMol.style.cssText = 'margin-top:6px;width:100%;background:rgba(167,139,250,.15);color:#a78bfa;border:1px solid #a78bfa;border-radius:8px;padding:8px 0;font-weight:600;cursor:pointer;font-family:"Prompt";font-size:.9rem;';
            btnMol.addEventListener('click', () => {
                const el = window.LabApp?.currentPTElement;
                if (el) MolViewer3D.show(el.sym, el.name);
            });
            card.appendChild(btnMol);
        };
        tryInject();
    },

    _addCurrentElement() {
        const app = window.LabApp;
        if (!app?.currentPTElement) return;
        const el = app.currentPTElement;
        const chems = app.config?.chemicalsData || [];
        const match = chems.find(c => c.formula?.toUpperCase().includes(el.sym.toUpperCase()));
        if (match) {
            app.state.activeTool = {
                id:match.id, name:match.name, formula:match.formula,
                state:match.state, color:match.color_neutral||'#ffffff',
                type:match.type, bp:match.boiling_point||100, amount:5,
            };
            app.log(`⚛️ เลือกสาร ${match.name} จากตารางธาตุ`);
        } else {
            app.log?.(`⚠️ ไม่มีสาร ${el.sym} ในฐานข้อมูล`);
        }
    },
};


// ─────────────────────────────────────────────────────────────
// E. Patch LabApp → hook 3D effects into processReaction
// ─────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    setTimeout(() => {
        if (!window.LabApp) { console.warn('[Phase5] LabApp not found'); return; }

        Lab3DUI.inject();
        PeriodicTableInteractive.inject();

        const _orig = window.LabApp.processReaction?.bind(window.LabApp);
        if (_orig) {
            window.LabApp.processReaction = function(rxn, c1, c2, idx1, idx2) {
                _orig(rxn, c1, c2, idx1, idx2);
                window._lastReactionFormula = rxn.product_formula || rxn.product_name || 'water';
                const btnMol = document.getElementById('btnMolViewer');
                if (btnMol) { btnMol.style.display='block'; btnMol.title=`ดูโมเลกุล: ${rxn.product_name}`; }
                if (Lab3DManager.active) {
                    Lab3DManager.triggerReactionFlash(rxn.reaction_type, rxn.is_explosive==1, rxn.result_color);
                    if (rxn.result_gas && rxn.result_gas!=='ไม่มีแก๊ส') Lab3DManager._spawnGas(rxn.result_gas,55);
                }
            };
        }

        console.log('[Phase5] ✅ 3D Toggle + Mol Viewer + Periodic Interactive ready');
    }, 100);
});



/* ═══════════ CHEM VISUAL ENGINE PATCH (Phase 1) ═══════════ */
/**
 * ============================================================
 * CHEM VISUAL ENGINE PATCH — lab_realistic.php
 * ============================================================
 * วิธีใช้: แทรกโค้ดทั้งหมดนี้ก่อน <\/script> สุดท้ายในไฟล์
 * หรือแทนที่ส่วน renderCanvas(), executePour() และ updatePhysics()
 * ============================================================
 * ครอบคลุม:
 *  - ChemVisualProfile: กำหนด texture/viscosity/dissolveRate ต่อ type+name
 *  - renderCanvas() ใหม่: solid pile แยกตาม texture จริง
 *  - Pour/Drop animation: stream + splash + ripple
 *  - Viscosity system: คลื่นหนืดตาม organic/polymer/acid
 *  - Dissolve logic: ละลายเร็ว/ช้า/ไม่ละลาย ตามชนิด
 *  - Steam particles เมื่อต้ม
 *  - Chem-item icon: แสดงรูปลักษณ์ขวด/ถุง/แผ่นโลหะ
 * ============================================================
 */

// ─────────────────────────────────────────────────────────────
// 1. CHEM VISUAL PROFILE ENGINE
//    กำหนด visual property ต่อ type (ไม่ต้องแก้ DB)
// ─────────────────────────────────────────────────────────────
const ChemVisualProfile = {

    /**
     * ดึง profile จาก type + name ของสาร
     * Return: { texture, viscosity, dissolveRate, particleSize,
     *           particleShape, shimmer, opacityBase, steamOnHeat }
     */
    get(type, name, state) {
        type = (type || '').toLowerCase();
        name = (name || '').toLowerCase();

        // ─── SOLID profiles ───────────────────────────────────
        if (state === 'solid') {
            // โลหะ: แผ่น/เม็ด เงาวาว ไม่ละลายในน้ำ
            if (type === 'metal') {
                const isRibbon = name.includes('ribbon') || name.includes('แผ่น') || name.includes('plate');
                return {
                    texture:       isRibbon ? 'metal_plate' : 'metal_chunk',
                    viscosity:     0,
                    dissolveRate:  0,          // ไม่ละลายในน้ำ (ต้องใช้กรด)
                    particleSize:  isRibbon ? 0 : 6,
                    particleShape: 'chunk',
                    shimmer:       true,
                    opacityBase:   1.0,
                    steamOnHeat:   false,
                };
            }

            // ตัวเร่งปฏิกิริยา: ผงดำ/น้ำตาล ละเอียดมาก
            if (type === 'catalyst') {
                return {
                    texture:       'fine_powder',
                    viscosity:     0,
                    dissolveRate:  0.02,       // ละลายได้เล็กน้อย
                    particleSize:  1.2,
                    particleShape: 'dot',
                    shimmer:       false,
                    opacityBase:   0.95,
                    steamOnHeat:   false,
                };
            }

            // เกลือ: ผลึกขาว ขนาดกลาง ละลายเร็วในน้ำ
            if (type === 'salt') {
                return {
                    texture:       'crystal',
                    viscosity:     0,
                    dissolveRate:  0.08,
                    particleSize:  2.5,
                    particleShape: 'square',
                    shimmer:       true,
                    opacityBase:   0.9,
                    steamOnHeat:   false,
                };
            }

            // น้ำตาล/อินทรีย์: ผลึกขาว/ทอง ละลายช้ากว่าเกลือ
            if (type === 'organic') {
                return {
                    texture:       'coarse_crystal',
                    viscosity:     0,
                    dissolveRate:  0.05,
                    particleSize:  3.0,
                    particleShape: 'rounded_square',
                    shimmer:       false,
                    opacityBase:   0.85,
                    steamOnHeat:   false,
                };
            }

            // น้ำแข็งแห้ง (Dry Ice): ก้อนขาว มีไอระเหย
            if (type === 'cold' || name.includes('dry ice') || name.includes('น้ำแข็งแห้ง')) {
                return {
                    texture:       'dry_ice',
                    viscosity:     0,
                    dissolveRate:  0.15,       // ระเหิด
                    particleSize:  8,
                    particleShape: 'chunk',
                    shimmer:       false,
                    opacityBase:   0.7,
                    steamOnHeat:   true,       // มีไอขาวตลอดเวลา
                };
            }

            // ยาสีฟัน/paste: ก้อนขาว
            if (type === 'base' || name.includes('paste') || name.includes('ยาสีฟัน')) {
                return {
                    texture:       'paste',
                    viscosity:     0,
                    dissolveRate:  0.03,
                    particleSize:  4,
                    particleShape: 'blob',
                    shimmer:       false,
                    opacityBase:   0.9,
                    steamOnHeat:   false,
                };
            }

            // default solid: ผงขาว ขนาดกลาง
            return {
                texture:       'powder',
                viscosity:     0,
                dissolveRate:  0.04,
                particleSize:  2.0,
                particleShape: 'dot',
                shimmer:       false,
                opacityBase:   0.9,
                steamOnHeat:   false,
            };
        }

        // ─── LIQUID profiles ──────────────────────────────────

        // น้ำมัน: หนืด สีเหลือง ไม่ผสมน้ำ
        if (type === 'organic' || name.includes('oil') || name.includes('น้ำมัน')) {
            return {
                texture:       'oily',
                viscosity:     0.85,     // สูง = คลื่นช้าและต่ำ
                dissolveRate:  0,
                particleSize:  0,
                particleShape: null,
                shimmer:       true,     // เงาสะท้อนแสง
                opacityBase:   0.75,
                steamOnHeat:   false,
            };
        }

        // กาว/polymer: หนืดมาก
        if (type === 'polymer' || name.includes('glue') || name.includes('กาว')) {
            return {
                texture:       'viscous',
                viscosity:     0.95,
                dissolveRate:  0,
                particleSize:  0,
                particleShape: null,
                shimmer:       false,
                opacityBase:   0.5,
                steamOnHeat:   false,
            };
        }

        // กรดเข้มข้น: ใส มีไอเล็กน้อย ความหนืดต่ำ
        if (type === 'acid') {
            const isConc = name.includes('concentrated') || name.includes('conc') || name.includes('เข้มข้น');
            return {
                texture:       isConc ? 'acid_conc' : 'acid_dilute',
                viscosity:     isConc ? 0.3 : 0.05,
                dissolveRate:  0,
                particleSize:  0,
                particleShape: null,
                shimmer:       false,
                opacityBase:   isConc ? 0.6 : 0.35,
                steamOnHeat:   isConc,
            };
        }

        // ด่าง: ใส/ขุ่นขาวเล็กน้อย
        if (type === 'base') {
            return {
                texture:       'base_liquid',
                viscosity:     0.1,
                dissolveRate:  0,
                particleSize:  0,
                particleShape: null,
                shimmer:       false,
                opacityBase:   0.3,
                steamOnHeat:   false,
            };
        }

        // indicator: ใส มีสีสวย
        if (type === 'indicator') {
            return {
                texture:       'indicator',
                viscosity:     0.05,
                dissolveRate:  0,
                particleSize:  0,
                particleShape: null,
                shimmer:       false,
                opacityBase:   0.55,
                steamOnHeat:   false,
            };
        }

        // นม/เลือด/น้ำชีวภาพ: ขุ่น ความหนืดปานกลาง
        if (type === 'biological' || type === 'buffer' ||
            name.includes('milk') || name.includes('นม') || name.includes('เลือด')) {
            return {
                texture:       'opaque_liquid',
                viscosity:     0.4,
                dissolveRate:  0,
                particleSize:  0,
                particleShape: null,
                shimmer:       false,
                opacityBase:   0.85,
                steamOnHeat:   false,
            };
        }

        // น้ำ/solvent: ใสมาก คลื่นไว
        if (type === 'solvent' || type === 'water') {
            return {
                texture:       'water',
                viscosity:     0.0,
                dissolveRate:  0,
                particleSize:  0,
                particleShape: null,
                shimmer:       false,
                opacityBase:   0.15,
                steamOnHeat:   true,
            };
        }

        // salt solution: ใส-ขุ่นเล็กน้อย
        if (type === 'salt' || type === 'complex' || type === 'oxidizer') {
            return {
                texture:       'salt_solution',
                viscosity:     0.05,
                dissolveRate:  0,
                particleSize:  0,
                particleShape: null,
                shimmer:       false,
                opacityBase:   0.5,
                steamOnHeat:   false,
            };
        }

        // default liquid
        return {
            texture:       'liquid_default',
            viscosity:     0.1,
            dissolveRate:  0,
            particleSize:  0,
            particleShape: null,
            shimmer:       false,
            opacityBase:   0.4,
            steamOnHeat:   false,
        };
    },

    /**
     * คำนวณ viscosity รวมใน beaker (weighted average)
     */
    getBlendedViscosity(contents) {
        if (!contents || contents.length === 0) return 0;
        let totalVis = 0, totalAmt = 0;
        contents.forEach(c => {
            if (!c || isNaN(c.amount)) return;
            if (c.state !== 'solid') {
                const prof = ChemVisualProfile.get(c.type, c.name, c.state);
                totalVis += prof.viscosity * c.amount;
                totalAmt += c.amount;
            }
        });
        return totalAmt > 0 ? totalVis / totalAmt : 0;
    },
};


// ─────────────────────────────────────────────────────────────
// 2. POUR / DROP ANIMATION SYSTEM
// ─────────────────────────────────────────────────────────────
const PourAnimator = {
    active:   false,
    startTime: 0,
    duration:  600,       // ms
    color:    [255, 255, 255],
    viscosity: 0,
    streamX:  96,         // canvas center
    particles: [],        // splash particles

    /**
     * เรียกเมื่อเทสารลงบีกเกอร์
     * @param {Array} rgb       - [r, g, b]
     * @param {number} viscosity - 0-1 (สูง = ไหลช้า)
     * @param {string} state    - 'solid' | 'liquid'
     */
    trigger(rgb, viscosity, state) {
        this.active    = true;
        this.startTime = performance.now();
        // NaN guard: ตรวจ rgb ทุก channel
        if (!Array.isArray(rgb) || rgb.some(v => isNaN(v))) rgb = [200, 220, 240];
        this.color     = rgb;
        this.viscosity = viscosity || 0;
        this.state     = state || 'liquid';
        this.duration  = state === 'solid' ? 300 : 400 + viscosity * 1200;  // หนืดมาก = ไหลช้ามาก
        this.particles = [];

        // สร้าง splash particles ณ จุดที่สารตก
        const numP = state === 'solid' ? 8 : 12;
        for (let i = 0; i < numP; i++) {
            const angle  = (Math.random() * Math.PI);  // กระเด็นขึ้น
            const speed  = Math.random() * 3 + 1.5;
            this.particles.push({
                x:  96, y: 200,
                vx: Math.cos(angle) * speed * (Math.random() > 0.5 ? 1 : -1),
                vy: -Math.sin(angle) * speed,
                life: 1.0,
                size: state === 'solid' ? Math.random() * 2 + 1 : Math.random() * 3 + 1,
                alpha: 0.9,
            });
        }
    },

    /**
     * วาดลงบน ctx (เรียกใน renderCanvas)
     * @param {CanvasRenderingContext2D} ctx
     * @param {number} topY - ระดับผิวของเหลว
     */
    draw(ctx, topY) {
        if (!this.active) return;
        const elapsed = performance.now() - this.startTime;
        const t       = Math.min(1, elapsed / this.duration);

        if (t >= 1) { this.active = false; this.particles = []; return; }

        const [r, g, b] = this.color;
        const streamAlpha = (1 - t) * 0.85;

        // ─── วาด stream ───────────────────────────────────────
        if (this.state === 'liquid') {
            // ความกว้างของ stream ขึ้นกับ viscosity
            const streamW = this.viscosity > 0.5 ? 6 + this.viscosity * 6 : 3 + (1 - t) * 4;
            const streamH = topY * (0.3 + t * 0.7);   // ยาวขึ้นตาม time

            // วาดหลาย layer เพื่อให้ดูหนา
            for (let layer = 0; layer < 2; layer++) {
                const lw = streamW - layer * 1.5;
                const la = streamAlpha * (layer === 0 ? 1 : 0.4);
                ctx.save();
                ctx.strokeStyle = `rgba(${r},${g},${b},${la})`;
                ctx.lineWidth   = Math.max(1, lw);
                ctx.lineCap     = 'round';
                ctx.beginPath();
                // stream โค้งเล็กน้อยสำหรับ viscous liquid
                const bendX = this.viscosity > 0.3 ? Math.sin(t * Math.PI) * 8 : 0;
                ctx.moveTo(this.streamX, 0);
                ctx.quadraticCurveTo(
                    this.streamX + bendX, topY * 0.5,
                    this.streamX, topY
                );
                ctx.stroke();
                ctx.restore();
            }

            // หยดน้ำที่ปลาย stream (เฉพาะ viscous)
            if (this.viscosity > 0.4 && t < 0.7) {
                const dropSize = 4 + this.viscosity * 6;
                ctx.save();
                ctx.fillStyle = `rgba(${r},${g},${b},${streamAlpha})`;
                ctx.beginPath();
                ctx.arc(this.streamX, topY - dropSize * 0.5, dropSize, 0, Math.PI * 2);
                ctx.fill();
                ctx.restore();
            }
        } else {
            // solid: วาดเม็ดตกลงมา
            const numFalling = Math.floor(3 + (1 - t) * 5);
            for (let i = 0; i < numFalling; i++) {
                const px = this.streamX + (Math.random() - 0.5) * 20;
                const py = t * topY + Math.random() * 30;
                ctx.save();
                ctx.fillStyle = `rgba(${r},${g},${b},${streamAlpha * 0.9})`;
                ctx.beginPath();
                ctx.arc(px, py, 1.5 + Math.random(), 0, Math.PI * 2);
                ctx.fill();
                ctx.restore();
            }
        }

        // ─── วาด splash particles ─────────────────────────────
        this.particles.forEach(p => {
            p.x  += p.vx;
            p.y  += p.vy;
            p.vy += 0.25;      // gravity
            p.life -= 0.04;
            if (p.life <= 0) return;
            ctx.save();
            ctx.globalAlpha = p.life * 0.8;
            ctx.fillStyle   = `rgba(${r},${g},${b},0.9)`;
            ctx.beginPath();
            ctx.arc(p.x, topY + (p.y - topY) * 0.3, p.size, 0, Math.PI * 2);
            ctx.fill();
            ctx.restore();
        });
        this.particles = this.particles.filter(p => p.life > 0);
    },
};


// ─────────────────────────────────────────────────────────────
// 3. SOLID RENDERER — วาด solid pile แยกตาม texture
// ─────────────────────────────────────────────────────────────
const CHEM_SPRITE_MAP = {
  "เกลือแกง (Salt)": {s:1,p:0},
  "น้ำตาลทราย (Sugar)": {s:1,p:1},
  "ผงฟู (Baking Soda)": {s:1,p:2},
  "คอปเปอร์ซัลเฟต (CuSO4)": {s:1,p:3},
  "ด่างทับทิม (KMnO4)": {s:1,p:4},
  "กำมะถัน (Sulfur)": {s:1,p:5},
  "ถ่านบด (Charcoal)": {s:1,p:6},
  "แคลเซียมคาร์บอเนต (CaCO3)": {s:1,p:7},
  "โซดาไฟ (NaOH)": {s:1,p:8},
  "ดินประสิว (KNO3)": {s:1,p:9},
  "คอปเปอร์คลอไรด์ (CuCl2)": {s:1,p:10},
  "เฟอร์ริกคลอไรด์ (FeCl3)": {s:1,p:11},
  "ซิลเวอร์ไนเตรต (AgNO3)": {s:1,p:12},
  "ไอโอดีน (Iodine)": {s:1,p:13},
  "โพแทสเซียมไอโอไดด์ (KI)": {s:1,p:14},
  "แป้งข้าวโพด (Cornstarch)": {s:1,p:15},
  "กรดมะนาว (Citric Acid)": {s:1,p:16},
  "วิตามินซี (Vitamin C)": {s:1,p:17},
  "ผงยีสต์ (Yeast - เอนไซม์คะตะเลส)": {s:1,p:18},
  "บอแรกซ์ (Borax)": {s:1,p:19},
  "ปุ๋ยยูเรีย (Urea)": {s:1,p:20},
  "แมกนีเซียมออกไซด์ (MgO)": {s:1,p:21},
  "แอมโมเนียมคลอไรด์ (Ammonium Chloride)": {s:1,p:22},
  "โพแทสเซียมไฮดรอกไซด์ (KOH)": {s:1,p:23},
  "โพแทสเซียมคลอเรต (KClO3)": {s:1,p:24},
  "แบเรียมคลอไรด์ (BaCl2)": {s:1,p:25},
  "สตรอนเทียม (Strontium)": {s:1,p:26},
  "สตรอนเทียมคลอไรด์ (SrCl2)": {s:1,p:27},
  "เลดไนเตรต (Lead Nitrate)": {s:1,p:28},
  "ผงแมงกานีส(IV)ออกไซด์ (Manganese Dioxide)": {s:1,p:29},
  "แมงกานีส (Manganese)": {s:2,p:0},
  "ผงสนิมเหล็ก (Iron Oxide)": {s:2,p:1},
  "ปูนขาว (Calcium Hydroxide)": {s:2,p:2},
  "โซเดียมแอซีเตต (Sodium Acetate)": {s:2,p:3},
  "ฟีนอล (Phenol)": {s:2,p:4},
  "ผงซักฟอก (Detergent)": {s:2,p:5},
  "ยาสีฟัน (Toothpaste)": {s:2,p:6},
  "กระดาษ (Paper)": {s:2,p:7},
  "โฟม (Styrofoam)": {s:2,p:8},
  "ลูกอมเมนทอส (Mentos)": {s:2,p:9},
  "ทีเอ็นที (TNT)": {s:2,p:10},
  "ซีโฟร์ (C4)": {s:2,p:11},
  "ไซยาไนด์ (Cyanide)": {s:2,p:12},
  "น้ำแข็งแห้ง (Dry Ice)": {s:2,p:13},
  "แผ่นแมกนีเซียม (Magnesium Ribbon)": {s:2,p:14},
  "แมกนีเซียม (Magnesium)": {s:2,p:15},
  "สังกะสี (Zinc)": {s:2,p:16},
  "แผ่นสังกะสี (Zinc Plate)": {s:2,p:17},
  "เหล็ก (Iron)": {s:2,p:18},
  "ทองแดง (Copper)": {s:2,p:19},
  "แผ่นทองแดง (Copper Plate)": {s:2,p:20},
  "อะลูมิเนียม (Aluminium)": {s:2,p:21},
  "ซิลิคอน (Silicon)": {s:2,p:22},
  "นิกเกิล (Nickel)": {s:2,p:23},
  "ดีบุก (Tin)": {s:2,p:24},
  "ตะกั่ว (Lead)": {s:2,p:25},
  "เงิน (Silver)": {s:2,p:26},
  "ทองคำ (Gold)": {s:2,p:27},
  "แคลเซียม (Calcium)": {s:2,p:28},
  "ฟอสฟอรัส (Phosphorus)": {s:2,p:29},
  "ฟอสฟอรัสขาว (White Phosphorus)": {s:3,p:0},
  "โซเดียม (Sodium)": {s:3,p:1},
  "คาร์บอน (Carbon)": {s:3,p:2},
  "คูเรียม (Curium)": {s:3,p:3},
  "ซาแมเรียม (Samarium)": {s:3,p:4},
  "ซีบอร์เกียม (Seaborgium)": {s:3,p:5},
  "ซีลีเนียม (Selenium)": {s:3,p:6},
  "ซีเซียม (Cesium)": {s:3,p:7},
  "ซีเรียม (Cerium)": {s:3,p:8},
  "ดาร์มสตัดเทียม (Darmstadtium)": {s:3,p:9},
  "ดิสโพรเซียม (Dysprosium)": {s:3,p:10},
  "ดุบเนียม (Dubnium)": {s:3,p:11},
  "ทอเรียม (Thorium)": {s:3,p:12},
  "ทังสเตน (Tungsten)": {s:3,p:13},
  "ทูเลียม (Thulium)": {s:3,p:14},
  "นิโฮเนียม (Nihonium)": {s:3,p:15},
  "นีโอดิเมียม (Neodymium)": {s:3,p:16},
  "บิสมัท (Bismuth)": {s:3,p:17},
  "พลวง (Antimony)": {s:3,p:18},
  "พลูโตเนียม (Plutonium)": {s:3,p:19},
  "พอโลเนียม (Polonium)": {s:3,p:20},
  "ฟลีรอเวียม (Flerovium)": {s:3,p:21},
  "มอสโกเวียม (Moscovium)": {s:3,p:22},
  "ยูเรเนียม (Uranium)": {s:3,p:23},
  "ยูโรเพียม (Europium)": {s:3,p:24},
  "รัทเทอร์ฟอร์เดียม (Rutherfordium)": {s:3,p:25},
  "รีเนียม (Rhenium)": {s:3,p:26},
  "รูทีเนียม (Ruthenium)": {s:3,p:27},
  "รูบิเดียม (Rubidium)": {s:3,p:28},
  "ลอว์เรนเซียม (Lawrencium)": {s:3,p:29},
  "ลิเทียม (Lithium)": {s:4,p:0},
  "ลิเวอร์มอเรียม (Livermorium)": {s:4,p:1},
  "ลูทีเชียม (Lutetium)": {s:4,p:2},
  "วาเนเดียม (Vanadium)": {s:4,p:3},
  "สารหนู (Arsenic)": {s:4,p:4},
  "สแคนเดียม (Scandium)": {s:4,p:5},
  "ออสเมียม (Osmium)": {s:4,p:6},
  "อะเมริเซียม (Americium)": {s:4,p:7},
  "อิตเทรียม (Yttrium)": {s:4,p:8},
  "อิตเทอร์เบียม (Ytterbium)": {s:4,p:9},
  "อินเดียม (Indium)": {s:4,p:10},
  "อิริเดียม (Iridium)": {s:4,p:11},
  "ฮาสเซียม (Hassium)": {s:4,p:12},
  "เจอร์เมเนียม (Germanium)": {s:4,p:13},
  "เซอร์โคเนียม (Zirconium)": {s:4,p:14},
  "เทคนีเซียม (Technetium)": {s:4,p:15},
  "เทนเนสซีน (Tennessine)": {s:4,p:16},
  "เทลลูเรียม (Tellurium)": {s:4,p:17},
  "เทอร์เบียม (Terbium)": {s:4,p:18},
  "เนปจูเนียม (Neptunium)": {s:4,p:19},
  "เบอริลเลียม (Beryllium)": {s:4,p:20},
  "เบอร์คีเลียม (Berkelium)": {s:4,p:21},
  "เพรซีโอดีเนียม (Praseodymium)": {s:4,p:22},
  "เฟอร์เมียม (Fermium)": {s:4,p:23},
  "เมนเดลีเวียม (Mendelevium)": {s:4,p:24},
  "เรินต์เกเนียม (Roentgenium)": {s:4,p:25},
  "เรเดียม (Radium)": {s:4,p:26},
  "เออร์เบียม (Erbium)": {s:4,p:27},
  "แกลเลียม (Gallium)": {s:4,p:28},
  "แกโดลิเนียม (Gadolinium)": {s:4,p:29},
  "แคดเมียม (Cadmium)": {s:5,p:0},
  "แคลิฟอร์เนียม (Californium)": {s:5,p:1},
  "แทนทาลัม (Tantalum)": {s:5,p:2},
  "แทลเลียม (Thallium)": {s:5,p:3},
  "แบเรียม (Barium)": {s:5,p:4},
  "แพลตตินัม (Platinum)": {s:5,p:5},
  "แพลเลเดียม (Palladium)": {s:5,p:6},
  "แฟรนเซียม (Francium)": {s:5,p:7},
  "แลนทานัม (Lanthanum)": {s:5,p:8},
  "แอกทิเนียม (Actinium)": {s:5,p:9},
  "แอสทาทีน (Astatine)": {s:5,p:10},
  "แฮฟเนียม (Hafnium)": {s:5,p:11},
  "โคบอลต์ (Cobalt)": {s:5,p:12},
  "โครเมียม (Chromium)": {s:5,p:13},
  "โคเปอร์นิเซียม (Copernicium)": {s:5,p:14},
  "โนเบเลียม (Nobelium)": {s:5,p:15},
  "โบรอน (Boron)": {s:5,p:16},
  "โบห์เรียม (Bohrium)": {s:5,p:17},
  "โพรมีเทียม (Promethium)": {s:5,p:18},
  "โพรแทกทิเนียม (Protactinium)": {s:5,p:19},
  "โพแทสเซียม (Potassium)": {s:5,p:20},
  "โมลิบดีนัม (Molybdenum)": {s:5,p:21},
  "โรเดียม (Rhodium)": {s:5,p:22},
  "โอแกเนสซอน (Oganesson)": {s:5,p:23},
  "โฮลเมียม (Holmium)": {s:5,p:24},
  "ไทเทเนียม (Titanium)": {s:5,p:25},
  "ไนโอเบียม (Niobium)": {s:5,p:26},
  "ไมต์เนเรียม (Meitnerium)": {s:5,p:27},
  "ไอน์สไตเนียม (Einsteinium)": {s:5,p:28},
};

// ── ระบบภาพจริงสารในบีกเกอร์ (sprite 6×5 = 30/แผ่น) ──
const CHEM_SHEET_COLS = 6, CHEM_SHEET_ROWS = 5;
const CHEM_SHEET_MAX = 5;  // มีภาพครบ 5 แผ่น (149 สาร)
const _chemSpriteCache = {};  // cache ภาพที่โหลดแล้ว

// โหลดภาพแผ่น (lazy)
function _loadChemSheet(sheetIdx) {
    if (_chemSpriteCache[sheetIdx]) return _chemSpriteCache[sheetIdx];
    const img = new Image();
    img.src = `images/chemicals/chem_sheet${sheetIdx}.png`;
    img._loaded = false;
    img.onload = () => { img._loaded = true; };
    _chemSpriteCache[sheetIdx] = img;
    return img;
}

// หาตำแหน่งสารในแผ่น (คืน null ถ้าไม่มีภาพ)
function getChemSprite(name) {
    // หา key ที่ตรง (ลองชื่อเต็ม + ชื่อไทยหลัก)
    let entry = CHEM_SPRITE_MAP[name];
    if (!entry) {
        // ลอง normalize (ตัดวงเล็บ, ตัด / )
        const clean = name.replace(/\s*\([^)]*\)/,'').trim();
        for (const k in CHEM_SPRITE_MAP) {
            if (k.replace(/\s*\([^)]*\)/,'').trim() === clean) { entry = CHEM_SPRITE_MAP[k]; break; }
        }
    }
    if (!entry || entry.s > CHEM_SHEET_MAX) return null;
    const img = _loadChemSheet(entry.s);
    if (!img._loaded) return null;  // ยังโหลดไม่เสร็จ
    const col = entry.p % CHEM_SHEET_COLS;
    const row = Math.floor(entry.p / CHEM_SHEET_COLS);
    return { img, col, row, cellW: img.width/CHEM_SHEET_COLS, cellH: img.height/CHEM_SHEET_ROWS };
}




// ═══════════════════════════════════════════════════════════════
//  ScoopSystem (Phase 2) — เครื่องมือตักสาร + cursor ตามเมาส์
// ═══════════════════════════════════════════════════════════════
const ScoopSystem = {
    activeTool: null,      // { type:'spatula', for:'solid', icon:'🥄' }
    loaded: null,          // สารที่ตักอยู่ { data, amount }
    cursorEl: null,
    _mouseHandler: null,

    ICONS: {
        spatula: '🥄', dropper: '💧'
    },
    // เครื่องมือที่ใช้รูปภาพแทน emoji: type → path
    ICON_IMG: {
        spatula: 'images/weapon/spon.png',
        dropper: 'images/weapon/หลอดหยด.png'
    },
    // คืน HTML ของไอคอน (รูปถ้ามี, ไม่งั้น emoji)
    iconHTML(type, size) {
        const sz = size || 22;
        if (this.ICON_IMG[type]) {
            return `<img src="${this.ICON_IMG[type]}" style="width:${sz}px;height:${sz}px;object-fit:contain;vertical-align:middle;" alt="">`;
        }
        return `<span style="font-size:${sz}px;line-height:1">${this.ICONS[type]}</span>`;
    },
    LABELS: {
        spatula: 'ช้อนตวง', dropper: 'หลอดหยด'
    },
    CAPACITY: {  // ความจุสูงสุด (บีกเกอร์รับได้ 200)
        spatula: 50, dropper: 20
    },

    init() {
        this.cursorEl = document.getElementById('scoopCursor');
        // bind ปุ่มเครื่องมือ
        document.querySelectorAll('.scoop-tool').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const type = btn.dataset.scoop;
                const forState = btn.dataset.for;
                // คลิกซ้ำ = ยกเลิก
                if (this.activeTool && this.activeTool.type === type) {
                    this.deactivate();
                } else {
                    this.activate(type, forState, btn);
                }
            });
        });
        // ESC = ยกเลิก
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') this.deactivate();
        });
    },

    activate(type, forState, btn) {
        this.deactivate();  // ล้างของเก่า
        if (window.LabApp && LabApp.audio) LabApp.audio.play('click');
        this.activeTool = { type, for: forState, icon: this.ICONS[type] };
        // highlight ปุ่ม
        document.querySelectorAll('.scoop-tool').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');
        // cursor
        document.body.classList.add('scoop-active');
        if (this.cursorEl) {
            this.cursorEl.querySelector('.scoop-icon').innerHTML = this.iconHTML(type, 96);
            this.cursorEl.classList.add('active');
            this.cursorEl.classList.remove('loaded');
        }
        // ติดตามเมาส์
        this._mouseHandler = (e) => {
            if (this.cursorEl) {
                this.cursorEl.style.left = e.clientX + 'px';
                this.cursorEl.style.top = e.clientY + 'px';
            }
            // ถ้าถือสารอยู่ → ไฮไลต์บีกเกอร์เมื่อเมาส์อยู่เหนือ (บอกว่าเทได้)
            if (this.loaded && window.PourSystem) {
                const bk = document.getElementById('beakerObj');
                if (bk) bk.classList.toggle('pour-ready', PourSystem._isOverBeaker(e));
            }
        };
        document.addEventListener('mousemove', this._mouseHandler);
        // highlight ภาชนะที่ตักได้ (state ตรง + เปิดฝา)
        this._updateContainerHints();
        this._updateHUD();
    },

    deactivate() {
        this.activeTool = null;
        this.loaded = null;
        document.body.classList.remove('scoop-active');
        document.querySelectorAll('.scoop-tool').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.chem-container').forEach(c => c.classList.remove('can-scoop'));
        if (this.cursorEl) {
            this.cursorEl.classList.remove('active','loaded');
        }
        if (this._mouseHandler) {
            document.removeEventListener('mousemove', this._mouseHandler);
            this._mouseHandler = null;
        }
        const hud = document.getElementById('scoopHUD');
        if (hud) hud.classList.remove('show');
        const bk = document.getElementById('beakerObj');
        if (bk) bk.classList.remove('pour-ready');
        document.body.classList.remove('scoop-loaded');
    },

    // highlight ภาชนะที่ตักได้ด้วยเครื่องมือนี้
    _updateContainerHints() {
        if (!this.activeTool) return;
        document.querySelectorAll('.chem-container').forEach(c => {
            const cid = c.dataset.chemId;
            const cont = (window.ContainerSystem && ContainerSystem.containers[cid]);
            if (cont && cont.data.state === this.activeTool.for) {
                c.classList.add('can-scoop');
            } else {
                c.classList.remove('can-scoop');
            }
        });
    },

    // ตักสารจากภาชนะ (เรียกจาก ContainerSystem click)
    scoopFrom(data, containerEl) {
        if (!this.activeTool) return;
        // เช็ค state ตรงกับเครื่องมือไหม
        if (data.state !== this.activeTool.for) {
            const need = this.activeTool.for === 'solid' ? 'ช้อนตวง/ช้อนตัก' : 'หลอดหยด/ปิเปต';
            if (window.LabApp && LabApp.log) {
                LabApp.log(`⚠️ ${this.LABELS[this.activeTool.type]} ใช้กับ${this.activeTool.for==='solid'?'ของแข็ง':'ของเหลว'}ไม่ได้ — ควรใช้ ${need}`);
            }
            return;
        }
        // ถ้าถืออยู่แล้ว → เตือน
        if (this.loaded) {
            if (window.LabApp && LabApp.log) LabApp.log(`⚠️ ${this.LABELS[this.activeTool.type]}มีสารอยู่แล้ว — เทก่อนตักใหม่`);
            return;
        }
        // เปิด modal เลือกปริมาณ
        if (!window.ScoopAmountModal) return;
        window.ScoopAmountModal.open(data, this.activeTool, (amount) => {
            this.loaded = { data, amount };
            if (this.cursorEl) {
                this.cursorEl.classList.add('loaded');
                const load = this.cursorEl.querySelector('.scoop-load');
                if (load) this._renderScoopLoad(load, data, amount);
            }
            document.body.classList.add('scoop-loaded');
            if (window.LabApp && LabApp.audio) {
                LabApp.audio.play(data.state === 'solid' ? 'drop_solid' : 'click');
            }
            this._updateHUD();
            const unit = data.state === 'solid' ? 'g' : 'mL';
            if (window.LabApp && LabApp.log) {
                LabApp.log(`${this.activeTool.icon} ตัก ${data.name} ${amount} ${unit} — คลิกที่บีกเกอร์เพื่อเท`);
            }
        });
    },

    // วาดสารบนช้อน — เม็ดกลมหลายเม็ด (ของแข็ง) / ของเหลวในหลอด
    _renderScoopLoad(load, data, amount) {
        const color = data.color || '#ccc';
        load.innerHTML = '';
        if (data.state === 'liquid' || data.state === 'aqueous') {
            // ของเหลว = หยดกลมใส
            const drop = document.createElement('div');
            drop.style.cssText = `position:absolute;left:50%;top:40%;transform:translate(-50%,-50%);
                width:70%;height:60%;border-radius:50% 50% 50% 50%/60% 60% 40% 40%;
                background:radial-gradient(circle at 40% 30%, ${color}dd, ${color}99);
                box-shadow:inset 0 -2px 3px rgba(0,0,0,0.2), 0 0 3px ${color}88;`;
            load.appendChild(drop);
            return;
        }
        // ของแข็ง = เม็ดกลมหลายเม็ด (มากตามปริมาณ) เต็มหลุมช้อน
        const cap = this.CAPACITY[this.activeTool.type] || 10;
        const fill = Math.min(1, amount / cap);           // 0-1 สัดส่วนเต็มช้อน
        const n = Math.round(25 + fill * 55);              // 25-80 เม็ด
        for (let i = 0; i < n; i++) {
            const g = document.createElement('div');
            g.className = 'scoop-grain';
            const sz = 3 + Math.random() * 3;               // เม็ดใหญ่ขึ้น 3-6px
            // กระจายเป็นวงรี (รูปหลุมช้อน) — หนาแน่นกลาง
            const ang = Math.random() * Math.PI * 2;
            const rad = Math.pow(Math.random(), 0.5);       // หนาแน่นกลาง
            const gx = 50 + Math.cos(ang) * rad * 45;
            const gy = 50 + Math.sin(ang) * rad * 42;
            // เฉดสีสุ่มให้ดูมีมิติ (สว่าง/เข้มนิดหน่อย)
            const shade = 0.82 + Math.random() * 0.36;
            g.style.cssText = `width:${sz}px;height:${sz}px;left:${gx}%;top:${gy}%;
                background:${color};filter:brightness(${shade});`;
            load.appendChild(g);
        }
    },

    // อัปเดตป้ายสถานะ "กำลังถือ"
    _updateHUD() {
        const hud = document.getElementById('scoopHUD');
        if (!hud) return;
        if (!this.activeTool) { hud.classList.remove('show'); return; }
        const toolName = this.LABELS[this.activeTool.type];
        if (this.loaded) {
            const unit = this.loaded.data.state === 'solid' ? 'g' : 'mL';
            const shortName = this.loaded.data.name.replace(/\s*\(.*\)/,'');
            hud.innerHTML = `<span>${this.iconHTML(this.activeTool.type, 26)}</span>`
                + `<span class="hud-dot" style="background:${this.loaded.data.color||'#ccc'}"></span>`
                + `<span><strong>${shortName}</strong> ${this.loaded.amount}${unit}</span>`
                + `<span class="hud-hint">— คลิกบีกเกอร์ = เท · คลิกโต๊ะ = หก</span>`;
        } else {
            const forTxt = this.activeTool.for === 'solid' ? 'ของแข็ง' : 'ของเหลว';
            const cap = this.CAPACITY[this.activeTool.type];
            const unit = this.activeTool.for === 'solid' ? 'g' : 'mL';
            hud.innerHTML = `<span>${this.iconHTML(this.activeTool.type, 26)}</span>`
                + `<span>ถือ <strong>${toolName}</strong> (${forTxt} · สูงสุด ${cap}${unit})</span>`
                + `<span class="hud-hint">— คลิกภาชนะที่เรืองแสงเพื่อตัก · ESC ยกเลิก</span>`;
        }
        hud.classList.add('show');
    },

    // ล้างสารบนเครื่องมือ (หลังเท)
    unload() {
        this.loaded = null;
        if (this.cursorEl) this.cursorEl.classList.remove('loaded');
        const bk = document.getElementById('beakerObj');
        if (bk) bk.classList.remove('pour-ready');
        document.body.classList.remove('scoop-loaded');
        this._updateHUD();
    },

    isActive() { return !!this.activeTool; }
};
window.ScoopSystem = ScoopSystem;

// ═══════════════════════════════════════════════════════════════
//  ScoopAmountModal (Phase 3) — หน้าต่างเลือกปริมาณตักสาร
// ═══════════════════════════════════════════════════════════════
const ScoopAmountModal = {
    _cb: null, _data: null, _tool: null,

    _el(id) { return document.getElementById(id); },

    open(data, tool, callback) {
        this._cb = callback; this._data = data; this._tool = tool;
        const cap = ScoopSystem.CAPACITY[tool.type] || 10;
        const unit = data.state === 'solid' ? 'g' : 'mL';
        const def = Math.min(cap * 0.5, cap);

        // เติมข้อมูล
        this._el('saToolIcon').innerHTML = (window.ScoopSystem && ScoopSystem.iconHTML) ? ScoopSystem.iconHTML(tool.type, 56) : tool.icon;
        this._el('saChemName').innerHTML = (window.LabApp && LabApp.formatFormula)
            ? LabApp.formatFormula(data.name) : data.name;
        this._el('saToolName').textContent = ScoopSystem.LABELS[tool.type] || tool.type;
        this._el('saUnit').textContent = unit;
        this._el('saCapacity').textContent = `ความจุสูงสุด ${cap} ${unit}`;

        // slider — step ปรับตามความจุ
        const slider = this._el('saSlider');
        let step;
        if (cap <= 20) step = 0.5;
        else if (cap <= 50) step = 1;
        else if (cap <= 100) step = 2.5;
        else step = 5;
        slider.min = step; slider.max = cap; slider.step = step; slider.value = def;
        this._el('saAmount').textContent = (+def).toFixed(1);

        // ปุ่มลัด (25% / 50% / 75% / 100%)
        const quick = this._el('saQuick');
        quick.innerHTML = '';
        [0.25, 0.5, 0.75, 1].forEach(f => {
            const v = +(cap * f).toFixed(1);
            const b = document.createElement('button');
            b.textContent = `${v}${unit}`;
            b.onclick = () => { slider.value = v; this._update(); };
            quick.appendChild(b);
        });

        // preview
        this._el('saPreviewDot').style.background = data.color || '#ccc';
        this._el('saPreviewTxt').textContent = tool.for === 'solid'
            ? `สารจะอยู่บน${ScoopSystem.LABELS[tool.type]}` : `สารจะอยู่ใน${ScoopSystem.LABELS[tool.type]}`;

        // events
        slider.oninput = () => this._update();
        this._el('saCancel').onclick = () => this.close();
        this._el('saConfirm').onclick = () => this._confirm();

        // แสดง (ซ่อน cursor เครื่องมือชั่วคราว ให้คลิกปุ่มได้)
        document.body.classList.remove('scoop-active');
        const sc = document.getElementById('scoopCursor');
        if (sc) sc.classList.remove('active');
        const modal = this._el('scoopAmountModal');
        modal.style.display = 'flex';
        setTimeout(() => this._el('scoopAmountBox').classList.add('show'), 10);
    },

    _update() {
        const v = parseFloat(this._el('saSlider').value);
        this._el('saAmount').textContent = v.toFixed(1);
        // ขนาด preview ตามปริมาณ
        const cap = ScoopSystem.CAPACITY[this._tool.type] || 10;
        const size = 16 + (v / cap) * 18;
        const dot = this._el('saPreviewDot');
        dot.style.width = size + 'px'; dot.style.height = size + 'px';
    },

    _confirm() {
        const amt = parseFloat(this._el('saSlider').value);
        this.close();
        if (this._cb) this._cb(amt);
    },

    close() {
        const box = this._el('scoopAmountBox');
        box.classList.remove('show');
        setTimeout(() => { this._el('scoopAmountModal').style.display = 'none'; }, 250);
        // คืน cursor เครื่องมือ (ถ้ายังถืออยู่)
        if (window.ScoopSystem && ScoopSystem.activeTool) {
            document.body.classList.add('scoop-active');
            const sc = document.getElementById('scoopCursor');
            if (sc) sc.classList.add('active');
        }
    }
};
window.ScoopAmountModal = ScoopAmountModal;

// ═══════════════════════════════════════════════════════════════
//  PourSystem (Phase 4) — เทสารลงบีกเกอร์ / หกบนโต๊ะ
// ═══════════════════════════════════════════════════════════════
const PourSystem = {
    _clickHandler: null,

    init() {
        // ดักคลิกซ้ายทั่วทั้ง workbench
        this._clickHandler = (e) => {
            // ต้องถือเครื่องมือ + มีสาร
            if (!window.ScoopSystem || !ScoopSystem.activeTool || !ScoopSystem.loaded) return;
            // ไม่นับคลิกที่ภาชนะ (ตักสาร) / แถบอุปกรณ์ / modal / panel
            if (e.target.closest('.chem-container')) return;
            if (e.target.closest('.equip-slot')) return;
            if (e.target.closest('.modal-backdrop')) return;
            if (e.target.closest('.chem-draggable')) return;
            if (e.target.closest('#containerShelf')) return;

            const wb = document.getElementById('workbenchScroll');
            if (!wb || !wb.contains(e.target)) return;  // คลิกนอกโต๊ะ = ไม่ทำอะไร

            const { data, amount } = ScoopSystem.loaded;
            if (this._isOverBeaker(e)) {
                this.pourIntoBeaker(data, amount);
            } else {
                this.spillOnDesk(data, amount, e);
            }
            ScoopSystem.unload();
        };
        document.addEventListener('click', this._clickHandler);
    },

    // เช็คว่าคลิกที่บีกเกอร์หรือเหนือบีกเกอร์
    _isOverBeaker(e) {
        const beaker = document.getElementById('beakerObj');
        if (!beaker) return false;
        const r = beaker.getBoundingClientRect();
        // ในแนวนอนตรงบีกเกอร์ (ขยายนิดหน่อย) + สูงกว่าก้นบีกเกอร์
        const padX = 30;
        return e.clientX >= r.left - padX && e.clientX <= r.right + padX && e.clientY <= r.bottom;
    },

    // เทลงบีกเกอร์ — ใช้ executePour เดิม (ได้ safety/particle/ล้นแก้วครบ)
    pourIntoBeaker(data, amount) {
        const app = window.LabApp;
        if (!app || typeof app.executePour !== 'function') return;
        // set state ชั่วคราวให้ executePour ใช้
        app.state.activeTool = { ...data, amount: 0.0, dissolvedAmt: 0.0 };
        // สำรอง + ใส่ค่าปริมาณ
        const realInput = app.ui.tbInputAmt;
        const hadReal = realInput && typeof realInput.value !== 'undefined';
        const backup = hadReal ? realInput.value : null;
        if (hadReal) {
            realInput.value = String(amount);
        } else {
            app.ui.tbInputAmt = { value: String(amount) };
        }
        try {
            app.executePour();
        } catch (err) {
            console.error('[Pour] error:', err);
        }
        // คืนค่า
        if (hadReal) realInput.value = backup;
        else app.ui.tbInputAmt = realInput;
    },

    // หกบนโต๊ะ — สารร่วงจากตำแหน่งเมาส์ลงพื้นโต๊ะ
    spillOnDesk(data, amount, e) {
        const app = window.LabApp;
        const wb = document.getElementById('workbenchScroll');
        if (!wb) return;
        const r = wb.getBoundingClientRect();
        const x = e.clientX - r.left;
        const startY = e.clientY - r.top;

        // ── หาระดับ "พื้นโต๊ะ" จาก desk-anchor (ที่บีกเกอร์วางอยู่) ──
        let floorY;
        const anchor = document.getElementById('labAnchor');
        if (anchor) {
            const ar = anchor.getBoundingClientRect();
            floorY = ar.bottom - r.top;   // ก้นของ desk-anchor = พื้นโต๊ะ
        } else {
            floorY = r.height * 0.75;     // fallback
        }
        // ถ้าคลิกต่ำกว่าพื้นโต๊ะแล้ว ให้ใช้ตำแหน่งคลิก
        if (startY > floorY) floorY = startY;
        const fallDist = Math.max(0, floorY - startY);

        // ── เม็ด/หยดร่วงลงพื้น ──
        const n = data.state === 'solid'
            ? Math.min(22, Math.round(amount * 0.5) + 5)
            : Math.min(14, Math.round(amount * 0.4) + 4);

        for (let i = 0; i < n; i++) {
            const p = document.createElement('div');
            const s = data.state === 'solid' ? (2 + Math.random() * 3.5) : (3 + Math.random() * 3);
            const spreadX = (Math.random() - 0.5) * (18 + amount * 0.5);
            const delay = Math.random() * 90;
            p.style.cssText = `
                position:absolute; left:${x}px; top:${startY}px; z-index:23;
                width:${s}px; height:${s}px; border-radius:50%;
                background:${data.color||'#ccc'};
                box-shadow:0 0 3px ${data.color||'#ccc'}88;
                pointer-events:none; opacity:0.95;
                transform:translate(-50%,-50%);
                transition: transform ${340 + Math.random()*180}ms cubic-bezier(.45,.05,.55,.95), opacity .3s ${300+delay}ms;
            `;
            wb.appendChild(p);
            requestAnimationFrame(() => {
                setTimeout(() => {
                    p.style.transform = `translate(calc(-50% + ${spreadX}px), calc(-50% + ${fallDist}px))`;
                }, delay);
            });
            setTimeout(() => p.remove(), 900 + delay);
        }

        // ── คราบสารบนพื้นโต๊ะ (โผล่หลังเม็ดตกถึง) ──
        const spill = document.createElement('div');
        spill.className = 'desk-spill';
        const size = Math.min(90, 20 + amount * 1.1);
        spill.style.cssText = `
            position:absolute; left:${x}px; top:${floorY}px; z-index:21;
            width:${size}px; height:${size*0.32}px;
            background: radial-gradient(ellipse at 48% 45%, ${data.color||'#ccc'} 0%, ${data.color||'#ccc'}cc 50%, transparent 82%);
            border-radius:50%; transform:translate(-50%,-50%) scale(0.15);
            opacity:0; pointer-events:none;
            transition: transform .5s cubic-bezier(.22,1,.36,1), opacity .35s;
            filter: blur(0.5px);
        `;
        wb.appendChild(spill);
        setTimeout(() => {
            spill.style.transform = 'translate(-50%,-50%) scale(1)';
            spill.style.opacity = data.state === 'solid' ? '0.88' : '0.72';
        }, 300);

        // ลบคราบหลัง 8 วิ
        setTimeout(() => {
            spill.style.opacity = '0';
            setTimeout(() => spill.remove(), 400);
        }, 8300);

        const unit = data.state === 'solid' ? 'g' : 'mL';
        if (app) {
            if (app.audio) setTimeout(() => app.audio.play(data.state === 'solid' ? 'drop_solid' : 'wipe'), 300);
            app.log(`⚠️ <span style="color:#f59e0b">${data.name} ${amount}${unit} หกบนโต๊ะ!</span>`);
            if (app.state && app.state.telemetry) app.state.telemetry.spillCount++;
            // สารอันตรายหก = เสียหาย
            const hz = parseInt(data.hazard) || 0;
            if (hz >= 3 && !app.config.isAdmin) {
                app.takeDamage(8, 'สารอันตรายหกบนโต๊ะ', 'chem');
            }
        }
    }
};
window.PourSystem = PourSystem;






// ═══════════════════════════════════════════════════════════════
//  ContainerSystem (Phase 1) — ภาชนะสารบนโต๊ะ + ฝาเปิด/ปิด
// ═══════════════════════════════════════════════════════════════
const ContainerSystem = {
    containers: {},   // { chemId: { data, el, lidState } }
    shelf: null,

    init() {
        this.shelf = document.getElementById('containerShelf');
        const btn = document.getElementById('clearContainersBtn');
        if (btn) {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.clear();
                if (window.LabApp && LabApp.audio) LabApp.audio.play('click');
                if (window.LabApp && LabApp.log) LabApp.log('📦 เก็บภาชนะทั้งหมดกลับคลัง');
            });
        }
    },

    // แสดง/ซ่อนปุ่มล้างตามจำนวนภาชนะ
    _syncClearBtn() {
        const btn = document.getElementById('clearContainersBtn');
        if (!btn) return;
        const has = Object.keys(this.containers).length > 0;
        btn.classList.toggle('show', has);
    },

    // เลือกประเภทภาชนะตามความปลอดภัยของสาร
    _pickBottle(data) {
        const name = (data.name||'').toLowerCase();
        const type = (data.type||'').toLowerCase();
        const hazard = parseInt(data.hazard) || 0;
        const state = data.state || 'solid';
        const isConc = name.includes('เข้มข้น') || name.includes('conc');

        // กัมมันตรังสี / hazard สูงสุด → กระป๋องโลหะ
        if (hazard >= 4 || /uranium|plutonium|radium|กัมมันตรังสี|ยูเรเนียม|เรเดียม|พลูโท/.test(name)
            || type==='actinide') return 'metal_can';
        // กรดแรงเข้มข้น → ขวดแก้วหนา+จุกแก้ว
        if (type==='acid' && (isConc || hazard>=3)) return 'thick_glass';
        // ไวไฟ/ระเบิด → ขวดกันแสง+ซีล
        if (/โซเดียม|โพแทสเซียม|ฟอสฟอรัสขาว|sodium|potassium|white phos|nitroglycer|ไนโตรกลี/.test(name)
            && state==='solid') return 'sealed_dark';
        // กรด/ด่างแรง → พลาสติก HDPE
        if ((type==='acid' || type==='base') && hazard>=2) return 'hdpe';
        // ไวแสง → ขวดสีชา
        if (/agno|silver nitrate|ซิลเวอร์ไนเตรต|iodine|ไอโอดีน|h2o2|เปอร์ออกไซด์|bromine|โบรมีน/.test(name)) return 'amber';
        // ของแข็งตัวอย่าง → จานเพาะ (สารที่ปลอดภัยมาก)
        if (state==='solid' && hazard===0 && /metal|โลหะ/.test(type)) return 'petri';
        // default
        return state==='liquid' ? 'clear_narrow' : 'clear_wide';
    },

    // สร้าง SVG ภาชนะ (มี substance สีจริง + ฝาเปิดปิดได้)
    _bottleSVG(bottle, color) {
        const c = color || '#e0e0e0';
        // แต่ละแบบมี: ฝา (.cc-lid) + ตัวขวด + สาร
        const bodies = {
            clear_wide: `
                <g class="cc-lid"><rect x="21" y="6" width="12" height="9" rx="2" fill="#8b7355"/></g>
                <path d="M17 16 L37 16 L37 24 Q41 28 41 36 L41 68 Q41 74 35 74 L19 74 Q13 74 13 68 L13 36 Q13 28 17 24 Z" fill="rgba(180,210,230,0.3)" stroke="#a0c4d8" stroke-width="1.3"/>
                <rect x="16" y="46" width="22" height="26" rx="3" fill="${c}" opacity="0.9"/>`,
            clear_narrow: `
                <g class="cc-lid"><rect x="22" y="6" width="10" height="9" rx="2" fill="#8b7355"/></g>
                <path d="M22 15 L32 15 L32 26 Q40 30 40 40 L40 68 Q40 74 34 74 L20 74 Q14 74 14 68 L14 40 Q14 30 22 26 Z" fill="rgba(180,210,230,0.3)" stroke="#a0c4d8" stroke-width="1.3"/>
                <path d="M16 48 L38 48 L38 68 Q38 72 34 72 L20 72 Q16 72 16 68 Z" fill="${c}" opacity="0.85"/>`,
            amber: `
                <g class="cc-lid"><rect x="21" y="6" width="12" height="9" rx="2" fill="#5a3a1a"/></g>
                <path d="M17 16 L37 16 L37 24 Q41 28 41 36 L41 68 Q41 74 35 74 L19 74 Q13 74 13 68 L13 36 Q13 28 17 24 Z" fill="rgba(140,90,40,0.5)" stroke="#8a5a2a" stroke-width="1.3"/>
                <rect x="16" y="46" width="22" height="26" rx="3" fill="${c}" opacity="0.7"/>`,
            hdpe: `
                <g class="cc-lid"><rect x="20" y="5" width="14" height="10" rx="2" fill="#d84040"/></g>
                <path d="M15 16 L39 16 L39 24 Q43 28 43 36 L43 70 Q43 74 39 74 L15 74 Q11 74 11 70 L11 36 Q11 28 15 24 Z" fill="rgba(230,240,245,0.55)" stroke="#c0c8cc" stroke-width="1.3"/>
                <rect x="14" y="46" width="26" height="27" rx="2" fill="${c}" opacity="0.8"/>`,
            thick_glass: `
                <g class="cc-lid"><path d="M20 5 L34 5 L32 14 L22 14 Z" fill="#b0b0b0" stroke="#888" stroke-width="0.8"/><rect x="23" y="12" width="8" height="5" fill="#999"/></g>
                <path d="M16 16 L38 16 L38 24 Q42 28 42 38 L42 68 Q42 74 36 74 L18 74 Q12 74 12 68 L12 38 Q12 28 16 24 Z" fill="rgba(200,220,235,0.4)" stroke="#90a8b8" stroke-width="2"/>
                <rect x="16" y="48" width="22" height="24" rx="2" fill="${c}" opacity="0.85"/>`,
            sealed_dark: `
                <g class="cc-lid"><rect x="19" y="5" width="16" height="12" rx="3" fill="#2a2a2a"/><rect x="23" y="14" width="8" height="5" fill="#444"/></g>
                <path d="M15 18 L39 18 L39 26 Q41 30 41 38 L41 70 Q41 74 37 74 L17 74 Q13 74 13 70 L13 38 Q13 30 15 26 Z" fill="rgba(80,80,90,0.55)" stroke="#555" stroke-width="1.3"/>
                <rect x="16" y="48" width="22" height="24" rx="2" fill="${c}" opacity="0.7"/>`,
            metal_can: `
                <g class="cc-lid"><rect x="17" y="8" width="20" height="7" rx="2" fill="#707070"/></g>
                <rect x="13" y="15" width="28" height="58" rx="4" fill="#8a8a92" stroke="#666" stroke-width="1.3"/>
                <rect x="17" y="21" width="20" height="47" rx="2" fill="${c}" opacity="0.6"/>
                <text x="27" y="50" text-anchor="middle" font-size="13" fill="#ffd700">☢</text>`,
            petri: `
                <g class="cc-lid"><ellipse cx="27" cy="40" rx="26" ry="6" fill="rgba(200,220,235,0.25)" stroke="#a0c4d8" stroke-width="1"/></g>
                <ellipse cx="27" cy="58" rx="26" ry="7" fill="rgba(200,220,235,0.3)" stroke="#a0c4d8" stroke-width="1.3"/>
                <path d="M1 58 Q1 68 27 68 Q53 68 53 58" fill="rgba(200,220,235,0.2)" stroke="#a0c4d8" stroke-width="1.3"/>
                <ellipse cx="27" cy="58" rx="17" ry="4" fill="${c}" opacity="0.75"/>`,
        };
        return `<svg width="54" height="82" viewBox="0 0 54 82">${bodies[bottle]||bodies.clear_wide}</svg>`;
    },

    // เพิ่มภาชนะเมื่อคลิกสาร
    // ═══ ลากภาชนะไปวางที่ไหนก็ได้บนโต๊ะ ═══
    _makeDraggable(el, id) {
        let startX = 0, startY = 0, origX = 0, origY = 0;
        let dragging = false, moved = false;
        const THRESHOLD = 4;   // px — เกินนี้ถือว่า "ลาก" ไม่ใช่ "คลิก"

        const onDown = (e) => {
            if (e.button !== 0) return;             // เฉพาะคลิกซ้าย
            const wb = document.getElementById('workbenchScroll');
            if (!wb) return;
            dragging = true; moved = false;
            startX = e.clientX; startY = e.clientY;

            // ถ้ายังอยู่ใน shelf → ย้ายออกมาเป็น absolute ใน workbench
            if (el.parentElement !== wb) {
                const r  = el.getBoundingClientRect();
                const wr = wb.getBoundingClientRect();
                origX = r.left - wr.left;
                origY = r.top  - wr.top;
                el.style.position = 'absolute';
                el.style.left = origX + 'px';
                el.style.top  = origY + 'px';
                el.style.margin = '0';
                wb.appendChild(el);
                el.classList.add('cc-free');
                this._syncClearBtn();
            } else {
                origX = parseFloat(el.style.left) || 0;
                origY = parseFloat(el.style.top)  || 0;
            }
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
            e.preventDefault();
        };

        const onMove = (e) => {
            if (!dragging) return;
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;
            if (!moved && Math.hypot(dx, dy) > THRESHOLD) {
                moved = true;
                el.classList.add('cc-dragging');
                el.style.zIndex = 60;
            }
            if (moved) {
                const wb = document.getElementById('workbenchScroll');
                const wr = wb.getBoundingClientRect();
                let nx = origX + dx, ny = origY + dy;
                // กันหลุดขอบโต๊ะ
                nx = Math.max(0, Math.min(wr.width  - el.offsetWidth,  nx));
                ny = Math.max(0, Math.min(wr.height - el.offsetHeight, ny));
                el.style.left = nx + 'px';
                el.style.top  = ny + 'px';
            }
        };

        const onUp = () => {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            if (!dragging) return;
            dragging = false;
            if (moved) {
                el.classList.remove('cc-dragging');
                el.style.zIndex = 25;
                el._justDragged = true;                  // กัน click ทำงานต่อ
                setTimeout(() => { el._justDragged = false; }, 60);
                if (window.LabApp && LabApp.audio) LabApp.audio.play('click');
            }
        };

        el.addEventListener('mousedown', onDown);
    },

    add(data) {
        if (!this.shelf) this.init();
        if (!this.shelf) return;
        const id = data.id || data.name;
        // ถ้ามีแล้ว → highlight (เด้ง)
        if (this.containers[id]) {
            const el = this.containers[id].el;
            el.style.transform = 'translateY(-8px)';
            setTimeout(()=>{ el.style.transform=''; }, 200);
            el.scrollIntoView({behavior:'smooth', inline:'center', block:'nearest'});
            return;
        }
        const bottle = this._pickBottle(data);
        const color = data.color || '#e0e0e0';
        const el = document.createElement('div');
        el.className = 'chem-container';
        el.dataset.chemId = id;
        el.dataset.bottle = bottle;
        el.title = `${data.name}\nคลิก = เปิด/ปิดฝา · ลาก = ย้ายที่ · คลิกขวา = เก็บ\n(ถือเครื่องมือแล้วคลิก = ตักสาร)`;
        el.innerHTML = this._bottleSVG(bottle, color)
            + `<div class="cc-label">${(data.name||'').replace(/\s*\(.*\)/,'')}</div>`;

        // ── ฝาเปิด/ปิด ──
        // hover = เปิดชั่วคราว (ถ้าไม่ locked)
        el.addEventListener('mouseenter', () => {
            if (!el.classList.contains('lid-locked')) el.classList.add('lid-open');
        });
        // คลิกขวา = เก็บภาชนะ
        el.addEventListener('contextmenu', (e) => {
            e.preventDefault(); e.stopPropagation();
            ContainerSystem.remove(id);
            if (window.LabApp && LabApp.audio) LabApp.audio.play('click');
            if (window.LabApp && LabApp.log) LabApp.log(`📦 เก็บ ${data.name} กลับคลัง`);
        });
        el.addEventListener('mouseleave', () => {
            if (!el.classList.contains('lid-locked')) el.classList.remove('lid-open');
        });
        // click = toggle ฝา / หรือตักถ้าถือเครื่องมือ
        el.addEventListener('click', (e) => {
            e.stopPropagation();
            if (el._justDragged) return;   // เพิ่งลากมา → ไม่ toggle ฝา
            const holding = window.ScoopSystem && window.ScoopSystem.activeTool;
            const lidOpen = el.classList.contains('lid-open') || el.classList.contains('lid-locked');
            // ถือเครื่องมือ + ฝาเปิด → ตักสาร
            if (holding && lidOpen) {
                window.ScoopSystem.scoopFrom(data, el);
                return;
            }
            // ถือเครื่องมือ + ฝาปิด → เตือนให้เปิดฝา
            if (holding && !lidOpen) {
                if (window.LabApp && LabApp.log) LabApp.log('⚠️ เปิดฝาภาชนะก่อนตักสาร (ชี้เมาส์หรือคลิกที่ภาชนะ)');
                el.classList.add('lid-locked','lid-open');
                return;
            }
            // ไม่ถือเครื่องมือ → toggle ล็อคฝา
            if (el.classList.contains('lid-locked')) {
                el.classList.remove('lid-locked','lid-open');
                if (window.LabApp && LabApp.audio) LabApp.audio.play('click');
            } else {
                el.classList.add('lid-locked','lid-open');
                if (window.LabApp && LabApp.audio) LabApp.audio.play('click');
            }
        });

        this._makeDraggable(el, id);   // ลากได้
        this.shelf.appendChild(el);
        this.containers[id] = { data, el, lidState:'closed' };
        this._syncClearBtn();
        if (window.ScoopSystem && ScoopSystem.activeTool) ScoopSystem._updateContainerHints();
    },

    remove(id) {
        if (this.containers[id]) {
            this.containers[id].el.remove();
            delete this.containers[id];
            this._syncClearBtn();
        }
    },

    clear() {
        Object.keys(this.containers).forEach(id => this.remove(id));
    }
};


// ═══════════════════════════════════════════════════════════════
//  SolidParticleSystem — เม็ดสารร่วง 3D + กองซ้อน + ละลาย
//  แทนที่ SolidRenderer เดิม (particle-based, มี physics)
// ═══════════════════════════════════════════════════════════════
// ── แก้สีสารที่ DB เก็บผิด (override ตามชื่อ) ──
const CHEM_COLOR_FIX = {
    'เหล็ก (Iron)': '#708090',
    'เหล็ก/ฝอยขัดหม้อ (Iron)': '#708090',
    'เหล็ก\\/ฝอยขัดหม้อ (Iron)': '#708090',
    'นิกเกิล (Nickel)': '#A0A0A8',
    'ดีบุก (Tin)': '#C8C8D0',
    'ตะกั่ว (Lead)': '#5A5A6A',
};
function fixChemColor(name, color) {
    return CHEM_COLOR_FIX[name] || color;
}

const SolidParticleSystem = {
    piles: {},        // { chemKey: { particles:[], falling:[], color, texture } }
    _lastAmounts: {}, // ติดตาม amount เพื่อ spawn เม็ดใหม่
    GRAVITY: 0.14,    // แรงโน้มถ่วง (เบา = ร่วงช้า เห็นชัด)
    FLOOR_BOUNCE: 0.25,

    // config ต่อ texture: [ขนาดเม็ด, จำนวนต่อกรัม, เหลี่ยม?]
    _texCfg(texture) {
        switch(texture) {
            case 'fine_powder': return { size: 2.0, perGram: 2.5, jagged: 0, shine: 0.15 };
            case 'powder':      return { size: 3.0, perGram: 1.5, jagged: 0.2, shine: 0.2 };
            case 'crystal':     return { size: 5.0, perGram: 0.7, jagged: 0.5, shine: 0.6 };
            case 'coarse_crystal': return { size: 8.0, perGram: 0.35, jagged: 0.6, shine: 0.7 };
            case 'metal_chunk': return { size: 10.0, perGram: 0.22, jagged: 0.4, shine: 0.9 };
            case 'metal_plate': return { size: 12.0, perGram: 0.15, jagged: 0.1, shine: 0.95 };
            case 'dry_ice':     return { size: 9.0, perGram: 0.3, jagged: 0.3, shine: 0.4 };
            case 'paste':       return { size: 7.0, perGram: 0.4, jagged: 0, shine: 0.1 };
            default:            return { size: 3.5, perGram: 1.2, jagged: 0.2, shine: 0.25 };
        }
    },

    _parseColor(ctx, color) {
        if (Array.isArray(color)) return color;
        if (typeof color === 'string') {
            if (color.startsWith('#')) {
                const h = color.slice(1);
                const n = h.length === 3
                    ? h.split('').map(c=>parseInt(c+c,16))
                    : [parseInt(h.slice(0,2),16),parseInt(h.slice(2,4),16),parseInt(h.slice(4,6),16)];
                if (!n.some(isNaN)) return n;
            }
            const m = color.match(/(\d+)[,\s]+(\d+)[,\s]+(\d+)/);
            if (m) return [+m[1],+m[2],+m[3]];
        }
        return [200,210,220];
    },

    // spawn เม็ดใหม่เมื่อใส่สารเพิ่ม
    _spawnFor(key, c, profile, width) {
        const cfg = this._texCfg(profile.texture);
        const undissolved = Math.max(0, (c.amount||0) - (c.dissolvedAmt||0));
        const targetCount = Math.min(180, Math.floor(undissolved * cfg.perGram));

        if (!this.piles[key]) {
            this.piles[key] = { settled: [], falling: [], color: this._parseColor(null, fixChemColor(c.name, c.color)), cfg, texture: profile.texture };
        }
        const pile = this.piles[key];
        pile.color = this._parseColor(null, fixChemColor(c.name, c.color));
        pile.cfg = cfg;
        pile.texture = profile.texture;

        const current = pile.settled.length + pile.falling.length;
        const diff = targetCount - current;

        if (diff > 0) {
            // spawn เม็ดร่วงใหม่ (จากเหนือปากบีกเกอร์)
            for (let i=0; i<Math.min(diff, 40); i++) {
                pile.falling.push({
                    x: width/2 + (Math.random()-0.5) * 24,
                    y: -10 - Math.random()*40,          // เหนือปากบีกเกอร์
                    vx: (Math.random()-0.5) * 0.6,
                    vy: Math.random() * 0.5,            // เริ่มช้า
                    size: cfg.size * (0.75 + Math.random()*0.5),
                    rot: Math.random()*Math.PI,
                    settled: false,
                    seed: Math.random(),
                });
            }
        } else if (diff < 0) {
            // ละลาย → เอาเม็ดออก (จากบนสุดของกอง)
            const remove = Math.min(-diff, pile.settled.length);
            pile.settled.sort((a,b)=>a.y-b.y);  // บนสุด (y น้อย) ก่อน
            pile.settled.splice(0, remove);
        }
    },

    // update physics ทุกเฟรม
    _update(pile, width, floorY) {
        const g = this.GRAVITY;
        pile.falling.forEach(p => {
            p.vy += g;
            p.x += p.vx;
            p.y += p.vy;
            p.rot += p.vx * 0.05;

            // หาความสูงกองที่ตำแหน่ง x นี้ (stacking)
            const stackY = this._stackHeightAt(pile, p.x, floorY, p.size);
            if (p.y >= stackY) {
                p.y = stackY;
                p.settled = true;
                // เลื่อนออกด้านข้างนิดหน่อยถ้าชนยอด (กองแผ่)
                if (Math.abs(p.vy) > 1) {
                    p.x += (Math.random()-0.5) * p.size * 1.5;
                }
                p.vx = 0; p.vy = 0;
            }
        });
        // ย้ายเม็ดที่ตกแล้วไป settled
        const nowSettled = pile.falling.filter(p=>p.settled);
        pile.settled.push(...nowSettled);
        pile.falling = pile.falling.filter(p=>!p.settled);
        // จำกัดขอบ
        pile.settled.forEach(p => {
            p.x = Math.max(p.size, Math.min(width - p.size, p.x));
        });
    },

    // ความสูงกอง ณ ตำแหน่ง x (เม็ดซ้อนเม็ด)
    _stackHeightAt(pile, x, floorY, size) {
        let maxTop = floorY;
        for (const s of pile.settled) {
            if (Math.abs(s.x - x) < (s.size + size) * 0.6) {
                const top = s.y - s.size * 0.8;
                if (top < maxTop) maxTop = top;
            }
        }
        return maxTop;
    },

    // วาดเม็ดกลม 3D (ไฮไลต์ + เงา)
    _drawParticle(ctx, p, color, cfg, floorBaseY) {
        const [r,g,b] = color;
        const s = p.size;
        ctx.save();
        ctx.translate(p.x, p.y);
        if (cfg.jagged > 0.3) ctx.rotate(p.rot);

        // เงาใต้เม็ด
        ctx.beginPath();
        ctx.ellipse(0, s*0.5, s*0.9, s*0.4, 0, 0, Math.PI*2);
        ctx.fillStyle = `rgba(0,0,0,0.18)`;
        ctx.fill();

        // ตัวเม็ด — gradient ทรงกลม
        const grad = ctx.createRadialGradient(-s*0.3, -s*0.3, s*0.1, 0, 0, s);
        const lighten = (v,amt)=>Math.min(255, v+amt);
        const darken = (v,amt)=>Math.max(0, v-amt);
        grad.addColorStop(0, `rgb(${lighten(r,60)},${lighten(g,60)},${lighten(b,60)})`);
        grad.addColorStop(0.5, `rgb(${r},${g},${b})`);
        grad.addColorStop(1, `rgb(${darken(r,50)},${darken(g,50)},${darken(b,50)})`);

        ctx.beginPath();
        if (cfg.jagged > 0.4) {
            // ผลึก/ก้อน = เหลี่ยม
            const sides = 5 + Math.floor(p.seed*3);
            for (let i=0;i<sides;i++){
                const a = (i/sides)*Math.PI*2;
                const rad = s * (0.75 + p.seed*0.35*Math.sin(i*2.3));
                const px=Math.cos(a)*rad, py=Math.sin(a)*rad;
                i===0?ctx.moveTo(px,py):ctx.lineTo(px,py);
            }
            ctx.closePath();
        } else {
            ctx.arc(0,0,s,0,Math.PI*2);
        }
        ctx.fillStyle = grad;
        ctx.fill();

        // ไฮไลต์วาว (มุมบนซ้าย)
        if (cfg.shine > 0.2) {
            ctx.beginPath();
            ctx.arc(-s*0.32, -s*0.32, s*0.28, 0, Math.PI*2);
            ctx.fillStyle = `rgba(255,255,255,${cfg.shine*0.7})`;
            ctx.fill();
        }
        ctx.restore();
    },

    // เรียกหลักจาก renderCanvas
    render(ctx, contents, width, height, time) {
        const floorY = height - 4;
        const solids = contents.filter(c => c.state === 'solid');
        const activeKeys = new Set();

        // spawn / update ต่อสาร
        solids.forEach(c => {
            if (!c || typeof c.amount !== 'number') return;
            const undissolved = Math.max(0, (c.amount||0)-(c.dissolvedAmt||0));
            const key = (c.name||'') + '_' + (c.type||'');
            activeKeys.add(key);
            const profile = (typeof ChemVisualProfile!=='undefined')
                ? ChemVisualProfile.get(c.type, c.name, 'solid')
                : { texture:'powder' };
            if (undissolved < 0.01) {
                if (this.piles[key]) delete this.piles[key];
                return;
            }
            this._spawnFor(key, c, profile, width);
        });

        // ลบ pile ของสารที่ไม่มีแล้ว
        Object.keys(this.piles).forEach(k => {
            if (!activeKeys.has(k)) delete this.piles[k];
        });

        // update + วาด ทุก pile
        let stackBase = floorY;
        Object.values(this.piles).forEach(pile => {
            this._update(pile, width, stackBase);
            // วาด settled ก่อน (ล่าง) แล้ว falling (บน)
            pile.settled.forEach(p => this._drawParticle(ctx, p, pile.color, pile.cfg, floorY));
            pile.falling.forEach(p => this._drawParticle(ctx, p, pile.color, pile.cfg, floorY));
        });
    },

    // เช็คว่ามี particle เคลื่อนไหวอยู่ไหม (สำหรับ render loop)
    isActive() {
        return Object.values(this.piles).some(p => p.falling.length > 0);
    },

    clear() { this.piles = {}; this._lastAmounts = {}; }
};


const SolidRenderer = {

    /**
     * วาด solid ทั้งหมดใน beaker
     * @param {CanvasRenderingContext2D} ctx
     * @param {Array} contents  - this.state.contents
     * @param {number} width    - canvas width
     * @param {number} height   - canvas height
     * @param {number} time     - performance.now() สำหรับ animation
     */
    render(ctx, contents, width, height, time) {
        let baseY = height - 4;
        let totalUndissolved = 0;

        const solids = contents.filter(c => c.state === 'solid');
        if (solids.length === 0) return;

        solids.forEach(c => {
            if (!c || typeof c.amount !== 'number') return;  // NaN guard
            const undissolved = Math.max(0, (c.amount || 0) - (c.dissolvedAmt || 0));
            if (undissolved < 0.01) return;

            totalUndissolved += undissolved;
            const profile = ChemVisualProfile.get(c.type, c.name, 'solid');
            const rgb     = this._parseColor(ctx, c.color);

            // ── ถ้ามีภาพจริง (sprite) วาดภาพแทน canvas ──
            const sprite = (typeof getChemSprite !== 'undefined') ? getChemSprite(c.name) : null;
            if (sprite) {
                this._drawSprite(ctx, sprite, undissolved, width, baseY);
                baseY -= Math.min(25, undissolved * 0.8);
                return;  // ข้าม canvas texture
            }

            switch (profile.texture) {
                case 'metal_plate':
                    this._drawMetalPlate(ctx, rgb, undissolved, width, baseY, time);
                    break;
                case 'metal_chunk':
                    this._drawMetalChunks(ctx, rgb, undissolved, width, baseY, profile.shimmer, time);
                    break;
                case 'fine_powder':
                    this._drawFinePowder(ctx, rgb, undissolved, width, baseY);
                    break;
                case 'crystal':
                    this._drawCrystals(ctx, rgb, undissolved, width, baseY, profile.particleSize, profile.shimmer);
                    break;
                case 'coarse_crystal':
                    this._drawCoarseCrystals(ctx, rgb, undissolved, width, baseY, profile.particleSize);
                    break;
                case 'dry_ice':
                    this._drawDryIce(ctx, rgb, undissolved, width, baseY, time);
                    break;
                case 'paste':
                    this._drawPaste(ctx, rgb, undissolved, width, baseY);
                    break;
                default:
                    this._drawPowder(ctx, rgb, undissolved, width, baseY, profile.particleSize);
            }

            // ปรับ baseY ขึ้น เพื่อ stack สารหลายชั้น
            baseY -= Math.min(25, undissolved * 0.8);
        });
    },

    // ── วาดภาพจริงของสาร (sprite จากแผ่น) ──
    _drawSprite(ctx, sprite, amount, width, baseY) {
        const { img, col, row, cellW, cellH } = sprite;
        const scale = Math.min(1, 0.4 + amount / 100);
        const dw = Math.min(width * 0.7, cellW * scale * 0.85);
        const dh = dw * (cellH / cellW);
        const dx = (width - dw) / 2;
        const dy = baseY - dh;
        ctx.save();
        ctx.globalAlpha = Math.min(1, 0.5 + amount / 50);
        ctx.drawImage(img, col * cellW, row * cellH, cellW, cellH, dx, dy, dw, dh);
        ctx.restore();
    },

    // ── แผ่นโลหะ ──────────────────────────────────────────────
    _drawMetalPlate(ctx, rgb, amt, width, baseY, time) {
        const [r, g, b] = rgb;
        const plateW = Math.min(width - 20, 60 + amt * 3);
        const plateH = Math.min(8, 3 + amt * 0.3);
        const x0 = (width - plateW) / 2;
        const y0 = baseY - plateH;

        // เงาด้านล่าง
        ctx.save();
        ctx.fillStyle = `rgba(0,0,0,0.3)`;
        ctx.fillRect(x0 + 3, y0 + plateH, plateW - 3, 3);

        // ตัวแผ่น
        const grad = ctx.createLinearGradient(x0, y0, x0, y0 + plateH);
        grad.addColorStop(0,   `rgba(${Math.min(255,r+60)},${Math.min(255,g+60)},${Math.min(255,b+60)},1)`);
        grad.addColorStop(0.4, `rgba(${r},${g},${b},1)`);
        grad.addColorStop(1,   `rgba(${Math.max(0,r-40)},${Math.max(0,g-40)},${Math.max(0,b-40)},1)`);
        ctx.fillStyle = grad;
        ctx.fillRect(x0, y0, plateW, plateH);

        // shimmer line
        const shimX = x0 + ((time * 0.03) % plateW);
        ctx.fillStyle = `rgba(255,255,255,0.4)`;
        ctx.fillRect(shimX, y0, 4, plateH);
        ctx.restore();
    },

    // ── ก้อนโลหะ ─────────────────────────────────────────────
    _drawMetalChunks(ctx, rgb, amt, width, baseY, shimmer, time) {
        const [r, g, b] = rgb;
        const numChunks = Math.min(8, Math.ceil(amt / 2) + 2);
        // seed random per chem to keep chunks stable
        const seed = r * 31 + g * 17 + b;
        const rng  = (i) => { const x = Math.sin(seed + i * 9301) * 43758.5453; return x - Math.floor(x); };

        for (let i = 0; i < numChunks; i++) {
            const cx   = 20 + rng(i * 3)     * (width - 40);
            const cy   = baseY - 4 - rng(i * 3 + 1) * 10;
            const size = 4 + rng(i * 3 + 2)  * 6;
            const angle = rng(i) * Math.PI * 2;

            ctx.save();
            ctx.translate(cx, cy);
            ctx.rotate(angle);
            const grad = ctx.createRadialGradient(-size * 0.3, -size * 0.3, 0, 0, 0, size);
            grad.addColorStop(0, `rgba(${Math.min(255,r+80)},${Math.min(255,g+80)},${Math.min(255,b+80)},1)`);
            grad.addColorStop(1, `rgba(${Math.max(0,r-30)},${Math.max(0,g-30)},${Math.max(0,b-30)},1)`);
            ctx.fillStyle = grad;
            ctx.beginPath();
            // วาด hexagon-ish shape
            for (let v = 0; v < 6; v++) {
                const va = (v / 6) * Math.PI * 2 + angle * 0.5;
                const vr = size * (0.8 + rng(i * 10 + v) * 0.4);
                v === 0 ? ctx.moveTo(Math.cos(va) * vr, Math.sin(va) * vr)
                        : ctx.lineTo(Math.cos(va) * vr, Math.sin(va) * vr);
            }
            ctx.closePath();
            ctx.fill();
            if (shimmer) {
                ctx.fillStyle = `rgba(255,255,255,0.3)`;
                ctx.beginPath();
                ctx.arc(-size * 0.2, -size * 0.3, size * 0.25, 0, Math.PI * 2);
                ctx.fill();
            }
            ctx.restore();
        }
    },

    // ── ผงละเอียด (catalyst, MnO2) ───────────────────────────
    _drawFinePowder(ctx, rgb, amt, width, baseY) {
        const [r, g, b] = rgb;
        const pileH = Math.min(12, amt * 1.2);
        const pileW = Math.min(width - 20, 30 + amt * 4);
        const x0    = (width - pileW) / 2;
        // วาด pile ด้วย gradient
        const grad = ctx.createRadialGradient(width/2, baseY, 0, width/2, baseY, pileW/2);
        grad.addColorStop(0,   `rgba(${r},${g},${b},0.95)`);
        grad.addColorStop(0.7, `rgba(${r},${g},${b},0.7)`);
        grad.addColorStop(1,   `rgba(${r},${g},${b},0)`);
        ctx.save();
        ctx.fillStyle = grad;
        ctx.beginPath();
        ctx.ellipse(width/2, baseY - pileH/2, pileW/2, pileH/2, 0, 0, Math.PI * 2);
        ctx.fill();
        // โรยจุดเล็กๆ ด้านบน
        for (let i = 0; i < 30; i++) {
            const px = x0 + Math.random() * pileW;
            const py = baseY - pileH - Math.random() * 3;
            ctx.fillStyle = `rgba(${r},${g},${b},${0.5 + Math.random() * 0.5})`;
            ctx.beginPath();
            ctx.arc(px, py, 0.8, 0, Math.PI * 2);
            ctx.fill();
        }
        ctx.restore();
    },

    // ── ผลึก (เกลือ NaCl, CuSO4 สีฟ้า) ──────────────────────
    _drawCrystals(ctx, rgb, amt, width, baseY, size, shimmer) {
        const [r, g, b] = rgb;
        const numCryst  = Math.min(60, Math.ceil(amt * 8) + 5);
        const pileW     = Math.min(width - 10, 20 + amt * 8);
        const pileH     = Math.min(20, amt * 1.5);
        const x0        = (width - pileW) / 2;

        ctx.save();
        // ระบายพื้นหลัง pile ก่อน
        const grad = ctx.createLinearGradient(x0, baseY - pileH, x0, baseY);
        grad.addColorStop(0, `rgba(${r},${g},${b},0.3)`);
        grad.addColorStop(1, `rgba(${r},${g},${b},0.6)`);
        ctx.fillStyle = grad;
        ctx.beginPath();
        ctx.ellipse(width/2, baseY - pileH/2, pileW/2, pileH/2 + 2, 0, 0, Math.PI * 2);
        ctx.fill();

        // วาดผลึกแต่ละก้อน
        const seed = r * 7 + g * 13 + b * 3;
        const rng  = (i) => { const x = Math.sin(seed + i * 7919) * 43758.5; return x - Math.floor(x); };

        for (let i = 0; i < numCryst; i++) {
            const px  = x0 + 4 + rng(i * 4)     * (pileW - 8);
            const py  = baseY - 2 - rng(i * 4+1) * pileH;
            const cs  = size * (0.5 + rng(i * 4+2) * 0.8);
            const rot = rng(i * 4+3) * Math.PI * 0.5; // หมุนแค่ 90° เพื่อให้ดูเหมือนผลึก

            ctx.save();
            ctx.translate(px, py);
            ctx.rotate(rot);
            ctx.fillStyle = `rgba(${r},${g},${b},${0.7 + rng(i)*0.3})`;
            ctx.fillRect(-cs/2, -cs/2, cs, cs);

            if (shimmer) {
                ctx.fillStyle = `rgba(255,255,255,0.5)`;
                ctx.fillRect(-cs/2, -cs/2, cs*0.35, cs*0.35);
            }
            ctx.restore();
        }
        ctx.restore();
    },

    // ── ผลึกหยาบ (น้ำตาลทราย) ────────────────────────────────
    _drawCoarseCrystals(ctx, rgb, amt, width, baseY, size) {
        const [r, g, b] = rgb;
        const numCryst  = Math.min(30, Math.ceil(amt * 4) + 3);
        const pileW     = Math.min(width - 10, 25 + amt * 6);
        const pileH     = Math.min(18, amt * 1.2);
        const x0        = (width - pileW) / 2;
        const seed      = r + g * 17 + b * 31;
        const rng       = (i) => { const x = Math.sin(seed + i * 6271) * 43758.5; return x - Math.floor(x); };

        ctx.save();
        for (let i = 0; i < numCryst; i++) {
            const px  = x0 + 4 + rng(i * 3)     * (pileW - 8);
            const py  = baseY - 3 - rng(i * 3+1) * pileH;
            const cs  = size * (0.7 + rng(i * 3+2));  // ขนาดใหญ่กว่า crystal
            const rot = rng(i) * Math.PI;

            ctx.save();
            ctx.translate(px, py);
            ctx.rotate(rot);
            // วาดเป็น rounded rect
            const half = cs / 2;
            ctx.fillStyle = `rgba(${r},${g},${b},${0.75 + rng(i)*0.25})`;
            ctx.beginPath();
            ctx.roundRect(-half, -half * 0.6, cs, cs * 0.7, cs * 0.15);
            ctx.fill();
            ctx.restore();
        }
        ctx.restore();
    },

    // ── ผง (default solid) ───────────────────────────────────
    _drawPowder(ctx, rgb, amt, width, baseY, pSize) {
        const [r, g, b] = rgb;
        const pileH = Math.min(20, amt * 1.5);
        const pileW = Math.min(width - 10, 25 + amt * 5);
        const x0    = (width - pileW) / 2;

        ctx.save();
        // gradient pile shape
        const grad = ctx.createRadialGradient(width/2, baseY, 1, width/2, baseY - pileH, pileW/2);
        grad.addColorStop(0,   `rgba(${r},${g},${b},0.9)`);
        grad.addColorStop(0.6, `rgba(${r},${g},${b},0.6)`);
        grad.addColorStop(1,   `rgba(${r},${g},${b},0)`);
        ctx.fillStyle = grad;
        ctx.beginPath();
        ctx.ellipse(width/2, baseY - pileH/3, pileW/2, pileH/1.5, 0, 0, Math.PI * 2);
        ctx.fill();

        // จุดเล็กๆ โรยบนกอง
        const numDots = Math.min(50, Math.ceil(amt * 6) + 10);
        const seed = r + g + b;
        for (let i = 0; i < numDots; i++) {
            const s = Math.sin(seed + i * 9973) * 43758.5;
            const rx = s - Math.floor(s);
            const s2 = Math.sin(seed + i * 6271 + 1) * 43758.5;
            const ry = s2 - Math.floor(s2);
            const px = x0 + rx * pileW;
            const py = baseY - ry * pileH * 0.8;
            const ps = (pSize || 2) * (0.4 + rx * 0.6);
            ctx.fillStyle = `rgba(${r},${g},${b},${0.6 + ry * 0.4})`;
            ctx.beginPath();
            ctx.arc(px, py, ps, 0, Math.PI * 2);
            ctx.fill();
        }
        ctx.restore();
    },

    // ── Dry Ice ──────────────────────────────────────────────
    _drawDryIce(ctx, rgb, amt, width, baseY, time) {
        const [r, g, b] = rgb;
        const blockW = Math.min(80, 30 + amt * 5);
        const blockH = Math.min(20, 8 + amt * 0.5);
        const x0     = (width - blockW) / 2;
        const y0     = baseY - blockH;

        ctx.save();
        // ก้อน Dry Ice
        ctx.fillStyle = `rgba(${r},${g},${b},0.85)`;
        ctx.beginPath();
        ctx.roundRect(x0, y0, blockW, blockH, 4);
        ctx.fill();

        // glint
        ctx.fillStyle = 'rgba(255,255,255,0.4)';
        ctx.beginPath();
        ctx.roundRect(x0 + 4, y0 + 2, blockW * 0.3, 3, 2);
        ctx.fill();

        // ไอ (CO₂ sublimation) — วาดเป็นวงกลมโปร่งแสง
        const numFog = 5;
        for (let i = 0; i < numFog; i++) {
            const phase = (time * 0.001 + i * 0.4) % 1;
            const fx = x0 + (i / numFog) * blockW + 8;
            const fy = y0 - phase * 30;
            const fs = 8 + phase * 15;
            ctx.fillStyle = `rgba(200,220,255,${0.25 * (1 - phase)})`;
            ctx.beginPath();
            ctx.arc(fx, fy, fs, 0, Math.PI * 2);
            ctx.fill();
        }
        ctx.restore();
    },

    // ── Paste/Toothpaste ─────────────────────────────────────
    _drawPaste(ctx, rgb, amt, width, baseY) {
        const [r, g, b] = rgb;
        const blobW = Math.min(width - 20, 20 + amt * 6);
        const blobH = Math.min(12, 4 + amt * 0.4);
        const x0    = (width - blobW) / 2;

        ctx.save();
        const grad = ctx.createLinearGradient(x0, baseY - blobH, x0, baseY);
        grad.addColorStop(0, `rgba(${Math.min(255,r+30)},${Math.min(255,g+30)},${Math.min(255,b+30)},0.9)`);
        grad.addColorStop(1, `rgba(${r},${g},${b},0.7)`);
        ctx.fillStyle = grad;
        ctx.beginPath();
        ctx.roundRect(x0, baseY - blobH, blobW, blobH, blobH / 2);
        ctx.fill();
        ctx.restore();
    },

    // ── helper: parse color string → [r,g,b] ─────────────────
    _parseColor(ctx, colorStr) {
        if (!colorStr || colorStr === 'ใส' || colorStr === 'transparent') return [200, 220, 240];
        ctx.fillStyle = '#ffffff';
        ctx.fillStyle = colorStr;
        const hex = ctx.fillStyle;
        if (hex && hex.startsWith('#') && hex.length === 7) {
            return [
                parseInt(hex.slice(1,3), 16),
                parseInt(hex.slice(3,5), 16),
                parseInt(hex.slice(5,7), 16),
            ];
        }
        return [200, 220, 240];
    },
};


// ─────────────────────────────────────────────────────────────
// 4. STEAM / GAS PARTICLE SYSTEM
// ─────────────────────────────────────────────────────────────
const SteamSystem = {
    particles: [],
    maxParticles: 30,

    /**
     * เรียกใน updatePhysics เมื่ออุณหภูมิสูง
     * @param {number} temp    - อุณหภูมิปัจจุบัน
     * @param {number} topY    - ระดับผิวของเหลว
     * @param {number} canvasW - ความกว้าง canvas
     */
    update(temp, topY, canvasW) {
        // spawn steam เมื่ออุณหภูมิ > 60°C
        if (temp > 60 && this.particles.length < this.maxParticles) {
            const spawnRate = Math.min(3, Math.floor((temp - 60) / 20));
            for (let i = 0; i < spawnRate; i++) {
                this.particles.push({
                    x:    20 + Math.random() * (canvasW - 40),
                    y:    topY,
                    vx:   (Math.random() - 0.5) * 0.8,
                    vy:   -(0.5 + Math.random() * 1.5),
                    size: 3 + Math.random() * 5,
                    life: 1.0,
                    maxLife: 0.8 + Math.random() * 0.5,
                });
            }
        }

        this.particles.forEach(p => {
            p.x    += p.vx;
            p.y    += p.vy;
            p.vx   += (Math.random() - 0.5) * 0.1;
            p.size += 0.3;
            p.life -= 0.02;
        });
        this.particles = this.particles.filter(p => p.life > 0 && p.y > -20);
    },

    draw(ctx) {
        this.particles.forEach(p => {
            ctx.save();
            ctx.globalAlpha = p.life * 0.35;
            ctx.fillStyle   = 'rgba(200, 220, 255, 1)';
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
            ctx.fill();
            ctx.restore();
        });
    },

    reset() { this.particles = []; },
};


// ─────────────────────────────────────────────────────────────
// 5. PATCH VirtualLabEngine — แทนที่ method ที่เกี่ยวข้อง
//    (รัน หลังจาก class ถูก define แล้ว)
// ─────────────────────────────────────────────────────────────

// รอให้ DOMContentLoaded แล้วค่อย patch
document.addEventListener('DOMContentLoaded', () => {
    // ให้ patch ทำงานหลัง LabApp ถูก new แล้ว (boot sequence อยู่ใน DOMContentLoaded เหมือนกัน)
    // ใช้ setTimeout(0) เพื่อรันหลัง boot
    setTimeout(() => {
        if (!window.LabApp) { console.error('[ChemVisual] LabApp not found'); return; }
        _patchLabApp(window.LabApp);
    }, 0);
});

function _patchLabApp(app) {

    // ── 5a. แทนที่ renderCanvas() ─────────────────────────────
    app.renderCanvas = function() {
        if (!this.ctx || !this.ui.liquidCanvas || this.state.isExploded) return;
        const ctx    = this.ctx;
        const width  = this.ui.liquidCanvas.width;   // 192
        const height = this.ui.liquidCanvas.height;  // 236
        const now    = performance.now();

        ctx.clearRect(0, 0, width, height);

        // ── วาด solid ที่ยังไม่ละลาย ──────────────────────────
        SolidParticleSystem.render(ctx, this.state.contents, width, height, now);

        if (this.state.volume <= 0) {
            // ไม่มีของเหลว — วาดแค่ตะกอนที่เหลือ
            this._drawPrecipitates(ctx);
            PourAnimator.draw(ctx, height - 5);
            return;
        }

        // ── คำนวณ viscosity รวม ───────────────────────────────
        const blendedViscosity = ChemVisualProfile.getBlendedViscosity(this.state.contents);
        const fillHeight = Math.min(height, (this.state.volume / this.state.maxVolume) * height);
        const topY       = height - fillHeight;

        // ── วาด liquid layer ──────────────────────────────────
        this._drawLiquidLayer(ctx, width, height, topY, fillHeight, blendedViscosity, now);

        // ── steam particles ────────────────────────────────────
        SteamSystem.update(this.state.temperature, topY, width);
        SteamSystem.draw(ctx);

        // ── precipitates ──────────────────────────────────────
        this._drawPrecipitates(ctx);

        // ── bubbles ───────────────────────────────────────────
        this._drawBubbles(ctx, topY);

        // ── pour animation ────────────────────────────────────
        PourAnimator.draw(ctx, topY);
    };

    // ── drawLiquidLayer (helper ใหม่) ─────────────────────────
    app._drawLiquidLayer = function(ctx, width, height, topY, fillHeight, viscosity, now) {
        const [r, g, b, a] = this.state.currentColor;
        const cr = Math.round(r || 56), cg = Math.round(g || 189), cb = Math.round(b || 248);

        // opacity ขึ้นกับ profile ของสารหลัก
        let dominantProfile = null;
        let maxAmt = 0;
        this.state.contents.forEach(c => {
            if (c.state !== 'solid' && c.amount > maxAmt) {
                maxAmt = c.amount;
                dominantProfile = ChemVisualProfile.get(c.type, c.name, c.state);
            }
        });
        const opacityBase = dominantProfile ? dominantProfile.opacityBase : (a || 0.4);
        const isShimmer   = dominantProfile ? dominantProfile.shimmer : false;

        // ── คลื่นผิวน้ำ (viscosity สูง = คลื่นช้าและต่ำ) ─────
        const waveFreq = 0.05 - viscosity * 0.035;   // viscous = low freq
        const waveAmp  = this.state.waveAmplitude * (1 - viscosity * 0.8);

        // body gradient (ดูมีความลึก)
        const bodyGrad = ctx.createLinearGradient(0, topY, 0, height);
        bodyGrad.addColorStop(0,   `rgba(${cr},${cg},${cb},${opacityBase * 0.7})`);
        bodyGrad.addColorStop(0.5, `rgba(${cr},${cg},${cb},${opacityBase})`);
        bodyGrad.addColorStop(1,   `rgba(${Math.max(0,cr-20)},${Math.max(0,cg-20)},${Math.max(0,cb-20)},${opacityBase})`);

        ctx.save();
        ctx.fillStyle = bodyGrad;
        ctx.beginPath();
        ctx.moveTo(0, height);
        for (let x = 0; x <= width; x += 4) {
            const y = topY + Math.sin(x * waveFreq + this.state.wavePhase) * waveAmp;
            ctx.lineTo(x, y);
        }
        ctx.lineTo(width, height);
        ctx.closePath();
        ctx.fill();

        // ── meniscus (ขอบโค้งของเหลวชิดผนังแก้ว) ────────────
        ctx.strokeStyle = `rgba(${Math.min(255,cr+40)},${Math.min(255,cg+40)},${Math.min(255,cb+40)},0.5)`;
        ctx.lineWidth   = 2;
        ctx.beginPath();
        ctx.moveTo(0, height);
        for (let x = 0; x <= width; x += 4) {
            const y = topY + Math.sin(x * waveFreq + this.state.wavePhase) * waveAmp;
            ctx.lineTo(x, y);
        }
        ctx.stroke();

        // ── highlight แถบขาว (reflection) ───────────────────
        ctx.fillStyle = 'rgba(255,255,255,0.12)';
        ctx.fillRect(width - 28, topY + 10, 8, Math.max(0, fillHeight - 20));

        // ── shimmer (สำหรับ oil/organic) ─────────────────────
        if (isShimmer) {
            const shimGrad = ctx.createLinearGradient(0, topY, width, topY + 10);
            shimGrad.addColorStop(0,   'rgba(255,255,255,0)');
            shimGrad.addColorStop(0.4, `rgba(255,255,200,0.15)`);
            shimGrad.addColorStop(1,   'rgba(255,255,255,0)');
            ctx.fillStyle = shimGrad;
            ctx.fillRect(0, topY, width, 10);
        }

        // ── ความขุ่น (opaque liquid เช่น นม, เลือด) ──────────
        if (dominantProfile && dominantProfile.texture === 'opaque_liquid') {
            ctx.fillStyle = `rgba(${cr},${cg},${cb},0.2)`;
            ctx.fillRect(0, topY, width, fillHeight);
        }

        ctx.restore();
    };

    // ── drawPrecipitates (helper ใหม่) ────────────────────────
    app._drawPrecipitates = function(ctx) {
        this.state.precipitates.forEach(p => {
            ctx.save();
            ctx.fillStyle = p.color || '#cbd5e1';
            // ใช้สีจาก reaction result_color ถ้ามี
            ctx.beginPath();
            if (p.shape === 'square') {
                ctx.fillRect(p.x - p.size, p.y - p.size, p.size * 2, p.size * 2);
            } else {
                ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
                ctx.fill();
            }
            ctx.restore();
        });
    };

    // ── drawBubbles (helper ใหม่) ─────────────────────────────
    app._drawBubbles = function(ctx, topY) {
        for (let i = this.state.bubbles.length - 1; i >= 0; i--) {
            const bub = this.state.bubbles[i];
            ctx.save();
            // วาดฟองพร้อม outline
            ctx.strokeStyle = bub.color || 'rgba(255,255,255,0.8)';
            ctx.fillStyle   = 'rgba(255,255,255,0.08)';
            ctx.lineWidth   = 1;
            ctx.beginPath();
            ctx.arc(bub.x, bub.y, bub.radius, 0, Math.PI * 2);
            ctx.fill();
            ctx.stroke();
            // highlight เล็กๆ ในฟอง
            ctx.fillStyle = 'rgba(255,255,255,0.5)';
            ctx.beginPath();
            ctx.arc(bub.x - bub.radius * 0.3, bub.y - bub.radius * 0.3, bub.radius * 0.25, 0, Math.PI * 2);
            ctx.fill();
            ctx.restore();

            bub.y  -= bub.speed;
            bub.x  += Math.sin(bub.y * 0.08) * 0.8;
            if (bub.y < topY) this.state.bubbles.splice(i, 1);
        }
    };

    // ── 5b. แทนที่ executePour() — เพิ่ม PourAnimator ─────────
    const _origExecutePour = app.executePour.bind(app);
    app.executePour = function() {
        const tool = this.state.activeTool;
        if (!tool || !tool.id) { _origExecutePour(); return; }

        // trigger animation ก่อน (ใช้ colorParserCtx ที่มีอยู่แล้ว)
        const profile = ChemVisualProfile.get(tool.type, tool.name, tool.state);
        let rgb;
        try {
            this.colorParserCtx.fillStyle = '#ffffff';
            this.colorParserCtx.fillStyle = tool.color || '#ffffff';
            const hex = this.colorParserCtx.fillStyle;
            rgb = hex.startsWith('#') && hex.length === 7
                ? [parseInt(hex.slice(1,3),16), parseInt(hex.slice(3,5),16), parseInt(hex.slice(5,7),16)]
                : [200, 220, 240];
        } catch(e) { rgb = [200, 220, 240]; }

        // ของแข็ง = particle system จัดการเอง, ของเหลว = PourAnimator
        if (tool.state === 'liquid') PourAnimator.trigger(rgb, profile.viscosity, tool.state);
        _origExecutePour();
    };

    // ── 5c. patch dissolve rate ใน updatePhysics ─────────────
    //    แทนที่อัตราการละลายของ solid ให้ใช้ dissolveRate จาก profile
    const _origUpdatePhysics = app.updatePhysics.bind(app);
    app.updatePhysics = function(dt) {
        // ปรับ dissolveRate ของ solid ใน contents ตาม profile
        // ก่อน call original
        if (this.state.isStirring) {
            this.state.contents.forEach(c => {
                if (c.state === 'solid' && c.dissolvedAmt < c.amount && this.state.volume > 0) {
                    const profile = ChemVisualProfile.get(c.type, c.name, 'solid');
                    const rate    = profile.dissolveRate;

                    if (rate <= 0) return;  // โลหะ/สารที่ไม่ละลาย — ข้ามไป

                    // dissolveRate คูณ stirring + temp bonus
                    const tempBonus = 1 + Math.max(0, (this.state.temperature - 25) / 75);
                    c._customDissolved = true;
                    c.dissolvedAmt += (c.amount * rate + 0.05) * tempBonus;
                    if (c.dissolvedAmt > c.amount) c.dissolvedAmt = c.amount;
                }
            });
        } else if (!this.state.isStirring) {
            // ไม่กวน: สารบางชนิดละลายช้าเองจากอุณหภูมิ
            this.state.contents.forEach(c => {
                if (c.state === 'solid' && c.dissolvedAmt < c.amount && this.state.volume > 0) {
                    const profile = ChemVisualProfile.get(c.type, c.name, 'solid');
                    if (profile.dissolveRate > 0) {
                        const tempBonus = 1 + Math.max(0, (this.state.temperature - 25) / 100);
                        c.dissolvedAmt += (c.amount * profile.dissolveRate * 0.15) * tempBonus;
                        if (c.dissolvedAmt > c.amount) c.dissolvedAmt = c.amount;
                    }
                }
            });
        }

        // call original (จัดการ heating, boiling, stirring animation ฯลฯ)
        // แต่ต้อง skip dissolve loop เดิมที่ใช้ค่าคงที่ 0.05
        // วิธี: override ชั่วคราว ให้ isStirring = false ก่อน call แล้วค่อย restore
        const wasStirring = this.state.isStirring;
        this.state.isStirring = false;   // pause stirring ชั่วคราวเพื่อไม่ให้ original loop dissolve ซ้ำ
        _origUpdatePhysics(dt);
        this.state.isStirring = wasStirring;
    };

    // ── 5d. reset SteamSystem เมื่อล้างบีกเกอร์ ─────────────
    const _origBtnWash = app.ui.btnWash;
    if (_origBtnWash) {
        _origBtnWash.addEventListener('click', () => {
            SteamSystem.reset();
        });
    }

    console.log('[ChemVisual] Patch applied ✅  — SolidRenderer + PourAnimator + SteamSystem + Viscosity');
}

// ═══════════════════════════════════════════════════════════════════
// LAB UPGRADE PATCH v4.0 — Room Temp, Timer, PPE Visual, Reaction Cards
// ═══════════════════════════════════════════════════════════════════

(function() {
'use strict';

// ── รอ app init ──
const waitApp = (cb) => {
    // รอ window.LabApp (engine ตัวจริง) — ไม่ใช่ window.app (deadlock!)
    if (window.LabApp) { window.app = window.LabApp; cb(window.LabApp); return; }
    let _tries = 0;
    const t = setInterval(() => {
        _tries++;
        if (window.LabApp) {
            clearInterval(t);
            window.app = window.LabApp;
            /* ready */
            cb(window.LabApp);
        } else if (_tries > 50) {
            clearInterval(t);
            console.error('[waitApp] ❌ window.LabApp ไม่ถูกสร้างภายใน 10 วินาที — ตรวจ error ด้านบน');
        }
    }, 200);
};

// ══════════════════════════════════════════
// 1. REACTION KNOWLEDGE BASE (อ้างอิงตาม IUPAC/NIST)
// ══════════════════════════════════════════
const REACTION_KNOWLEDGE = {
    // reaction_type → ข้อมูลอธิบาย
    'neutralization': {
        principle: 'ปฏิกิริยาสะเทิน (Neutralization): กรดให้โปรตอน H⁺ รวมกับด่างที่ให้ OH⁻ ได้น้ำและเกลือ',
        mechanism: 'H⁺ + OH⁻ → H₂O (ΔH = -55.8 kJ/mol)',
        source: 'IUPAC Green Book 2007, §2.13',
        color_change: 'ถ้าใช้ indicator: ฟีนอล์ฟทาลีนจะจางจากชมพูเป็นไม่มีสี ที่จุดสมมูล',
        safety: 'กรดและด่างแก่กัดกร่อน ต้องสวม PPE ครบ',
    },
    'precipitation': {
        principle: 'ปฏิกิริยาตกตะกอน (Precipitation): ไอออนในสารละลายรวมตัวเกิดสารที่ไม่ละลายน้ำ (Ksp < Q)',
        mechanism: 'ผลคูณของความเข้มข้น Q > Ksp → ตะกอนเกิดขึ้น',
        source: 'Atkins Physical Chemistry 11th Ed., §6C',
        color_change: 'สีของตะกอนขึ้นกับสารประกอบ: BaSO₄=ขาว, PbI₂=เหลือง, Fe(OH)₃=น้ำตาลแดง',
        safety: 'บางตะกอนมีพิษสะสม เช่น Pb²⁺ — ล้างมือทุกครั้ง',
    },
    'redox': {
        principle: 'ปฏิกิริยารีดอกซ์ (Redox): การถ่ายโอนอิเล็กตรอนระหว่างสาร ตัวรีดิวซ์ให้ e⁻ ตัวออกซิไดซ์รับ e⁻',
        mechanism: 'ตาม Activity Series: โลหะที่แรงกว่าจะแทนที่โลหะที่อ่อนกว่าออกจากสารละลาย',
        source: 'Electrochemical Series, Haynes CRC Handbook 97th Ed.',
        color_change: 'สีของสารละลาย/โลหะเปลี่ยนตาม oxidation state',
        safety: 'บางปฏิกิริยารีดอกซ์รุนแรงมาก เช่น Na+H₂O ระเบิดได้',
    },
    'acid_metal': {
        principle: 'ปฏิกิริยาโลหะกับกรด (Metal-Acid): โลหะที่อยู่เหนือ H ใน activity series แทนที่ H⁺ ได้ H₂↑',
        mechanism: 'M + nH⁺ → Mⁿ⁺ + (n/2)H₂↑ (เฉพาะโลหะที่ E°(M²⁺/M) < 0)',
        source: 'IUPAC Nomenclature of Inorganic Chemistry 2005',
        color_change: 'ฟองก๊าซ H₂ ไม่มีสี ลอยขึ้น สารละลายอาจเปลี่ยนสีตาม metal ion',
        safety: 'H₂ ติดไฟได้! ห้ามมีเปลวไฟใกล้ — HF/H₂SO₄ ข้นอันตรายมาก',
    },
    'metal_water': {
        principle: 'ปฏิกิริยาโลหะอัลคาลิกับน้ำ: โลหะ Group 1 ทำปฏิกิริยารุนแรงกับน้ำ คายความร้อน + ก๊าซ H₂',
        mechanism: '2M + 2H₂O → 2MOH + H₂↑ ความรุนแรง Li < Na < K < Rb < Cs',
        source: 'Greenwood & Earnshaw "Chemistry of the Elements" 2nd Ed.',
        color_change: 'เปลวไฟ H₂ สีเหลือง (Na), สีม่วง (K) — ควันสีขาวจาก MOH',
        safety: 'อันตรายสูงมาก! ระเบิดได้! ต้องทำในตู้ดูด ห้ามสัมผัส',
    },
    'decomposition': {
        principle: 'ปฏิกิริยาการสลายตัว (Decomposition): สารประกอบหนึ่งแตกตัวเป็นสารสองชนิดขึ้นไป ส่วนใหญ่ต้องการพลังงาน',
        mechanism: 'AB → A + B อาจเกิดจากความร้อน แสง หรือตัวเร่งปฏิกิริยา',
        source: 'Chang "Chemistry" 12th Ed., Ch. 4',
        color_change: 'ขึ้นกับผลิตภัณฑ์ เช่น 2H₂O₂ → 2H₂O + O₂↑ (ฟองใส)',
        safety: 'H₂O₂ เข้มข้นกัดผิวหนัง O₂ สนับสนุนการเผาไหม้',
    },
    'acid_carbonate': {
        principle: 'กรดกับคาร์บอเนต: H⁺ ทำให้ CO₃²⁻ สลายตัวปล่อย CO₂ ก๊าซ เกิดฟองทันที',
        mechanism: '2H⁺ + CO₃²⁻ → H₂O + CO₂↑ หรือ H⁺ + HCO₃⁻ → H₂O + CO₂↑',
        source: 'Silberberg "Chemistry: Molecular Nature" 8th Ed.',
        color_change: 'ฟองก๊าซ CO₂ ไม่มีสี ไม่มีกลิ่น ถ้าผ่านน้ำปูนใสจะขุ่น',
        safety: 'CO₂ ระดับสูงทำให้หายใจลำบาก ระบายอากาศดี',
    },
    'complex_formation': {
        principle: 'การเกิดสารประกอบเชิงซ้อน (Complex Formation): ไอออนโลหะรับ ligand ที่มีคู่อิเล็กตรอนโดดเดี่ยว',
        mechanism: 'M^n+ + L → [ML]^n+ ตาม Crystal Field Theory สีเกิดจาก d-d transition',
        source: 'Housecroft "Inorganic Chemistry" 5th Ed., §20',
        color_change: 'สีสดใสมาก: [Fe(SCN)]²⁺ แดงเข้ม, [Cu(NH₃)₄]²⁺ น้ำเงินเข้ม',
        safety: 'โลหะทรานสิชันบางชนิดมีพิษสะสม',
    },
    'combustion': {
        principle: 'การเผาไหม้ (Combustion): การออกซิเดชันอย่างรวดเร็วด้วย O₂ คายความร้อนและแสง',
        mechanism: 'Fuel + O₂ → CO₂ + H₂O + Energy (ΔH < 0)',
        source: 'NIST Chemistry WebBook, Standard Enthalpies of Combustion',
        color_change: 'Mg ขาวจ้ามาก (~3100K) Na เหลือง K ม่วง Li แดง',
        safety: 'ไม่ดับไฟ Mg ด้วยน้ำ! ใช้ทราย Mg เผาไหม้ได้แม้ไม่มี O₂',
    },
};

// ── ปฏิกิริยาในชีวิตจริง 20 อย่างจากตารางที่ให้มา ──
const REAL_REACTIONS = [
    { id:'vinegar_soda', c1:'น้ำส้มสายชู', c2:'เบกกิ้งโซดา', gas:'CO₂', obs:'เกิดฟองก๊าซ CO₂ อย่างรวดเร็ว ฟองขาว', eq:'CH₃COOH + NaHCO₃ → CH₃COONa + H₂O + CO₂↑', type:'acid_carbonate', dT:5, hazard_eye:false },
    { id:'iron_rust', c1:'เหล็ก', c2:'ออกซิเจน+น้ำ', gas:'', obs:'เกิดสนิมเหล็กสีน้ำตาลแดงบนผิวโลหะ ช้ามาก', eq:'4Fe + 3O₂ + 6H₂O → 4Fe(OH)₃', type:'redox', dT:2, hazard_eye:false },
    { id:'mg_flame', c1:'แมกนีเซียม', c2:'O₂ (ตะเกียง)', gas:'', obs:'แสงขาวจ้ามาก (~3100°C) เกิดผงขาว MgO', eq:'2Mg + O₂ → 2MgO', type:'combustion', dT:120, hazard_eye:true, eye_warn:'แสงจาก Mg เผาไหม้รุนแรงมากจนทำให้ตาบอดชั่วคราวได้! ต้องสวมแว่นดูแสงจ้า' },
    { id:'h2_o2', c1:'ไฮโดรเจน', c2:'ออกซิเจน', gas:'', obs:'เกิดน้ำ คายพลังงานมหาศาล เสียงดังมาก', eq:'2H₂ + O₂ → 2H₂O', type:'combustion', dT:150, hazard_eye:true, eye_warn:'การระเบิด H₂+O₂ สร้างแสงจ้า สวมแว่นกันก่อน' },
    { id:'agno3_nacl', c1:'เงินไนเตรต', c2:'โซเดียมคลอไรด์', gas:'', obs:'ตะกอนสีขาวขุ่นของ AgCl ทันที', eq:'AgNO₃ + NaCl → AgCl↓ + NaNO₃', type:'precipitation', dT:0, hazard_eye:true, eye_warn:'AgNO₃ ทำให้ผิวหนัง/ตาดำถาวร สวมแว่นก่อนใช้' },
    { id:'hcl_naoh', c1:'กรดไฮโดรคลอริก', c2:'โซเดียมไฮดรอกไซด์', gas:'', obs:'ไม่เห็นสีเปลี่ยน ความร้อนเพิ่มขึ้น 55.8°C ต่อโมล', eq:'HCl + NaOH → NaCl + H₂O', type:'neutralization', dT:55.8, hazard_eye:true, eye_warn:'NaOH กัดกร่อนตาร้ายแรงมาก ต้องสวมแว่นทุกครั้ง!' },
    { id:'zn_hcl', c1:'สังกะสี', c2:'กรดไฮโดรคลอริก', gas:'H₂', obs:'ฟองก๊าซ H₂ ไม่มีสีพุ่งขึ้น สังกะสีค่อยๆ ละลาย', eq:'Zn + 2HCl → ZnCl₂ + H₂↑', type:'acid_metal', dT:15, hazard_eye:false },
    { id:'na_water', c1:'โซเดียม', c2:'น้ำ', gas:'H₂', obs:'โซเดียมวิ่งบนผิวน้ำ เปลวไฟเหลือง เกิดฟอง H₂', eq:'2Na + 2H₂O → 2NaOH + H₂↑', type:'metal_water', dT:150, hazard_eye:true, eye_warn:'อันตราย! ระเบิดได้ ควัน NaOH กัดตา สวมแว่นด้วย!' },
    { id:'h2o2_mno2', c1:'ไฮโดรเจนเปอร์ออกไซด์', c2:'แมงกานีสไดออกไซด์', gas:'O₂', obs:'ฟอง O₂ พุ่งรุนแรงมาก ของเหลวร้อนขึ้น MnO₂ ไม่หมด', eq:'2H₂O₂ →(MnO₂) 2H₂O + O₂↑', type:'decomposition', dT:45, hazard_eye:true, eye_warn:'H₂O₂ เข้มข้นกระเด็นเข้าตาอันตราย สวมแว่นก่อนเพิ่ม MnO₂' },
    { id:'iodine_starch', c1:'ไอโอดีน', c2:'แป้ง', gas:'', obs:'สีน้ำเงินเข้ม/ม่วงดำทันที เกิดจาก I₅⁻ ใน helix ของ amylose', eq:'I₂ + Starch → Blue Complex', type:'complex_formation', dT:0, hazard_eye:false },
    { id:'cu_hno3', c1:'ทองแดง', c2:'กรดไนตริก', gas:'NO₂', obs:'ก๊าซน้ำตาลแดง NO₂ เกิดทองแดงละลาย สารละลายสีน้ำเงิน', eq:'Cu + 4HNO₃ → Cu(NO₃)₂ + 2NO₂↑ + 2H₂O', type:'redox', dT:10, hazard_eye:true, eye_warn:'NO₂ พิษต่อดวงตาและปอด! สวมแว่นและทำในตู้ดูดควัน' },
    { id:'sugar_heat', c1:'น้ำตาล', c2:'ความร้อน', gas:'CO₂', obs:'น้ำตาลเปลี่ยนเป็นสีน้ำตาล (คาราเมล) ที่ ~160°C', eq:'C₁₂H₂₂O₁₁ → Caramel + H₂O + CO₂', type:'decomposition', dT:40, hazard_eye:false },
    { id:'nh3_hcl', c1:'แอมโมเนีย', c2:'กรดไฮโดรคลอริก', gas:'', obs:'ควันขาวของ NH₄Cl เกิดทันทีเมื่อก๊าซพบกัน', eq:'NH₃ + HCl → NH₄Cl (ควันขาว)', type:'neutralization', dT:7, hazard_eye:true, eye_warn:'แอมโมเนียระคายเคืองดวงตารุนแรง ใส่แว่นและระบายอากาศ' },
    { id:'cuso4_fe', c1:'คอปเปอร์ซัลเฟต', c2:'เหล็ก', gas:'', obs:'เหล็กเคลือบทองแดงสีน้ำตาลแดง สารละลายสีฟ้าจาง', eq:'Fe + CuSO₄ → FeSO₄ + Cu', type:'redox', dT:5, hazard_eye:false },
    { id:'ca_carbide_h2o', c1:'แคลเซียมคาร์ไบด์', c2:'น้ำ', gas:'C₂H₂', obs:'ก๊าซอะเซทิลีน C₂H₂ เกิด กลิ่นฉุน', eq:'CaC₂ + 2H₂O → Ca(OH)₂ + C₂H₂↑', type:'acid_carbonate', dT:8, hazard_eye:false },
    { id:'milk_lemon', c1:'นม', c2:'น้ำมะนาว', gas:'', obs:'โปรตีน casein จับตัวเป็นก้อนขาว เวย์ใสแยกชั้น', eq:'Casein (pH~6.7) + H⁺ (pH<4.6) → Curd ↓', type:'precipitation', dT:0, hazard_eye:false },
    { id:'ca_oh2_co2', c1:'น้ำปูนใส', c2:'CO₂', gas:'', obs:'น้ำใสกลายเป็นขุ่นขาวจาก CaCO₃ ถ้าเป่า CO₂ เพิ่มจะใสอีก', eq:'Ca(OH)₂ + CO₂ → CaCO₃↓ + H₂O', type:'precipitation', dT:2, hazard_eye:false },
    { id:'kmno4_glycerol', c1:'โพแทสเซียมเปอร์แมงกาเนต', c2:'กลีเซอรอล', gas:'', obs:'เกิดไฟและควันทันที! ปฏิกิริยารีดอกซ์รุนแรง', eq:'14KMnO₄ + 4C₃H₈O₃ → 14MnO + 7K₂O + 12CO₂ + 16H₂O', type:'redox', dT:200, hazard_eye:true, eye_warn:'อันตราย! เกิดไฟและควัน ต้องสวมแว่นและใช้ระยะห่าง' },
    { id:'nahco3_heat', c1:'โซเดียมไบคาร์บอเนต', c2:'ความร้อน', gas:'CO₂', obs:'สลายตัวเป็น CO₂ + H₂O เมื่อถูกความร้อน นิยมใช้ฟูฟ่อง', eq:'2NaHCO₃ → Na₂CO₃ + H₂O + CO₂↑', type:'decomposition', dT:15, hazard_eye:false },
    { id:'ethanol_o2', c1:'เอทานอล', c2:'ออกซิเจน', gas:'CO₂', obs:'เผาไหม้สีน้ำเงิน คายความร้อนมาก ได้ CO₂+H₂O', eq:'C₂H₅OH + 3O₂ → 2CO₂ + 3H₂O', type:'combustion', dT:90, hazard_eye:false },
];


// ══════════════════════════════════════════════════════════════
// STEP 2 — REACTION RESULT CARD + HOVER TOOLTIP + KNOWLEDGE BASE
// อัปเกรดสมบูรณ์ — แสดงผลการทดลองครบถ้วน + อ้างอิงวิทยาศาสตร์
// ══════════════════════════════════════════════════════════════

// ── CSS injection ────────────────────────────────────────────
(function injectStep2Styles() {
    if (document.getElementById('step2Styles')) return;
    const s = document.createElement('style');
    s.id = 'step2Styles';
    s.textContent = `
/* ── Reaction Result Card ── */
@keyframes rxnSlideUp { from{transform:translateY(110%);opacity:0} to{transform:translateY(0);opacity:1} }
@keyframes rxnFadeOut { from{opacity:1;transform:translateY(0)} to{opacity:0;transform:translateY(30px)} }
@keyframes rxnBounce  { 0%,100%{transform:scale(1)} 50%{transform:scale(1.03)} }

#rxnResultCard {
    position:fixed; bottom:0; left:0; right:0; z-index:8000;
    background:#0f172a;
    border-top:2px solid #334155;
    border-radius:20px 20px 0 0;
    max-height:88vh; overflow-y:auto;
    animation:rxnSlideUp 0.45s cubic-bezier(0.175,0.885,0.32,1.275) both;
    font-family:'Prompt',sans-serif;
    box-shadow:0 -12px 60px rgba(0,0,0,0.7);
}
#rxnResultCard.dismissing { animation:rxnFadeOut 0.3s ease forwards; }
.rxnHandle { width:44px;height:5px;background:#334155;border-radius:3px;margin:12px auto 0;cursor:grab; }
.rxnInner  { padding:14px 18px 28px; }

/* Type indicator strip */
.rxnStrip  { height:3px;border-radius:2px;margin-bottom:14px;transition:width 0.3s; }

/* Header */
.rxnTypeLabel { font-size:0.63rem;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;margin-bottom:5px; }
.rxnTitle     { font-size:1.05rem;font-weight:700;color:#f1f5f9;margin-bottom:12px;line-height:1.3; }

/* Equation box */
.rxnEqBox {
    background:#020617; border-radius:10px;
    padding:12px 16px; margin-bottom:12px;
    border-left:3px solid; overflow-x:auto;
}
.rxnEqLabel { font-size:0.6rem;color:#475569;letter-spacing:0.12em;text-transform:uppercase;margin-bottom:4px; }
.rxnEqText  { font-family:'Share Tech Mono',monospace;font-size:1.05rem;color:#7dd3fc;line-height:1.5;white-space:nowrap; }

/* Observation */
.rxnObsBox {
    background:rgba(34,197,94,0.08);
    border:1px solid rgba(34,197,94,0.25);
    border-radius:10px; padding:11px 14px; margin-bottom:12px;
}
.rxnObsLabel { font-size:0.6rem;color:#16a34a;letter-spacing:0.12em;text-transform:uppercase;font-weight:700;margin-bottom:4px; }
.rxnObsText  { font-size:0.83rem;color:#86efac;line-height:1.6; }

/* Badges row */
.rxnBadges { display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px; }
.rxnBadge  { padding:4px 11px;border-radius:20px;font-size:0.72rem;font-weight:600;display:flex;align-items:center;gap:4px; }
.rxnBadge-exo   { background:rgba(239,68,68,0.15);color:#fca5a5;border:1px solid rgba(239,68,68,0.35); }
.rxnBadge-endo  { background:rgba(56,189,248,0.15);color:#7dd3fc;border:1px solid rgba(56,189,248,0.35); }
.rxnBadge-gas   { background:rgba(168,85,247,0.15);color:#d8b4fe;border:1px solid rgba(168,85,247,0.35); }
.rxnBadge-prec  { background:rgba(234,179,8,0.15);color:#fde047;border:1px solid rgba(234,179,8,0.35); }
.rxnBadge-warn  { background:rgba(239,68,68,0.15);color:#fca5a5;border:1px solid rgba(239,68,68,0.35); }
.rxnBadge-real  { background:rgba(45,212,191,0.1);color:#5eead4;border:1px solid rgba(45,212,191,0.25); }

/* Knowledge expander */
.rxnLearnBtn {
    width:100%;padding:11px 16px;
    background:rgba(56,189,248,0.08);
    border:1px solid rgba(56,189,248,0.25);
    color:#38bdf8;border-radius:10px;cursor:pointer;
    font-family:'Prompt';font-size:0.82rem;font-weight:600;
    text-align:left;display:flex;align-items:center;justify-content:space-between;
    transition:background 0.2s;
}
.rxnLearnBtn:hover { background:rgba(56,189,248,0.15); }
.rxnLearnBtn .arrow { transition:transform 0.25s; }
.rxnLearnBtn.open .arrow { transform:rotate(180deg); }

.rxnKnow {
    max-height:0; overflow:hidden;
    transition:max-height 0.35s ease, opacity 0.3s;
    opacity:0;
}
.rxnKnow.open { max-height:600px; opacity:1; }
.rxnKnowInner { background:#020617;border-radius:0 0 10px 10px;border:1px solid #1e293b;border-top:none;padding:14px 16px; }
.rxnKnowRow   { margin-bottom:12px; }
.rxnKnowRow:last-child { margin-bottom:0; }
.rxnKnowLabel { font-size:0.6rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.12em;display:block;margin-bottom:3px; }
.rxnKnowText  { font-size:0.8rem;color:#94a3b8;line-height:1.65; }
.rxnKnowSrc   { font-size:0.67rem;color:#334155;font-style:italic;margin-top:8px;padding-top:8px;border-top:1px solid #1e293b; }

/* Dismiss progress bar */
.rxnBar { height:2px;background:#334155;border-radius:1px;margin-top:14px;overflow:hidden; }
.rxnBarFill { height:100%;border-radius:1px;transition:width linear; }

/* Close button */
.rxnCloseBtn {
    position:absolute;top:14px;right:14px;
    width:26px;height:26px;border-radius:50%;
    background:#1e293b;border:1px solid #334155;
    color:#64748b;cursor:pointer;font-size:0.75rem;
    display:flex;align-items:center;justify-content:center;
    transition:0.15s;
}
.rxnCloseBtn:hover { background:#334155;color:#f1f5f9; }

/* ── Hover Tooltip ── */
#chemTooltip {
    position:fixed;z-index:9000;pointer-events:none;display:none;
    background:#0f172a;border:1px solid #334155;border-radius:12px;
    padding:13px 15px;max-width:300px;min-width:220px;
    font-family:'Prompt';font-size:0.78rem;color:#cbd5e1;line-height:1.65;
    box-shadow:0 8px 30px rgba(0,0,0,0.6);
}
.tt-name { font-size:0.88rem;font-weight:700;color:#f1f5f9;margin-bottom:6px; }
.tt-formula { font-family:'Share Tech Mono';font-size:0.8rem;color:#7dd3fc;margin-bottom:8px; }
.tt-row { display:flex;gap:6px;align-items:baseline;margin-bottom:3px; }
.tt-label { font-size:0.62rem;color:#475569;text-transform:uppercase;letter-spacing:0.1em;flex-shrink:0;min-width:52px; }
.tt-val { font-size:0.78rem;color:#94a3b8; }
.tt-ph-bar { height:5px;border-radius:3px;margin:6px 0;background:linear-gradient(to right,#ef4444,#f97316,#eab308,#22c55e,#3b82f6,#8b5cf6);position:relative; }
.tt-ph-marker { position:absolute;top:-2px;width:9px;height:9px;border-radius:50%;background:#fff;border:2px solid #0f172a;transform:translateX(-50%);transition:left 0.3s; }
.tt-principle { font-size:0.73rem;color:#64748b;margin-top:7px;padding-top:7px;border-top:1px solid #1e293b;line-height:1.5; }
.tt-realworld { font-size:0.72rem;color:#5eead4;margin-top:4px; }
.tt-hazard { display:inline-block;font-size:0.65rem;font-weight:600;padding:1px 8px;border-radius:10px;margin-top:5px; }
.tt-hazard-0 { background:rgba(34,197,94,0.15);color:#4ade80; }
.tt-hazard-1 { background:rgba(234,179,8,0.15);color:#fde047; }
.tt-hazard-2 { background:rgba(245,158,11,0.15);color:#fb923c; }
.tt-hazard-3 { background:rgba(239,68,68,0.15);color:#f87171; }
.tt-hazard-4 { background:rgba(239,68,68,0.25);color:#ef4444;border:1px solid rgba(239,68,68,0.4); }
`;
    document.head.appendChild(s);

    // Inject tooltip + overlay elements
    if (!document.getElementById('chemTooltip')) {
        document.body.insertAdjacentHTML('beforeend', '<div id="chemTooltip"></div>');
    }
    if (!document.getElementById('flameColorOverlay')) {
        document.body.insertAdjacentHTML('beforeend', '<div id="flameColorOverlay"></div>');
    }
    if (!document.getElementById('fumeHoodOverlay')) {
        document.body.insertAdjacentHTML('beforeend', '<div id="fumeHoodOverlay"></div>');
    }
})();

// ── React card queue (กัน overlap) ──────────────────────────
let _rxnQueue   = [];
let _rxnShowing = false;
let _rxnEl      = null;
let _rxnTimer   = null;

function showReactionCard(rxn, c1name, c2name) {
    // ใส่ใน queue ถ้ามี card แสดงอยู่
    _rxnQueue.push({ rxn, c1name, c2name });
    if (!_rxnShowing) _processRxnQueue();
}

function _processRxnQueue() {
    if (_rxnQueue.length === 0) { _rxnShowing = false; return; }
    _rxnShowing = true;
    const { rxn, c1name, c2name } = _rxnQueue.shift();
    _renderCard(rxn, c1name, c2name);
}

function _renderCard(rxn, c1name, c2name) {
    if (_rxnEl) { _rxnEl.remove(); _rxnEl = null; }
    if (_rxnTimer) { clearTimeout(_rxnTimer); _rxnTimer = null; }

    const typeKey  = (rxn.reaction_type || '').toLowerCase();
    const know     = REACTION_KNOWLEDGE[typeKey] || {};
    const realRxn  = REAL_REACTIONS.find(r => r.type === typeKey);
    const deltaT   = parseFloat(rxn.temperature_change ?? rxn.heat_level ?? 0) || 0;

    // สี type
    const TYPE_COLORS = {
        neutralization:'#22d3ee', precipitation:'#fcd34d', redox:'#f472b6',
        acid_metal:'#fb923c', metal_water:'#ef4444', decomposition:'#a78bfa',
        acid_carbonate:'#34d399', complex_formation:'#818cf8', combustion:'#f97316',
        dissolution:'#38bdf8', buffer:'#6ee7b7', iodine_clock:'#fbbf24',
    };
    const TYPE_NAMES = {
        neutralization:'การสะเทิน', precipitation:'การตกตะกอน', redox:'รีดอกซ์',
        acid_metal:'โลหะ + กรด', metal_water:'โลหะ + น้ำ', decomposition:'การสลายตัว',
        acid_carbonate:'กรด + คาร์บอเนต', complex_formation:'สารประกอบเชิงซ้อน',
        combustion:'การเผาไหม้', dissolution:'การละลาย', buffer:'บัฟเฟอร์',
        iodine_clock:'Iodine Clock (Kinetics)',
    };
    const typeColor = TYPE_COLORS[typeKey] || '#94a3b8';
    const typeName  = TYPE_NAMES[typeKey]  || typeKey;

    // format equation: superscript +/- charge, subscript numbers
    function fmtEq(eq) {
        if (!eq) return '';
        return eq
            .replace(/\^(\d+[+-]|[+-]\d*)/g, '<sup>$1</sup>')
            .replace(/([A-Za-z\)])(\d+)/g, '$1<sub>$2</sub>')
            .replace(/(↑|↓)/g, '<span style="color:#a3e635">$1</span>');
    }

    const eqStr = rxn.equation || `${c1name} + ${c2name} → ${rxn.product_name || '?'}`;

    // Badges
    let badges = '';
    if (deltaT > 0.5)   badges += `<span class="rxnBadge rxnBadge-exo">🔥 +${deltaT.toFixed(1)}°C Exothermic</span>`;
    if (deltaT < -0.5)  badges += `<span class="rxnBadge rxnBadge-endo">🧊 ${deltaT.toFixed(1)}°C Endothermic</span>`;
    if (rxn.result_gas && rxn.result_gas !== 'ไม่มีแก๊ส')
        badges += `<span class="rxnBadge rxnBadge-gas">💨 ${rxn.result_gas}</span>`;
    if (rxn.result_precipitate && rxn.result_precipitate !== 'ไม่มีตะกอน')
        badges += `<span class="rxnBadge rxnBadge-prec">🧊 ตะกอน: ${rxn.result_precipitate}</span>`;
    if (realRxn)
        badges += `<span class="rxnBadge rxnBadge-real">🌍 พบในชีวิตจริง</span>`;
    if (rxn.result_ph !== undefined && rxn.result_ph !== null && rxn.result_ph >= 0)
        badges += `<span class="rxnBadge" style="background:rgba(56,189,248,0.1);color:#7dd3fc;border:1px solid rgba(56,189,248,0.25)">pH ผลลัพธ์: ${parseFloat(rxn.result_ph).toFixed(1)}</span>`;

    // Knowledge HTML
    const safetyWarning = rxn.safety_warning || (know.safety ? `⚠️ ${know.safety}` : '');
    let knowHTML = '';
    if (know.principle)    knowHTML += `<div class="rxnKnowRow"><span class="rxnKnowLabel">หลักการ</span><span class="rxnKnowText">${know.principle}</span></div>`;
    if (know.mechanism)    knowHTML += `<div class="rxnKnowRow"><span class="rxnKnowLabel">กลไก</span><span class="rxnKnowText">${know.mechanism}</span></div>`;
    if (know.color_change) knowHTML += `<div class="rxnKnowRow"><span class="rxnKnowLabel">สังเกตได้</span><span class="rxnKnowText">${know.color_change}</span></div>`;
    if (realRxn?.obs)      knowHTML += `<div class="rxnKnowRow"><span class="rxnKnowLabel">ชีวิตจริง</span><span class="rxnKnowText">${realRxn.obs}</span></div>`;
    if (safetyWarning)     knowHTML += `<div class="rxnKnowRow"><span class="rxnKnowLabel">ความปลอดภัย</span><span class="rxnKnowText" style="color:#fca5a5">${safetyWarning}</span></div>`;
    if (know.source)       knowHTML += `<div class="rxnKnowSrc">📚 อ้างอิง: ${know.source}</div>`;

    const DURATION = 14000;
    const card = document.createElement('div');
    card.id = 'rxnResultCard';
    card.style.position = 'relative';
    card.innerHTML = `
        <button class="rxnCloseBtn" aria-label="ปิด">✕</button>
        <div class="rxnHandle"></div>
        <div class="rxnInner">
            <div class="rxnStrip" style="background:${typeColor}"></div>
            <div class="rxnTypeLabel" style="color:${typeColor}">${typeName}</div>
            <div class="rxnTitle">⚗️ ${rxn.product_name || `${c1name} + ${c2name}`}</div>
            <div class="rxnEqBox" style="border-left-color:${typeColor}">
                <div class="rxnEqLabel">สมการเคมี</div>
                <div class="rxnEqText">${fmtEq(eqStr)}</div>
            </div>
            ${rxn.observation_th ? `
            <div class="rxnObsBox">
                <div class="rxnObsLabel">👁 สังเกตเห็น</div>
                <div class="rxnObsText">${rxn.observation_th}</div>
            </div>` : ''}
            ${badges ? `<div class="rxnBadges">${badges}</div>` : ''}
            ${knowHTML ? `
            <button class="rxnLearnBtn" id="rxnLearnBtn">
                <span>📚 เรียนรู้หลักการ — ทำไมถึงเกิดขึ้น?</span>
                <span class="arrow">▼</span>
            </button>
            <div class="rxnKnow" id="rxnKnow">
                <div class="rxnKnowInner">${knowHTML}</div>
            </div>` : ''}
            <div class="rxnBar"><div class="rxnBarFill" id="rxnBarFill" style="background:${typeColor};width:100%"></div></div>
        </div>`;

    document.body.appendChild(card);
    _rxnEl = card;

    // Knowledge toggle
    const learnBtn = card.querySelector('#rxnLearnBtn');
    const knowPanel = card.querySelector('#rxnKnow');
    if (learnBtn && knowPanel) {
        learnBtn.addEventListener('click', () => {
            const open = knowPanel.classList.toggle('open');
            learnBtn.classList.toggle('open', open);
            learnBtn.querySelector('span:first-child').textContent =
                open ? '▲ ซ่อนหลักการ' : '📚 เรียนรู้หลักการ — ทำไมถึงเกิดขึ้น?';
        });
    }

    // Close button
    card.querySelector('.rxnCloseBtn').addEventListener('click', dismissCard);

    // Swipe down
    let _ty = 0;
    card.addEventListener('touchstart', e => { _ty = e.touches[0].clientY; }, { passive:true });
    card.addEventListener('touchend', e => { if (e.changedTouches[0].clientY - _ty > 70) dismissCard(); });

    // Progress bar shrink
    const fill = card.querySelector('#rxnBarFill');
    if (fill) {
        fill.style.transition = `width ${DURATION}ms linear`;
        requestAnimationFrame(() => { fill.style.width = '0%'; });
    }

    _rxnTimer = setTimeout(() => {
        dismissCard();
        // ถ้ายัง queue อยู่ รอ 400ms แล้วแสดงถัดไป
        setTimeout(_processRxnQueue, 400);
    }, DURATION);
}

function dismissCard() {
    if (!_rxnEl) return;
    _rxnEl.classList.add('dismissing');
    setTimeout(() => { if (_rxnEl) { _rxnEl.remove(); _rxnEl = null; } }, 320);
    if (_rxnTimer) { clearTimeout(_rxnTimer); _rxnTimer = null; }
}

// ── Hover Tooltip System ─────────────────────────────────────
function initHoverTooltip(app) {
    const tt = document.getElementById('chemTooltip');
    if (!tt) return;

    const GHS_LABELS = ['ไม่มีอันตราย', 'ระวัง (GHS 1)', 'เตือน (GHS 2)', 'อันตราย (GHS 3)', 'อันตรายมาก (GHS 4)'];
    const PH_LABELS  = ph => ph < 3 ? 'กรดแก่มาก' : ph < 7 ? 'กรดอ่อน' : ph === 7 ? 'กลาง' : ph < 11 ? 'ด่างอ่อน' : 'ด่างแก่มาก';

    let hideTimer = null;

    function buildTooltipHTML(chemData) {
        const ph = chemData.ph_value !== undefined && chemData.ph_value !== null ? parseFloat(chemData.ph_value) : null;
        const hazard = parseInt(chemData.hazard || chemData.hazard_level || 0);
        const know   = REACTION_KNOWLEDGE[chemData.type] || {};
        const phPct  = ph !== null ? (ph / 14) * 100 : null;

        return `
            <div class="tt-name">${chemData.name || ''}</div>
            ${chemData.formula ? `<div class="tt-formula">${chemData.formula}</div>` : ''}
            ${ph !== null ? `
            <div class="tt-row">
                <span class="tt-label">pH</span>
                <span class="tt-val">${ph.toFixed(1)} — ${PH_LABELS(ph)}</span>
            </div>
            <div class="tt-ph-bar">
                <div class="tt-ph-marker" style="left:${phPct}%"></div>
            </div>` : ''}
            ${chemData.state ? `
            <div class="tt-row">
                <span class="tt-label">สถานะ</span>
                <span class="tt-val">${chemData.state === 'solid' ? '🧂 ของแข็ง' : chemData.state === 'gas' ? '💨 ก๊าซ' : '💧 ของเหลว'}</span>
            </div>` : ''}
            ${chemData.amount !== undefined ? `
            <div class="tt-row">
                <span class="tt-label">ปริมาณ</span>
                <span class="tt-val">${parseFloat(chemData.amount || 0).toFixed(1)} ${chemData.state === 'solid' ? 'g' : 'mL'}</span>
            </div>` : ''}
            ${chemData.description || chemData.desc ? `
            <div class="tt-principle">${chemData.description || chemData.desc}</div>` :
            (know.principle ? `<div class="tt-principle">${know.principle.substring(0,120)}...</div>` : '')}
            ${chemData.real_world || chemData.real_world_example ? `
            <div class="tt-realworld">🌍 ${chemData.real_world || chemData.real_world_example}</div>` : ''}
            ${hazard > 0 ? `
            <span class="tt-hazard tt-hazard-${hazard}">${['','⚠️','🔶','🔴','☠️'][hazard]} ${GHS_LABELS[hazard]}</span>` : ''}
        `;
    }

    document.addEventListener('mousemove', e => {
        clearTimeout(hideTimer);

        // ตรวจสอบ target ที่มี data-chem-id (chem-item card ที่เราใส่ใน Step 1)
        const chemEl = e.target.closest('[data-chem-id]');
        if (chemEl) {
            const cid  = chemEl.dataset.chemId;
            // หาจาก LAB_CONFIG.chemicalsData (full data จาก DB)
            const full = (window.LAB_CONFIG?.chemicalsData || []).find(c => String(c.id) === String(cid));
            // หรือจาก beaker contents
            const inBeaker = app.state.contents.find(c => String(c.id) === String(cid));

            const src = full || inBeaker || {
                name:        chemEl.dataset.name || '',
                formula:     chemEl.dataset.formula || '',
                ph_value:    chemEl.dataset.ph !== '' ? parseFloat(chemEl.dataset.ph) : null,
                state:       chemEl.dataset.state || '',
                hazard_level:parseInt(chemEl.dataset.hazard) || 0,
                description: chemEl.dataset.description || '',
                real_world_example: chemEl.dataset.realWorld || '',
            };

            const beakerVersion = inBeaker ? { ...src, amount: inBeaker.amount, dissolvedAmt: inBeaker.dissolvedAmt } : src;
            tt.innerHTML = buildTooltipHTML(beakerVersion);
            tt.style.display = 'block';
            _positionTooltip(tt, e);
            return;
        }

        // ตรวจสอบ target ใน beaker contents (สารที่อยู่ใน beaker)
        const contentEl = e.target.closest('[data-beaker-chem]');
        if (contentEl) {
            const cid  = contentEl.dataset.beakerChem;
            const chem = app.state.contents.find(c => String(c.id) === String(cid));
            if (chem) {
                const full = (window.LAB_CONFIG?.chemicalsData || []).find(c => String(c.id) === String(cid));
                tt.innerHTML = buildTooltipHTML({ ...(full || {}), ...chem });
                tt.style.display = 'block';
                _positionTooltip(tt, e);
                return;
            }
        }

        // ซ่อนถ้าไม่มี target
        hideTimer = setTimeout(() => { tt.style.display = 'none'; }, 120);
    });

    document.addEventListener('mouseleave', () => { tt.style.display = 'none'; });
    document.addEventListener('touchstart', () => { tt.style.display = 'none'; });
}

function _positionTooltip(tt, e) {
    const W = window.innerWidth, H = window.innerHeight;
    const tw = 300, th = 200; // approx
    let x = e.clientX + 16, y = e.clientY + 16;
    if (x + tw > W - 8) x = e.clientX - tw - 8;
    if (y + th > H - 8) y = e.clientY - th - 8;
    tt.style.left = Math.max(8, x) + 'px';
    tt.style.top  = Math.max(8, y) + 'px';
}

// ── patch patchProcessReaction ───────────────────────────────
function patchProcessReaction(app) {
    const orig = app.processReaction.bind(app);
    app.processReaction = function(rxn, c1, c2, idx1, idx2) {
        // 1. ตรวจ eye safety ก่อน reaction
        checkEyeSafety(rxn);
        // 2. run original
        orig.call(this, rxn, c1, c2, idx1, idx2);
        // 3. แสดง result card
        showReactionCard(rxn, c1 ? c1.name : '?', c2 ? c2.name : '?');
    };
}



// ══════════════════════════════════════════
// 3. PPE VISUAL SYSTEM
// ══════════════════════════════════════════

// ══════════════════════════════════════════════════════════════
// STEP 3 — PPE VISUAL SYSTEM + EYE WARNING (อัปเกรดสมบูรณ์)
// ══════════════════════════════════════════════════════════════

// ── Inject HTML elements ที่ขาด ──────────────────────────────
(function injectStep3HTML() {
    const S = document.createElement('style');
    S.id = 'step3Styles';
    S.textContent = `
/* Eye Warning */
@keyframes eyePulse { 0%,100%{opacity:.55} 50%{opacity:.85} }
@keyframes eyeBannerIn { from{transform:translateX(-50%) translateY(-20px);opacity:0} to{transform:translateX(-50%) translateY(0);opacity:1} }
#eyeWarnOverlay {
    position:fixed;inset:0;z-index:9500;pointer-events:none;display:none;
    background:rgba(239,68,68,0.18);
    animation:eyePulse 0.45s ease-in-out infinite;
    border:4px solid rgba(239,68,68,0.5);
}
#eyeWarnBanner {
    position:fixed;top:76px;left:50%;transform:translateX(-50%);
    z-index:9600;display:none;
    background:linear-gradient(135deg,#7f1d1d,#991b1b);
    border:2px solid #ef4444;border-radius:14px;
    padding:14px 22px;max-width:440px;width:90%;text-align:center;
    font-family:'Prompt',sans-serif;font-size:.85rem;color:#fca5a5;
    box-shadow:0 10px 40px rgba(239,68,68,0.5);
    animation:eyeBannerIn .35s ease both;
}
#eyeWarnBanner .ewb-title  { font-size:1rem;font-weight:700;color:#fecaca;margin-bottom:6px; }
#eyeWarnBanner .ewb-msg    { font-size:.82rem;line-height:1.55;margin-bottom:8px; }
#eyeWarnBanner .ewb-action { font-size:.75rem;opacity:.8; }
#eyeWarnBanner .ewb-close  {
    position:absolute;top:8px;right:10px;
    background:none;border:none;color:#f87171;cursor:pointer;font-size:.9rem;
}
/* PPE labels */
.ppe-label { position:relative; }
.ppe-label.ppe-on { background:rgba(16,185,129,.07)!important;border-color:rgba(16,185,129,.45)!important; }
.ppe-label.ppe-goggles-on { background:rgba(56,189,248,.07)!important;border-color:rgba(56,189,248,.45)!important; }
/* Tutorial */
@keyframes tutIn { from{opacity:0;transform:scale(.95)} to{opacity:1;transform:scale(1)} }
#tutorialOverlay {
    position:fixed;inset:0;z-index:10000;
    background:rgba(0,0,0,.75);backdrop-filter:blur(4px);
    display:none;align-items:center;justify-content:center;
}
#tutorialOverlay.open { display:flex;animation:tutIn .3s ease; }
#tutBox {
    background:#1e293b;border:1px solid #334155;border-radius:16px;
    padding:28px 30px;max-width:480px;width:90%;
    font-family:'Prompt',sans-serif;position:relative;
}
.tut-step { display:none; }
.tut-step.active { display:block; }
.tut-step h2 { font-size:1.1rem;font-weight:700;color:#38bdf8;margin-bottom:10px; }
.tut-step p  { font-size:.83rem;color:#94a3b8;line-height:1.65;margin-bottom:12px; }
.tut-hi { background:rgba(56,189,248,.1);border:1px solid rgba(56,189,248,.25);border-radius:8px;padding:10px 13px;font-size:.8rem;color:#7dd3fc;margin-bottom:14px; }
.tut-nav { display:flex;justify-content:space-between;align-items:center;margin-top:4px; }
.tut-btn { padding:8px 18px;border-radius:8px;cursor:pointer;font-family:'Prompt';font-size:.8rem;font-weight:600;border:none;transition:.15s; }
.tut-next { background:#38bdf8;color:#0f172a; } .tut-next:hover { background:#7dd3fc; }
.tut-skip { background:transparent;color:#475569;border:1px solid #334155; }
.tut-dots { display:flex;gap:5px;align-items:center; }
.tut-dot  { width:7px;height:7px;border-radius:50%;background:#334155;transition:.2s; }
.tut-dot.active { background:#38bdf8;transform:scale(1.2); }
.tut-close-btn { position:absolute;top:12px;right:14px;background:none;border:none;color:#475569;cursor:pointer;font-size:.9rem; }
`;
    document.head.appendChild(S);

    // eye warning overlay
    if (!document.getElementById('eyeWarnOverlay')) {
        document.body.insertAdjacentHTML('beforeend', `
<div id="eyeWarnOverlay"></div>
<div id="eyeWarnBanner" style="position:fixed">
    <button class="ewb-close" id="eyeWarnClose">✕</button>
    <div class="ewb-title">👁️ อันตรายต่อดวงตา!</div>
    <div class="ewb-msg" id="eyeWarnMsg">—</div>
    <div class="ewb-action">กดปุ่ม 🥽 สวมแว่นตาเพื่อป้องกัน</div>
</div>`);
        document.getElementById('eyeWarnClose')?.addEventListener('click', () => {
            document.getElementById('eyeWarnOverlay').style.display = 'none';
            document.getElementById('eyeWarnBanner').style.display  = 'none';
        });
    }

    // tutorial overlay
    if (!document.getElementById('tutorialOverlay')) {
        document.body.insertAdjacentHTML('beforeend', `
<div id="tutorialOverlay">
    <div id="tutBox">
        <button class="tut-close-btn" id="tutClose">✕</button>
        <div class="tut-step active" data-step="0">
            <h2>🧪 ยินดีต้อนรับสู่ Virtual Chemistry Lab!</h2>
            <p>ระบบนี้จำลองการทดลองเคมีแบบสมจริง ทุกปฏิกิริยามีคำอธิบายทางวิทยาศาสตร์และแหล่งอ้างอิงจริง</p>
            <div class="tut-hi">📌 ลากสารจากแผงซ้ายลงบีกเกอร์ — ปฏิกิริยาเกิดอัตโนมัติเมื่อสารพร้อม</div>
        </div>
        <div class="tut-step" data-step="1">
            <h2>🥽 PPE — ความปลอดภัยมาก่อนเสมอ</h2>
            <p>คลิกที่แผง <strong>PPE</strong> ด้านขวาเพื่อสวมแว่นตานิรภัยและถุงมือยาง — เมื่อสวมแว่นจะเห็น <em>overlay กรอบแว่น</em> ครอบหน้าจอจริงๆ</p>
            <div class="tut-hi">⚠️ ถ้าไม่สวมแว่นแล้วทำ reaction อันตราย จะมีแสงแดงกระพริบเตือนพร้อมคำอธิบาย</div>
        </div>
        <div class="tut-step" data-step="2">
            <h2>🌡️ อุณหภูมิ — ห้องและบีกเกอร์</h2>
            <p>ปุ่ม <strong>❄️ AC</strong> ลดอุณหภูมิห้อง — <strong>🔆 Heater</strong> เพิ่ม — ห้องเย็น/ร้อนส่งผลต่อสารในบีกเกอร์จริง</p>
            <div class="tut-hi">⏱️ Stopwatch จับเวลา — เร่ง 1×/2×/5×/10× ได้ — สารบางชนิดเกิด reaction ช้าๆ เมื่อทิ้งไว้นาน</div>
        </div>
        <div class="tut-step" data-step="3">
            <h2>📊 ผลการทดลอง</h2>
            <p>ทุก reaction มี <strong>Result Card</strong> ขึ้นมาจากล่าง: สมการ, สิ่งที่สังเกต, ΔT badge, ก๊าซ/ตะกอน — กด <em>เรียนรู้หลักการ</em> เพื่อดูอ้างอิง IUPAC/NIST</p>
            <div class="tut-hi">🖱️ เอาเมาส์ชี้ที่สารในแผงซ้ายหรือในบีกเกอร์เพื่อดูข้อมูลละเอียด pH, hazard, หลักการ</div>
        </div>
        <div class="tut-step" data-step="4">
            <h2>✅ พร้อมแล้ว — เริ่มทดลองได้เลย!</h2>
            <p>สวม PPE → ลากสาร → สังเกต → อ่านผล → เรียนรู้ — ทุกขั้นตอนมีข้อมูลครบ</p>
            <div class="tut-hi">💡 เคล็ดลับ: ลอง HCl + NaOH เป็นปฏิกิริยาแรก — เห็น neutralization + ΔT +55.8°C</div>
        </div>
        <div class="tut-nav">
            <button class="tut-btn tut-skip" id="tutSkip">ข้าม</button>
            <div class="tut-dots" id="tutDots"></div>
            <button class="tut-btn tut-next" id="tutNext">ถัดไป →</button>
        </div>
    </div>
</div>`);
    }
})();

// ══════════════════════════════════════════
// PPE VISUAL SYSTEM (Step 3 — สมบูรณ์)
// ══════════════════════════════════════════
function initPPESystem() {
    const chkGoggles     = document.getElementById('chkGoggles');
    const chkGloves      = document.getElementById('chkGloves');
    const gogglesOverlay = document.getElementById('gogglesOverlay');
    if (!chkGoggles) return;

    // ── Goggles ──────────────────────────────────────────────
    document.getElementById('labelGoggles')?.addEventListener('click', () => {
        chkGoggles.checked = !chkGoggles.checked;
        _updateGogglesUI(chkGoggles.checked);
    });

    // ── Gloves ───────────────────────────────────────────────
    document.getElementById('labelGloves')?.addEventListener('click', () => {
        chkGloves.checked = !chkGloves.checked;
        _updateGlovesUI(chkGloves.checked);
    });

    // init state ให้ตรงกับ checkbox value
    _updateGogglesUI(chkGoggles.checked);
    _updateGlovesUI(chkGloves.checked);
}

function _updateGogglesUI(on) {
    const wrap    = document.getElementById('gogglesWrap');
    const tag     = document.getElementById('gogglesTag');
    const stat    = document.getElementById('gogglesStatus');
    const lbl     = document.getElementById('labelGoggles');
    const overlay = document.getElementById('gogglesOverlay');

    if (wrap) {
        wrap.style.background  = on ? 'rgba(56,189,248,0.2)' : '#1e293b';
        wrap.style.borderColor = on ? '#38bdf8' : '#334155';
        wrap.style.boxShadow   = on ? '0 0 12px rgba(56,189,248,0.3)' : 'none';
        if (on) {
            wrap.style.transform = 'scale(1.12)';
            setTimeout(() => { if(wrap) wrap.style.transform='scale(1)'; }, 200);
        }
    }
    if (tag) {
        tag.textContent      = on ? 'กำลังสวม ✓' : 'ถอด';
        tag.style.background = on ? 'rgba(56,189,248,0.2)' : '#1e293b';
        tag.style.color      = on ? '#38bdf8' : '#475569';
        tag.style.border     = on ? '1px solid rgba(56,189,248,0.4)' : '1px solid transparent';
    }
    if (stat) {
        stat.textContent      = on ? '✓ สวมแว่นตานิรภัยแล้ว' : 'สวมแว่นตานิรภัย';
        stat.style.color      = on ? '#22d3ee' : '#94a3b8';
        stat.style.fontWeight = on ? '700' : '600';
    }
    if (lbl) {
        lbl.classList.toggle('ppe-goggles-on', on);
        lbl.classList.toggle('ppe-on', on);
    }
    // แว่น overlay
    if (overlay) {
        if (on) {
            overlay.style.display  = 'block';
            overlay.style.opacity  = '0';
            overlay.style.transition = 'opacity 0.4s ease';
            requestAnimationFrame(() => { overlay.style.opacity = '1'; });
        } else {
            // ปิด: fade out แล้วซ่อน
            overlay.style.transition = 'opacity 0.3s ease';
            overlay.style.opacity    = '0';
            setTimeout(() => { overlay.style.display = 'none'; }, 320);
        }
    }
    // ซ่อน eye warning เมื่อสวมแว่น
    if (on) {
        const ew = document.getElementById('eyeWarnOverlay');
        const eb = document.getElementById('eyeWarnBanner');
        if (ew) ew.style.display = 'none';
        if (eb) eb.style.display = 'none';
    }
}

function _updateGlovesUI(on) {
    const wrap = document.getElementById('glovesWrap');
    const tag  = document.getElementById('glovesTag');
    const stat = document.getElementById('glovesStatus');
    const lbl  = document.getElementById('labelGloves');

    if (wrap) {
        wrap.style.background  = on ? 'rgba(16,185,129,0.2)' : '#1e293b';
        wrap.style.borderColor = on ? '#10b981' : '#334155';
        wrap.style.boxShadow   = on ? '0 0 12px rgba(16,185,129,0.3)' : 'none';
        if (on) { wrap.style.transform = 'scale(1.12)'; setTimeout(() => { if(wrap) wrap.style.transform='scale(1)'; }, 200); }
    }
    if (tag) {
        tag.textContent     = on ? 'กำลังสวม ✓' : 'ถอด';
        tag.style.background = on ? 'rgba(16,185,129,0.2)' : '#1e293b';
        tag.style.color      = on ? '#10b981' : '#475569';
        tag.style.border     = on ? '1px solid rgba(16,185,129,0.4)' : '1px solid transparent';
    }
    if (stat) {
        stat.textContent  = on ? '✓ สวมถุงมือยางแล้ว' : 'สวมถุงมือยาง';
        stat.style.color  = on ? '#4ade80' : '#94a3b8';
        stat.style.fontWeight = on ? '700' : '600';
    }
    if (lbl) lbl.classList.toggle('ppe-on', on);
}

// ══════════════════════════════════════════
// EYE WARNING SYSTEM (Step 3 — สมบูรณ์)
// ══════════════════════════════════════════

// แผนที่ hazard เฉพาะสาร → ข้อความเตือนดวงตา (ครอบคลุมทุก reaction type)
const PPE_EYE_HAZARDS = {
    // reaction_type → { warn, severity: 1=mild, 2=serious, 3=critical }
    metal_water:        { warn: 'ควัน NaOH/KOH กัดเยื่อบุตาถาวร + เปลวไฟ H₂ สร้างแสงจ้า!',         severity: 3 },
    combustion:         { warn: 'แสงจากการเผาไหม้ (โดยเฉพาะ Mg) รุนแรงพอที่จะทำให้ตาบอดชั่วคราว',   severity: 3 },
    acid_metal:         { warn: 'กรดและ H₂ ที่เกิดขึ้นอาจกระเด็นเข้าตา — กรดกัดกร่อนแม้ปริมาณน้อย', severity: 2 },
    neutralization:     { warn: 'กรด/ด่างแก่กัดกร่อนตารุนแรง — HCl, NaOH, H₂SO₄ อันตรายมาก',       severity: 2 },
    redox:              { warn: 'ปฏิกิริยารีดอกซ์อาจเกิดก๊าซพิษหรือของเหลวกระเด็น',                   severity: 2 },
    decomposition:      { warn: 'การสลายตัวอาจปล่อย O₂ หรือก๊าซที่ระคายเคือง',                        severity: 1 },
    precipitation:      { warn: 'ไอออนโลหะหนักบางชนิด (Pb²⁺, Ag⁺) เป็นพิษต่อตา',                    severity: 2 },
    acid_carbonate:     { warn: 'กรดที่ใช้อาจกระเด็น — ฟองก๊าซ CO₂ ไม่อันตราย แต่กรดตัวทำปฏิกิริยาต้องระวัง', severity: 1 },
    complex_formation:  { warn: 'สาร ligand หลายชนิดระคายเคืองดวงตา เช่น NH₃, CN⁻',                  severity: 2 },
};

let _eyeWarnActive = false;
let _eyeWarnTimer  = null;

function showEyeWarning(message, severity = 2, duration = 7000) {
    const overlay = document.getElementById('eyeWarnOverlay');
    const banner  = document.getElementById('eyeWarnBanner');
    const msgEl   = document.getElementById('eyeWarnMsg');
    if (!overlay || !banner) return;

    _eyeWarnActive = true;
    if (_eyeWarnTimer) clearTimeout(_eyeWarnTimer);

    // ความรุนแรง → สีขอบ
    const borderColors = { 1:'#f59e0b', 2:'#ef4444', 3:'#dc2626' };
    const bgColors     = { 1:'rgba(245,158,11,.15)', 2:'rgba(239,68,68,.18)', 3:'rgba(220,38,38,.25)' };
    overlay.style.background = bgColors[severity] || bgColors[2];
    overlay.style.borderColor = borderColors[severity] || borderColors[2];
    banner.style.borderColor  = borderColors[severity] || borderColors[2];

    if (msgEl) msgEl.textContent = message;
    overlay.style.display = 'block';
    banner.style.display  = 'block';
    banner.style.animation = 'eyeBannerIn .35s ease both';

    // shake screen เฉพาะ severity 3
    if (severity >= 3) {
        document.body.classList.add('shake-active');
        setTimeout(() => document.body.classList.remove('shake-active'), 600);
    }

    _eyeWarnTimer = setTimeout(() => {
        if (!document.getElementById('chkGoggles')?.checked) {
            // ยังไม่ได้สวม — fade แต่ไม่ซ่อน
            overlay.style.opacity = '0.4';
        } else {
            overlay.style.display = 'none';
            banner.style.display  = 'none';
        }
        _eyeWarnActive = false;
    }, duration);
}

function checkEyeSafety(rxn) {
    const chkGoggles = document.getElementById('chkGoggles');
    if (!chkGoggles || chkGoggles.checked) return false; // สวมแล้ว — ปลอดภัย

    const typeKey = (rxn.reaction_type || '').toLowerCase();

    // 1. ตรวจ REAL_REACTIONS รายชนิด (แม่นยำที่สุด)
    const realRxn = REAL_REACTIONS.find(r =>
        r.type === typeKey && r.hazard_eye
    );
    if (realRxn) {
        showEyeWarning(realRxn.eye_warn, 2);
        return true;
    }

    // 2. ตรวจ PPE_EYE_HAZARDS ตาม reaction_type
    const typeHazard = PPE_EYE_HAZARDS[typeKey];
    if (typeHazard) {
        showEyeWarning(typeHazard.warn, typeHazard.severity);
        return true;
    }

    // 3. ตรวจ hazard_level จากสารที่ทำปฏิกิริยา (ค่าจาก DB)
    const hazardLv = parseInt(rxn.hazard_level || rxn.toxicity_bonus || 0);
    if (hazardLv >= 3) {
        const know = REACTION_KNOWLEDGE[typeKey] || {};
        showEyeWarning(`สารอันตราย GHS ${hazardLv}/4 — ${know.safety || 'ต้องสวมแว่นตานิรภัยทุกครั้ง'}`, Math.min(3, hazardLv - 1));
        return true;
    }

    // 4. ตรวจก๊าซอันตราย
    const dangerGases = ['HCl','Cl₂','Cl2','NO₂','NO2','SO₂','SO2','NH₃','NH3','HF','F₂'];
    if (rxn.result_gas && dangerGases.some(g => rxn.result_gas.includes(g))) {
        showEyeWarning(`ก๊าซ ${rxn.result_gas} ที่เกิดขึ้นระคายเคืองดวงตาและทางเดินหายใจ`, 2);
        return true;
    }

    return false;
}

// ══════════════════════════════════════════
// 5. REACTION RESULT CARD
// ══════════════════════════════════════════
let rxnCardTimer = null;
let rxnCardEl = null;

function showReactionCard(rxn, c1name, c2name) {
    // ลบ card เดิม
    if (rxnCardEl) { rxnCardEl.remove(); rxnCardEl = null; }
    if (rxnCardTimer) { clearTimeout(rxnCardTimer); rxnCardTimer = null; }

    const typeKey  = rxn.reaction_type || '';
    const know     = REACTION_KNOWLEDGE[typeKey] || {};
    const deltaT   = parseFloat(rxn.temperature_change) || 0;

    // type badge color
    const typeColors = {
        neutralization:'#22d3ee', precipitation:'#fcd34d', redox:'#f472b6',
        acid_metal:'#fb923c', metal_water:'#ef4444', decomposition:'#a78bfa',
        acid_carbonate:'#34d399', complex_formation:'#818cf8', combustion:'#f97316',
    };
    const typeColor = typeColors[typeKey] || '#94a3b8';
    const typeName  = { neutralization:'การสะเทิน', precipitation:'การตกตะกอน', redox:'รีดอกซ์', acid_metal:'โลหะ+กรด', metal_water:'โลหะ+น้ำ', decomposition:'การสลายตัว', acid_carbonate:'กรด+คาร์บอเนต', complex_formation:'สารประกอบเชิงซ้อน', combustion:'การเผาไหม้' }[typeKey] || typeKey;

    const card = document.createElement('div');
    card.id = 'rxnResultCard';
    card.style.position = 'relative';

    let badgesHTML = '';
    if (deltaT > 0)  badgesHTML += `<span class="rxn-badge rxn-badge-exo">🔥 Exothermic +${deltaT.toFixed(1)}°C</span>`;
    if (deltaT < 0)  badgesHTML += `<span class="rxn-badge rxn-badge-endo">🧊 Endothermic ${deltaT.toFixed(1)}°C</span>`;
    if (rxn.result_gas && rxn.result_gas !== 'ไม่มีแก๊ส') badgesHTML += `<span class="rxn-badge rxn-badge-gas">💨 ก๊าซ: ${rxn.result_gas}</span>`;
    if (rxn.result_precipitate && rxn.result_precipitate !== 'ไม่มีตะกอน') badgesHTML += `<span class="rxn-badge rxn-badge-precip">🧊 ตะกอน: ${rxn.result_precipitate}</span>`;
    if (rxn.safety_warning) badgesHTML += `<span class="rxn-badge rxn-badge-ghs">⚠️ ${rxn.safety_warning.substring(0,40)}...</span>`;

    card.innerHTML = `
        <button class="rxn-close-btn" id="rxnCloseBtn">✕</button>
        <div class="rxn-card-handle"></div>
        <div class="rxn-card-inner">
            <div class="rxn-card-type" style="color:${typeColor}">${typeName}</div>
            <div class="rxn-card-title">⚗️ ${rxn.product_name || c1name + ' + ' + c2name}</div>
            <div class="rxn-eq">${(rxn.equation || `${c1name} + ${c2name} → ${rxn.product_name}`).replace(/([A-Za-z])(\d+)/g,'$1<sub>$2</sub>').replace(/↑|↓/g,'$&')}</div>
            ${rxn.observation_th ? `<div class="rxn-observe">${rxn.observation_th}</div>` : ''}
            <div class="rxn-badges">${badgesHTML}</div>
            <button class="rxn-learn-btn" id="rxnLearnBtn">📚 เรียนรู้หลักการ — ทำไมถึงเกิดขึ้น?</button>
            <div class="rxn-knowledge" id="rxnKnowledge">
                ${know.principle ? `<div class="rxn-know-row"><span class="rxn-know-label">หลักการ</span><span class="rxn-know-text">${know.principle}</span></div>` : ''}
                ${know.mechanism ? `<div class="rxn-know-row"><span class="rxn-know-label">กลไก</span><span class="rxn-know-text">${know.mechanism}</span></div>` : ''}
                ${know.color_change ? `<div class="rxn-know-row"><span class="rxn-know-label">การสังเกต</span><span class="rxn-know-text">${know.color_change}</span></div>` : ''}
                ${know.safety ? `<div class="rxn-know-row"><span class="rxn-know-label">ความปลอดภัย</span><span class="rxn-know-text" style="color:#fca5a5">${know.safety}</span></div>` : ''}
                ${know.source ? `<div class="rxn-know-row"><span class="rxn-know-src">📚 อ้างอิง: ${know.source}</span></div>` : ''}
            </div>
            <div class="rxn-dismiss-bar" id="rxnDismissBar"></div>
        </div>`;

    document.body.appendChild(card);
    rxnCardEl = card;

    // Events
    document.getElementById('rxnCloseBtn').addEventListener('click', dismissCard);
    document.getElementById('rxnLearnBtn').addEventListener('click', () => {
        const k = document.getElementById('rxnKnowledge');
        k.classList.toggle('open');
        document.getElementById('rxnLearnBtn').textContent = k.classList.contains('open') ? '▲ ซ่อนหลักการ' : '📚 เรียนรู้หลักการ — ทำไมถึงเกิดขึ้น?';
    });

    // Swipe down to dismiss
    let touchY = 0;
    card.addEventListener('touchstart', e => { touchY = e.touches[0].clientY; });
    card.addEventListener('touchend',   e => { if (e.changedTouches[0].clientY - touchY > 60) dismissCard(); });

    // Auto dismiss bar animation
    const bar = document.getElementById('rxnDismissBar');
    const DURATION = 12000;
    if (bar) {
        bar.style.transition = `width ${DURATION}ms linear`;
        requestAnimationFrame(() => { bar.style.width = '0%'; });
    }
    rxnCardTimer = setTimeout(dismissCard, DURATION);
}

function dismissCard() {
    if (!rxnCardEl) return;
    rxnCardEl.classList.add('dismissing');
    setTimeout(() => { if (rxnCardEl) { rxnCardEl.remove(); rxnCardEl = null; } }, 300);
    if (rxnCardTimer) { clearTimeout(rxnCardTimer); rxnCardTimer = null; }
}

// ══════════════════════════════════════════
// 6. ROOM TEMPERATURE SYSTEM
// ══════════════════════════════════════════

// ══════════════════════════════════════════════════════════════
// STEP 4 — ROOM TEMPERATURE + STOPWATCH + SLOW REACTIONS
// ══════════════════════════════════════════════════════════════

// ── Inject Step 4 styles ─────────────────────────────────────
(function injectStep4Styles() {
    if (document.getElementById('step4Styles')) return;
    const s = document.createElement('style');
    s.id = 'step4Styles';
    s.textContent = `
/* AC/Heater button active states */
#btnAC.ac-active {
    background:rgba(56,189,248,0.3)!important;
    border-color:#38bdf8!important;
    box-shadow:0 0 14px rgba(56,189,248,0.35);
    color:#7dd3fc!important;
}
#btnHeater2.heat-active {
    background:rgba(251,146,60,0.3)!important;
    border-color:#fb923c!important;
    box-shadow:0 0 14px rgba(251,146,60,0.35);
    color:#fdba74!important;
}
/* Ambient room effect overlay */
#roomAmbientOverlay {
    position:fixed;inset:0;pointer-events:none;z-index:5;
    transition:background 1.5s ease, opacity 1.2s;
    opacity:0;
}
#roomAmbientOverlay.cold  { background:radial-gradient(ellipse at top,rgba(56,189,248,0.06),transparent 70%); opacity:1; }
#roomAmbientOverlay.warm  { background:radial-gradient(ellipse at top,rgba(251,146,60,0.07),transparent 70%); opacity:1; }
/* Timer styles */
#timerDisplay.running { color:#22c55e; }
#timerDisplay.fast    { color:#a855f7;animation:timerPulse .8s ease-in-out infinite alternate; }
@keyframes timerPulse { from{opacity:1} to{opacity:.65} }
/* Speed button */
#btnTimeSpeed.fast { background:rgba(168,85,247,0.25)!important;border-color:rgba(168,85,247,.5)!important; }
/* Slow reaction notification */
@keyframes slowRxnIn { from{transform:translateX(120%);opacity:0} to{transform:translateX(0);opacity:1} }
@keyframes slowRxnOut { to{transform:translateX(120%);opacity:0} }
.slow-rxn-toast {
    position:fixed;bottom:80px;right:16px;z-index:7500;
    background:#1e293b;border:1px solid #334155;border-left:4px solid;
    border-radius:10px;padding:12px 14px;max-width:280px;
    font-family:'Prompt';font-size:.78rem;color:#cbd5e1;line-height:1.5;
    box-shadow:0 6px 24px rgba(0,0,0,.5);
    animation:slowRxnIn .4s cubic-bezier(0.175,0.885,0.32,1.275) both;
}
.slow-rxn-toast.out { animation:slowRxnOut .3s ease forwards; }
.srt-title { font-size:.82rem;font-weight:700;margin-bottom:4px; }
.srt-obs   { font-size:.74rem;color:#94a3b8;margin-top:4px; }
/* Room temp panel mini-graph */
#roomTempGraph {
    width:100%;height:28px;margin-top:4px;
    background:#0f172a;border-radius:4px;border:1px solid #1e293b;
    overflow:hidden;position:relative;
}
.rt-graph-line { position:absolute;bottom:0;width:3px;border-radius:1px 1px 0 0;transition:height .5s,background .5s; }
`;
    document.head.appendChild(s);

    // Ambient overlay
    if (!document.getElementById('roomAmbientOverlay')) {
        document.body.insertAdjacentHTML('afterbegin', '<div id="roomAmbientOverlay"></div>');
    }
})();

// ── Room Temperature mini-graph (sparkline) ──────────────────
const ROOM_HISTORY   = new Array(20).fill(25);
let   roomHistoryIdx = 0;

function renderRoomGraph(roomTemp) {
    const graph = document.getElementById('roomTempGraph');
    if (!graph) return;
    ROOM_HISTORY[roomHistoryIdx % 20] = roomTemp;
    roomHistoryIdx++;
    const min = Math.min(...ROOM_HISTORY) - 1;
    const max = Math.max(...ROOM_HISTORY) + 1;
    const range = max - min || 1;
    const bars  = graph.children;
    // lazy-create bars
    if (bars.length === 0) {
        graph.innerHTML = Array.from({length:20}, (_,i) =>
            `<div class="rt-graph-line" style="left:${i*5}%;width:4%"></div>`).join('');
    }
    Array.from(graph.children).forEach((bar, i) => {
        const val = ROOM_HISTORY[(roomHistoryIdx + i) % 20];
        const pct = ((val - min) / range) * 100;
        bar.style.height = Math.max(8, pct) + '%';
        bar.style.background = val < 22 ? '#38bdf8' : val > 28 ? '#fb923c' : '#334155';
    });
}

// ── Room Temperature System (อัปเกรด) ────────────────────────
function initRoomTempSystem(app) {
    if (app._roomTempPatched) return;
    app._roomTempPatched = true;

    let roomTemp    = 25;
    let acActive    = false;
    let heaterActive= false;
    let roomTempRAF = null;
    const AC_RATE   = -2.0;    // °C / นาที จริง (เร่งเวลาด้วย timeSpeed)
    const HEAT_RATE = +3.0;
    const AMBIENT   = document.getElementById('roomAmbientOverlay');

    window._labRoomTemp = 25;

    const btnAC      = document.getElementById('btnAC');
    const btnHeater2 = document.getElementById('btnHeater2');
    const roomDisp   = document.getElementById('roomTempDisplay');

    // สร้าง mini-graph placeholder
    if (roomDisp) {
        const g = document.createElement('div');
        g.id = 'roomTempGraph';
        roomDisp.parentNode?.insertBefore(g, roomDisp.nextSibling);
    }

    function setAC(on) {
        acActive = on;
        if (on) heaterActive = false;
        btnAC?.classList.toggle('ac-active', on);
        btnHeater2?.classList.toggle('heat-active', false);
        if (AMBIENT) AMBIENT.className = on ? 'cold' : '';
        updateRoomDisplay();
    }
    function setHeater(on) {
        heaterActive = on;
        if (on) acActive = false;
        btnHeater2?.classList.toggle('heat-active', on);
        btnAC?.classList.toggle('ac-active', false);
        if (AMBIENT) AMBIENT.className = on ? 'warm' : '';
        updateRoomDisplay();
    }

    btnAC?.addEventListener('click',      () => setAC(!acActive));
    btnHeater2?.addEventListener('click', () => setHeater(!heaterActive));

    function updateRoomDisplay() {
        window._labRoomTemp = roomTemp;
        if (!roomDisp) return;
        const icon = acActive ? '❄️' : (heaterActive ? '🔆' : '🌡️');
        const clr  = acActive ? '#38bdf8' : (heaterActive ? '#fb923c' : '#64748b');
        roomDisp.textContent = `${icon} ห้อง: ${roomTemp.toFixed(1)}°C`;
        roomDisp.style.color = clr;
        renderRoomGraph(roomTemp);
    }

    // patch updatePhysics (guard ป้องกัน double-patch)
    const origPhysics = app.updatePhysics.bind(app);
    app.updatePhysics = function(dt) {
        const dtMin = (dt / 1000 / 60) * timeSpeed;  // delta นาที ปรับตาม speed

        // อัปเดต room temp
        if (acActive)      roomTemp = Math.max(15, roomTemp + AC_RATE   * dtMin);
        else if (heaterActive) roomTemp = Math.min(45, roomTemp + HEAT_RATE * dtMin);
        else                   roomTemp += (25 - roomTemp) * 0.008 * timeSpeed;  // drift กลับ 25°C

        // ambient cooling: ห้องเย็น → beaker เย็นตาม
        if (!this.state.isHeating && this.state.temperature > roomTemp + 2) {
            const diff = this.state.temperature - roomTemp;
            this.state.targetTemp = Math.max(roomTemp, this.state.targetTemp - diff * 0.003 * timeSpeed);
        }
        // ambient heating: ห้องร้อน → beaker อุ่นขึ้นเล็กน้อย
        if (!this.state.isHeating && roomTemp > 32 && this.state.targetTemp < roomTemp - 3) {
            this.state.targetTemp = Math.min(roomTemp, this.state.targetTemp + 0.015 * timeSpeed);
        }

        checkSlowReactions(this);
        updateRoomDisplay();
        origPhysics.call(this, dt);
    };

    updateRoomDisplay();
}


// ── Stopwatch + Time Speed (อัปเกรด) ────────────────────────
let timerRunning   = false;
let timerElapsed   = 0;      // ms สะสม
let timerLastTick  = 0;
let timeSpeed      = 1;      // multiplier ทั้งระบบ
let timerRAF       = null;
let speedIdx       = 0;
const SPEEDS       = [1, 2, 5, 10];

// expose ให้ slow reactions และ room temp ใช้
window._getTimerElapsed  = () => timerElapsed;
window._getTimeSpeed     = () => timeSpeed;
window._isTimerRunning   = () => timerRunning;

function initTimerSystem() {
    const display   = document.getElementById('timerDisplay');
    const btnToggle = document.getElementById('btnTimerToggle');
    const btnReset  = document.getElementById('btnTimerReset');
    const btnSpeed  = document.getElementById('btnTimeSpeed');
    if (!display) return;

    function tick(ts) {
        if (!timerRunning) return;
        const wall = ts - timerLastTick;       // ms จริง
        timerElapsed += wall * timeSpeed;       // ms เสมือน (ปรับ speed)
        timerLastTick  = ts;
        updateDisplay();
        timerRAF = requestAnimationFrame(tick);
    }

    function fmt(ms) {
        const total = Math.floor(ms / 1000);
        const h = Math.floor(total / 3600);
        const m = Math.floor((total % 3600) / 60);
        const s = total % 60;
        return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
    }

    function updateDisplay() {
        display.textContent = fmt(timerElapsed);
        // สีเปลี่ยนตาม speed
        if (!timerRunning)      display.className = '';
        else if (timeSpeed >= 5) display.className = 'fast';
        else                     display.className = 'running';
    }

    // ── Start / Pause ──
    btnToggle?.addEventListener('click', () => {
        timerRunning = !timerRunning;
        if (timerRunning) {
            timerLastTick = performance.now();
            timerRAF = requestAnimationFrame(tick);
            btnToggle.textContent = '⏸ Pause';
            btnToggle.style.background = 'rgba(34,197,94,0.3)';
            btnToggle.style.borderColor = 'rgba(34,197,94,0.6)';
        } else {
            if (timerRAF) cancelAnimationFrame(timerRAF);
            btnToggle.textContent = '▶ Start';
            btnToggle.style.background = 'rgba(34,197,94,0.2)';
            btnToggle.style.borderColor = 'rgba(34,197,94,0.4)';
        }
        updateDisplay();
    });

    // ── Reset ──
    btnReset?.addEventListener('click', () => {
        timerRunning = false;
        timerElapsed = 0;
        slowReactionChecked = {};   // reset slow reaction flags ด้วย
        if (timerRAF) cancelAnimationFrame(timerRAF);
        if (btnToggle) { btnToggle.textContent = '▶ Start'; btnToggle.style.background='rgba(34,197,94,0.2)'; }
        updateDisplay();
    });

    // ── Speed ──
    btnSpeed?.addEventListener('click', () => {
        speedIdx  = (speedIdx + 1) % SPEEDS.length;
        timeSpeed = SPEEDS[speedIdx];
        btnSpeed.textContent = timeSpeed + '×';
        btnSpeed.classList.toggle('fast', timeSpeed > 1);
        btnSpeed.style.color = timeSpeed > 1 ? '#a855f7' : '#64748b';
        updateDisplay();
    });

    updateDisplay();
}


// ── Slow Reactions (สารทิ้งไว้นานๆ — อัปเกรด) ───────────────
let slowReactionChecked = {};   // key → true ป้องกัน trigger ซ้ำ

// คลัง slow reactions — ใช้เวลาจริง (วินาทีเสมือน)
const SLOW_REACTIONS = [
    {
        match: n => n.includes('เหล็ก') || n.toLowerCase().includes('iron') || n.toLowerCase().includes('fe'),
        needWater: true, minTemp: 15, maxTemp: 100, triggerSec: 20,
        rxn: (chem) => ({
            product_name: 'สนิมเหล็ก Fe(OH)₃',
            reaction_type: 'redox',
            equation: '4Fe + 3O₂ + 6H₂O → 4Fe(OH)₃',
            temperature_change: 1,
            observation_th: 'ผิวโลหะค่อยๆ เปลี่ยนเป็นสีน้ำตาลแดงอมส้ม (Fe₂O₃·nH₂O) สังเกตได้หลังทิ้งไว้ในสภาวะชื้น',
            result_gas: null, result_precipitate: 'Fe(OH)₃ สีน้ำตาลแดง', safety_warning: null,
            result_ph: null, result_color: '#8B4513',
        }),
        color: '#fb923c', label: 'เหล็กเกิดสนิม',
        obs: 'สังเกต: ผิวโลหะค่อยๆ เปลี่ยนสีน้ำตาลแดง (ออกไซด์ Fe₂O₃)',
        ref: 'Evans, U.R. (1960) The Corrosion and Oxidation of Metals',
    },
    {
        match: n => n.includes('ทองแดง') || n.toLowerCase().includes('copper') || n.toLowerCase().includes('cu)'),
        needWater: false, minTemp: 20, maxTemp: 100, triggerSec: 40,
        rxn: (chem) => ({
            product_name: 'Copper Patina (CuCO₃·Cu(OH)₂)',
            reaction_type: 'redox',
            equation: '2Cu + O₂ → 2CuO → Cu₂(OH)₂CO₃ (Patina)',
            temperature_change: 0,
            observation_th: 'ผิวทองแดงค่อยๆ หมองด้า → เปลี่ยนเป็นสีเขียว (Patina) ปกป้องโลหะจากการกัดกร่อนต่อไป',
            result_gas: null, result_precipitate: 'Patina สีเขียว', safety_warning: null,
            result_ph: null, result_color: '#22c55e',
        }),
        color: '#34d399', label: 'ทองแดงเกิด Patina',
        obs: 'สังเกต: ผิวทองแดงเปลี่ยนจากสีทองแดงแดงเป็นสีน้ำตาล → เขียว (Patina)',
        ref: 'Fitzgerald, K.P. et al. (1998) Studies in Conservation, 43(1)',
    },
    {
        match: n => n.includes('น้ำตาล') || n.toLowerCase().includes('sucrose') || n.toLowerCase().includes('glucose'),
        needWater: true, minTemp: 28, maxTemp: 100, triggerSec: 30,
        rxn: (chem) => ({
            product_name: 'เอทานอล + CO₂ (Fermentation)',
            reaction_type: 'decomposition',
            equation: 'C₆H₁₂O₆ →(yeast) 2C₂H₅OH + 2CO₂↑',
            temperature_change: 2,
            observation_th: 'กลิ่นแอลกอฮอล์อ่อนๆ ฟองก๊าซ CO₂ เล็กน้อย เกิดจากยีสต์ธรรมชาติที่มีในอากาศ',
            result_gas: 'CO₂ (ปริมาณน้อย)', result_precipitate: null, safety_warning: null,
        }),
        color: '#fcd34d', label: 'น้ำตาลหมัก',
        obs: 'สังเกต: กลิ่นแอลกอฮอล์อ่อน ฟองเล็กๆ — ยีสต์ธรรมชาติเริ่มหมักน้ำตาล',
        ref: 'Pasteur, L. (1857) Mémoire sur la fermentation alcoolique',
    },
    {
        match: n => n.toLowerCase().includes('แมกนีเซียม') || n.toLowerCase().includes('magnesium') || n.includes('Mg)'),
        needWater: true, minTemp: 15, maxTemp: 100, triggerSec: 50,
        rxn: (chem) => ({
            product_name: 'Magnesium Hydroxide Mg(OH)₂',
            reaction_type: 'metal_water',
            equation: 'Mg + 2H₂O → Mg(OH)₂ + H₂↑ (ช้ามาก ที่อุณหภูมิห้อง)',
            temperature_change: 3,
            observation_th: 'เกิดฟิล์มขาวบางๆ บนผิวแมกนีเซียม ก๊าซ H₂ ออกมาช้าๆ',
            result_gas: 'H₂ (ปริมาณน้อย)', result_precipitate: 'Mg(OH)₂ สีขาว', safety_warning: null,
        }),
        color: '#a78bfa', label: 'Mg ทำปฏิกิริยากับน้ำ',
        obs: 'สังเกต: ฟิล์มขาวบางๆ เกิดบนผิว Mg ฟองเล็กมากจาก H₂',
        ref: 'CRC Handbook of Chemistry 97th Ed., §5-5',
    },
    {
        match: n => n.toLowerCase().includes('เงิน') || n.toLowerCase().includes('silver') || n.includes('Ag)'),
        needWater: false, minTemp: 15, maxTemp: 100, triggerSec: 35,
        rxn: (chem) => ({
            product_name: 'Silver Sulfide Ag₂S (สีดำ)',
            reaction_type: 'redox',
            equation: '4Ag + 2H₂S + O₂ → 2Ag₂S + 2H₂O',
            temperature_change: 0,
            observation_th: 'ผิวเงินหมองดำจาก Ag₂S ที่ทำปฏิกิริยากับ H₂S ในอากาศ',
            result_gas: null, result_precipitate: 'Ag₂S สีดำ', safety_warning: null,
            result_color: '#1a1a1a',
        }),
        color: '#94a3b8', label: 'เงินหมองดำ (Tarnish)',
        obs: 'สังเกต: ผิวเงินเปลี่ยนเป็นสีน้ำตาลเข้ม → ดำ (Ag₂S)',
        ref: 'Selwyn, L. (2004) Metals and Corrosion: A Handbook for the Conservation Professional',
    },
];

function _showSlowRxnToast(rxnDef, chemName) {
    // ลบ toast เดิมถ้ามี
    document.querySelectorAll('.slow-rxn-toast').forEach(t => {
        t.classList.add('out');
        setTimeout(() => t.remove(), 320);
    });

    const toast = document.createElement('div');
    toast.className = 'slow-rxn-toast';
    toast.style.borderLeftColor = rxnDef.color;
    toast.innerHTML = `
        <div class="srt-title" style="color:${rxnDef.color}">⏱️ ${rxnDef.label}</div>
        <div>${rxnDef.obs}</div>
        <div class="srt-obs">📚 ${rxnDef.ref}</div>
    `;
    document.body.appendChild(toast);
    setTimeout(() => { toast.classList.add('out'); setTimeout(() => toast.remove(), 320); }, 9000);
}

function checkSlowReactions(app) {
    if (!timerRunning || app.state.contents.length < 1) return;

    // elapsed วินาทีเสมือน (ปรับด้วย timeSpeed แล้วใน timerElapsed)
    const elapsedSec = timerElapsed / 1000;

    app.state.contents.forEach(chem => {
        const chemName = chem.name || '';
        const hasWater = app.state.contents.some(c =>
            (c.name || '').includes('น้ำ') || (c.name || '').toLowerCase().includes('water') || c.formula === 'H₂O'
        ) || app.state.volume > 0;

        SLOW_REACTIONS.forEach(rxnDef => {
            const key = `slow_${chem.id}_${rxnDef.label}`;
            if (slowReactionChecked[key]) return;
            if (!rxnDef.match(chemName)) return;
            if (rxnDef.needWater && !hasWater) return;
            if (app.state.temperature < rxnDef.minTemp) return;
            if (elapsedSec < rxnDef.triggerSec) return;

            // Trigger!
            slowReactionChecked[key] = true;
            const rxn = rxnDef.rxn(chem);

            app.log(`⏱️ <span style="color:${rxnDef.color};font-weight:600">${rxnDef.label} — ${rxn.observation_th.substring(0,60)}...</span>`);
            _showSlowRxnToast(rxnDef, chemName);
            showReactionCard(rxn, chemName, 'O₂ / H₂O / อากาศ');

            // เพิ่มสีใน beaker (ถ้ามี result_color)
            if (rxn.result_color && app.state.contents.length > 0) {
                // แค่ hint สีในบีกเกอร์
                const rgb = rxn.result_color;
                // ปล่อยให้ calculateLiquidColor ทำงานเอง
            }
        });
    });
}

// ══════════════════════════════════════════
// 9. HOVER TOOLTIP — upgraded to initHoverTooltip (see Step 2)
// ══════════════════════════════════════════
function initProductTooltip(app) {
    // delegate ไปยัง initHoverTooltip ที่ upgrade แล้วใน Step 2
    initHoverTooltip(app);
}

// ══════════════════════════════════════════
// 10. TUTORIAL SYSTEM (Step 3 — ใช้ overlay ใหม่)
// ══════════════════════════════════════════
function initTutorial() {
    const TUT_KEY = 'lab_tutorial_v2';
    let tutStep = 0;

    function openTutorial() {
        const overlay = document.getElementById('tutorialOverlay');
        if (!overlay) return;
        overlay.classList.add('open');
        buildDots();
        goTo(0);
    }

    function closeTutorial(save = true) {
        const overlay = document.getElementById('tutorialOverlay');
        if (overlay) overlay.classList.remove('open');
        if (save) { try { localStorage.setItem(TUT_KEY,'1'); } catch(e){} }
    }

    function buildDots() {
        const dots  = document.getElementById('tutDots');
        const steps = document.querySelectorAll('#tutBox .tut-step');
        if (!dots) return;
        dots.innerHTML = Array.from({length:steps.length}, (_,i) =>
            `<span class="tut-dot${i===0?' active':''}"></span>`).join('');
    }

    function goTo(step) {
        const steps = document.querySelectorAll('#tutBox .tut-step');
        if (step >= steps.length) { closeTutorial(); return; }
        steps.forEach((s,i) => s.classList.toggle('active', i === step));
        document.querySelectorAll('#tutDots .tut-dot').forEach((d,i) => d.classList.toggle('active', i === step));
        const btn = document.getElementById('tutNext');
        if (btn) btn.textContent = step >= steps.length - 1 ? 'เริ่มทดลอง! 🧪' : 'ถัดไป →';
        tutStep = step;
    }

    document.getElementById('tutNext')?.addEventListener('click', () => goTo(tutStep + 1));
    document.getElementById('tutSkip')?.addEventListener('click', () => closeTutorial());
    document.getElementById('tutClose')?.addEventListener('click', () => closeTutorial());

    // เปิดอัตโนมัติครั้งแรก
    try {
        if (!localStorage.getItem(TUT_KEY)) openTutorial();
    } catch(e) {
        openTutorial(); // localStorage ไม่ available → แสดงเสมอ
    }

    // expose เพื่อให้เรียกจากข้างนอก (เช่น ปุ่ม Help)
    window._openLabTutorial = openTutorial;
}

// ══════════════════════════════════════════
// 11. PATCH processReaction TO SHOW CARD
// ══════════════════════════════════════════
function patchProcessReaction(app) {
    if (app._step3Patched) return;
    app._step3Patched = true;
    const orig = app.processReaction.bind(app);
    app.processReaction = function(rxn, c1, c2, idx1, idx2) {
        // 1. ตรวจ eye safety — ก่อน reaction เสมอ
        checkEyeSafety(rxn);
        // 2. run original (visual effects, HP damage, etc.)
        orig.call(this, rxn, c1, c2, idx1, idx2);
        // 3. แสดง Reaction Result Card
        showReactionCard(rxn, c1 ? c1.name : '?', c2 ? c2.name : '?');
    };
}

// ══════════════════════════════════════════
// BOOT
// ══════════════════════════════════════════

// ══════════════════════════════════════════════════════════════
// STEP 5 — EQUIPMENT TRAY + ALCOHOL LAMP + FLAME TEST
// ══════════════════════════════════════════════════════════════

const FLAME_TEST_COLORS = {
    'na': { color:'#FFD700', rgb:'255,215,0',   name:'Na — เหลืองส้ม',    wavelength:'589 nm' },
    'k':  { color:'#CC44FF', rgb:'204,68,255',  name:'K — ม่วง-ชมพู',    wavelength:'766 nm' },
    'li': { color:'#FF3300', rgb:'255,51,0',    name:'Li — แดงเข้ม',       wavelength:'670 nm' },
    'cu': { color:'#00DD44', rgb:'0,221,68',    name:'Cu — เขียวน้ำเงิน',  wavelength:'510 nm' },
    'sr': { color:'#FF2200', rgb:'255,34,0',    name:'Sr — แดงสด',         wavelength:'640 nm' },
    'ba': { color:'#88FF44', rgb:'136,255,68',  name:'Ba — เขียวเหลือง',   wavelength:'513 nm' },
    'ca': { color:'#FF6600', rgb:'255,102,0',   name:'Ca — ส้มแดง',        wavelength:'622 nm' },
    'mg': { color:'#FFFFFF', rgb:'255,255,255', name:'Mg — ขาวจ้า',        wavelength:'285 nm' },
    'fe': { color:'#FFB300', rgb:'255,179,0',   name:'Fe — เหลืองทอง',    wavelength:'372 nm' },
    'zn': { color:'#88CCFF', rgb:'136,204,255', name:'Zn — น้ำเงินเขียว',  wavelength:'472 nm' },
};

function getFlameTestKey(chemName) {
    const n = (chemName || '').toLowerCase();
    if (n.includes('sodium') || n.includes('โซเดียม') || n.includes('naoh') || n.includes('nacl') || n.includes('na\u2082')) return 'na';
    if (n.includes('potassium') || n.includes('โพแทสเซียม') || n.includes('koh') || n.includes('kcl') || n.includes('kmno')) return 'k';
    if (n.includes('lithium') || n.includes('ลิเธียม') || n.includes('licl')) return 'li';
    if (n.includes('copper') || n.includes('ทองแดง') || n.includes('cuso') || n.includes('cu(')) return 'cu';
    if (n.includes('strontium') || n.includes('สตรอน')) return 'sr';
    if (n.includes('barium') || n.includes('แบเรียม')) return 'ba';
    if (n.includes('calcium') || n.includes('แคลเซียม') || n.includes('cacl') || n.includes('ca(oh)')) return 'ca';
    if (n.includes('magnesium') || n.includes('แมกนีเซียม') || n.includes('mg(') || n.includes('mgcl')) return 'mg';
    if (n.includes('iron') || n.includes('เหล็ก') || n.includes('fecl') || n.includes('feso') || n.includes('fe(')) return 'fe';
    if (n.includes('zinc') || n.includes('สังกะสี') || n.includes('znso') || n.includes('zncl')) return 'zn';
    return null;
}

let _alcLampLit    = false;
let _activeBurner  = null;
let _fumeHoodActive= false;
let _flameTestTimer= null;

function initEquipmentSystem(app) {
    if (window._equipInitDone) return;
    window._equipInitDone = true;

    // Event delegation บน tray — รองรับ slots ที่ inject ทีหลังด้วย
    const tray = document.getElementById('equipTray');
    if (tray) {
        tray.addEventListener('click', e => {
            const slot = e.target.closest('.equip-slot');
            if (slot && slot.dataset.equip) {
                _handleEquipClick(slot.dataset.equip, slot, app);
            }
        });
    }
    // fallback: per-slot สำหรับ slots ที่มีอยู่แล้ว
    document.querySelectorAll('.equip-slot').forEach(slot => {
        slot.addEventListener('click', () => _handleEquipClick(slot.dataset.equip, slot, app));
    });

    const closeTray = document.getElementById('btnCloseTray');
    if (closeTray && tray) {
        let open = true;
        closeTray.addEventListener('click', () => {
            open = !open;
            tray.style.transition = 'max-height .3s ease';
            tray.style.maxHeight  = open ? '80px' : '0';
            tray.style.overflow   = open ? '' : 'hidden';
            tray.style.padding    = open ? '' : '0';
            closeTray.textContent = open ? '\u25bc' : '\u25b2';
        });
    }

    const alcCap = document.getElementById('alcCap');
    if (alcCap) alcCap.addEventListener('click', e => { e.stopPropagation(); _toggleAlcLamp(app); });
}

function _handleEquipClick(equip, slot, app) {
    switch (equip) {
        case 'alcohol_lamp': _placeAlcLamp(slot, app); break;
        case 'bunsen':       _placeBunsen(slot, app);  break;
        case 'hot_plate':    _placeHotPlate(slot, app); break;
        case 'fume_hood':    _toggleFumeHood(slot, app); break;
        case 'ph_meter':     document.getElementById('btnMeasurePH')?.click(); break;
        case 'gas_syringe':  _showGasSyringe(app); break;
        case 'iodine_clock':
            if (typeof openIodineClock === 'function') openIodineClock(); break;
        case 'burette_titr':
            if (typeof openBuretteTitr === 'function') openBuretteTitr(); break;
        case 'flask':
        case 'test_tube':
        case 'evap_dish':
            app && app.log('\u{1F9EA} <span style="color:#7dd3fc">'+equip+'</span>'); break;
        default:
            app && app.log('\u{1F527} '+equip);
    }
}

function _placeAlcLamp(slot, app) {
    const lamp = document.getElementById('alcLampOnDesk');
    if (!lamp) return;
    const showing = lamp.style.display !== 'none';
    if (!showing) {
        lamp.style.display = 'block';
        slot.classList.add('active-equip');
        _activeBurner = 'alcohol_lamp';
        app.log('\ud83d\udd35 <span style="color:#60a5fa">วางตะเกียงแอลกอฮอล์ — คลิกที่ฝาจุกเพื่อจุดไฟ</span>');
    } else {
        if (_alcLampLit) _extinguishAlcLamp(app);
        lamp.style.display = 'none';
        slot.classList.remove('active-equip');
        _activeBurner = null;
    }
}

function _toggleAlcLamp(app) { _alcLampLit ? _extinguishAlcLamp(app) : _lightAlcLamp(app); }

function _lightAlcLamp(app) {
    _alcLampLit = true;
    const flame = document.getElementById('alcFlame');
    const cap   = document.getElementById('alcCap');
    if (flame) flame.style.display = 'block';
    if (cap)   { cap.style.background = '#1e3a5f'; cap.title = 'คลิกเพื่อดับไฟ'; }
    app.log('\ud83d\udd35 <span style="color:#60a5fa;font-weight:600">จุดตะเกียง — เปลวน้ำเงิน 80-250\u00b0C</span>');
    app.audio?.play('heater_on');
    app.state.targetTemp = Math.min(250, app.state.targetTemp + 35);
    app.state.isHeating  = true;
    document.body.classList.add('heating-active');
    _checkFlameTest(app);
    setTimeout(() => { app.log('\ud83d\udd25 ตรวจปฏิกิริยาที่ต้องการไฟ...'); app.checkReactions(true); }, 1500);
}

function _extinguishAlcLamp(app) {
    _alcLampLit = false;
    const flame = document.getElementById('alcFlame');
    const cap   = document.getElementById('alcCap');
    if (flame) flame.style.display = 'none';
    if (cap)   { cap.style.background = '#64748b'; cap.title = 'คลิกเพื่อจุดไฟ'; }
    _stopFlameTest();
    app.audio?.play('heater_off');
    app.state.isHeating = false;
    document.body.classList.remove('heating-active');
    app.log('\ud83d\udca8 ดับตะเกียงแอลกอฮอล์');
}

function _checkFlameTest(app) {
    let key = null;
    app.state.contents.forEach(c => { if (!key) key = getFlameTestKey(c.name); });
    if (!key && app.state.activeTool?.name) key = getFlameTestKey(app.state.activeTool.name);
    if (key && FLAME_TEST_COLORS[key]) _showFlameTestEffect(FLAME_TEST_COLORS[key], app);
}

function _showFlameTestEffect(ft, app) {
    const overlay = document.getElementById('flameColorOverlay');
    if (overlay) {
        overlay.style.display = 'block';
        overlay.style.background = 'radial-gradient(ellipse at 50% 70%,rgba(' + ft.rgb + ',0.2) 0%,transparent 65%)';
    }
    const flameDiv = document.getElementById('alcFlame');
    if (flameDiv && flameDiv.firstElementChild) {
        flameDiv.firstElementChild.style.background =
            'radial-gradient(ellipse at 50% 90%,' + ft.color + 'cc 0%,' + ft.color + '88 40%,transparent 100%)';
    }
    app.log('\ud83d\udd2c <span style="color:' + ft.color + ';font-weight:600">Flame Test: ' + ft.name + '</span> \u2014 ' + ft.wavelength);
    showReactionCard({
        product_name: 'Flame Test — ' + ft.name,
        reaction_type: 'combustion',
        equation: 'M\u207a + flame \u2192 M\u207a* \u2192 M\u207a + h\u03bd (' + ft.wavelength + ')',
        temperature_change: 5,
        observation_th: 'เปลวไฟเปลี่ยนสีเป็น ' + ft.name.split('\u2014')[1]?.trim() + ' เกิดจาก electron excitation และ emission',
        result_gas: null, result_precipitate: null,
        safety_warning: ft.color === '#FFFFFF' ? 'ห้ามมองตรงๆ เปลว Mg! อาจทำให้ตาบอดชั่วคราว' : null,
        result_color: ft.color,
    }, ft.name.split('\u2014')[0].trim() + ' ion', 'เปลว');
    if (_flameTestTimer) clearTimeout(_flameTestTimer);
    _flameTestTimer = setTimeout(_stopFlameTest, 5000);
}

function _stopFlameTest() {
    const overlay = document.getElementById('flameColorOverlay');
    if (overlay) overlay.style.display = 'none';
    const flameDiv = document.getElementById('alcFlame');
    if (flameDiv && flameDiv.firstElementChild) {
        flameDiv.firstElementChild.style.background =
            'radial-gradient(ellipse at 50% 90%,#1d4ed8 0%,#3b82f6 30%,#f59e0b 60%,transparent 100%)';
    }
}

function _placeBunsen(slot, app) {
    const on = !slot.classList.contains('active-equip');
    document.querySelectorAll('.equip-slot').forEach(s => s.classList.remove('active-equip'));
    if (on) {
        slot.classList.add('active-equip');
        _activeBurner = 'bunsen';
        app.state.targetTemp = Math.min(600, app.state.targetTemp + 80);
        app.state.isHeating  = true;
        document.body.classList.add('heating-active');
        if (app.ui?.flameFire) app.ui.flameFire.style.display = 'flex';
        app.audio?.play('heater_on');
        app.log('\ud83d\udd25 <span style="color:#f97316;font-weight:600">Bunsen Burner — เปลวน้ำเงินเข้ม 300-600\u00b0C</span>');
        setTimeout(() => { app.checkReactions(true); _checkFlameTest(app); }, 1000);
    } else {
        _activeBurner = null;
        app.state.isHeating = false;
        document.body.classList.remove('heating-active');
        if (app.ui?.flameFire) app.ui.flameFire.style.display = 'none';
        app.audio?.play('heater_off');
        _stopFlameTest();
        app.log('\ud83d\udca8 ปิด Bunsen Burner');
    }
}

function _placeHotPlate(slot, app) {
    const on = !slot.classList.contains('active-equip');
    slot.classList.toggle('active-equip', on);
    _activeBurner = on ? 'hot_plate' : null;
    app.state.isHeating = on;
    if (on) { app.state.targetTemp = 150; document.body.classList.add('heating-active'); app.log('\u26a1 <span style="color:#fbbf24">Hot Plate 150\u00b0C</span>'); }
    else    { document.body.classList.remove('heating-active'); app.log('\u26a1 ปิด Hot Plate'); }
}

function _toggleFumeHood(slot, app) {
    _fumeHoodActive = !_fumeHoodActive;
    slot.classList.toggle('fume-active', _fumeHoodActive);
    const overlay = document.getElementById('fumeHoodOverlay');
    if (overlay) overlay.style.display = _fumeHoodActive ? 'block' : 'none';
    window._labFumeHoodActive = _fumeHoodActive;
    app?.log(_fumeHoodActive
        ? '\ud83c\udfed <span style="color:#22c55e;font-weight:600">ตู้ดูดควันเปิด — ป้องกัน toxic gas 100%</span>'
        : '\ud83c\udfed ปิดตู้ดูดควัน');
}

function _showGasSyringe(app) {
    const gases = app.state.contents.filter(c => c.state === 'gas');
    app.log('\ud83d\udc89 Gas Syringe: ' + (gases.length ? gases.map(g => g.name + ' ' + (g.amount||0).toFixed(1) + ' mL').join(', ') : 'ไม่มีก๊าซ'));
}

function patchFumeHood(app) {
    if (app._fumeHoodPatched) return; app._fumeHoodPatched = true;
    const orig = app.takeDamage.bind(app);
    app.takeDamage = function(amount, reason, type) {
        if (_fumeHoodActive && type === 'poison') { app.log('\ud83c\udfed ตู้ดูดควันป้องกัน — HP ไม่ลด'); return; }
        orig.call(this, amount, reason, type);
    };
}


// ══════════════════════════════════════════════════════════════
// STEP 7 — LAB LOG + POST-LAB SUMMARY MODAL + ส่งครู
// ══════════════════════════════════════════════════════════════

// ── Inject CSS ───────────────────────────────────────────────
(function injectStep7Styles() {
    if (document.getElementById('step7Styles')) return;
    const s = document.createElement('style');
    s.id = 'step7Styles';
    s.textContent = `
/* Post-Lab Modal */
@keyframes plModalIn { from{opacity:0;transform:translateY(30px)} to{opacity:1;transform:translateY(0)} }
#postLabModal {
    position:fixed;inset:0;z-index:9200;background:rgba(0,0,0,.8);
    display:none;align-items:flex-start;justify-content:center;
    overflow-y:auto;padding:24px 12px 40px;
}
#postLabModal.open { display:flex; }
#postLabBox {
    background:#0f172a;border:1px solid #1e293b;border-radius:16px;
    width:100%;max-width:700px;animation:plModalIn .35s ease;
    font-family:'Prompt',sans-serif;
}
.plh { padding:18px 22px;border-bottom:1px solid #1e293b;display:flex;align-items:center;justify-content:space-between; }
.plh h2 { font-size:1.05rem;font-weight:700;color:#f1f5f9; }
.plh .plh-close { background:none;border:none;color:#64748b;font-size:1.2rem;cursor:pointer; }
.plb { padding:18px 22px; }

/* Score bar */
.pl-score-bar { display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap; }
.pl-score-chip { flex:1;min-width:100px;background:#1e293b;border:1px solid #334155;border-radius:10px;padding:12px 14px;text-align:center; }
.pl-score-chip .sc-val { font-size:1.4rem;font-weight:700; }
.pl-score-chip .sc-lbl { font-size:.65rem;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-top:2px; }

/* Reaction timeline table */
.pl-table { width:100%;border-collapse:collapse;font-size:.78rem;margin-bottom:18px; }
.pl-table th { text-align:left;padding:7px 10px;color:#475569;font-size:.63rem;letter-spacing:.1em;text-transform:uppercase;border-bottom:1px solid #1e293b; }
.pl-table td { padding:8px 10px;border-bottom:1px solid #0f172a;color:#94a3b8;vertical-align:top; }
.pl-table tr:hover td { background:rgba(255,255,255,.02); }
.pl-table td:first-child { font-family:'Share Tech Mono';color:#475569;font-size:.7rem;white-space:nowrap; }
.pl-table .rxn-type-dot { display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:5px;vertical-align:middle; }
.pl-dt { color:#f97316;font-size:.7rem; }
.pl-ph { color:#38bdf8;font-size:.7rem; }

/* Distribution chart */
.pl-dist { margin-bottom:18px; }
.pl-dist-title { font-size:.65rem;color:#475569;text-transform:uppercase;letter-spacing:.1em;margin-bottom:8px; }
.pl-dist-bar { display:flex;align-items:center;gap:8px;margin-bottom:5px;font-size:.75rem;color:#94a3b8; }
.pl-dist-bar .bar-label { min-width:100px;color:#64748b; }
.pl-dist-bar .bar-fill  { height:10px;border-radius:5px;min-width:4px;transition:width .5s; }
.pl-dist-bar .bar-count { color:#475569;font-size:.68rem; }

/* PPE & Safety summary */
.pl-safety { background:#0a1628;border-radius:10px;padding:12px 16px;margin-bottom:18px;display:flex;gap:16px;flex-wrap:wrap; }
.pl-safe-item { display:flex;align-items:center;gap:6px;font-size:.78rem;color:#94a3b8; }
.pl-safe-item .si-icon { font-size:1.1rem; }
.pl-safe-item .si-ok  { color:#22c55e; }
.pl-safe-item .si-bad { color:#ef4444; }

/* Action buttons */
.pl-actions { display:flex;gap:10px;flex-wrap:wrap; }
.pl-btn { padding:10px 18px;border-radius:10px;cursor:pointer;font-family:'Prompt';font-size:.82rem;font-weight:600;border:none;transition:.15s; }
.pl-btn-send  { background:#38bdf8;color:#0f172a;flex:1; }
.pl-btn-send:hover { background:#7dd3fc; }
.pl-btn-print { background:rgba(100,116,139,.2);color:#94a3b8;border:1px solid #334155; }
.pl-btn-close { background:rgba(100,116,139,.2);color:#94a3b8;border:1px solid #334155; }

/* 📋 ปุ่ม Post-Lab ใน header */
#btnPostLab {
    background:rgba(56,189,248,.1);border:1px solid rgba(56,189,248,.3);
    color:#38bdf8;border-radius:8px;padding:5px 12px;cursor:pointer;
    font-family:'Prompt';font-size:.75rem;font-weight:600;transition:.2s;
}
#btnPostLab:hover { background:rgba(56,189,248,.2); }

/* Print */
@media print {
    body * { visibility:hidden; }
    #postLabModal, #postLabModal * { visibility:visible; }
    #postLabModal { position:absolute;top:0;left:0;background:white;padding:0; }
    .pl-actions, .plh-close { display:none!important; }
    .pl-table td, .pl-table th { color:#000!important;border-color:#ccc!important; }
    #postLabBox { border:none;border-radius:0;box-shadow:none; }
}
`;
    document.head.appendChild(s);

    // inject modal HTML
    if (!document.getElementById('postLabModal')) {
        document.body.insertAdjacentHTML('beforeend', `
<div id="postLabModal">
    <div id="postLabBox">
        <div class="plh">
            <h2>📋 สรุปผลการทดลอง (Post-Lab Summary)</h2>
            <button class="plh-close" id="plClose">✕</button>
        </div>
        <div class="plb" id="plBody">
            <div style="text-align:center;color:#475569;padding:40px">กำลังสร้างรายงาน...</div>
        </div>
    </div>
</div>`);
        document.getElementById('plClose')?.addEventListener('click', () => {
            document.getElementById('postLabModal')?.classList.remove('open');
        });
        document.getElementById('postLabModal')?.addEventListener('click', e => {
            if (e.target.id === 'postLabModal') e.target.classList.remove('open');
        });
    }
})();

// ── Lab Event Log (memory array) ─────────────────────────────
const LAB_LOG = [];   // { ts, type, c1, c2, product, rxnType, dT, phBefore, phAfter, obs }

function _logLabEvent(type, data) {
    LAB_LOG.push({
        ts:      Date.now(),
        elapsed: typeof timerElapsed !== 'undefined' ? timerElapsed : 0,
        type,
        ...data,
    });
}

// ── Patch processReaction เพื่อ record lab log ───────────────
function patchLabLog(app) {
    if (app._logPatched) return;
    app._logPatched = true;
    const orig = app.processReaction.bind(app);
    app.processReaction = function(rxn, c1, c2, idx1, idx2) {
        const phBefore = this.state.currentPH;
        orig.call(this, rxn, c1, c2, idx1, idx2);
        _logLabEvent('reaction', {
            c1:       c1?.name || '?',
            c2:       c2?.name || '?',
            product:  rxn.product_name,
            equation: rxn.equation,
            rxnType:  rxn.reaction_type,
            dT:       parseFloat(rxn.temperature_change || rxn.heat_level || 0),
            phBefore: parseFloat(phBefore || 7),
            phAfter:  parseFloat(rxn.result_ph ?? this.state.currentPH ?? 7),
            obs:      rxn.observation_th || '',
            gas:      rxn.result_gas,
            precip:   rxn.result_precipitate,
        });
    };

    // log PPE events
    document.getElementById('labelGoggles')?.addEventListener('click', () => {
        _logLabEvent('ppe', { item:'goggles', on: !document.getElementById('chkGoggles')?.checked });
    });
    document.getElementById('labelGloves')?.addEventListener('click', () => {
        _logLabEvent('ppe', { item:'gloves', on: !document.getElementById('chkGloves')?.checked });
    });

    // log add chemical
    const origPour = app.executePour?.bind(app);
    if (origPour) {
        app.executePour = function() {
            const tool = this.state.activeTool;
            if (tool?.name) {
                _logLabEvent('add', { chem: tool.name, amount: tool.amount, state: tool.state });
            }
            return origPour.call(this);
        };
    }
}

// ── Type color map ────────────────────────────────────────────
const RXN_TYPE_COLORS = {
    neutralization:'#22d3ee', precipitation:'#fcd34d', redox:'#f472b6',
    acid_metal:'#fb923c',     metal_water:'#ef4444',   decomposition:'#a78bfa',
    acid_carbonate:'#34d399', complex_formation:'#818cf8', combustion:'#f97316',
    dissolution:'#38bdf8',    buffer:'#6ee7b7',         iodine_clock:'#fbbf24',
};
const RXN_TYPE_TH = {
    neutralization:'สะเทิน', precipitation:'ตกตะกอน', redox:'รีดอกซ์',
    acid_metal:'โลหะ+กรด',   metal_water:'โลหะ+น้ำ', decomposition:'สลายตัว',
    acid_carbonate:'กรด+คาร์บอเนต', complex_formation:'เชิงซ้อน', combustion:'เผาไหม้',
    dissolution:'ละลาย',      buffer:'บัฟเฟอร์',        iodine_clock:'Iodine Clock',
};

// ── Build Post-Lab HTML ───────────────────────────────────────
function buildPostLabHTML(app) {
    const reactions = LAB_LOG.filter(e => e.type === 'reaction');
    const adds      = LAB_LOG.filter(e => e.type === 'add');
    const ppeLog    = LAB_LOG.filter(e => e.type === 'ppe');

    const gogglesWorn = document.getElementById('chkGoggles')?.checked || false;
    const glovesWorn  = document.getElementById('chkGloves')?.checked  || false;
    const hp          = app.state.hp;
    const spills      = app.state.telemetry.spillCount      || 0;
    const safetyViol  = app.state.telemetry.safetyViolations || 0;
    const toxicExp    = app.state.telemetry.toxicExposures   || 0;

    // score calculation
    const baseScore   = Math.round(hp);
    const ppeBonus    = (gogglesWorn ? 5 : 0) + (glovesWorn ? 5 : 0);
    const spillPenalty= Math.min(20, spills * 5);
    const safetyPen   = Math.min(15, safetyViol * 5);
    const rxnBonus    = Math.min(30, reactions.length * 5);
    const totalScore  = Math.max(0, Math.min(100, baseScore + ppeBonus + rxnBonus - spillPenalty - safetyPen));

    // format elapsed
    const totalSec = Math.floor((LAB_LOG.length ? LAB_LOG[LAB_LOG.length-1].elapsed : 0) / 1000);
    const h=Math.floor(totalSec/3600), m=Math.floor((totalSec%3600)/60), sc=totalSec%60;
    const timeStr = h>0 ? `${h}:${String(m).padStart(2,'0')}:${String(sc).padStart(2,'0')}` : `${m}:${String(sc).padStart(2,'0')}`;

    // type distribution
    const typeCounts = {};
    reactions.forEach(r => { typeCounts[r.rxnType] = (typeCounts[r.rxnType]||0)+1; });
    const maxCount = Math.max(1, ...Object.values(typeCounts));
    const distHTML = Object.entries(typeCounts).sort((a,b)=>b[1]-a[1]).map(([t,c]) => `
        <div class="pl-dist-bar">
            <span class="bar-label">${RXN_TYPE_TH[t]||t}</span>
            <div class="bar-fill" style="background:${RXN_TYPE_COLORS[t]||'#334155'};width:${(c/maxCount*180).toFixed(0)}px"></div>
            <span class="bar-count">${c} ครั้ง</span>
        </div>`).join('');

    // timeline rows
    const rowsHTML = reactions.length === 0
        ? '<tr><td colspan="5" style="text-align:center;color:#334155;padding:20px">ยังไม่มีปฏิกิริยาเกิดขึ้น</td></tr>'
        : reactions.map((r,i) => {
            const t  = new Date(r.ts);
            const ts = `${String(t.getHours()).padStart(2,'0')}:${String(t.getMinutes()).padStart(2,'0')}:${String(t.getSeconds()).padStart(2,'0')}`;
            const col = RXN_TYPE_COLORS[r.rxnType]||'#475569';
            const dT  = r.dT !== 0 ? `<span class="pl-dt">${r.dT>0?'+':''}${r.dT.toFixed(1)}°C</span>` : '';
            const ph  = r.phAfter ? `<span class="pl-ph">pH ${r.phAfter.toFixed(1)}</span>` : '';
            return `<tr>
                <td>${ts}</td>
                <td><span class="rxn-type-dot" style="background:${col}"></span>${RXN_TYPE_TH[r.rxnType]||r.rxnType}</td>
                <td style="color:#f1f5f9">${r.product||'?'}</td>
                <td>${dT} ${ph}</td>
                <td style="font-size:.7rem;color:#475569;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${(r.obs||'').replace(/"/g,"'")}">
                    ${(r.obs||'').substring(0,50)}${(r.obs||'').length>50?'…':''}
                </td>
            </tr>`;
        }).join('');

    return `
    <!-- Score summary -->
    <div class="pl-score-bar">
        <div class="pl-score-chip">
            <div class="sc-val" style="color:${totalScore>=70?'#22c55e':totalScore>=50?'#f59e0b':'#ef4444'}">${totalScore}</div>
            <div class="sc-lbl">คะแนนรวม</div>
        </div>
        <div class="pl-score-chip">
            <div class="sc-val" style="color:#38bdf8">${reactions.length}</div>
            <div class="sc-lbl">ปฏิกิริยา</div>
        </div>
        <div class="pl-score-chip">
            <div class="sc-val" style="color:#22c55e">${hp}%</div>
            <div class="sc-lbl">HP คงเหลือ</div>
        </div>
        <div class="pl-score-chip">
            <div class="sc-val" style="color:#94a3b8">${timeStr}</div>
            <div class="sc-lbl">เวลาใช้</div>
        </div>
    </div>

    <!-- PPE & Safety -->
    <div class="pl-safety">
        <div class="pl-safe-item">
            <span class="si-icon">🥽</span>
            <span class="${gogglesWorn?'si-ok':'si-bad'}">${gogglesWorn?'สวมแว่นตา ✓':'ไม่ได้สวมแว่นตา ✗'}</span>
        </div>
        <div class="pl-safe-item">
            <span class="si-icon">🧤</span>
            <span class="${glovesWorn?'si-ok':'si-bad'}">${glovesWorn?'สวมถุงมือ ✓':'ไม่ได้สวมถุงมือ ✗'}</span>
        </div>
        <div class="pl-safe-item">
            <span class="si-icon">💧</span>
            <span class="${spills===0?'si-ok':'si-bad'}">สารล้น ${spills} ครั้ง</span>
        </div>
        <div class="pl-safe-item">
            <span class="si-icon">☠️</span>
            <span class="${toxicExp===0?'si-ok':'si-bad'}">สัมผัสสารพิษ ${toxicExp} ครั้ง</span>
        </div>
        <div class="pl-safe-item">
            <span class="si-icon">⚠️</span>
            <span class="${safetyViol===0?'si-ok':'si-bad'}">ละเมิดความปลอดภัย ${safetyViol} ครั้ง</span>
        </div>
    </div>

    <!-- Reaction type distribution -->
    ${Object.keys(typeCounts).length > 0 ? `
    <div class="pl-dist">
        <div class="pl-dist-title">การกระจายประเภทปฏิกิริยา</div>
        ${distHTML}
    </div>` : ''}

    <!-- Timeline table -->
    <div style="font-size:.65rem;color:#475569;text-transform:uppercase;letter-spacing:.1em;margin-bottom:8px">
        ลำดับปฏิกิริยาทั้งหมด (${reactions.length} รายการ)
    </div>
    <div style="overflow-x:auto;margin-bottom:18px">
        <table class="pl-table">
            <thead><tr>
                <th>เวลา</th><th>ประเภท</th><th>ผลิตภัณฑ์</th><th>ΔT / pH</th><th>สังเกต</th>
            </tr></thead>
            <tbody>${rowsHTML}</tbody>
        </table>
    </div>

    <!-- Score breakdown -->
    <div style="background:#0a1628;border-radius:8px;padding:12px 14px;margin-bottom:18px;font-size:.75rem;color:#64748b;line-height:1.8">
        <div style="color:#475569;font-size:.63rem;text-transform:uppercase;letter-spacing:.1em;margin-bottom:6px">รายละเอียดคะแนน</div>
        HP คงเหลือ: <span style="color:#94a3b8">${baseScore}%</span>
        + PPE bonus: <span style="color:#22c55e">+${ppeBonus}</span>
        + Reaction bonus: <span style="color:#38bdf8">+${rxnBonus}</span>
        - Spill penalty: <span style="color:#ef4444">-${spillPenalty}</span>
        - Safety penalty: <span style="color:#ef4444">-${safetyPen}</span>
        = <span style="color:#f1f5f9;font-weight:700">${totalScore}/100</span>
    </div>

    <!-- Action buttons -->
    <div class="pl-actions">
        <button class="pl-btn pl-btn-send" id="plBtnSend">🚀 ส่งรายงานให้ครู</button>
        <button class="pl-btn pl-btn-print" onclick="window.print()">🖨️ พิมพ์</button>
        <button class="pl-btn pl-btn-close" id="plBtnClose2">✕ ปิด</button>
    </div>

    <input type="hidden" id="plTotalScore" value="${totalScore}">
    <input type="hidden" id="plLogJSON" value='${JSON.stringify(reactions).replace(/'/g,"&apos;")}'>
    `;
}

// ── Open Post-Lab Modal ───────────────────────────────────────
function openPostLab(app) {
    const modal = document.getElementById('postLabModal');
    const body  = document.getElementById('plBody');
    if (!modal || !body) return;

    body.innerHTML = buildPostLabHTML(app);
    modal.classList.add('open');

    // bind send button
    document.getElementById('plBtnSend')?.addEventListener('click', () => sendPostLab(app));
    document.getElementById('plBtnClose2')?.addEventListener('click', () => modal.classList.remove('open'));
}

// ── Send to server ────────────────────────────────────────────
function sendPostLab(app) {
    const scoreEl = document.getElementById('plTotalScore');
    const logEl   = document.getElementById('plLogJSON');
    const score   = scoreEl ? parseInt(scoreEl.value) : 0;

    let rxnLog;
    try { rxnLog = JSON.parse(logEl?.value || '[]'); } catch(e) { rxnLog = []; }

    const payload = {
        assignment_id:   app.config.assignmentId,
        csrf_token:      app.config.csrfToken,
        final_score:     score,
        hp_remaining:    app.state.hp,
        report_summary:  JSON.stringify(rxnLog),
        telemetry:       app.state.telemetry,
        goggles_worn:    document.getElementById('chkGoggles')?.checked ? 1 : 0,
        gloves_worn:     document.getElementById('chkGloves')?.checked  ? 1 : 0,
    };

    const btn = document.getElementById('plBtnSend');
    if (btn) { btn.disabled=true; btn.textContent='⏳ กำลังส่ง...'; }

    fetch('api_auto_grade.php', {
        method:  'POST',
        headers: { 'Content-Type':'application/json' },
        body:    JSON.stringify(payload),
    })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'success') {
            document.getElementById('postLabModal')?.classList.remove('open');
            Swal.fire({
                icon:  'success',
                title: `ได้รับเกรด: ${data.grade || 'A'}`,
                html:  `คะแนน <b>${data.final_score || score}/100</b><br>ส่งรายงานสำเร็จแล้ว`,
                confirmButtonText: 'กลับหน้าหลัก',
                confirmButtonColor:'#10b981',
            }).then(() => { window.location.href = 'student_reports.php'; });
        } else {
            if (btn) { btn.disabled=false; btn.textContent='🚀 ส่งรายงานให้ครู'; }
            Swal.fire('ผิดพลาด', data.message || 'ไม่สามารถส่งได้', 'error');
        }
    })
    .catch(() => {
        if (btn) { btn.disabled=false; btn.textContent='🚀 ส่งรายงานให้ครู'; }
        Swal.fire('เชื่อมต่อล้มเหลว', 'กรุณาลองใหม่', 'error');
    });
}

// ── เพิ่มปุ่ม Post-Lab ใน panel ──────────────────────────────
function injectPostLabButton(app) {
    // หา btnSubmit เดิม แล้วเพิ่มปุ่ม Post-Lab ข้างๆ
    const existing = document.getElementById('btnPostLab');
    if (existing) return;

    const submitBtn = document.getElementById('btnSubmit');
    if (submitBtn) {
        const btn = document.createElement('button');
        btn.id = 'btnPostLab';
        btn.textContent = '📋 สรุปผลการทดลอง';
        btn.style.cssText = 'width:100%;margin-top:8px;';
        btn.addEventListener('click', () => openPostLab(app));
        submitBtn.parentNode?.insertBefore(btn, submitBtn.nextSibling);
    }

    // override btnSubmit เดิม ให้เปิด Post-Lab แทน
    if (submitBtn) {
        const oldClick = submitBtn.onclick;
        submitBtn.addEventListener('click', (e) => {
            e.stopImmediatePropagation();
            if (app.state.hp <= 0) { alert('HP = 0 ไม่สามารถส่งงานได้'); return; }
            openPostLab(app);
        }, true);
    }
}


// ══════════════════════════════════════════════════════════════
// STEP 8 — QUEST SYSTEM + TUTORIAL PANEL
// ══════════════════════════════════════════════════════════════

(function injectStep8() {
    if (document.getElementById('step8Styles')) return;

    // ── CSS ──────────────────────────────────────────────────
    const s = document.createElement('style');
    s.id = 'step8Styles';
    s.textContent = `
/* ── Quest Browser Modal ── */
@keyframes qModalIn { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
#questBrowserModal {
    position:fixed;inset:0;z-index:9300;background:rgba(0,0,0,.8);backdrop-filter:blur(4px);
    display:none;align-items:flex-start;justify-content:center;overflow-y:auto;padding:20px 12px 40px;
}
#questBrowserModal.open { display:flex;animation:qModalIn .3s ease; }
#questBrowserBox {
    background:#0f172a;border:1px solid #1e293b;border-radius:16px;
    width:100%;max-width:680px;font-family:'Prompt',sans-serif;
}
.qbh { padding:16px 20px;border-bottom:1px solid #1e293b;display:flex;align-items:center;justify-content:space-between; }
.qbh h2 { font-size:1rem;font-weight:700;color:#f1f5f9; }
.qbh-close { background:none;border:none;color:#64748b;font-size:1.2rem;cursor:pointer; }

/* Quest filters */
.q-filters { display:flex;gap:6px;flex-wrap:wrap;padding:12px 18px;border-bottom:1px solid #0f172a; }
.q-filter-btn {
    font-size:.68rem;font-weight:600;padding:4px 11px;border-radius:20px;cursor:pointer;
    background:rgba(255,255,255,.05);border:1px solid #334155;color:#64748b;transition:.15s;
    font-family:'Prompt';
}
.q-filter-btn.active { background:rgba(56,189,248,.15);border-color:#38bdf8;color:#38bdf8; }

/* Quest cards */
.q-list { padding:12px 16px;display:flex;flex-direction:column;gap:10px;max-height:65vh;overflow-y:auto; }
.q-card {
    background:#1e293b;border:1px solid #334155;border-radius:10px;padding:14px 16px;
    cursor:pointer;transition:.15s;position:relative;
}
.q-card:hover { border-color:#38bdf8;background:#1e3040; }
.q-card.active-quest { border-color:#22d3ee;background:rgba(34,211,238,.06); }
.q-card-head { display:flex;align-items:flex-start;gap:10px;margin-bottom:6px; }
.q-card-title { font-size:.88rem;font-weight:600;color:#f1f5f9;line-height:1.3; }
.q-card-desc  { font-size:.76rem;color:#64748b;line-height:1.5;margin-bottom:8px; }
.q-card-meta  { display:flex;gap:6px;flex-wrap:wrap;align-items:center; }
.q-type-badge { font-size:.62rem;font-weight:600;padding:2px 8px;border-radius:10px; }
.q-diff-stars { color:#f59e0b;font-size:.75rem; }
.q-xp  { font-size:.65rem;color:#22c55e;font-weight:600; }
.q-intro { font-size:.74rem;color:#475569;border-left:2px solid #334155;padding-left:8px;margin-bottom:8px;font-style:italic; }
/* Hints collapsible */
.q-hints { display:none;margin-top:8px; }
.q-hints.open { display:block; }
.q-hint-item { font-size:.74rem;padding:6px 10px;background:#0f172a;border-radius:6px;margin-bottom:4px;color:#94a3b8; }
.q-hint-lv1 { border-left:2px solid #22c55e; }
.q-hint-lv2 { border-left:2px solid #f59e0b; }
.q-hint-lv3 { border-left:2px solid #ef4444; }
/* Steps */
.q-steps { margin-top:8px;display:none; }
.q-steps.open { display:block; }
.q-step-item {
    display:flex;align-items:center;gap:8px;padding:6px 8px;
    border-radius:6px;margin-bottom:3px;font-size:.76rem;color:#94a3b8;
    background:#0a1220;
}
.q-step-item.done { color:#22c55e;text-decoration:line-through;opacity:.7; }
.q-step-num { width:18px;height:18px;border-radius:50%;background:#1e293b;border:1px solid #334155;
    display:flex;align-items:center;justify-content:center;font-size:.6rem;color:#475569;flex-shrink:0; }
.q-step-item.done .q-step-num { background:#22c55e;color:#0f172a;border-color:#22c55e; }
/* Action buttons */
.q-actions { display:flex;gap:8px;margin-top:10px; }
.q-btn { padding:7px 14px;border-radius:8px;cursor:pointer;font-family:'Prompt';font-size:.76rem;font-weight:600;border:none;transition:.15s; }
.q-btn-start  { background:#38bdf8;color:#0f172a;flex:1; }
.q-btn-start:hover { background:#7dd3fc; }
.q-btn-hint   { background:rgba(245,158,11,.15);color:#f59e0b;border:1px solid rgba(245,158,11,.3); }
.q-btn-steps  { background:rgba(167,139,250,.15);color:#a78bfa;border:1px solid rgba(167,139,250,.3); }

/* ── Quest HUD (active quest overlay) ── */
#activeQuestHUD {
    position:fixed;bottom:72px;right:12px;z-index:700;
    background:#0f172a;border:1px solid #1e293b;border-radius:12px;
    width:240px;font-family:'Prompt';font-size:.78rem;
    box-shadow:0 4px 20px rgba(0,0,0,.5);
    transition:.3s;
}
#activeQuestHUD.hidden { display:none; }
.aqh-head { padding:10px 12px 6px;border-bottom:1px solid #1e293b;display:flex;align-items:center;justify-content:space-between; }
.aqh-title { font-size:.8rem;font-weight:700;color:#38bdf8;line-height:1.3; }
.aqh-close { background:none;border:none;color:#334155;cursor:pointer;font-size:.85rem; }
.aqh-body { padding:8px 12px 10px; }
.aqh-progress { display:flex;align-items:center;gap:6px;margin-bottom:6px; }
.aqh-prog-bar { flex:1;height:4px;background:#1e293b;border-radius:2px;overflow:hidden; }
.aqh-prog-fill { height:100%;background:#22d3ee;border-radius:2px;transition:width .4s; }
.aqh-prog-txt { font-size:.62rem;color:#475569;white-space:nowrap; }
.aqh-step { display:flex;align-items:center;gap:6px;margin-bottom:3px;font-size:.73rem;color:#64748b; }
.aqh-step.done { color:#22c55e; }
.aqh-step-icon { font-size:.65rem; }
.aqh-hint-btn {
    margin-top:8px;width:100%;padding:5px 0;background:rgba(245,158,11,.1);
    border:1px solid rgba(245,158,11,.25);color:#f59e0b;border-radius:6px;
    cursor:pointer;font-family:'Prompt';font-size:.7rem;font-weight:600;
}
.aqh-success {
    padding:12px;text-align:center;
    background:rgba(34,197,94,.1);border-radius:8px;
    color:#22c55e;font-weight:600;font-size:.85rem;
}

/* ── Tutorial Panel ── */
#tutorialPanel {
    position:fixed;right:0;top:0;bottom:0;z-index:8500;
    width:320px;background:#0f172a;border-left:1px solid #1e293b;
    display:none;flex-direction:column;font-family:'Prompt';
    box-shadow:-8px 0 30px rgba(0,0,0,.6);
    transform:translateX(100%);transition:transform .3s ease;
}
#tutorialPanel.open { display:flex;transform:translateX(0); }
.tp-head { padding:14px 16px;border-bottom:1px solid #1e293b;display:flex;align-items:center;justify-content:space-between;flex-shrink:0; }
.tp-head h2 { font-size:.95rem;font-weight:700;color:#f1f5f9; }
.tp-close { background:none;border:none;color:#64748b;font-size:1.1rem;cursor:pointer; }
.tp-tabs { display:flex;border-bottom:1px solid #1e293b;flex-shrink:0; }
.tp-tab { flex:1;padding:8px 0;text-align:center;font-size:.72rem;font-weight:600;color:#475569;cursor:pointer;border-bottom:2px solid transparent;transition:.15s; }
.tp-tab.active { color:#38bdf8;border-bottom-color:#38bdf8; }
.tp-body { flex:1;overflow-y:auto;padding:14px 16px; }
/* Chapter content */
.tp-chapter { margin-bottom:20px; }
.tp-chapter-title { font-size:.82rem;font-weight:700;color:#7dd3fc;margin-bottom:8px;display:flex;align-items:center;gap:6px; }
.tp-item { margin-bottom:10px;font-size:.78rem;line-height:1.6; }
.tp-item-title { font-weight:600;color:#cbd5e1;margin-bottom:3px; }
.tp-item-text  { color:#94a3b8; }
.tp-tip  { background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2);border-radius:6px;padding:8px 10px;font-size:.75rem;color:#86efac;margin-bottom:8px; }
.tp-warn { background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:6px;padding:8px 10px;font-size:.75rem;color:#fca5a5;margin-bottom:8px; }
.tp-step { display:flex;gap:8px;margin-bottom:6px;font-size:.76rem;color:#94a3b8; }
.tp-step-no { background:#1e293b;border-radius:50%;width:20px;height:20px;display:flex;align-items:center;justify-content:center;font-size:.65rem;color:#38bdf8;flex-shrink:0;font-weight:700; }
/* Quest list in tutorial panel */
.tp-quest-item { background:#1e293b;border:1px solid #334155;border-radius:8px;padding:10px 12px;margin-bottom:8px;cursor:pointer;transition:.15s; }
.tp-quest-item:hover { border-color:#38bdf8; }
.tp-quest-title { font-size:.8rem;font-weight:600;color:#f1f5f9;margin-bottom:3px; }
.tp-quest-meta  { display:flex;gap:6px;align-items:center;font-size:.65rem;color:#64748b; }

/* Quest open button in header */
#btnQuestBrowser {
    background:rgba(56,189,248,.1);border:1px solid rgba(56,189,248,.3);
    color:#38bdf8;border-radius:8px;padding:5px 12px;cursor:pointer;
    font-family:'Prompt';font-size:.75rem;font-weight:600;margin-left:6px;transition:.2s;
}
#btnQuestBrowser:hover { background:rgba(56,189,248,.2); }
#btnTutorialOpen {
    background:rgba(167,139,250,.1);border:1px solid rgba(167,139,250,.3);
    color:#a78bfa;border-radius:8px;padding:5px 12px;cursor:pointer;
    font-family:'Prompt';font-size:.75rem;font-weight:600;margin-left:6px;transition:.2s;
}
`;
    document.head.appendChild(s);

    // ── Quest Browser Modal HTML ──
    if (!document.getElementById('questBrowserModal')) {
        document.body.insertAdjacentHTML('beforeend', `
<div id="questBrowserModal">
    <div id="questBrowserBox">
        <div class="qbh">
            <h2>⚗️ เลือกภารกิจ (34 Quest)</h2>
            <button class="qbh-close" id="qbClose">✕</button>
        </div>
        <div class="q-filters" id="qFilters"></div>
        <div class="q-list" id="qList">
            <div style="text-align:center;color:#334155;padding:30px">กำลังโหลด...</div>
        </div>
    </div>
</div>`);
        document.getElementById('qbClose')?.addEventListener('click', closeQuestBrowser);
        document.getElementById('questBrowserModal')?.addEventListener('click', e => {
            if (e.target.id === 'questBrowserModal') closeQuestBrowser();
        });
    }

    // ── Active Quest HUD ──
    if (!document.getElementById('activeQuestHUD')) {
        document.body.insertAdjacentHTML('beforeend', `
<div id="activeQuestHUD" class="hidden">
    <div class="aqh-head">
        <div class="aqh-title" id="aqhTitle">ภารกิจ</div>
        <button class="aqh-close" id="aqhClose" title="ซ่อน HUD">✕</button>
    </div>
    <div class="aqh-body">
        <div class="aqh-progress">
            <div class="aqh-prog-bar"><div class="aqh-prog-fill" id="aqhProgFill" style="width:0%"></div></div>
            <span class="aqh-prog-txt" id="aqhProgTxt">0/0</span>
        </div>
        <div id="aqhSteps"></div>
        <button class="aqh-hint-btn" id="aqhHintBtn">💡 ขอใบ้</button>
    </div>
</div>`);
        document.getElementById('aqhClose')?.addEventListener('click', () => {
            document.getElementById('activeQuestHUD')?.classList.add('hidden');
        });
        document.getElementById('aqhHintBtn')?.addEventListener('click', () => showNextHint());
    }

    // ── Tutorial Panel HTML ──
    if (!document.getElementById('tutorialPanel')) {
        document.body.insertAdjacentHTML('beforeend', `
<div id="tutorialPanel">
    <div class="tp-head">
        <h2>📚 คู่มือและภารกิจ</h2>
        <button class="tp-close" id="tpClose">✕</button>
    </div>
    <div class="tp-tabs">
        <div class="tp-tab active" data-tab="tutorial" id="tabTutorial">📖 คู่มือ</div>
        <div class="tp-tab" data-tab="quests" id="tabQuests">⚗️ ภารกิจ</div>
    </div>
    <div class="tp-body" id="tpBody">
        <div style="color:#334155;text-align:center;padding:30px">กำลังโหลด...</div>
    </div>
</div>`);
        document.getElementById('tpClose')?.addEventListener('click', closeTutorialPanel);
        document.querySelectorAll('.tp-tab').forEach(t => {
            t.addEventListener('click', () => switchTpTab(t.dataset.tab));
        });
    }
})();

// ══════════════════════════════════════════════════════════════
// QUEST SYSTEM — Type colors/names/icons
// ══════════════════════════════════════════════════════════════
const QUEST_TYPE_COLOR = {
    identification:'#fcd34d', synthesis:'#22d3ee', safety:'#22c55e',
    observation:'#a78bfa',    free:'#94a3b8',     titration:'#f472b6',
    investigation:'#fb923c',
};
const QUEST_TYPE_NAME = {
    identification:'🔍 ค้นหา', synthesis:'⚗️ สังเคราะห์', safety:'🛡️ ความปลอดภัย',
    observation:'👁️ สังเกต',   free:'🔬 อิสระ',         titration:'💧 ไทเทรต',
    investigation:'🧬 สืบสวน',
};

// ── Current active quest state ─────────────────────────────
let _activeQuest    = null;
let _questStepsDone = {};
let _hintLevel      = 0;
let _currentFilter  = 'all';

// ══════════════════════════════════════════════════════════════
// QUEST BROWSER
// ══════════════════════════════════════════════════════════════
function openQuestBrowser() {
    const modal = document.getElementById('questBrowserModal');
    if (!modal) return;
    modal.classList.add('open');
    _renderQuestFilters();
    _renderQuestList(_currentFilter);
}
function closeQuestBrowser() {
    document.getElementById('questBrowserModal')?.classList.remove('open');
}

function _renderQuestFilters() {
    const el = document.getElementById('qFilters');
    if (!el || el.children.length > 0) return;
    const types = ['all','identification','synthesis','safety','observation','titration','investigation','free'];
    const labels = { all:'ทั้งหมด', ...Object.fromEntries(Object.entries(QUEST_TYPE_NAME).map(([k,v])=>[k,v])) };
    el.innerHTML = types.map(t => `
        <button class="q-filter-btn${t==='all'?' active':''}" data-type="${t}"
            onclick="_setQuestFilter(this,'${t}')">${labels[t]||t}</button>
    `).join('');
}

function _setQuestFilter(btn, type) {
    _currentFilter = type;
    document.querySelectorAll('.q-filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    _renderQuestList(type);
}

function _renderQuestList(filter) {
    const el    = document.getElementById('qList');
    const quests = window.LAB_CONFIG?.allQuests || [];
    if (!el) return;

    const filtered = filter === 'all' ? quests : quests.filter(q => q.quest_type === filter);
    if (!filtered.length) {
        el.innerHTML = '<div style="text-align:center;color:#334155;padding:30px">ไม่มีภารกิจในหมวดนี้</div>';
        return;
    }

    el.innerHTML = filtered.map(q => {
        const col   = QUEST_TYPE_COLOR[q.quest_type] || '#94a3b8';
        const tname = QUEST_TYPE_NAME[q.quest_type]  || q.quest_type;
        const stars = '★'.repeat(q.difficulty) + '☆'.repeat(5-q.difficulty);
        const isActive = _activeQuest?.id == q.id;
        const steps = q.steps_json ? JSON.parse(q.steps_json) : [];

        return `<div class="q-card${isActive?' active-quest':''}" id="qcard_${q.id}">
            <div class="q-card-head">
                <div style="flex:1">
                    <div class="q-card-title">${q.title}</div>
                    <div class="q-card-desc">${q.description}</div>
                </div>
            </div>
            ${q.intro_text ? `<div class="q-intro">${q.intro_text}</div>` : ''}
            <div class="q-card-meta">
                <span class="q-type-badge" style="background:${col}22;color:${col};border:1px solid ${col}44">${tname}</span>
                <span class="q-diff-stars" title="ความยาก">${stars}</span>
                <span class="q-xp">+${q.xp_reward} XP</span>
                ${isActive ? '<span style="color:#22d3ee;font-size:.65rem;font-weight:600">⚡ กำลังทำ</span>' : ''}
            </div>
            ${steps.length > 0 ? `
            <div class="q-steps" id="steps_${q.id}">
                ${steps.map((st,i) => `
                    <div class="q-step-item${_questStepsDone[q.id+'_'+st.id]?' done':''}">
                        <div class="q-step-num">${i+1}</div>
                        <span>${st.label}</span>
                    </div>`).join('')}
            </div>` : ''}
            <div class="q-actions">
                <button class="q-btn q-btn-start" onclick="startQuest(${q.id})">
                    ${isActive ? '⚡ กำลังทำอยู่' : '▶ เริ่มภารกิจ'}
                </button>
                ${steps.length > 0 ? `<button class="q-btn q-btn-steps"
                    onclick="document.getElementById('steps_${q.id}').classList.toggle('open')">📋 ขั้นตอน</button>` : ''}
                ${q.hints_json ? `<button class="q-btn q-btn-hint"
                    onclick="showHintFor(${q.id})">💡 ใบ้</button>` : ''}
            </div>
        </div>`;
    }).join('');
}

// ══════════════════════════════════════════════════════════════
// START / ACTIVE QUEST
// ══════════════════════════════════════════════════════════════
function startQuest(questId) {
    const quests = window.LAB_CONFIG?.allQuests || [];
    const q = quests.find(x => x.id == questId);
    if (!q) return;

    _activeQuest     = q;
    _questStepsDone  = {};
    _hintLevel       = 0;

    // อัปเดต HUD
    _updateActiveQuestHUD();
    document.getElementById('activeQuestHUD')?.classList.remove('hidden');
    closeQuestBrowser();

    // อัปเดต quest HUD เดิม (ซ้ายบน)
    const hudTitle = document.getElementById('hudTitle');
    if (hudTitle) hudTitle.textContent = `⚗️ ${q.title}`;
    _renderQuestHUDChecklist(q);

    window.app?.log(`⚗️ <span style="color:#22d3ee;font-weight:600">เริ่มภารกิจ: ${q.title}</span>`);
    _logLabEvent?.('quest_start', { questId: q.id, title: q.title });
}

function _renderQuestHUDChecklist(q) {
    const el = document.getElementById('hudChecklist');
    if (!el) return;
    const steps = q.steps_json ? JSON.parse(q.steps_json) : [];
    el.innerHTML = steps.map((st,i) => `
        <li class="qh-item${_questStepsDone[q.id+'_'+st.id]?' done':' pending'}" id="chk_s${st.id}">
            <span class="qh-icon">${_questStepsDone[q.id+'_'+st.id]?'✅':'⬜'}</span>
            ${st.label}
        </li>`).join('') ||
        `<li class="qh-item pending"><span class="qh-icon">🔬</span>${q.description}</li>`;
}

function _updateActiveQuestHUD() {
    if (!_activeQuest) return;
    const q      = _activeQuest;
    const steps  = q.steps_json ? JSON.parse(q.steps_json) : [];
    const done   = steps.filter(st => _questStepsDone[q.id+'_'+st.id]).length;
    const total  = steps.length || 1;
    const pct    = Math.round(done/total*100);

    const title  = document.getElementById('aqhTitle');
    const fill   = document.getElementById('aqhProgFill');
    const txt    = document.getElementById('aqhProgTxt');
    const stepsEl= document.getElementById('aqhSteps');

    if (title)  title.textContent  = q.title;
    if (fill)   fill.style.width   = pct + '%';
    if (txt)    txt.textContent    = `${done}/${total}`;
    if (stepsEl) stepsEl.innerHTML = steps.slice(0, 5).map(st => {
        const d = _questStepsDone[q.id+'_'+st.id];
        return `<div class="aqh-step${d?' done':''}">
            <span class="aqh-step-icon">${d?'✅':'⬜'}</span>
            <span>${st.label}</span>
        </div>`;
    }).join('');

    // ตรวจสำเร็จ
    if (done >= total && total > 0) _onQuestComplete(q);
}

function _onQuestComplete(q) {
    const hud = document.getElementById('activeQuestHUD');
    if (hud) {
        hud.querySelector('.aqh-body').innerHTML = `
            <div class="aqh-success">
                🎉 ภารกิจสำเร็จ!<br>
                <span style="font-size:.75rem;color:#86efac">+${q.xp_reward} XP</span>
                <br><span style="font-size:.7rem;color:#475569">${q.success_text||''}</span>
            </div>`;
    }
    window.app?.log(`🎉 <span style="color:#22c55e;font-weight:700">ภารกิจสำเร็จ: ${q.title} — +${q.xp_reward} XP!</span>`);
    _logLabEvent?.('quest_complete', { questId: q.id, xp: q.xp_reward });

    // แสดง success card
    showReactionCard?.({
        product_name: `✅ ภารกิจสำเร็จ: ${q.title}`,
        reaction_type: 'dissolution',
        equation: q.success_text || 'ยอดเยี่ยม!',
        temperature_change: 0,
        observation_th: `ได้รับ ${q.xp_reward} XP — ${q.success_text || 'ทำได้ดีมาก!'}`,
        result_gas: null, result_precipitate: null, safety_warning: null,
    }, 'Quest', 'Complete');
}

// ── Hint system ────────────────────────────────────────────
function showNextHint() {
    if (!_activeQuest?.hints_json) return;
    let hints;
    try { hints = JSON.parse(_activeQuest.hints_json); } catch(e) { return; }
    _hintLevel = Math.min(_hintLevel + 1, hints.length);
    const h = hints[_hintLevel - 1];
    if (h) window.app?.log(`💡 <span style="color:#f59e0b">${h.text}</span>`);
}

function showHintFor(questId) {
    const q = (window.LAB_CONFIG?.allQuests || []).find(x => x.id == questId);
    if (!q?.hints_json) return;
    let hints; try { hints = JSON.parse(q.hints_json); } catch(e) { return; }
    const panel = document.createElement('div');
    panel.style.cssText='position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:10000;background:#1e293b;border:1px solid #334155;border-radius:12px;padding:18px 20px;max-width:380px;font-family:Prompt;';
    panel.innerHTML = `<div style="font-size:.85rem;font-weight:700;color:#f59e0b;margin-bottom:10px">💡 ใบ้: ${q.title}</div>
        ${hints.map(h => `<div class="q-hint-item q-hint-lv${h.level}" style="margin-bottom:6px;padding:8px 10px;background:#0f172a;border-radius:6px;border-left:3px solid ${['','#22c55e','#f59e0b','#ef4444'][h.level]};font-size:.76rem;color:#94a3b8">${h.text}</div>`).join('')}
        <button onclick="this.closest('div').remove()" style="margin-top:10px;width:100%;padding:8px;background:#334155;color:#f1f5f9;border:none;border-radius:8px;cursor:pointer;font-family:Prompt">ปิด</button>`;
    document.body.appendChild(panel);
}

// ══════════════════════════════════════════════════════════════
// QUEST STEP CHECKER — ตรวจสอบ step เมื่อ action เกิด
// ══════════════════════════════════════════════════════════════
function checkQuestStep(action, data) {
    if (!_activeQuest) return;
    const q     = _activeQuest;
    let steps;
    try { steps = q.steps_json ? JSON.parse(q.steps_json) : []; } catch(e) { steps = []; }

    steps.forEach(st => {
        const key = q.id + '_' + st.id;
        if (_questStepsDone[key]) return;

        let done = false;
        switch (st.check_type) {
            case 'safety':
                const gogg = document.getElementById('chkGoggles')?.checked;
                const glov = document.getElementById('chkGloves')?.checked;
                done = st.check_value === 'both' ? (gogg && glov) : (gogg || glov);
                break;
            case 'add_chem':
                done = action === 'add_chem' && String(data?.chemId) === String(st.check_value);
                break;
            case 'add_any':
                done = action === 'add_chem';
                break;
            case 'reaction':
                done = action === 'reaction' && data?.rxnType === st.check_value;
                break;
            case 'measure_ph':
                done = action === 'measure_ph';
                break;
            case 'ph_range':
                if (action === 'reaction' || action === 'measure_ph') {
                    const [mn, mx] = (st.check_value || '6-8').split('-').map(Number);
                    done = (data?.ph || window.app?.state?.currentPH || 7) >= mn &&
                           (data?.ph || window.app?.state?.currentPH || 7) <= mx;
                }
                break;
            case 'observe':
                done = action === 'reaction';
                break;
            case 'classify':
                done = action === 'reaction' && (data?.rxnType || '');
                break;
        }

        if (done) {
            _questStepsDone[key] = true;
            // อัปเดต UI
            const li = document.getElementById('chk_s' + st.id);
            if (li) { li.className = 'qh-item done'; li.querySelector('.qh-icon').textContent = '✅'; }
            _updateActiveQuestHUD();
            window.app?.log(`✅ <span style="color:#22c55e">ขั้นตอน: ${st.label}</span>`);
        }
    });
}

// ══════════════════════════════════════════════════════════════
// TUTORIAL PANEL
// ══════════════════════════════════════════════════════════════
function openTutorialPanel() {
    const panel = document.getElementById('tutorialPanel');
    if (panel) panel.classList.add('open');
    switchTpTab('tutorial');
}
function closeTutorialPanel() {
    document.getElementById('tutorialPanel')?.classList.remove('open');
}
function switchTpTab(tab) {
    document.querySelectorAll('.tp-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
    tab === 'tutorial' ? _renderTutorialContent() : _renderQuestListInPanel();
}

function _renderTutorialContent() {
    const el = document.getElementById('tpBody');
    if (!el) return;
    const chapters = window.LAB_CONFIG?.tutorialChapters || [];
    if (!chapters.length) { el.innerHTML = '<div style="color:#334155;text-align:center;padding:20px">ไม่มีข้อมูลคู่มือ</div>'; return; }

    el.innerHTML = chapters.map(ch => {
        let items;
        try { items = JSON.parse(ch.content_json); } catch(e) { items = []; }
        const itemsHTML = items.map(it => {
            if (it.type === 'intro')   return `<p style="color:#94a3b8;font-size:.78rem;margin-bottom:10px;line-height:1.6">${it.content}</p>`;
            if (it.type === 'tip')     return `<div class="tp-tip">💡 ${it.content}</div>`;
            if (it.type === 'warning') return `<div class="tp-warn">⚠️ ${it.content}</div>`;
            if (it.type === 'item')    return `<div class="tp-item"><div class="tp-item-title">${it.icon||''} ${it.title||''}</div><div class="tp-item-text">${it.content}</div></div>`;
            if (it.type === 'step')    return `<div class="tp-step"><div class="tp-step-no">${it.no||'•'}</div><div>${it.content}</div></div>`;
            return `<div style="font-size:.75rem;color:#64748b;margin-bottom:6px">${it.content||''}</div>`;
        }).join('');
        return `<div class="tp-chapter">
            <div class="tp-chapter-title">${ch.icon||'📖'} บทที่ ${ch.chapter_no}: ${ch.title}</div>
            ${itemsHTML}
        </div>`;
    }).join('<hr style="border-color:#1e293b;margin:12px 0">');
}

function _renderQuestListInPanel() {
    const el = document.getElementById('tpBody');
    if (!el) return;
    const quests = window.LAB_CONFIG?.allQuests || [];
    el.innerHTML = `<div style="font-size:.65rem;color:#475569;text-transform:uppercase;letter-spacing:.1em;margin-bottom:10px">ภารกิจทั้งหมด ${quests.length} รายการ</div>` +
        quests.map(q => {
            const col   = QUEST_TYPE_COLOR[q.quest_type] || '#94a3b8';
            const stars = '★'.repeat(q.difficulty) + '☆'.repeat(5-q.difficulty);
            return `<div class="tp-quest-item" onclick="startQuest(${q.id});closeTutorialPanel()">
                <div class="tp-quest-title">${q.title}</div>
                <div class="tp-quest-meta">
                    <span style="color:${col};font-size:.62rem;font-weight:600">${QUEST_TYPE_NAME[q.quest_type]||q.quest_type}</span>
                    <span style="color:#f59e0b;font-size:.68rem">${stars}</span>
                    <span style="color:#22c55e;font-size:.65rem;font-weight:600">+${q.xp_reward} XP</span>
                </div>
            </div>`;
        }).join('');
}

// ══════════════════════════════════════════════════════════════
// PATCH: ส่ง quest step check events
// ══════════════════════════════════════════════════════════════
function patchQuestChecker(app) {
    if (app._questPatched) return;
    app._questPatched = true;

    // ตรวจ safety ทุกครั้งที่ PPE เปลี่ยน
    document.getElementById('labelGoggles')?.addEventListener('click', () => {
        setTimeout(() => checkQuestStep('safety', {}), 100);
    });
    document.getElementById('labelGloves')?.addEventListener('click', () => {
        setTimeout(() => checkQuestStep('safety', {}), 100);
    });

    // ตรวจ add_chem เมื่อ executePour
    const origPour = app.executePour?.bind(app);
    if (origPour) {
        app.executePour = function() {
            const tool = this.state.activeTool;
            if (tool) checkQuestStep('add_chem', { chemId: tool.id, chemName: tool.name });
            return origPour.call(this);
        };
    }

    // ตรวจ reaction เมื่อ processReaction
    const origRxn = app.processReaction?.bind(app);
    if (origRxn) {
        app.processReaction = function(rxn, c1, c2, i1, i2) {
            origRxn.call(this, rxn, c1, c2, i1, i2);
            checkQuestStep('reaction', {
                rxnType: rxn.reaction_type,
                ph: this.state.currentPH,
            });
        };
    }

    // ตรวจ measure_ph
    const btnPH = document.getElementById('btnMeasurePH');
    if (btnPH) {
        btnPH.addEventListener('click', () => {
            checkQuestStep('measure_ph', { ph: app.state.currentPH });
        });
    }
}

// ── เพิ่มปุ่มใน header ─────────────────────────────────────
function injectQuestButtons() {
    const helpBtn = document.querySelector('#btnToggleAudio')?.parentElement
                 || document.querySelector('.lab-header-right, .header-controls');
    if (!helpBtn || document.getElementById('btnQuestBrowser')) return;

    const tutBtn = document.createElement('button');
    tutBtn.id = 'btnTutorialOpen';
    tutBtn.textContent = '📚 คู่มือ';
    tutBtn.addEventListener('click', openTutorialPanel);

    const questBtn = document.createElement('button');
    questBtn.id = 'btnQuestBrowser';
    questBtn.textContent = '⚗️ ภารกิจ';
    questBtn.addEventListener('click', openQuestBrowser);

    const refBtn = document.querySelector('button[onclick*="_openLabTutorial"]');
    if (refBtn) {
        refBtn.parentNode.insertBefore(tutBtn, refBtn.nextSibling);
        refBtn.parentNode.insertBefore(questBtn, tutBtn.nextSibling);
    }
}

// ── auto-start quest จาก URL param ─────────────────────────
function autoStartQuestFromConfig() {
    const qd = window.LAB_CONFIG?.questData;
    if (qd && qd.id) {
        // inject เข้า allQuests ถ้ายังไม่มี
        const all = window.LAB_CONFIG?.allQuests || [];
        if (!all.find(q => q.id == qd.id)) all.unshift(qd);
        startQuest(qd.id);
    }
}

// ── Boot sequence ────────────────────────────────────────────
initPPESystem();
initTimerSystem();
initTutorial();


// ══════════════════════════════════════════════════════════════
// STEP 9 — IODINE CLOCK + BURETTE TITRATION
// ══════════════════════════════════════════════════════════════
(function injectStep9() {
    if (document.getElementById('step9Styles')) return;
    const s = document.createElement('style');
    s.id = 'step9Styles';
    s.textContent = `
/* ── Iodine Clock Panel ── */
#iodineClockPanel {
    position:fixed;bottom:72px;left:12px;z-index:700;
    background:#0f172a;border:1px solid #1e293b;border-radius:12px;
    width:260px;font-family:'Prompt';display:none;
    box-shadow:0 4px 20px rgba(0,0,0,.5);
}
#iodineClockPanel.open { display:block; }
.icp-head { padding:10px 14px;border-bottom:1px solid #1e293b;display:flex;align-items:center;justify-content:space-between; }
.icp-title { font-size:.82rem;font-weight:700;color:#fbbf24; }
.icp-close { background:none;border:none;color:#334155;cursor:pointer;font-size:.9rem; }
.icp-body  { padding:12px 14px; }

/* Prediction input */
.icp-predict { display:flex;align-items:center;gap:6px;margin-bottom:10px;font-size:.75rem;color:#94a3b8; }
.icp-predict input {
    width:65px;padding:4px 8px;background:#1e293b;border:1px solid #334155;
    border-radius:6px;color:#f1f5f9;font-family:'Share Tech Mono';font-size:.85rem;text-align:center;
}
.icp-predict span { color:#64748b; }

/* Timer display */
.icp-timer-box { text-align:center;margin-bottom:10px;padding:8px;background:#020617;border-radius:8px; }
.icp-timer { font-family:'Share Tech Mono';font-size:2rem;color:#22d3ee;letter-spacing:3px; }
.icp-timer.running { color:#22c55e;animation:icp-blink .8s step-end infinite; }
.icp-timer.stopped { color:#f59e0b; }
@keyframes icp-blink { 0%,100%{opacity:1} 50%{opacity:.5} }
.icp-status { font-size:.68rem;color:#475569;margin-top:3px; }

/* Conc vs Time mini-graph */
.icp-graph-label { font-size:.62rem;color:#475569;text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px; }
#icpCanvas { width:100%;height:80px;border-radius:6px;background:#020617;display:block; }

/* Run controls */
.icp-controls { display:flex;gap:6px;margin-top:10px; }
.icp-btn { flex:1;padding:7px 0;border-radius:7px;cursor:pointer;font-family:'Prompt';font-size:.74rem;font-weight:600;border:none; }
.icp-btn-start { background:#22c55e;color:#0f172a; }
.icp-btn-start.running { background:#ef4444; }
.icp-btn-reset { background:#1e293b;color:#64748b;border:1px solid #334155; }

/* Run comparison */
.icp-runs { margin-top:10px;font-size:.72rem; }
.icp-run-item { display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #0f172a;color:#94a3b8; }
.icp-run-item .conc { color:#38bdf8; }
.icp-run-item .time { color:#fbbf24;font-family:'Share Tech Mono'; }
.icp-run-item .diff { font-size:.65rem;color:#64748b; }

/* ── Burette Titration Panel ── */
#buretteTitrPanel {
    position:fixed;bottom:72px;left:12px;z-index:700;
    background:#0f172a;border:1px solid #1e293b;border-radius:12px;
    width:270px;font-family:'Prompt';display:none;
    box-shadow:0 4px 20px rgba(0,0,0,.5);
}
#buretteTitrPanel.open { display:block; }
.btp-head { padding:10px 14px;border-bottom:1px solid #1e293b;display:flex;align-items:center;justify-content:space-between; }
.btp-title { font-size:.82rem;font-weight:700;color:#f472b6; }
.btp-close { background:none;border:none;color:#334155;cursor:pointer;font-size:.9rem; }
.btp-body  { padding:12px 14px; }

/* Burette visual */
.btp-burette-wrap { display:flex;gap:12px;align-items:flex-end;margin-bottom:10px; }
.btp-burette { width:22px;background:#1e293b;border:1px solid #334155;border-radius:4px 4px 0 0;position:relative;height:120px; }
.btp-burette-fill { position:absolute;bottom:0;left:0;right:0;background:linear-gradient(to top,#f472b6,#fb7185);border-radius:0 0 2px 2px;transition:height .3s; }
.btp-burette-scale { flex:1; }
.btp-vol-display { font-family:'Share Tech Mono';font-size:1.2rem;color:#f472b6;text-align:center;margin-bottom:4px; }
.btp-vol-label { font-size:.62rem;color:#475569;text-align:center;letter-spacing:.08em; }

/* Slider */
.btp-slider-row { display:flex;align-items:center;gap:8px;margin-bottom:10px; }
.btp-slider { flex:1;accent-color:#f472b6; }
.btp-drop-btn {
    padding:6px 12px;background:rgba(244,114,182,.15);border:1px solid rgba(244,114,182,.35);
    color:#f472b6;border-radius:6px;cursor:pointer;font-family:'Prompt';font-size:.72rem;font-weight:600;
    white-space:nowrap;
}
.btp-drop-btn:hover { background:rgba(244,114,182,.25); }

/* pH vs Volume graph */
.btp-graph-label { font-size:.62rem;color:#475569;text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px; }
#btpCanvas { width:100%;height:90px;border-radius:6px;background:#020617;display:block; }

/* Endpoint flash */
@keyframes endpointFlash { 0%,100%{opacity:0} 30%,70%{opacity:1} }
#endpointOverlay {
    position:fixed;inset:0;z-index:9800;pointer-events:none;display:none;
    background:rgba(244,114,182,0.22);
    animation:endpointFlash .6s ease;
}

/* Indicator info */
.btp-indicator { background:#0a1220;border-radius:6px;padding:8px 10px;font-size:.72rem;color:#94a3b8;margin-top:8px; }
.btp-ind-name { font-weight:600;color:#f1f5f9;margin-bottom:2px; }
`;
    document.head.appendChild(s);

    // Iodine Clock Panel
    if (!document.getElementById('iodineClockPanel')) {
        document.body.insertAdjacentHTML('beforeend', `
<div id="iodineClockPanel">
    <div class="icp-head">
        <span class="icp-title">⏱️ Iodine Clock</span>
        <button class="icp-close" onclick="document.getElementById('iodineClockPanel').classList.remove('open')">✕</button>
    </div>
    <div class="icp-body">
        <div class="icp-predict">
            <span>ทำนายเวลา:</span>
            <input type="number" id="icpPrediction" placeholder="วิ" min="1" max="999">
            <span>วินาที</span>
        </div>
        <div class="icp-timer-box">
            <div class="icp-timer" id="icpTimer">00.0</div>
            <div class="icp-status" id="icpStatus">กด Start เพื่อจับเวลา</div>
        </div>
        <div class="icp-graph-label">Concentration vs Time</div>
        <canvas id="icpCanvas" height="80"></canvas>
        <div class="icp-controls">
            <button class="icp-btn icp-btn-start" id="icpStartBtn" onclick="icpToggle()">▶ Start</button>
            <button class="icp-btn icp-btn-reset" onclick="icpReset()">↺ Reset</button>
        </div>
        <div class="icp-runs" id="icpRuns"></div>
    </div>
</div>`);
    }

    // Endpoint overlay
    if (!document.getElementById('endpointOverlay')) {
        document.body.insertAdjacentHTML('beforeend', '<div id="endpointOverlay"></div>');
    }

    // Burette Panel
    if (!document.getElementById('buretteTitrPanel')) {
        document.body.insertAdjacentHTML('beforeend', `
<div id="buretteTitrPanel">
    <div class="btp-head">
        <span class="btp-title">💧 Burette Titration</span>
        <button class="btp-close" onclick="closeBuretteTitr()">✕</button>
    </div>
    <div class="btp-body">
        <div class="btp-burette-wrap">
            <div>
                <div class="btp-burette" id="btpBuretteVis">
                    <div class="btp-burette-fill" id="btpFill" style="height:100%"></div>
                </div>
            </div>
            <div style="flex:1">
                <div class="btp-vol-display" id="btpVolDisplay">0.00 mL</div>
                <div class="btp-vol-label">ปริมาตรที่หยด</div>
                <div style="margin-top:6px;font-size:.72rem;color:#64748b">pH ปัจจุบัน:</div>
                <div style="font-family:'Share Tech Mono';font-size:1.1rem;color:#38bdf8" id="btpPhDisplay">7.00</div>
            </div>
        </div>
        <div class="btp-slider-row">
            <input type="range" class="btp-slider" id="btpSlider" min="0" max="5000" step="5" value="0">
            <button class="btp-drop-btn" id="btpDropBtn" onclick="btpDrop(0.5)">+0.5 mL</button>
        </div>
        <div style="display:flex;gap:6px;margin-bottom:10px;">
            <button class="btp-drop-btn" style="flex:1" onclick="btpDrop(0.05)">+0.05 mL</button>
            <button class="btp-drop-btn" style="flex:1" onclick="btpDrop(0.1)">+0.1 mL</button>
            <button class="btp-drop-btn" style="flex:1" onclick="btpReset()" style="color:#64748b">↺ Reset</button>
        </div>
        <!-- Indicator selector -->
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:.74rem;color:#94a3b8;">
            <span>Indicator:</span>
            <select id="btpIndicator" style="background:#1e293b;border:1px solid #334155;color:#f1f5f9;border-radius:6px;padding:3px 8px;font-family:Prompt;font-size:.72rem;">
                <option value="phenolphthalein">Phenolphthalein (8.2–10.0)</option>
                <option value="methyl_orange">Methyl Orange (3.1–4.4)</option>
                <option value="litmus">Litmus (5–8)</option>
                <option value="universal">Universal Indicator</option>
            </select>
        </div>
        <div class="btp-graph-label">pH vs Volume (mL)</div>
        <canvas id="btpCanvas" height="90"></canvas>
        <div class="btp-indicator" id="btpIndInfo">
            <div class="btp-ind-name">Phenolphthalein</div>
            <div>ใส → ชมพู ที่ pH > 8.2 | จุดสมมูลที่ pH ~9</div>
        </div>
    </div>
</div>`);
    }
})();

// ══════════════════════════════════════════════════════════════
// IODINE CLOCK ENGINE
// ══════════════════════════════════════════════════════════════
const ICP = {
    running: false,
    elapsed: 0,          // ms
    lastTick: 0,
    rafId: null,
    runs: [],            // [{conc, timeMs, label}]
    // Concentration → expected time (Landolt reaction kinetics)
    // Na₂S₂O₃ + HCl: higher conc = faster (1/[S₂O₃²⁻] relationship)
    calcExpectedSec(conc) {
        // empirical: t ≈ k / [conc]   where k ≈ 3.0 (adjusted for lab scale)
        return Math.max(3, Math.round(3.0 / Math.max(0.01, conc)));
    },
    // Detect conc from beaker contents
    detectConc() {
        const app = window.app;
        if (!app?.state?.contents) return 0.1;
        const s2o3 = app.state.contents.find(c =>
            (c.name || '').toLowerCase().includes('thiosulfate') ||
            (c.name || '').includes('ไทโอซัลเฟต') ||
            (c.formula || '').includes('S2O3')
        );
        if (!s2o3) return 0.1;
        // ดึง concentration จาก formula หรือ name (0.1M / 0.3M)
        const m = (s2o3.name || '').match(/(\d+\.\d+)M/);
        return m ? parseFloat(m[1]) : 0.1;
    },
    // Check if reaction should auto-trigger (เมื่อ HCl เจอ Na₂S₂O₃)
    checkAutoTrigger(rxnType, contents) {
        if (!this.running) return;
        const hasS2O3 = contents.some(c => (c.name||'').includes('ไทโอซัลเฟต') || (c.formula||'').includes('S2O3'));
        const hasHCl  = contents.some(c => (c.name||'').toLowerCase().includes('hcl') || (c.name||'').includes('กรดไฮโดรคลอริก'));
        if (hasS2O3 && hasHCl) this.triggerOpaque();
    },
    triggerOpaque() {
        if (!this.running) return;
        this.stop();
        const conc = this.detectConc();
        const pred = parseInt(document.getElementById('icpPrediction')?.value) || 0;
        const actual = Math.round(this.elapsed / 1000 * 10) / 10;
        const predDiff = pred > 0 ? ` | ทำนาย ${pred}s (คลาดเคลื่อน ${Math.abs(actual-pred).toFixed(1)}s)` : '';

        this.runs.push({ conc, timeMs: this.elapsed, label:`${conc}M → ${actual}s${predDiff}` });
        this._renderRuns();
        this._drawGraph();

        // อัปเดต status
        const statusEl = document.getElementById('icpStatus');
        if (statusEl) statusEl.textContent = `⏹ ขุ่นแล้ว! ${actual}s${predDiff}`;

        // log
        window.app?.log(`⏱️ <span style="color:#fbbf24;font-weight:600">Iodine Clock: [${conc}M] เกิดตะกอน S° หลัง ${actual}s${predDiff}</span>`);

        // แสดง reaction card
        showReactionCard?.({
            product_name: `Iodine Clock — [${conc}M Na₂S₂O₃]`,
            reaction_type: 'iodine_clock',
            equation: 'Na₂S₂O₃ + HCl → Na₂SO₄ + S↓ + H₂O (ขุ่นทันที)',
            temperature_change: 1,
            observation_th: `สารละลายขุ่นขาวจาก S° หลัง ${actual}s — ความเข้มข้นสูงกว่า ปฏิกิริยาเร็วกว่า (Rate Law)`,
            result_gas: null, result_precipitate: 'S° ขุ่นขาว', safety_warning: null,
        }, `Na₂S₂O₃ ${conc}M`, 'HCl');

        checkQuestStep?.('reaction', { rxnType:'iodine_clock', ph: 7, conc });
        _logLabEvent?.('iodine_clock', { conc, actualSec: actual, predictedSec: pred });
    },
    start() {
        this.running   = true;
        this.lastTick  = performance.now();
        const btn = document.getElementById('icpStartBtn');
        if (btn) { btn.textContent = '⏹ Stop'; btn.classList.add('running'); }
        const st = document.getElementById('icpStatus');
        if (st) st.textContent = '⏱️ กำลังจับเวลา...';
        this._tick();
    },
    stop() {
        this.running = false;
        if (this.rafId) { cancelAnimationFrame(this.rafId); this.rafId=null; }
        const btn = document.getElementById('icpStartBtn');
        if (btn) { btn.textContent = '▶ Start'; btn.classList.remove('running'); }
        const d = document.getElementById('icpTimer');
        if (d) d.classList.replace('running','stopped');
    },
    reset() {
        this.stop();
        this.elapsed = 0;
        const d = document.getElementById('icpTimer');
        if (d) { d.textContent='00.0'; d.className='icp-timer'; }
        const st = document.getElementById('icpStatus');
        if (st) st.textContent = 'กด Start เพื่อจับเวลา';
    },
    _tick() {
        if (!this.running) return;
        const now  = performance.now();
        const wall = now - this.lastTick;
        this.elapsed += wall * timeSpeed;
        this.lastTick = now;
        const sec = this.elapsed / 1000;
        const d = document.getElementById('icpTimer');
        if (d) {
            d.textContent = sec < 100 ? sec.toFixed(1) : Math.round(sec) + '.0';
            if (!d.classList.contains('running')) d.classList.add('running');
        }
        // Auto-check trigger
        this.checkAutoTrigger('', window.app?.state?.contents || []);
        this.rafId = requestAnimationFrame(() => this._tick());
    },
    _renderRuns() {
        const el = document.getElementById('icpRuns');
        if (!el || !this.runs.length) return;
        el.innerHTML = `<div style="font-size:.62rem;color:#475569;text-transform:uppercase;letter-spacing:.1em;margin-bottom:6px">ผลเปรียบเทียบ</div>` +
            this.runs.map((r,i) => {
                const faster = i > 0 ? ((this.runs[i-1].timeMs - r.timeMs)/1000).toFixed(1) : null;
                return `<div class="icp-run-item">
                    <span class="conc">${r.label.split('→')[0].trim()}</span>
                    <span class="time">${(r.timeMs/1000).toFixed(1)}s</span>
                    ${faster ? `<span class="diff">↑ ${Math.abs(faster)}s</span>` : ''}
                </div>`;
            }).join('');
    },
    _drawGraph() {
        const canvas = document.getElementById('icpCanvas');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        canvas.width  = canvas.offsetWidth  || 220;
        canvas.height = canvas.offsetHeight || 80;
        const { width:W, height:H } = canvas;
        ctx.clearRect(0,0,W,H);
        if (!this.runs.length) return;

        // วาดเส้น Conc vs Time (2 runs เปรียบเทียบ)
        const COLORS = ['#38bdf8','#a78bfa','#22d3ee','#f472b6'];
        this.runs.slice(-4).forEach((r,i) => {
            const conc = r.conc;
            const tEnd = r.timeMs/1000;
            const k    = this.calcExpectedSec(conc);
            ctx.beginPath();
            ctx.strokeStyle = COLORS[i];
            ctx.lineWidth   = 2;
            for (let t=0; t<=tEnd; t+=0.5) {
                const concT = Math.max(0, conc - (conc/k)*t);
                const x = (t/Math.max(tEnd,1)) * (W-20) + 10;
                const y = H - (concT/conc)*(H-16) - 8;
                t===0 ? ctx.moveTo(x,y) : ctx.lineTo(x,y);
            }
            ctx.stroke();
            // label
            ctx.fillStyle = COLORS[i];
            ctx.font      = `${Math.round(H*0.14)}px 'Share Tech Mono'`;
            ctx.fillText(`${conc}M`, W - 40, 14 + i*14);
        });

        // แกน
        ctx.strokeStyle = '#1e293b'; ctx.lineWidth = 1;
        ctx.beginPath(); ctx.moveTo(10,H-8); ctx.lineTo(W-4,H-8); ctx.stroke(); // X
        ctx.beginPath(); ctx.moveTo(10,8);   ctx.lineTo(10,H-8);  ctx.stroke(); // Y
        ctx.fillStyle = '#334155'; ctx.font = `${Math.round(H*0.12)}px Prompt`;
        ctx.fillText('[Conc]',12,16); ctx.fillText('Time →',W-42,H-1);
    },
};

function icpToggle() { ICP.running ? ICP.stop() : ICP.start(); }
function icpReset()  { ICP.reset(); ICP.runs=[]; document.getElementById('icpRuns').innerHTML=''; }

// ── Open Iodine Clock (trigger จาก quest หรือ equipment) ──
function openIodineClock() {
    document.getElementById('iodineClockPanel')?.classList.add('open');
    document.getElementById('buretteTitrPanel')?.classList.remove('open');
    ICP.reset();
    window.app?.log('⏱️ <span style="color:#fbbf24">เปิด Iodine Clock — จับเวลาปฏิกิริยา Na₂S₂O₃ + HCl</span>');
}

// ══════════════════════════════════════════════════════════════
// BURETTE TITRATION ENGINE
// ══════════════════════════════════════════════════════════════
const BTP = {
    volumeDropped: 0,   // mL
    maxVol: 50,         // mL burette
    phData: [],         // [{vol, ph}]
    endpointReached: false,

    // Indicator ranges
    INDICATORS: {
        phenolphthalein: { name:'Phenolphthalein', range:[8.2,10.0], color_acid:'#f1f5f9', color_base:'#fbcfe8', desc:'ใส → ชมพู ที่ pH > 8.2' },
        methyl_orange:   { name:'Methyl Orange',   range:[3.1,4.4],  color_acid:'#f97316', color_base:'#fde047', desc:'แดง → เหลือง ที่ pH > 4.4' },
        litmus:          { name:'Litmus',           range:[5.0,8.0],  color_acid:'#ef4444', color_base:'#3b82f6', desc:'แดง → น้ำเงิน ที่ pH > 7' },
        universal:       { name:'Universal Indicator', range:[0,14], color_acid:'#ef4444', color_base:'#3b82f6', desc:'เปลี่ยนสีตาม pH' },
    },

    // pH simulation จาก vol dropped (Strong acid-base titration)
    calcPH(volDropped) {
        const app = window.app;
        if (!app) return 7;
        const initPH    = app.state.currentPH || 7;
        const initVol   = Math.max(1, app.state.volume || 10);
        // จำลอง: เติม base (buret) ลงใน acid
        if (initPH < 7) {
            // Acid in beaker, Base in burette
            const molesAcid = initVol * (7 - initPH) * 0.001;
            const molesBase = volDropped * 0.1 * 0.001;
            const excess    = molesBase - molesAcid;
            if (excess < -0.0001) return Math.max(1, 14 + Math.log10(molesAcid - molesBase));
            if (excess < 0.0001)  return 7;
            return Math.min(13, 14 + Math.log10(excess));
        } else {
            // Base in beaker, Acid in burette
            const molesBase = initVol * (initPH - 7) * 0.001;
            const molesAcid = volDropped * 0.1 * 0.001;
            const excess    = molesBase - molesAcid;
            if (excess < -0.0001) return Math.min(13, 14 + Math.log10(molesAcid - molesBase));
            if (excess < 0.0001)  return 7;
            return Math.max(1, 14 - Math.log10(excess));
        }
    },

    drop(mL) {
        if (this.volumeDropped + mL > this.maxVol) {
            window.app?.log('⚠️ Burette หมดแล้ว (50 mL)');
            return;
        }
        this.volumeDropped = Math.round((this.volumeDropped + mL) * 100) / 100;
        const newPH = this.calcPH(this.volumeDropped);
        this.phData.push({ vol: this.volumeDropped, ph: newPH });

        // อัปเดต app state
        if (window.app) window.app.state.currentPH = newPH;

        // อัปเดต UI
        const fillPct = (1 - this.volumeDropped/this.maxVol)*100;
        const fill = document.getElementById('btpFill');
        if (fill) fill.style.height = Math.max(0,fillPct) + '%';
        const volEl = document.getElementById('btpVolDisplay');
        if (volEl) volEl.textContent = this.volumeDropped.toFixed(2) + ' mL';
        const phEl = document.getElementById('btpPhDisplay');
        if (phEl) { phEl.textContent = newPH.toFixed(2); phEl.style.color = newPH<7?'#fb923c':newPH>7?'#38bdf8':'#22c55e'; }

        // sync slider
        const slider = document.getElementById('btpSlider');
        if (slider) slider.value = Math.round(this.volumeDropped * 100);

        this._checkEndpoint(newPH);
        this._drawGraph();
        this._updateIndicatorColor(newPH);
        checkQuestStep?.('measure_ph', { ph: newPH });
        checkQuestStep?.('ph_range',   { ph: newPH });
    },

    _checkEndpoint(ph) {
        if (this.endpointReached) return;
        const indKey = document.getElementById('btpIndicator')?.value || 'phenolphthalein';
        const ind    = this.INDICATORS[indKey];
        if (!ind) return;
        const [mn, mx] = ind.range;
        if (ph >= mn && ph <= mx) {
            this.endpointReached = true;
            // Flash overlay
            const ov = document.getElementById('endpointOverlay');
            if (ov) { ov.style.display='block'; setTimeout(()=>ov.style.display='none',700); }
            window.app?.log(`🎯 <span style="color:#f472b6;font-weight:700">จุด Endpoint! pH ${ph.toFixed(2)} — ${ind.name} เปลี่ยนสี — ปริมาตรรวม ${this.volumeDropped.toFixed(2)} mL</span>`);
            showReactionCard?.({
                product_name: `Endpoint — ${ind.name} เปลี่ยนสี`,
                reaction_type: 'neutralization',
                equation: `Acid + Base → Salt + H₂O (pH = ${ph.toFixed(2)})`,
                temperature_change: 55.8,
                observation_th: `${ind.name}: ${ind.desc} — จุดสมมูลที่ pH ${ph.toFixed(2)} ใช้ ${this.volumeDropped.toFixed(2)} mL`,
                result_gas: null, result_precipitate: null, safety_warning: null,
            }, 'Base (Burette)', 'Acid (Beaker)');
            checkQuestStep?.('reaction', { rxnType:'neutralization', ph });
            _logLabEvent?.('titration_endpoint', { ph, volDropped: this.volumeDropped, indicator: indKey });
        }
    },

    _drawGraph() {
        const canvas = document.getElementById('btpCanvas');
        if (!canvas || !this.phData.length) return;
        canvas.width  = canvas.offsetWidth  || 240;
        canvas.height = canvas.offsetHeight || 90;
        const { width:W, height:H } = canvas;
        const ctx  = canvas.getContext('2d');
        ctx.clearRect(0,0,W,H);
        const maxVol = Math.max(10, this.volumeDropped);

        // grid line pH=7
        ctx.strokeStyle='#1e293b'; ctx.lineWidth=1; ctx.setLineDash([3,3]);
        const y7 = H - ((7/14)*(H-16)) - 8;
        ctx.beginPath(); ctx.moveTo(10,y7); ctx.lineTo(W-4,y7); ctx.stroke();
        ctx.setLineDash([]);
        ctx.fillStyle='#334155'; ctx.font=`${Math.round(H*.12)}px 'Share Tech Mono'`; ctx.fillText('7',2,y7+4);

        // pH curve
        ctx.beginPath(); ctx.strokeStyle='#38bdf8'; ctx.lineWidth=2.5;
        this.phData.forEach(({vol,ph},i) => {
            const x = (vol/maxVol)*(W-16)+10;
            const y = H - (ph/14)*(H-16) - 8;
            i===0 ? ctx.moveTo(x,y) : ctx.lineTo(x,y);
        });
        ctx.stroke();

        // endpoint mark
        if (this.endpointReached) {
            const ep = this.phData.find(d => {
                const ind = this.INDICATORS[document.getElementById('btpIndicator')?.value||'phenolphthalein'];
                return d.ph >= (ind?.range[0]||8) && d.ph <= (ind?.range[1]||10);
            });
            if (ep) {
                const x = (ep.vol/maxVol)*(W-16)+10;
                const y = H - (ep.ph/14)*(H-16) - 8;
                ctx.beginPath(); ctx.arc(x,y,5,0,Math.PI*2);
                ctx.fillStyle='#f472b6'; ctx.fill();
            }
        }

        // axes
        ctx.strokeStyle='#1e293b'; ctx.lineWidth=1;
        ctx.beginPath(); ctx.moveTo(10,8); ctx.lineTo(10,H-8); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(10,H-8); ctx.lineTo(W-4,H-8); ctx.stroke();
        ctx.fillStyle='#334155'; ctx.font=`${Math.round(H*.12)}px Prompt`;
        ctx.fillText('pH',14,16); ctx.fillText('Vol →',W-36,H-1);
    },

    _updateIndicatorColor(ph) {
        const indKey = document.getElementById('btpIndicator')?.value || 'phenolphthalein';
        const ind    = this.INDICATORS[indKey];
        if (!ind) return;
        const [mn,mx] = ind.range;
        const inRange = ph >= mn && ph <= mx;
        const color   = ph < mn ? ind.color_acid : (ph > mx ? ind.color_base : '#a78bfa');
        const infoEl  = document.getElementById('btpIndInfo');
        if (infoEl) {
            infoEl.style.background = color + '22';
            infoEl.style.borderColor = color + '44';
            infoEl.querySelector('.btp-ind-name').style.color = color;
        }
    },

    reset() {
        this.volumeDropped = 0; this.phData = []; this.endpointReached = false;
        const fill = document.getElementById('btpFill'); if (fill) fill.style.height='100%';
        const vol  = document.getElementById('btpVolDisplay'); if (vol) vol.textContent='0.00 mL';
        const ph   = document.getElementById('btpPhDisplay'); if (ph) { ph.textContent='7.00'; ph.style.color='#22c55e'; }
        const sl   = document.getElementById('btpSlider'); if (sl) sl.value=0;
        const canvas = document.getElementById('btpCanvas');
        if (canvas) { const ctx=canvas.getContext('2d'); ctx.clearRect(0,0,canvas.width,canvas.height); }
    },
};

// slider ↔ drop sync
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('btpSlider')?.addEventListener('input', function() {
        const target = this.value / 100;
        if (target > BTP.volumeDropped) BTP.drop(target - BTP.volumeDropped);
    });
    document.getElementById('btpIndicator')?.addEventListener('change', () => {
        BTP._updateIndicatorColor(window.app?.state?.currentPH || 7);
        const ind = BTP.INDICATORS[document.getElementById('btpIndicator').value];
        if (ind) {
            const el = document.getElementById('btpIndInfo');
            if (el) {
                el.querySelector('.btp-ind-name').textContent = ind.name;
                el.querySelector('div:last-child').textContent = ind.desc;
            }
        }
    });
});

function btpDrop(mL)  { BTP.drop(mL); }
function btpReset()   { BTP.reset(); }

function openBuretteTitr() {
    document.getElementById('buretteTitrPanel')?.classList.add('open');
    document.getElementById('iodineClockPanel')?.classList.remove('open');
    window.app?.log('💧 <span style="color:#f472b6">เปิด Burette Titration — หยด 0.05 mL/ครั้ง</span>');
}
function closeBuretteTitr() {
    document.getElementById('buretteTitrPanel')?.classList.remove('open');
}

// ── Patch: ตรวจ iodine clock reaction จาก processReaction ──
function patchIodineClockDetect(app) {
    if (app._icpPatched) return;
    app._icpPatched = true;
    const origRxn = app.processReaction.bind(app);
    app.processReaction = function(rxn, c1, c2, i1, i2) {
        origRxn.call(this, rxn, c1, c2, i1, i2);
        // ตรวจว่าเป็น precipitation (ตะกอน S°) ที่เกิดจาก Na₂S₂O₃ + HCl
        if (rxn.reaction_type === 'precipitation' &&
            (c1?.name||'').includes('ไทโอซัลเฟต') || (c2?.name||'').includes('ไทโอซัลเฟต')) {
            ICP.triggerOpaque();
        }
    };
}

// ── เพิ่ม Iodine Clock + Titration ใน Equipment Tray ──
function injectIodineAndTitrButtons() {
    const tray = document.getElementById('equipTray');
    if (!tray || document.getElementById('equipIodineClock')) return;

    const divider = document.createElement('div');
    divider.style.cssText = 'width:1px;height:36px;background:#1e293b;flex-shrink:0;margin:0 2px';

    const icpSlot = document.createElement('div');
    icpSlot.id = 'equipIodineClock';
    icpSlot.className = 'equip-slot';
    icpSlot.dataset.equip = 'iodine_clock';
    icpSlot.title = 'Iodine Clock — จับเวลาปฏิกิริยา';
    icpSlot.style.cssText = 'flex-shrink:0;background:#1e293b;border:1px solid #334155;border-radius:8px;padding:6px 10px;cursor:pointer;transition:.2s;text-align:center;min-width:72px;';
    icpSlot.innerHTML = `<div style="font-size:1.3rem;line-height:1.1">⏱️</div>
        <div style="font-size:.62rem;color:#94a3b8;margin-top:2px">Iodine Clock</div>
        <div style="font-size:.58rem;color:#fbbf24">Kinetics</div>`;
    icpSlot.addEventListener('click', openIodineClock);

    const btrSlot = document.createElement('div');
    btrSlot.id = 'equipBurette2';
    btrSlot.className = 'equip-slot';
    btrSlot.dataset.equip = 'burette_titr';
    btrSlot.title = 'Burette Titration — หยด mL ทีละน้อย';
    btrSlot.style.cssText = icpSlot.style.cssText;
    btrSlot.innerHTML = `<div style="font-size:1.3rem;line-height:1.1">🧫</div>
        <div style="font-size:.62rem;color:#94a3b8;margin-top:2px">Titration</div>
        <div style="font-size:.58rem;color:#f472b6">pH graph</div>`;
    btrSlot.addEventListener('click', openBuretteTitr);

    const closeBtn = document.getElementById('btnCloseTray');
    // closeBtn อยู่ใน wrapper div — insert ก่อน wrapper ที่เป็นลูกจริงของ tray
    let anchor = closeBtn;
    while (anchor && anchor.parentNode !== tray) anchor = anchor.parentNode;
    if (anchor && anchor.parentNode === tray) {
        tray.insertBefore(divider, anchor);
        tray.insertBefore(icpSlot, anchor);
        tray.insertBefore(btrSlot, anchor);
    } else {
        tray.appendChild(divider);
        tray.appendChild(icpSlot);
        tray.appendChild(btrSlot);
    }
}

// ── expose สำหรับ quest 14, 29 (Iodine Clock) ──
window._openIodineClock  = openIodineClock;
window._openBuretteTitr  = openBuretteTitr;


// ══════════════════════════════════════════════════════════════
// STEP 10 — AI LAB ASSISTANT (Claude API)
// ══════════════════════════════════════════════════════════════
(function injectStep10() {
    if (document.getElementById('step10Styles')) return;
    const s = document.createElement('style');
    s.id = 'step10Styles';
    s.textContent = `
/* ── AI Chat Panel ── */
@keyframes aiPanelIn { from{opacity:0;transform:translateX(20px)} to{opacity:1;transform:translateX(0)} }
#aiLabPanel {
    position:fixed;right:0;top:0;bottom:0;z-index:8600;
    width:340px;background:#0a1628;border-left:1px solid #1e293b;
    display:none;flex-direction:column;font-family:'Prompt';
    box-shadow:-8px 0 30px rgba(0,0,0,.6);
}
#aiLabPanel.open { display:flex;animation:aiPanelIn .3s ease; }
.ai-head { padding:14px 16px;border-bottom:1px solid #1e293b;display:flex;align-items:center;gap:10px;flex-shrink:0;background:#0f1f38; }
.ai-head-icon { font-size:1.5rem; }
.ai-head-title { flex:1; }
.ai-head-title h2 { font-size:.9rem;font-weight:700;color:#f1f5f9;margin:0; }
.ai-head-title p  { font-size:.65rem;color:#475569;margin:0; }
.ai-head-close { background:none;border:none;color:#475569;cursor:pointer;font-size:1.1rem; }
.ai-badge { display:inline-block;font-size:.6rem;font-weight:700;padding:1px 7px;border-radius:10px;background:rgba(56,189,248,.15);color:#38bdf8;border:1px solid rgba(56,189,248,.3);margin-left:6px;vertical-align:middle; }

/* Messages */
.ai-messages { flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px; }
.ai-msg { display:flex;gap:8px;align-items:flex-start; }
.ai-msg.user { flex-direction:row-reverse; }
.ai-msg-bubble {
    max-width:85%;padding:10px 13px;border-radius:12px;font-size:.78rem;line-height:1.6;
}
.ai-msg.ai   .ai-msg-bubble { background:#1e293b;color:#cbd5e1;border-radius:4px 12px 12px 12px; }
.ai-msg.user .ai-msg-bubble { background:rgba(56,189,248,.15);color:#7dd3fc;border-radius:12px 4px 12px 12px; }
.ai-msg-avatar { width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;margin-top:2px; }
.ai-msg.ai   .ai-msg-avatar { background:#1e3050; }
.ai-msg.user .ai-msg-avatar { background:rgba(56,189,248,.1); }
.ai-thinking { display:flex;gap:4px;align-items:center;padding:8px 12px; }
.ai-thinking span { width:6px;height:6px;border-radius:50%;background:#38bdf8;animation:aiDot 1.2s infinite; }
.ai-thinking span:nth-child(2) { animation-delay:.2s; }
.ai-thinking span:nth-child(3) { animation-delay:.4s; }
@keyframes aiDot { 0%,80%,100%{transform:scale(.6);opacity:.4} 40%{transform:scale(1);opacity:1} }

/* Suggestion chips */
.ai-chips { display:flex;flex-wrap:wrap;gap:5px;padding:0 14px 8px; }
.ai-chip {
    font-size:.67rem;padding:4px 10px;border-radius:20px;
    background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.2);
    color:#38bdf8;cursor:pointer;transition:.15s;
}
.ai-chip:hover { background:rgba(56,189,248,.18); }

/* Input */
.ai-input-row { padding:10px 14px;border-top:1px solid #1e293b;display:flex;gap:8px;flex-shrink:0; }
.ai-input {
    flex:1;background:#1e293b;border:1px solid #334155;border-radius:10px;
    padding:9px 13px;color:#f1f5f9;font-family:'Prompt';font-size:.78rem;
    resize:none;outline:none;height:40px;transition:.2s;
}
.ai-input:focus { border-color:#38bdf8;box-shadow:0 0 0 2px rgba(56,189,248,.1); }
.ai-send-btn {
    width:40px;height:40px;border-radius:10px;background:#38bdf8;border:none;
    color:#0f172a;cursor:pointer;font-size:1rem;font-weight:700;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;transition:.15s;
}
.ai-send-btn:hover { background:#7dd3fc; }
.ai-send-btn:disabled { background:#334155;color:#475569;cursor:not-allowed; }

/* Quick analyze button */
.ai-analyze-btn {
    margin:0 14px 8px;padding:9px 0;width:calc(100% - 28px);
    background:rgba(56,189,248,.1);border:1px solid rgba(56,189,248,.3);
    color:#38bdf8;border-radius:10px;cursor:pointer;font-family:'Prompt';
    font-size:.78rem;font-weight:600;transition:.15s;text-align:center;
}
.ai-analyze-btn:hover { background:rgba(56,189,248,.2); }

/* Lab AI button in header */
#btnAILab {
    background:linear-gradient(135deg,rgba(56,189,248,.15),rgba(167,139,250,.15));
    border:1px solid rgba(56,189,248,.35);color:#7dd3fc;
    border-radius:8px;padding:5px 12px;cursor:pointer;
    font-family:'Prompt';font-size:.75rem;font-weight:600;margin-left:6px;
    transition:.2s;
}
#btnAILab:hover { background:rgba(56,189,248,.2); }
`;
    document.head.appendChild(s);

    // ── AI Panel HTML ──
    if (!document.getElementById('aiLabPanel')) {
        document.body.insertAdjacentHTML('beforeend', `
<div id="aiLabPanel">
    <div class="ai-head">
        <div class="ai-head-icon">🤖</div>
        <div class="ai-head-title">
            <h2>AI Lab Assistant <span class="ai-badge">Claude</span></h2>
            <p>ผู้ช่วยวิทยาศาสตร์ — ถามได้ทุกเรื่อง</p>
        </div>
        <button class="ai-head-close" onclick="closeAIPanel()">✕</button>
    </div>
    <div class="ai-messages" id="aiMessages">
        <div class="ai-msg ai">
            <div class="ai-msg-avatar">🧪</div>
            <div class="ai-msg-bubble">
                สวัสดีครับ! ผมเป็น AI ผู้ช่วยทดลองเคมี ถามได้เลยเกี่ยวกับ:
                <br>• ปฏิกิริยาที่เกิดขึ้น
                <br>• หลักการทางวิทยาศาสตร์
                <br>• ความปลอดภัยในการทดลอง
                <br>• หรือให้วิเคราะห์ผลทดลองของคุณ
            </div>
        </div>
    </div>
    <button class="ai-analyze-btn" id="aiAnalyzeBtn" onclick="aiAnalyzeSession()">
        📊 วิเคราะห์ผลการทดลองทั้งหมด
    </button>
    <div class="ai-chips" id="aiChips">
        <span class="ai-chip" onclick="aiAsk('HCl กับ NaOH เกิดปฏิกิริยาอะไร?')">HCl + NaOH คืออะไร?</span>
        <span class="ai-chip" onclick="aiAsk('ทำไม Mg ถึงเผาไหม้สว่างมาก?')">Mg เผาไหม้สว่างทำไม?</span>
        <span class="ai-chip" onclick="aiAsk('ความปลอดภัยเมื่อใช้กรดเข้มข้น')">ความปลอดภัยกรดเข้มข้น</span>
        <span class="ai-chip" onclick="aiAsk('อธิบาย pH และกรด-ด่าง')">pH คืออะไร?</span>
        <span class="ai-chip" onclick="aiAsk('Iodine Clock ทดสอบอัตราปฏิกิริยาอย่างไร?')">Iodine Clock อธิบาย</span>
        <span class="ai-chip" onclick="aiAsk('ทำไมต้องสวมแว่นตาในการทดลองเคมี?')">เหตุใดต้องสวม PPE?</span>
    </div>
    <div class="ai-input-row">
        <textarea class="ai-input" id="aiInput" placeholder="ถามเกี่ยวกับเคมีหรือการทดลอง..." rows="1"
            onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();aiSend();}"></textarea>
        <button class="ai-send-btn" id="aiSendBtn" onclick="aiSend()" title="ส่ง">→</button>
    </div>
</div>`);
    }
})();

// ══════════════════════════════════════════════════════════════
// AI ASSISTANT ENGINE
// ══════════════════════════════════════════════════════════════
const AI_HISTORY = [];  // [{role, content}] สำหรับ multi-turn

// System prompt สำหรับ AI ผู้ช่วยเคมีภาษาไทย
function _buildSystemPrompt() {
    const app = window.app;
    const reactions = LAB_LOG.filter(e => e.type === 'reaction');
    const beakerContents = app?.state?.contents?.map(c => `${c.name} (${c.amount?.toFixed(1)} ${c.state==='solid'?'g':'mL'})`).join(', ') || 'ว่าง';
    const temp  = app?.state?.temperature?.toFixed(1) || '25.0';
    const ph    = app?.state?.currentPH?.toFixed(2) || '7.00';
    const quest = _activeQuest ? `ภารกิจปัจจุบัน: ${_activeQuest.title}` : 'ไม่มีภารกิจ';

    return `คุณคือ "น้องเคม" — AI ผู้ช่วยห้องทดลองเคมีสำหรับนักเรียนมัธยมปลายในระบบ Smart School Plus Virtual Chemistry Lab
    
สถานะห้องแล็บตอนนี้:
- สารในบีกเกอร์: ${beakerContents}
- อุณหภูมิ: ${temp}°C | pH: ${ph}
- ${quest}
- ปฏิกิริยาที่เกิดแล้ว: ${reactions.length} ครั้ง
${reactions.slice(-3).map(r => `  • ${r.c1||'?'} + ${r.c2||'?'} → ${r.product||'?'} (${r.rxnType||''})`).join('\n')}

กฎการตอบ:
1. ตอบภาษาไทยเสมอ กระชับและเข้าใจง่าย
2. ใช้หลักการวิทยาศาสตร์ที่ถูกต้อง อ้างอิง IUPAC/NIST ถ้าเหมาะสม
3. ให้คำแนะนำความปลอดภัยเมื่อเกี่ยวข้อง
4. ถ้าถามเรื่องผลการทดลอง ให้อ้างอิงข้อมูลจริงจากห้องแล็บ
5. ตอบไม่เกิน 200 คำ ใช้ bullet points ถ้าเหมาะสม
6. ห้ามแนะนำการทดลองที่อันตรายจริง`;
}

let _aiTyping = false;

async function aiSend(customMsg) {
    const input = document.getElementById('aiInput');
    const msg = customMsg || input?.value?.trim();
    if (!msg || _aiTyping) return;
    if (input && !customMsg) input.value = '';

    // แสดง user message
    _appendMessage('user', msg);

    // เพิ่มใน history
    AI_HISTORY.push({ role: 'user', content: msg });

    // แสดง thinking indicator
    _showThinking();
    _aiTyping = true;

    const sendBtn = document.getElementById('aiSendBtn');
    if (sendBtn) sendBtn.disabled = true;

    try {
        const response = await fetch('https://api.anthropic.com/v1/messages', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                model:      'claude-sonnet-4-6',
                max_tokens: 1000,
                system:     _buildSystemPrompt(),
                messages:   AI_HISTORY.slice(-8),  // max 8 turns
            }),
        });

        const data = await response.json();
        const reply = data.content?.[0]?.text || 'ขอโทษครับ ไม่สามารถตอบได้ในขณะนี้';

        // เพิ่มใน history
        AI_HISTORY.push({ role: 'assistant', content: reply });

        _hideThinking();
        _appendMessage('ai', reply);

        // log
        _logLabEvent?.('ai_chat', { question: msg, answer: reply.substring(0,100) });

    } catch(err) {
        _hideThinking();
        _appendMessage('ai', `⚠️ ไม่สามารถเชื่อมต่อ AI ได้: ${err.message || 'ตรวจสอบ Internet connection'}`);
    } finally {
        _aiTyping = false;
        if (sendBtn) sendBtn.disabled = false;
    }
}

// ── ให้ AI วิเคราะห์ session ทั้งหมด ──────────────────────────
async function aiAnalyzeSession() {
    const reactions = LAB_LOG.filter(e => e.type === 'reaction');
    const ppeLog    = LAB_LOG.filter(e => e.type === 'ppe');
    const gogglesOn = document.getElementById('chkGoggles')?.checked;
    const glovesOn  = document.getElementById('chkGloves')?.checked;
    const app       = window.app;

    if (reactions.length === 0) {
        aiAsk('ฉันยังไม่ได้ทดลองอะไรเลย ควรเริ่มต้นจากอะไรดี?');
        return;
    }

    const analysisPrompt = `วิเคราะห์ผลการทดลองของฉันทั้งหมด:

ปฏิกิริยาที่เกิดขึ้น (${reactions.length} ครั้ง):
${reactions.map((r,i) => `${i+1}. ${r.c1} + ${r.c2} → ${r.product} (${r.rxnType}) ΔT=${r.dT>0?'+':''}${r.dT?.toFixed(1)||0}°C`).join('\n')}

PPE:
- แว่นตา: ${gogglesOn ? 'สวมตลอด ✓' : 'ไม่ได้สวม ✗'}
- ถุงมือ: ${glovesOn ? 'สวมตลอด ✓' : 'ไม่ได้สวม ✗'}

HP คงเหลือ: ${app?.state?.hp || 100}%
ภารกิจ: ${_activeQuest ? _activeQuest.title : 'ไม่มี'}

กรุณาวิเคราะห์:
1. จุดแข็ง — ทำอะไรได้ดี
2. จุดที่ต้องปรับปรุง
3. หลักการวิทยาศาสตร์ที่ได้เรียนรู้
4. คำแนะนำสำหรับการทดลองครั้งต่อไป`;

    document.getElementById('aiAnalyzeBtn').textContent = '⏳ กำลังวิเคราะห์...';
    document.getElementById('aiAnalyzeBtn').disabled = true;

    await aiSend(analysisPrompt);

    document.getElementById('aiAnalyzeBtn').textContent = '📊 วิเคราะห์ผลการทดลองทั้งหมด';
    document.getElementById('aiAnalyzeBtn').disabled = false;
}

function aiAsk(question) {
    const input = document.getElementById('aiInput');
    if (input) input.value = question;
    aiSend();
}

function _appendMessage(role, text) {
    const el = document.getElementById('aiMessages');
    if (!el) return;
    const div = document.createElement('div');
    div.className = `ai-msg ${role}`;

    // format text — แปลง markdown เบื้องต้น
    const formatted = text
        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.*?)\*/g, '<em>$1</em>')
        .replace(/`(.*?)`/g, '<code style="background:#0f172a;padding:1px 5px;border-radius:3px;font-family:monospace;font-size:.75rem">$1</code>')
        .replace(/^• /gm, '• ')
        .replace(/\n/g, '<br>');

    div.innerHTML = `
        <div class="ai-msg-avatar">${role === 'ai' ? '🧪' : '👨‍🎓'}</div>
        <div class="ai-msg-bubble">${formatted}</div>`;
    el.appendChild(div);
    el.scrollTop = el.scrollHeight;
}

function _showThinking() {
    const el = document.getElementById('aiMessages');
    if (!el) return;
    const div = document.createElement('div');
    div.className = 'ai-msg ai';
    div.id = 'aiThinking';
    div.innerHTML = `<div class="ai-msg-avatar">🧪</div>
        <div class="ai-thinking"><span></span><span></span><span></span></div>`;
    el.appendChild(div);
    el.scrollTop = el.scrollHeight;
}
function _hideThinking() {
    document.getElementById('aiThinking')?.remove();
}

function openAIPanel() {
    document.getElementById('aiLabPanel')?.classList.add('open');
    document.getElementById('tutorialPanel')?.classList.remove('open');
    document.getElementById('aiInput')?.focus();
}
function closeAIPanel() {
    document.getElementById('aiLabPanel')?.classList.remove('open');
}

// ── เพิ่มปุ่ม AI ใน header ──
function injectAIButton() {
    if (document.getElementById('btnAILab')) return;
    const refBtn = document.getElementById('btnTutorialOpen');
    if (!refBtn) return;
    const btn = document.createElement('button');
    btn.id = 'btnAILab';
    btn.textContent = '🤖 AI';
    btn.title = 'AI Lab Assistant — ถามเกี่ยวกับเคมีได้เลย';
    btn.addEventListener('click', openAIPanel);
    refBtn.parentNode.insertBefore(btn, refBtn);
}

// ── Patch Post-Lab: เพิ่ม AI analysis ──
function patchPostLabWithAI(app) {
    if (app._aiPostLabPatched) return;
    app._aiPostLabPatched = true;

    // override sendPostLab เพิ่ม AI analysis ก่อนส่ง
    const origSend = window.sendPostLab;
    window.sendPostLab = async function(app) {
        // ขอ AI วิเคราะห์ก่อน (non-blocking)
        const reactions = LAB_LOG.filter(e => e.type === 'reaction');
        if (reactions.length > 0 && !_aiTyping) {
            try {
                const r = await fetch('https://api.anthropic.com/v1/messages', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        model: 'claude-sonnet-4-6', max_tokens: 600,
                        system: 'คุณคือระบบวิเคราะห์ผลการทดลองเคมี ตอบภาษาไทย กระชับ',
                        messages: [{ role:'user', content:
                            `วิเคราะห์การทดลองนี้ใน 3 หัวข้อ: จุดแข็ง, จุดปรับปรุง, ข้อสรุป\n` +
                            `ปฏิกิริยา: ${reactions.map(r=>`${r.c1}+${r.c2}→${r.product}(${r.rxnType})`).join(', ')}\n` +
                            `PPE: แว่น=${document.getElementById('chkGoggles')?.checked?'ใส่':'ไม่ใส่'} ถุงมือ=${document.getElementById('chkGloves')?.checked?'ใส่':'ไม่ใส่'}`
                        }],
                    }),
                });
                const d = await r.json();
                const aiText = d.content?.[0]?.text || '';
                if (aiText) {
                    // inject ลงใน Post-Lab modal
                    const plBody = document.getElementById('plBody');
                    if (plBody) {
                        const aiSection = document.createElement('div');
                        aiSection.style.cssText = 'background:rgba(56,189,248,.06);border:1px solid rgba(56,189,248,.2);border-radius:10px;padding:14px 16px;margin-bottom:16px;';
                        aiSection.innerHTML = `<div style="font-size:.65rem;color:#38bdf8;font-weight:600;text-transform:uppercase;letter-spacing:.1em;margin-bottom:8px">🤖 AI วิเคราะห์ผลการทดลอง</div>
                            <div style="font-size:.8rem;color:#94a3b8;line-height:1.65">${aiText.replace(/\n/g,'<br>').replace(/\*\*(.*?)\*\*/g,'<strong>$1</strong>')}</div>`;
                        plBody.insertBefore(aiSection, plBody.firstChild);
                    }
                }
            } catch(e) { /* AI ไม่ได้ทำให้ส่งไม่ได้ */ }
        }
        return origSend?.(app);
    };
}


// ══════════════════════════════════════════════════════════════
// STEP 11 — CHEMICAL INFO PANEL + GUIDED DISCOVERY MODE
// ══════════════════════════════════════════════════════════════
(function injectStep11() {
    if (document.getElementById('step11Styles')) return;
    const s = document.createElement('style');
    s.id = 'step11Styles';
    s.textContent = `
/* ── Chemical Info Panel ── */
@keyframes infoPanelIn { from{opacity:0;transform:scale(.95)} to{opacity:1;transform:scale(1)} }
#chemInfoPanel {
    position:fixed;z-index:9100;
    background:#0f172a;border:1px solid #334155;border-radius:14px;
    width:300px;font-family:'Prompt';
    box-shadow:0 12px 40px rgba(0,0,0,.7);
    display:none;
}
#chemInfoPanel.open { display:block;animation:infoPanelIn .2s ease; }

.cip-head { padding:13px 15px 10px;border-bottom:1px solid #1e293b;display:flex;align-items:flex-start;gap:10px; }
.cip-formula { font-family:'Share Tech Mono';font-size:1.1rem;color:#38bdf8;font-weight:700; }
.cip-name    { font-size:.82rem;color:#f1f5f9;font-weight:600;margin-bottom:2px; }
.cip-type    { font-size:.65rem;color:#475569;text-transform:uppercase;letter-spacing:.08em; }
.cip-close   { background:none;border:none;color:#334155;cursor:pointer;font-size:1rem;margin-left:auto;flex-shrink:0; }
.cip-body    { padding:12px 15px; }

/* pH bar */
.cip-ph-row   { margin-bottom:10px; }
.cip-ph-label { font-size:.62rem;color:#475569;text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px;display:flex;justify-content:space-between; }
.cip-ph-bar   { height:7px;border-radius:4px;background:linear-gradient(to right,#ef4444 0%,#f97316 20%,#eab308 40%,#22c55e 50%,#3b82f6 70%,#8b5cf6 100%);position:relative;margin-bottom:4px; }
.cip-ph-marker { position:absolute;top:-2px;width:11px;height:11px;border-radius:50%;background:#fff;border:2px solid #0f172a;box-shadow:0 0 6px rgba(0,0,0,.4);transform:translateX(-50%); }
.cip-ph-val   { font-family:'Share Tech Mono';font-size:1rem;color:#f1f5f9;font-weight:700; }
.cip-ph-desc  { font-size:.7rem;color:#94a3b8;margin-top:1px; }

/* GHS hazard */
.cip-ghs { display:flex;gap:5px;margin-bottom:10px;flex-wrap:wrap; }
.cip-ghs-badge { font-size:.68rem;padding:3px 9px;border-radius:20px;font-weight:600; }
.ghs0 { background:rgba(74,222,128,.12);color:#4ade80;border:1px solid rgba(74,222,128,.3); }
.ghs1 { background:rgba(234,179,8,.12);color:#fde047;border:1px solid rgba(234,179,8,.3); }
.ghs2 { background:rgba(245,158,11,.12);color:#fb923c;border:1px solid rgba(245,158,11,.3); }
.ghs3 { background:rgba(239,68,68,.12);color:#f87171;border:1px solid rgba(239,68,68,.3); }
.ghs4 { background:rgba(239,68,68,.2);color:#ef4444;border:1px solid rgba(239,68,68,.5); }

/* Info rows */
.cip-row { margin-bottom:9px;font-size:.78rem; }
.cip-row-label { font-size:.62rem;color:#475569;text-transform:uppercase;letter-spacing:.1em;display:block;margin-bottom:2px; }
.cip-row-text  { color:#94a3b8;line-height:1.55; }
.cip-row-text strong { color:#cbd5e1; }

/* Texture badge */
.cip-texture { display:inline-block;background:#1e293b;border:1px solid #334155;border-radius:6px;padding:3px 10px;font-size:.7rem;color:#94a3b8;margin-top:4px; }

/* ℹ️ button on chem-item */
.chem-info-btn {
    position:absolute;top:4px;right:4px;
    background:rgba(56,189,248,.12);border:1px solid rgba(56,189,248,.25);
    color:#38bdf8;border-radius:50%;width:18px;height:18px;
    font-size:.6rem;cursor:pointer;display:none;
    align-items:center;justify-content:center;line-height:1;
    transition:.15s;z-index:5;
}
.chem-item:hover .chem-info-btn { display:flex; }
.chem-info-btn:hover { background:rgba(56,189,248,.25); }

/* ── Guided Discovery Mode ── */
body.guided-mode .rxn-card-title,
body.guided-mode .rxnTitle { filter:blur(4px);user-select:none; }
body.guided-mode .rxnObsText { filter:blur(3px); }

#guidedOverlay {
    display:none;position:fixed;inset:0;z-index:7500;
    background:rgba(0,0,0,.0);pointer-events:none;
}
body.guided-mode #guidedOverlay { display:block; }

/* Guided answer panel */
#guidedAnswerPanel {
    position:fixed;bottom:72px;left:50%;transform:translateX(-50%);
    z-index:7600;background:#1e293b;border:1px solid #f59e0b;border-radius:14px;
    width:400px;max-width:94vw;padding:16px 18px;
    font-family:'Prompt';display:none;
    box-shadow:0 8px 30px rgba(245,158,11,.25);
}
#guidedAnswerPanel.open { display:block;animation:infoPanelIn .25s ease; }
.gap-title  { font-size:.82rem;font-weight:700;color:#fcd34d;margin-bottom:10px; }
.gap-obs    { font-size:.78rem;color:#94a3b8;margin-bottom:12px;line-height:1.5; }
.gap-choices { display:flex;flex-direction:column;gap:6px;margin-bottom:12px; }
.gap-choice {
    padding:9px 13px;border:1px solid #334155;border-radius:8px;
    cursor:pointer;font-size:.78rem;color:#94a3b8;
    background:#0f172a;transition:.15s;text-align:left;
}
.gap-choice:hover  { border-color:#f59e0b;color:#fcd34d; }
.gap-choice.correct { border-color:#22c55e;background:rgba(34,197,94,.1);color:#4ade80; }
.gap-choice.wrong   { border-color:#ef4444;background:rgba(239,68,68,.08);color:#f87171; }
.gap-feedback { font-size:.75rem;padding:8px 11px;border-radius:7px;display:none; }
.gap-feedback.show { display:block; }
.gap-feedback.ok  { background:rgba(34,197,94,.1);color:#4ade80; }
.gap-feedback.bad { background:rgba(239,68,68,.1);color:#f87171; }

/* Guided mode toggle */
#btnGuidedMode {
    font-size:.7rem;padding:3px 10px;border-radius:20px;cursor:pointer;
    font-family:'Prompt';font-weight:600;border:1px solid;margin-left:6px;
    transition:.15s;
}
#btnGuidedMode.off { background:rgba(100,116,139,.1);color:#64748b;border-color:#334155; }
#btnGuidedMode.on  { background:rgba(245,158,11,.15);color:#f59e0b;border-color:rgba(245,158,11,.4); }
`;
    document.head.appendChild(s);

    // Chemical Info Panel HTML
    if (!document.getElementById('chemInfoPanel')) {
        document.body.insertAdjacentHTML('beforeend', `
<div id="chemInfoPanel">
    <div class="cip-head">
        <div style="flex:1">
            <div class="cip-formula" id="cipFormula">—</div>
            <div class="cip-name"    id="cipName">ชื่อสาร</div>
            <div class="cip-type"    id="cipType">ประเภท</div>
        </div>
        <button class="cip-close" onclick="closeChemInfo()">✕</button>
    </div>
    <div class="cip-body">
        <div class="cip-ph-row" id="cipPHSection">
            <div class="cip-ph-label">
                <span>ค่า pH</span>
                <span class="cip-ph-val" id="cipPHVal">7.0</span>
            </div>
            <div class="cip-ph-bar"><div class="cip-ph-marker" id="cipPHMarker" style="left:50%"></div></div>
            <div class="cip-ph-desc" id="cipPHDesc">กลาง</div>
        </div>
        <div class="cip-ghs" id="cipGHS"></div>
        <div class="cip-row" id="cipDescRow">
            <span class="cip-row-label">คำอธิบาย</span>
            <div class="cip-row-text" id="cipDesc">—</div>
        </div>
        <div class="cip-row" id="cipRealWorldRow">
            <span class="cip-row-label">🌍 พบในชีวิตจริง</span>
            <div class="cip-row-text" id="cipRealWorld">—</div>
        </div>
        <div class="cip-row" id="cipAcidRow">
            <span class="cip-row-label">⚗️ ทำไมถึงเป็นกรด/ด่าง</span>
            <div class="cip-row-text" id="cipAcidExplain">—</div>
        </div>
        <div class="cip-row" id="cipTextureRow">
            <span class="cip-row-label">🔬 ลักษณะ</span>
            <div class="cip-row-text" id="cipTexture">—</div>
        </div>
        <button onclick="aiAsk && aiAsk('อธิบาย ' + document.getElementById(\'cipName\').textContent + ' ให้ฉันเข้าใจ')"
            style="width:100%;margin-top:8px;padding:7px;background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.2);color:#38bdf8;border-radius:7px;cursor:pointer;font-family:Prompt;font-size:.74rem;font-weight:600;">
            🤖 ถาม AI เพิ่มเติม
        </button>
    </div>
</div>`);
    }

    // Guided mode overlay + answer panel
    if (!document.getElementById('guidedOverlay')) {
        document.body.insertAdjacentHTML('beforeend', `
<div id="guidedOverlay"></div>
<div id="guidedAnswerPanel">
    <div class="gap-title" id="gapTitle">⚗️ สังเกตแล้วเกิดอะไรขึ้น?</div>
    <div class="gap-obs"   id="gapObs">สังเกตการเปลี่ยนแปลงในบีกเกอร์</div>
    <div class="gap-choices" id="gapChoices"></div>
    <div class="gap-feedback" id="gapFeedback"></div>
</div>`);
    }
})();

// ══════════════════════════════════════════════════════════════
// CHEMICAL INFO PANEL ENGINE
// ══════════════════════════════════════════════════════════════
const GHS_LABELS = ['ไม่มีอันตราย','ระวัง (GHS 1)','เตือน (GHS 2)','อันตราย (GHS 3)','อันตรายมาก (GHS 4)'];
const GHS_ICONS  = ['✅','⚠️','🔶','🔴','☠️'];
const PH_DESCS   = ph => ph < 2 ? 'กรดแก่มาก' : ph < 5 ? 'กรด' : ph < 6.5 ? 'กรดอ่อน' : ph > 11 ? 'ด่างแก่มาก' : ph > 9 ? 'ด่าง' : ph > 7.5 ? 'ด่างอ่อน' : 'กลาง';

function openChemInfo(chemId, anchorEl) {
    const panel = document.getElementById('chemInfoPanel');
    if (!panel) return;

    // หาข้อมูลจาก LAB_CONFIG.chemicalsData (full DB data)
    const all  = window.LAB_CONFIG?.chemicalsData || [];
    const chem = all.find(c => String(c.id) === String(chemId));
    if (!chem) return;

    // หรือจาก dataset ของ element
    const el    = anchorEl || document.querySelector(`[data-chem-id="${chemId}"]`);
    const phVal = chem.ph_value !== null && chem.ph_value !== undefined ? parseFloat(chem.ph_value) : null;
    const hazard= parseInt(chem.hazard_level || 0);

    // ── populate ──
    document.getElementById('cipFormula').textContent  = chem.formula || '—';
    document.getElementById('cipName').textContent     = chem.name    || '—';
    document.getElementById('cipType').textContent     = chem.type    || '';

    // pH section
    const phSection = document.getElementById('cipPHSection');
    if (phVal !== null) {
        phSection.style.display = 'block';
        document.getElementById('cipPHVal').textContent  = phVal.toFixed(1);
        document.getElementById('cipPHMarker').style.left = ((phVal/14)*100).toFixed(1) + '%';
        document.getElementById('cipPHDesc').textContent  = PH_DESCS(phVal);
    } else {
        phSection.style.display = 'none';
    }

    // GHS badges
    const ghsEl = document.getElementById('cipGHS');
    ghsEl.innerHTML = hazard >= 0 ? `
        <span class="cip-ghs-badge ghs${hazard}">${GHS_ICONS[hazard]||''}  ${GHS_LABELS[hazard]||''}</span>
        ${chem.toxicity > 0 ? `<span class="cip-ghs-badge ghs2">☠️ ความเป็นพิษ ${chem.toxicity}</span>` : ''}
    ` : '';

    // text fields
    const _set = (id, rowId, val) => {
        const el = document.getElementById(id);
        const row = document.getElementById(rowId);
        if (val && val.trim()) { if (el) el.textContent = val; if (row) row.style.display=''; }
        else { if (row) row.style.display='none'; }
    };
    _set('cipDesc',        'cipDescRow',       chem.description   || '');
    _set('cipRealWorld',   'cipRealWorldRow',   chem.real_world_example || '');
    _set('cipAcidExplain', 'cipAcidRow',       chem.acid_base_explain || '');
    _set('cipTexture',     'cipTextureRow',    chem.texture_type  || '');

    // ── position near anchor ──
    if (el) {
        const rect = el.getBoundingClientRect();
        const W = window.innerWidth, H = window.innerHeight;
        let left = rect.right + 10;
        let top  = rect.top;
        if (left + 310 > W) left = rect.left - 315;
        if (top  + 400 > H) top  = H - 410;
        panel.style.left = Math.max(8, left) + 'px';
        panel.style.top  = Math.max(8, top)  + 'px';
    } else {
        panel.style.left = '50%';
        panel.style.top  = '50%';
        panel.style.transform = 'translate(-50%,-50%)';
    }

    panel.classList.add('open');
}

function closeChemInfo() {
    document.getElementById('chemInfoPanel')?.classList.remove('open');
}

// ── Add ℹ️ button to each chem-item ───────────────────────────
function injectChemInfoButtons() {
    document.querySelectorAll('.chem-item[data-id]').forEach(item => {
        if (item.querySelector('.chem-info-btn')) return;
        item.style.position = 'relative';
        const btn = document.createElement('button');
        btn.className = 'chem-info-btn';
        btn.title     = 'ข้อมูลสาร';
        btn.textContent = 'ℹ';
        btn.addEventListener('click', e => {
            e.stopPropagation();
            openChemInfo(item.dataset.id, item);
        });
        item.appendChild(btn);
    });

    // ปิด panel เมื่อคลิกนอก
    document.addEventListener('click', e => {
        const panel = document.getElementById('chemInfoPanel');
        if (panel?.classList.contains('open') && !panel.contains(e.target) && !e.target.classList.contains('chem-info-btn')) {
            closeChemInfo();
        }
    });
}

// ── Double-click เพื่อเปิด info ──────────────────────────────
function patchChemItemDblClick(app) {
    document.querySelectorAll('.chem-item[data-id]').forEach(item => {
        item.addEventListener('dblclick', e => {
            e.preventDefault();
            openChemInfo(item.dataset.id, item);
        });
    });
}

// ══════════════════════════════════════════════════════════════
// GUIDED DISCOVERY MODE
// ══════════════════════════════════════════════════════════════
let _guidedMode = false;

// คลังคำถาม Multiple Choice ตาม reaction_type
const GUIDED_QUESTIONS = {
    neutralization: {
        q: 'ปฏิกิริยาที่เกิดขึ้นเป็นประเภทใด?',
        choices: ['การสะเทิน (Neutralization)','การตกตะกอน (Precipitation)','การเผาไหม้ (Combustion)','รีดอกซ์ (Redox)'],
        answer: 0,
        explain: 'กรด + ด่าง → เกลือ + น้ำ คือปฏิกิริยาสะเทิน (Neutralization) ΔH = -55.8 kJ/mol',
    },
    precipitation: {
        q: 'ตะกอนที่เกิดขึ้นเป็นผลมาจากอะไร?',
        choices: ['Q > Ksp (ผลคูณ ion เกินค่าละลาย)','การเผาไหม้','ปฏิกิริยาสะเทิน','การสลายตัวด้วยความร้อน'],
        answer: 0,
        explain: 'เมื่อ Q (ion product) > Ksp (solubility product) ไอออนจะรวมตัวเป็นตะกอนทันที',
    },
    redox: {
        q: 'ในปฏิกิริยานี้ อิเล็กตรอนถ่ายโอนไปในทิศทางใด?',
        choices: ['จากตัวรีดิวซ์ (reducing agent) ไปยังตัวออกซิไดซ์','จากตัวออกซิไดซ์ไปยังตัวรีดิวซ์','ไม่มีการถ่ายโอนอิเล็กตรอน','อิเล็กตรอนรวมตัวกันเป็น O₂'],
        answer: 0,
        explain: 'Redox: ตัวรีดิวซ์ให้ e⁻ (oxidation) ตัวออกซิไดซ์รับ e⁻ (reduction) — LEO GER',
    },
    acid_metal: {
        q: 'ก๊าซ H₂ เกิดขึ้นได้อย่างไร?',
        choices: ['โลหะแทนที่ H⁺ จากกรด ตาม Activity Series','กรดสลายตัวเองในอากาศ','น้ำแตกตัวด้วยกระแสไฟฟ้า','H₂ มีอยู่แล้วในกรด'],
        answer: 0,
        explain: 'โลหะที่อยู่เหนือ H ใน Activity Series จะแทนที่ H⁺: M + nH⁺ → Mⁿ⁺ + (n/2)H₂↑',
    },
    decomposition: {
        q: 'ปฏิกิริยาสลายตัวต้องการพลังงานหรือคายพลังงาน?',
        choices: ['ส่วนใหญ่ต้องการพลังงาน (endothermic)','คายพลังงานเสมอ','ไม่เกี่ยวกับพลังงาน','ขึ้นกับอุณหภูมิห้องเท่านั้น'],
        answer: 0,
        explain: 'การสลายตัวส่วนมาก endothermic เพราะต้องทำลายพันธะ แต่บางปฏิกิริยา exothermic ก็มี',
    },
    combustion: {
        q: 'ทำไมการเผาไหม้ต้องการ O₂?',
        choices: ['O₂ รับ e⁻ จาก C และ H ทำให้เกิดพันธะใหม่','O₂ เป็นตัวจุดระเบิด','O₂ ดูดซับความร้อน','O₂ เป็น catalyst'],
        answer: 0,
        explain: 'O₂ เป็น oxidizing agent รับ e⁻ จาก C, H → CO₂, H₂O พร้อมคายพลังงาน (exothermic)',
    },
    acid_carbonate: {
        q: 'ก๊าซ CO₂ ที่เกิดขึ้น มาจากไหน?',
        choices: ['H⁺ สลาย CO₃²⁻ → CO₂ + H₂O','CO₂ ละลายออกจากน้ำ','คาร์บอนในอากาศรวมกับ O₂','การเผาไหม้ของคาร์บอเนต'],
        answer: 0,
        explain: '2H⁺ + CO₃²⁻ → H₂O + CO₂↑ — H⁺ สลาย ion CO₃²⁻ ได้ CO₂ ฟองขึ้นทันที',
    },
    complex_formation: {
        q: 'สีของสารประกอบเชิงซ้อนเกิดจากอะไร?',
        choices: ['d-d electron transition ดูดแสงบางคลื่น','สีของโลหะโดยตรง','ปฏิกิริยากับแสง UV','ฟองก๊าซสะท้อนแสง'],
        answer: 0,
        explain: 'ตาม Crystal Field Theory: การแยก d-orbital → d-d transition ดูดแสงคลื่นที่จำเพาะ สีที่เห็นคือ complementary color',
    },
    iodine_clock: {
        q: 'ทำไม Na₂S₂O₃ 0.3M ขุ่นเร็วกว่า 0.1M?',
        choices: ['ความเข้มข้นสูงกว่า → โมเลกุลชนกันบ่อยกว่า → Rate สูงกว่า','อุณหภูมิสูงกว่า','S₂O₃²⁻ หนักกว่า','pH ต่างกัน'],
        answer: 0,
        explain: 'Rate Law: Rate ∝ [S₂O₃²⁻]ⁿ — ความเข้มข้นสูง → ชนกันบ่อยกว่า → ปฏิกิริยาเร็วกว่า (Collision Theory)',
    },
};

function showGuidedQuestion(rxnType, obsText) {
    if (!_guidedMode) return;
    const q = GUIDED_QUESTIONS[rxnType];
    if (!q) return;

    const panel   = document.getElementById('guidedAnswerPanel');
    const titleEl = document.getElementById('gapTitle');
    const obsEl   = document.getElementById('gapObs');
    const chEl    = document.getElementById('gapChoices');
    const fbEl    = document.getElementById('gapFeedback');
    if (!panel) return;

    if (titleEl) titleEl.textContent = `⚗️ ${q.q}`;
    if (obsEl)   obsEl.textContent   = obsText || 'สังเกตการเปลี่ยนแปลงในบีกเกอร์';
    if (fbEl)    { fbEl.textContent=''; fbEl.className='gap-feedback'; }

    if (chEl) {
        chEl.innerHTML = q.choices.map((c,i) => `
            <button class="gap-choice" data-idx="${i}" onclick="_guidedAnswer(${i}, ${q.answer}, '${q.explain.replace(/'/g,"\\'")}')">
                ${String.fromCharCode(65+i)}. ${c}
            </button>`).join('');
    }

    panel.classList.add('open');
    setTimeout(() => panel.classList.remove('open'), 30000);
}

function _guidedAnswer(selected, correct, explain) {
    const choices = document.querySelectorAll('.gap-choice');
    const fb = document.getElementById('gapFeedback');
    choices.forEach(btn => {
        btn.disabled = true;
        const idx = parseInt(btn.dataset.idx);
        if (idx === correct) btn.classList.add('correct');
        else if (idx === selected && selected !== correct) btn.classList.add('wrong');
    });
    if (fb) {
        fb.textContent = selected === correct ? `✅ ถูกต้อง! ${explain}` : `❌ ไม่ถูก — ${explain}`;
        fb.className   = `gap-feedback show ${selected === correct ? 'ok' : 'bad'}`;
    }
    checkQuestStep?.('observe', { correct: selected === correct });
    setTimeout(() => document.getElementById('guidedAnswerPanel')?.classList.remove('open'), 12000);
}

function toggleGuidedMode() {
    _guidedMode = !_guidedMode;
    document.body.classList.toggle('guided-mode', _guidedMode);
    const btn = document.getElementById('btnGuidedMode');
    if (btn) {
        btn.textContent = _guidedMode ? '🔍 Guided ON' : '🔍 Guided';
        btn.className   = `#btnGuidedMode ${_guidedMode ? 'on' : 'off'}`;
        btn.style.cssText = _guidedMode
            ? 'background:rgba(245,158,11,.15);color:#f59e0b;border-color:rgba(245,158,11,.4);'
            : 'background:rgba(100,116,139,.1);color:#64748b;border-color:#334155;';
    }
    window.app?.log(_guidedMode
        ? '🔍 <span style="color:#f59e0b;font-weight:600">Guided Discovery Mode: เปิดแล้ว — สังเกตและตอบคำถาม</span>'
        : '🔍 Guided Discovery Mode: ปิด');
}

// ── Inject Guided button ──────────────────────────────────────
function injectGuidedButton() {
    if (document.getElementById('btnGuidedMode')) return;
    const refBtn = document.getElementById('btnQuestBrowser');
    if (!refBtn) return;
    const btn = document.createElement('button');
    btn.id        = 'btnGuidedMode';
    btn.textContent = '🔍 Guided';
    btn.title     = 'Guided Discovery Mode — ซ่อนผลและให้ตอบ multiple choice';
    btn.style.cssText = 'font-size:.7rem;padding:3px 10px;border-radius:20px;cursor:pointer;font-family:Prompt;font-weight:600;border:1px solid #334155;background:rgba(100,116,139,.1);color:#64748b;margin-left:4px;';
    btn.addEventListener('click', toggleGuidedMode);
    refBtn.parentNode.insertBefore(btn, refBtn.nextSibling);
}

// ── Patch processReaction → show guided question ──────────────
function patchGuidedMode(app) {
    if (app._guidedPatched) return;
    app._guidedPatched = true;
    const orig = app.processReaction.bind(app);
    app.processReaction = function(rxn, c1, c2, i1, i2) {
        orig.call(this, rxn, c1, c2, i1, i2);
        if (_guidedMode) {
            setTimeout(() => showGuidedQuestion(rxn.reaction_type, rxn.observation_th), 600);
        }
    };
}

waitApp((app) => {
    window.app = app;
    initControlTabs();       // ย้าย Live Data + Temp เข้าแท็บ
    ContainerSystem.init();  // Phase 1: init ภาชนะ
    applyElementIcons();     // ใส่รูปธาตุจากตารางธาตุให้สารที่เป็นธาตุ
    window.ContainerSystem = ContainerSystem;
    ScoopSystem.init();      // Phase 2: init เครื่องมือตัก
    PourSystem.init();       // Phase 4: init ระบบเท
    // Event delegation: คลิกสารที่ไหนก็ได้ใน .chem-draggable → สร้างภาชนะ (กัน timing)
    document.addEventListener('click', (e) => {
        const item = e.target.closest('.chem-draggable');
        if (!item) return;
        ContainerSystem.add({
            id:      item.dataset.id,
            name:    item.dataset.name,
            state:   item.dataset.state,
            color:   item.dataset.color,
            type:    item.dataset.type,
            bp:      parseFloat(item.dataset.bp) || 100.0,
            formula: item.dataset.formula || item.dataset.name,
            ph_value: item.dataset.ph !== '' ? parseFloat(item.dataset.ph) : null,
            hazard:  parseInt(item.dataset.hazard) || 0,
            toxicity: parseInt(item.dataset.toxicity) || 0,
        });
    });
    patchProcessReaction(app);
    patchLabLog(app);
    patchQuestChecker(app);
    patchIodineClockDetect(app);
    patchGuidedMode(app);          // Step 11: guided discovery
    initRoomTempSystem(app);
    initHoverTooltip(app);
    initProductTooltip(app);
    initEquipmentSystem(app);
    patchFumeHood(app);
    injectPostLabButton(app);
    injectQuestButtons();
    injectIodineAndTitrButtons();
    injectAIButton();
    patchPostLabWithAI(app);
    injectChemInfoButtons();       // Step 11: ℹ️ on every chem card
    patchChemItemDblClick(app);    // Step 11: double-click info
    injectGuidedButton();          // Step 11: guided mode toggle
    autoStartQuestFromConfig();
    console.log('[LabUpgrade v11.0] ✅ Steps 1-11 Complete: Chemical Info + Guided Discovery');
});


// ── Expose ฟังก์ชันที่ปุ่ม onclick เรียก ขึ้น window (fix scope) ──
Object.assign(window, {
    _guidedAnswer, _setQuestFilter, aiAnalyzeSession, aiAsk, aiSend,
    btpDrop, btpReset, closeAIPanel, closeBuretteTitr, closeChemInfo,
    icpReset, icpToggle, showHintFor, startQuest,
    openIodineClock: typeof openIodineClock!=='undefined'?openIodineClock:undefined,
    openBuretteTitr: typeof openBuretteTitr!=='undefined'?openBuretteTitr:undefined,
    openAIPanel: typeof openAIPanel!=='undefined'?openAIPanel:undefined,
});

})(); // end IIFE



</script>

<?php require_once 'footer.php'; ?>