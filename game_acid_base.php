<?php
/**
 * game_acid_base.php - เกมคู่กรด-เบส (v2 — คลิกเลือก, ทำงานทุก browser)
 * เนื้อหา: บทที่ 14 กรด-เบส หนังสือเคมี ม.5 เล่ม 4 (สสวท.)
 */
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

requireRole(['student', 'teacher', 'developer']);

$user_id       = $_SESSION['user_id'];
$user_role     = $_SESSION['role'];
$is_preview    = isset($_GET['preview']);
$assignment_id = intval($_GET['assignment_id'] ?? 0);

if ($user_role === 'student' && !$is_preview && $assignment_id === 0) {
    header('Location: student_games.php'); exit;
}

$page_title = "เกมคู่กรด-เบส";

$levels = [
    [
        'level'       => 1,
        'title'       => 'ด่านที่ 1 — กรดและเบสแก่',
        'description' => 'กรด-เบสแก่แตกตัว 100% — คำนวณ pH จาก [H⁺] หรือ [OH⁻] ได้โดยตรง',
        'theory'      => 'pH = −log[H⁺] | pOH = −log[OH⁻] | pH + pOH = 14',
        'items' => [
            ['id'=>'a1','name'=>'HCl 0.1 M',    'formula'=>'HCl',   'hint'=>'กรดแก่ [H⁺]=0.1 → pH=1'],
            ['id'=>'a2','name'=>'H₂SO₄ 0.05 M', 'formula'=>'H₂SO₄','hint'=>'กรดแก่ 2 โปรตอน [H⁺]=0.1 → pH=1'],
            ['id'=>'a3','name'=>'NaOH 0.1 M',   'formula'=>'NaOH',  'hint'=>'เบสแก่ pOH=1 → pH=13'],
            ['id'=>'a4','name'=>'KOH 0.01 M',   'formula'=>'KOH',   'hint'=>'เบสแก่ pOH=2 → pH=12'],
        ],
        'zones' => [
            ['id'=>'z1','label'=>'pH = 1',  'accepts'=>['a1','a2'],'color'=>'red'],
            ['id'=>'z2','label'=>'pH = 13', 'accepts'=>['a3'],     'color'=>'blue'],
            ['id'=>'z3','label'=>'pH = 12', 'accepts'=>['a4'],     'color'=>'blue'],
        ],
        'xp' => 150,
    ],
    [
        'level'       => 2,
        'title'       => 'ด่านที่ 2 — กรดและเบสอ่อน',
        'description' => 'กรด-เบสอ่อนแตกตัวไม่สมบูรณ์ → pH ต่างจากกรดแก่ความเข้มข้นเดียวกัน',
        'theory'      => 'กรดอ่อน: pH > −log[HA] | ยิ่ง Ka มาก → แตกตัวมาก → pH ต่ำกว่า',
        'items' => [
            ['id'=>'b1','name'=>'CH₃COOH 0.1 M', 'formula'=>'CH₃COOH','hint'=>'Ka=1.8×10⁻⁵ → pH≈2.9'],
            ['id'=>'b2','name'=>'HF 0.1 M',       'formula'=>'HF',     'hint'=>'Ka=6.8×10⁻⁴ (แรงกว่า) → pH≈2.1'],
            ['id'=>'b3','name'=>'NH₃ 0.1 M',      'formula'=>'NH₃',    'hint'=>'เบสอ่อน Kb=1.8×10⁻⁵ → pH≈11.1'],
            ['id'=>'b4','name'=>'C₆H₅COOH 0.1 M','formula'=>'C₆H₅COOH','hint'=>'Ka=6.5×10⁻⁵ → pH≈3.1'],
        ],
        'zones' => [
            ['id'=>'z4','label'=>'pH ≈ 2.1', 'accepts'=>['b2'],'color'=>'red'],
            ['id'=>'z5','label'=>'pH ≈ 2.9', 'accepts'=>['b1'],'color'=>'red'],
            ['id'=>'z6','label'=>'pH ≈ 3.1', 'accepts'=>['b4'],'color'=>'red'],
            ['id'=>'z7','label'=>'pH ≈ 11.1','accepts'=>['b3'],'color'=>'blue'],
        ],
        'xp' => 200,
    ],
    [
        'level'       => 3,
        'title'       => 'ด่านที่ 3 — สารในชีวิตประจำวัน',
        'description' => 'เชื่อมโยงสารรอบตัวกับช่วง pH ที่สอดคล้อง',
        'theory'      => 'pH < 7 = กรด | pH = 7 = กลาง | pH > 7 = เบส',
        'items' => [
            ['id'=>'c1','name'=>'น้ำบริสุทธิ์', 'formula'=>'H₂O',         'hint'=>'กลาง pH=7'],
            ['id'=>'c2','name'=>'น้ำมะนาว',    'formula'=>'กรดซิตริก',    'hint'=>'เปรี้ยว pH 2–3'],
            ['id'=>'c3','name'=>'เลือดมนุษย์', 'formula'=>'บัฟเฟอร์ HCO₃⁻','hint'=>'pH 7.35–7.45'],
            ['id'=>'c4','name'=>'น้ำปูนใส',    'formula'=>'Ca(OH)₂',      'hint'=>'เบสอ่อน pH 11–12'],
            ['id'=>'c5','name'=>'น้ำส้มสายชู', 'formula'=>'CH₃COOH 5%',  'hint'=>'pH 2–3'],
        ],
        'zones' => [
            ['id'=>'z8', 'label'=>'pH = 7',       'accepts'=>['c1'],     'color'=>'green'],
            ['id'=>'z9', 'label'=>'pH 2–3',       'accepts'=>['c2','c5'],'color'=>'red'],
            ['id'=>'z10','label'=>'pH 7.35–7.45', 'accepts'=>['c3'],     'color'=>'green'],
            ['id'=>'z11','label'=>'pH 11–12',     'accepts'=>['c4'],     'color'=>'blue'],
        ],
        'xp' => 150,
    ],
];

require_once 'header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;}
body{background:#0b1120;font-family:'Prompt',sans-serif;color:#f1f5f9;min-height:100vh;}
.gw{max-width:900px;margin:0 auto;padding:20px 16px 60px;}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:20px;}
.game-title{font-size:1.5rem;font-weight:700;color:#38bdf8;display:flex;align-items:center;gap:10px;}
.xp-pill{background:#fef3c7;color:#92400e;padding:5px 16px;border-radius:20px;font-weight:700;font-size:.9rem;}
.exit-btn{color:#64748b;text-decoration:none;font-size:.9rem;font-weight:600;}
.preview-pill{background:#fce7f3;color:#9d174d;padding:4px 14px;border-radius:20px;font-weight:700;font-size:.8rem;display:inline-block;margin-bottom:14px;}
.prog-wrap{background:#1e293b;border-radius:12px;height:8px;margin-bottom:4px;overflow:hidden;}
.prog-fill{height:100%;background:linear-gradient(90deg,#38bdf8,#818cf8);border-radius:12px;transition:width .5s;}
.prog-label{font-size:.8rem;color:#64748b;margin-bottom:18px;}
.lvl-card{background:#131f35;border:1px solid #1e3a5f;border-radius:20px;padding:24px;margin-bottom:20px;}
.lvl-title{font-size:1.1rem;font-weight:700;color:#f1f5f9;margin:0 0 4px;}
.lvl-desc{font-size:.85rem;color:#94a3b8;margin:0 0 14px;}
.theory{background:#0f172a;border-left:3px solid #38bdf8;border-radius:0 10px 10px 0;padding:10px 14px;margin-bottom:18px;font-size:.82rem;color:#93c5fd;font-family:'Share Tech Mono',monospace;line-height:1.6;}
.step-lbl{font-size:.78rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px;}
.items-row{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px;min-height:48px;}
.item-chip{background:#1e3a5f;border:2px solid #3b82f6;border-radius:10px;padding:9px 16px;cursor:pointer;transition:.18s;font-size:.9rem;font-weight:600;color:#bfdbfe;display:flex;align-items:center;gap:8px;user-select:none;}
.item-chip:hover{background:#1d4ed8;border-color:#60a5fa;color:#fff;transform:scale(1.03);}
.item-chip.selected{background:#1d4ed8;border-color:#fff;color:#fff;box-shadow:0 0 0 3px rgba(59,130,246,.5);}
.item-chip.matched{background:#052e16;border-color:#10b981;color:#4ade80;opacity:.6;cursor:default;pointer-events:none;}
.item-formula{font-size:.73rem;color:#60a5fa;font-family:'Share Tech Mono',monospace;}
.item-chip.matched .item-formula{color:#34d399;}
.zones-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:20px;}
.drop-zone{background:#0f172a;border:2px dashed #334155;border-radius:14px;min-height:90px;padding:14px 12px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;cursor:pointer;transition:.2s;position:relative;}
.drop-zone:hover,.drop-zone.active{border-color:#38bdf8;background:#0c2647;}
.drop-zone.correct{border-style:solid;background:#052e16;cursor:default;}
.drop-zone.correct.zone-color-red{border-color:#10b981;}
.drop-zone.correct.zone-color-blue{border-color:#10b981;}
.drop-zone.correct.zone-color-green{border-color:#10b981;}
.drop-zone.wrong{animation:shake .35s;}
.zone-ph{font-size:1.05rem;font-weight:700;font-family:'Share Tech Mono',monospace;margin-bottom:5px;}
.zone-color-red .zone-ph{color:#f87171;}
.zone-color-blue .zone-ph{color:#60a5fa;}
.zone-color-green .zone-ph{color:#4ade80;}
.zone-hint{font-size:.72rem;color:#475569;}
.zone-items{display:flex;flex-direction:column;gap:4px;margin-top:6px;width:100%;}
.zone-item-tag{font-size:.78rem;font-weight:600;color:#4ade80;background:rgba(16,185,129,.12);padding:3px 8px;border-radius:6px;text-align:center;}
.toast{position:fixed;top:20px;left:50%;transform:translateX(-50%) translateY(-10px);padding:12px 24px;border-radius:12px;font-weight:700;font-size:.9rem;z-index:9999;opacity:0;transition:opacity .25s,transform .25s;pointer-events:none;white-space:nowrap;max-width:90vw;text-align:center;}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0);}
.toast.ok{background:#052e16;color:#4ade80;border:1.5px solid #10b981;}
.toast.err{background:#2d0e0e;color:#f87171;border:1.5px solid #ef4444;}
.btn-next{display:none;background:linear-gradient(135deg,#38bdf8,#818cf8);color:#0b1120;border:none;padding:12px 36px;border-radius:12px;font-weight:700;font-size:1rem;cursor:pointer;margin-top:6px;font-family:'Prompt',sans-serif;transition:.2s;}
.btn-next:hover{transform:scale(1.04);}
.btn-next.show{display:inline-block;}
.score-screen{display:none;text-align:center;padding:48px 20px;}
.score-screen.show{display:block;}
.score-ring{width:130px;height:130px;border-radius:50%;background:linear-gradient(135deg,#38bdf8,#818cf8);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:2rem;font-weight:700;color:#0b1120;}
.score-xp{font-size:1.8rem;color:#fbbf24;font-weight:700;margin-bottom:8px;}
.score-msg{font-size:1rem;color:#94a3b8;margin-bottom:28px;}
.score-btns{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;}
.btn-replay{background:#1e293b;color:#f1f5f9;border:1.5px solid #334155;padding:11px 26px;border-radius:10px;font-weight:700;cursor:pointer;font-family:'Prompt',sans-serif;}
.btn-done{background:linear-gradient(135deg,#38bdf8,#818cf8);color:#0b1120;border:none;padding:11px 26px;border-radius:10px;font-weight:700;cursor:pointer;font-family:'Prompt',sans-serif;}
@keyframes shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-7px)}75%{transform:translateX(7px)}}
@media(max-width:520px){.zones-grid{grid-template-columns:1fr 1fr;}}
</style>

<div class="gw">
    <?php if($is_preview):?><div class="preview-pill">👁 โหมดทดลองเล่น — คะแนนไม่ถูกบันทึก</div><?php endif;?>

    <div class="topbar">
        <div class="game-title">⚗️ เกมคู่กรด-เบส</div>
        <div style="display:flex;align-items:center;gap:12px;">
            <span class="xp-pill">+500 XP</span>
            <a href="education_games.php" class="exit-btn">✕ ออก</a>
        </div>
    </div>

    <div class="prog-wrap"><div class="prog-fill" id="prog" style="width:0%"></div></div>
    <div class="prog-label" id="prog-lbl">ด่านที่ 1 จาก 3</div>

    <div id="game-area"></div>

    <div class="score-screen" id="score-screen">
        <div class="score-ring" id="score-num">0%</div>
        <div class="score-xp" id="score-xp"></div>
        <div class="score-msg" id="score-msg"></div>
        <div class="score-btns">
            <button class="btn-replay" onclick="location.reload()">🔄 เล่นใหม่</button>
            <button class="btn-done" onclick="goBack()">✔ เสร็จสิ้น</button>
        </div>
    </div>
</div>
<div class="toast" id="toast"></div>

<script>
var LEVELS = <?= json_encode($levels, JSON_UNESCAPED_UNICODE) ?>;
var IS_PREVIEW = <?= $is_preview ? 'true' : 'false' ?>;
var ASSIGNMENT_ID = <?= $assignment_id ?>;
var BACK_URL = '<?= $user_role==='student' ? 'student_games.php' : 'education_games.php' ?>';

var cur = 0;
var selectedItem = null;
var totalXP = 0;
var totalCorrect = 0;
var matched = {};   // matched[levelIndex][itemId] = zoneId
var zoneItems = {}; // zoneItems[levelIndex][zoneId] = [itemName, ...]

var totalItems = 0;
for (var i = 0; i < LEVELS.length; i++) totalItems += LEVELS[i].items.length;

function render() {
    var lvl = LEVELS[cur];
    if (!matched[cur]) matched[cur] = {};
    if (!zoneItems[cur]) zoneItems[cur] = {};

    document.getElementById('prog').style.width = ((cur / LEVELS.length) * 100) + '%';
    document.getElementById('prog-lbl').textContent = 'ด่านที่ ' + (cur+1) + ' จาก ' + LEVELS.length;

    var itemsHtml = '';
    for (var i = 0; i < lvl.items.length; i++) {
        var item = lvl.items[i];
        var cls = matched[cur][item.id] ? 'matched' : '';
        itemsHtml += '<div class="item-chip ' + cls + '" id="chip-' + item.id + '" onclick="selectItem(\'' + item.id + '\')">'
            + item.name
            + '<span class="item-formula">' + item.formula + '</span>'
            + '</div>';
    }

    var zonesHtml = '';
    for (var j = 0; j < lvl.zones.length; j++) {
        var z = lvl.zones[j];
        var isCorrect = false;
        var mKeys = Object.keys(matched[cur]);
        for (var k = 0; k < mKeys.length; k++) {
            if (matched[cur][mKeys[k]] === z.id) { isCorrect = true; break; }
        }
        var correctCls = isCorrect ? 'correct' : '';
        var taggedItems = zoneItems[cur][z.id] || [];
        var tagsHtml = '';
        for (var t = 0; t < taggedItems.length; t++) {
            tagsHtml += '<div class="zone-item-tag">✔ ' + taggedItems[t] + '</div>';
        }
        zonesHtml += '<div class="drop-zone zone-color-' + z.color + ' ' + correctCls + '" id="zone-' + z.id + '" onclick="dropTo(\'' + z.id + '\')">'
            + '<div class="zone-ph">' + z.label + '</div>'
            + '<div class="zone-hint">คลิกเพื่อวาง</div>'
            + '<div class="zone-items">' + tagsHtml + '</div>'
            + '</div>';
    }

    var btnCls = isLevelDone() ? 'show' : '';
    var btnTxt = cur < LEVELS.length - 1 ? 'ด่านถัดไป →' : '✔ ดูผลคะแนน';

    document.getElementById('game-area').innerHTML =
        '<div class="lvl-card">'
        + '<div class="lvl-title">' + lvl.title + '</div>'
        + '<div class="lvl-desc">' + lvl.description + '</div>'
        + '<div class="theory">📐 ' + lvl.theory + '</div>'
        + '<div class="step-lbl">① เลือกสาร (คลิก)</div>'
        + '<div class="items-row">' + itemsHtml + '</div>'
        + '<div class="step-lbl">② คลิกช่อง pH ที่ตรงกัน</div>'
        + '<div class="zones-grid">' + zonesHtml + '</div>'
        + '<button class="btn-next ' + btnCls + '" id="btn-next" onclick="nextLevel()">' + btnTxt + '</button>'
        + '</div>';
}

function selectItem(id) {
    if (matched[cur] && matched[cur][id]) return;
    selectedItem = (selectedItem === id) ? null : id;
    var chips = document.querySelectorAll('.item-chip');
    for (var i = 0; i < chips.length; i++) chips[i].classList.remove('selected');
    var zones = document.querySelectorAll('.drop-zone:not(.correct)');
    for (var j = 0; j < zones.length; j++) {
        if (selectedItem) zones[j].classList.add('active');
        else zones[j].classList.remove('active');
    }
    if (selectedItem) {
        var el = document.getElementById('chip-' + id);
        if (el) el.classList.add('selected');
        showToast('เลือก: ' + id2name(id) + ' — คลิกช่อง pH', 'ok');
    }
}

function id2name(id) {
    var lvl = LEVELS[cur];
    for (var i = 0; i < lvl.items.length; i++) if (lvl.items[i].id === id) return lvl.items[i].name;
    return id;
}

function dropTo(zoneId) {
    if (!selectedItem) { showToast('⬆ เลือกสารก่อน แล้วคลิกช่อง pH', 'err'); return; }
    var lvl = LEVELS[cur];
    var zone = null, item = null;
    for (var i = 0; i < lvl.zones.length; i++) if (lvl.zones[i].id === zoneId) { zone = lvl.zones[i]; break; }
    for (var j = 0; j < lvl.items.length; j++) if (lvl.items[j].id === selectedItem) { item = lvl.items[j]; break; }
    if (!zone || !item) return;

    var ok = false;
    for (var k = 0; k < zone.accepts.length; k++) if (zone.accepts[k] === selectedItem) { ok = true; break; }

    if (ok) {
        matched[cur][selectedItem] = zoneId;
        if (!zoneItems[cur][zoneId]) zoneItems[cur][zoneId] = [];
        zoneItems[cur][zoneId].push(item.name);
        totalCorrect++;
        totalXP += Math.round(lvl.xp / lvl.items.length);
        showToast('✔ ถูกต้อง! ' + item.hint, 'ok');
        selectedItem = null;
        render();
    } else {
        var zEl = document.getElementById('zone-' + zoneId);
        if (zEl) { zEl.classList.add('wrong'); setTimeout(function(){ if(zEl) zEl.classList.remove('wrong'); }, 400); }
        showToast('✗ ไม่ตรง — ลองช่องอื่น', 'err');
    }
}

function isLevelDone() {
    return Object.keys(matched[cur] || {}).length >= LEVELS[cur].items.length;
}

function nextLevel() {
    cur++;
    selectedItem = null;
    if (cur >= LEVELS.length) { showScore(); return; }
    render();
}

function showScore() {
    document.getElementById('game-area').style.display = 'none';
    document.getElementById('prog').style.width = '100%';
    var sc = document.getElementById('score-screen');
    sc.classList.add('show');
    var pct = Math.round((totalCorrect / totalItems) * 100);
    document.getElementById('score-num').textContent = pct + '%';
    document.getElementById('score-xp').textContent = '+' + totalXP + ' XP';
    document.getElementById('score-msg').textContent =
        pct >= 80 ? '🎉 ยอดเยี่ยม! ผ่านเกณฑ์ 80% แล้ว' :
        pct >= 60 ? '👍 ดีมาก! ลองเล่นใหม่เพื่อเพิ่มคะแนน' :
                    '📚 ทบทวนบทที่ 14 แล้วลองใหม่อีกครั้ง';
    if (!IS_PREVIEW && ASSIGNMENT_ID > 0) {
        fetch('save_game_result.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                assignment_id: ASSIGNMENT_ID,
                score: pct, max_score: 100,
                earned_xp: totalXP,
                is_passed: pct >= 80 ? 1 : 0,
                level_reached: LEVELS.length
            })
        }).catch(function(){});
    }
}

function goBack() { window.location.href = BACK_URL; }

var toastTimer = null;
function showToast(msg, type) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast ' + type + ' show';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function(){ t.classList.remove('show'); }, 2200);
}

// เริ่มเกม
render();
</script>

<?php require_once 'footer.php'; ?>