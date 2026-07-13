<?php
/**
 * tenant_manager.php — Tenant Management (UX/UI Redesign)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

requireRole(['developer']);

$page_title = 'จัดการโรงเรียน';
$csrf       = generate_csrf_token();

$tenants = [];
try {
    $tenants = $master_pdo->query("
        SELECT t.*,
            (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = t.db_name) AS tbl_count,
            (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = t.db_name AND TABLE_NAME = 'users') AS has_users
        FROM sys_tenants t ORDER BY COALESCE(t.created_at, t.id) ASC
    ")->fetchAll();
} catch (Exception $e) { error_log('[tenant_manager] ' . $e->getMessage()); }

require_once 'header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
/* ── Layout ─────────────────────────────────────────────── */
.tm-page { max-width:1100px; margin:0 auto; padding:28px 20px 60px; }
.tm-topbar { display:flex; align-items:center; justify-content:space-between; margin-bottom:28px; flex-wrap:wrap; gap:12px; }
.tm-topbar h1 { margin:0; font-size:1.5rem; font-weight:700; color:var(--primary); display:flex; align-items:center; gap:10px; }
.tm-topbar .sub { font-size:.82rem; color:var(--text-muted); margin-top:2px; }

/* ── Stats Row ──────────────────────────────────────────── */
.stats-row { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:24px; }
.stat-card { background:#fff; border-radius:12px; border:1px solid #e2e8f0; padding:16px 20px;
             display:flex; align-items:center; gap:14px; box-shadow:0 1px 4px rgba(0,0,0,.04); }
.stat-icon { width:44px; height:44px; border-radius:10px; display:flex; align-items:center;
             justify-content:center; font-size:1.3rem; flex-shrink:0; }
.stat-icon.blue   { background:#eff6ff; }
.stat-icon.green  { background:#f0fdf4; }
.stat-icon.orange { background:#fff7ed; }
.stat-val { font-size:1.6rem; font-weight:700; color:var(--primary); line-height:1; }
.stat-lbl { font-size:.75rem; color:var(--text-muted); margin-top:2px; }

/* ── Tenant Cards Grid ──────────────────────────────────── */
.tenant-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:16px; margin-bottom:24px; }
.tenant-card { background:#fff; border-radius:14px; border:1px solid #e2e8f0; overflow:hidden;
               box-shadow:0 2px 8px rgba(0,0,0,.05); transition:.2s; }
.tenant-card:hover { box-shadow:0 6px 20px rgba(0,0,0,.1); transform:translateY(-2px); }
.tenant-card.inactive { opacity:.65; }
.tc-header { padding:16px 18px 12px; border-bottom:1px solid #f1f5f9;
             display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
.tc-color-bar { width:4px; border-radius:4px; flex-shrink:0; align-self:stretch; min-height:32px; }
.tc-info { flex:1; min-width:0; }
.tc-code { font-family:'Share Tech Mono',monospace; font-size:.78rem; color:var(--text-muted);
           background:#f8fafc; border:1px solid #e2e8f0; padding:2px 8px; border-radius:6px;
           display:inline-block; margin-bottom:4px; }
.tc-name { font-size:1rem; font-weight:600; color:var(--text-dark); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.tc-db   { font-family:'Share Tech Mono',monospace; font-size:.72rem; color:var(--text-muted); margin-top:2px; }
.tc-badge { flex-shrink:0; padding:3px 10px; border-radius:20px; font-size:.75rem; font-weight:600; white-space:nowrap; }
.tc-badge.on  { background:#d1fae5; color:#065f46; }
.tc-badge.off { background:#fee2e2; color:#b91c1c; }

.tc-body { padding:12px 18px; display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.tc-stat { display:flex; flex-direction:column; gap:2px; }
.tc-stat-val { font-size:.88rem; font-weight:600; color:var(--text-dark); }
.tc-stat-lbl { font-size:.7rem; color:var(--text-muted); }

.tc-footer { padding:10px 14px; background:#f8fafc; border-top:1px solid #f1f5f9;
             display:flex; gap:6px; align-items:center; }
.tc-action { padding:6px 12px; border:none; border-radius:7px; font-family:'Prompt',sans-serif;
             font-size:.78rem; font-weight:600; cursor:pointer; transition:.15s; }
.tc-action:hover { opacity:.85; }
.tc-action.toggle { background:#fff7ed; color:#c2410c; border:1px solid #fed7aa; }
.tc-action.toggle.active { background:#f0fdf4; color:#15803d; border-color:#bbf7d0; }
.tc-action.edit   { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
.tc-action.login  { background:var(--primary); color:#fff; margin-left:auto; }

/* ── Add Panel ──────────────────────────────────────────── */
.add-panel { background:#fff; border-radius:14px; border:2px dashed #cbd5e1; padding:24px;
             transition:.2s; }
.add-panel.highlight { border-color:var(--primary); box-shadow:0 0 0 4px rgba(30,58,138,.06); }
.add-panel-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; cursor:pointer; }
.add-panel-head h2 { margin:0; font-size:1.1rem; font-weight:700; color:var(--primary); display:flex; align-items:center; gap:8px; }
.add-panel-head .toggle-icon { transition:.3s; }
.add-panel-body { display:none; }
.add-panel-body.open { display:block; }
.step-badge { display:inline-flex; align-items:center; justify-content:center;
              width:22px; height:22px; border-radius:50%; background:var(--primary);
              color:#fff; font-size:.72rem; font-weight:700; flex-shrink:0; }
.step-row { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
.step-label { font-size:.8rem; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:.04em; }
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px; }
.fg { display:flex; flex-direction:column; gap:4px; }
.fg label { font-size:.78rem; color:var(--text-muted); font-weight:600; }
.fg input[type=text],.fg input[type=password] {
    padding:10px 13px; border:1.5px solid #e2e8f0; border-radius:9px;
    font-family:'Prompt',sans-serif; font-size:.9rem; width:100%; box-sizing:border-box;
    color:var(--text-dark); transition:.2s; background:#fafafa;
}
.fg input:focus { outline:none; border-color:var(--primary); background:#fff;
                  box-shadow:0 0 0 3px rgba(30,58,138,.08); }
.fg .hint { font-size:.7rem; color:#94a3b8; }
.fg .err-hint { font-size:.7rem; color:#ef4444; display:none; }
.fg.has-error input { border-color:#ef4444; }
.fg.has-error .err-hint { display:block; }
.crow { display:flex; gap:8px; align-items:center; }
.crow input[type=color] { width:44px; height:40px; padding:2px; border-radius:9px;
                           border:1.5px solid #e2e8f0; cursor:pointer; flex-shrink:0; }
.crow input[type=text] { flex:1; }
.pw-wrap { position:relative; }
.pw-wrap input { padding-right:40px; }
.pw-eye { position:absolute; right:10px; top:50%; transform:translateY(-50%);
          background:none; border:none; cursor:pointer; color:#94a3b8; font-size:1rem; padding:4px; }
.notice { background:#fef3c7; border:1px solid #fcd34d; border-radius:9px;
          padding:10px 14px; font-size:.8rem; color:#78350f; margin-bottom:16px;
          display:flex; gap:8px; align-items:flex-start; }
.divider { border:none; border-top:1px solid #f1f5f9; margin:18px 0; }
.btn-create { display:inline-flex; align-items:center; gap:8px; padding:12px 28px;
              background:linear-gradient(135deg,#1e3a8a,#2563eb); color:#fff; border:none;
              border-radius:10px; font-family:'Prompt',sans-serif; font-size:.95rem; font-weight:700;
              cursor:pointer; transition:.2s; box-shadow:0 4px 12px rgba(30,58,138,.25); }
.btn-create:hover:not(:disabled) { transform:translateY(-1px); box-shadow:0 6px 18px rgba(30,58,138,.35); }
.btn-create:disabled { opacity:.6; cursor:not-allowed; transform:none; }
.spin { width:16px; height:16px; border:2px solid rgba(255,255,255,.4);
        border-top-color:#fff; border-radius:50%; animation:sp .7s linear infinite; flex-shrink:0; }
@keyframes sp { to { transform:rotate(360deg); } }

/* ── Progress ───────────────────────────────────────────── */
.progress-wrap { display:none; background:#f8fafc; border:1px solid #e2e8f0;
                 border-radius:10px; padding:16px; margin-top:14px; }
.progress-wrap.show { display:block; }
.progress-bar-bg { height:8px; background:#e2e8f0; border-radius:8px; overflow:hidden; margin:8px 0; }
.progress-bar-fill { height:100%; background:linear-gradient(90deg,#1e3a8a,#2563eb);
                     border-radius:8px; width:0%; transition:width .4s ease; }
.progress-step { font-size:.8rem; color:var(--text-muted); }

/* ── Toast ──────────────────────────────────────────────── */
.toast-wrap { position:fixed; top:80px; right:20px; z-index:9999; display:flex;
              flex-direction:column; gap:8px; pointer-events:none; }
.toast { background:#fff; border-radius:12px; padding:14px 18px; min-width:280px; max-width:380px;
         box-shadow:0 8px 24px rgba(0,0,0,.15); border-left:4px solid #10b981;
         display:flex; align-items:flex-start; gap:10px; pointer-events:auto;
         animation:toastIn .3s ease; font-family:'Prompt',sans-serif; font-size:.88rem; }
.toast.err { border-left-color:#ef4444; }
.toast.warn { border-left-color:#f59e0b; }
.toast-icon { font-size:1.1rem; flex-shrink:0; margin-top:1px; }
.toast-body { flex:1; }
.toast-title { font-weight:600; color:var(--text-dark); margin-bottom:2px; }
.toast-msg { color:var(--text-muted); line-height:1.5; }
.toast-close { background:none; border:none; cursor:pointer; color:#94a3b8; font-size:1rem; flex-shrink:0; padding:0; }
@keyframes toastIn { from{opacity:0;transform:translateX(20px)} to{opacity:1;transform:translateX(0)} }

/* ── Modal ──────────────────────────────────────────────── */
.modal-bg { display:none; position:fixed; inset:0; background:rgba(15,23,42,.5);
            z-index:8000; align-items:center; justify-content:center; backdrop-filter:blur(2px); }
.modal-bg.open { display:flex; }
.modal { background:#fff; border-radius:16px; padding:28px; width:94%; max-width:420px;
         box-shadow:0 20px 60px rgba(0,0,0,.2); animation:modalIn .25s ease; }
@keyframes modalIn { from{opacity:0;transform:scale(.96)} to{opacity:1;transform:scale(1)} }
.modal h3 { margin:0 0 18px; color:var(--primary); font-size:1.1rem;
            display:flex; align-items:center; gap:8px; }
.modal-footer { display:flex; gap:10px; justify-content:flex-end; margin-top:20px; }
.btn-cancel { padding:10px 18px; border-radius:9px; border:1.5px solid #e2e8f0;
              background:#f8fafc; cursor:pointer; font-family:'Prompt',sans-serif;
              font-size:.88rem; color:var(--text-muted); }
.btn-save { padding:10px 22px; border-radius:9px; border:none;
            background:var(--primary); color:#fff; cursor:pointer;
            font-family:'Prompt',sans-serif; font-size:.88rem; font-weight:600; }

/* ── Empty state ────────────────────────────────────────── */
.empty-state { text-align:center; padding:48px 20px; color:var(--text-muted); }
.empty-state .icon { font-size:3rem; margin-bottom:12px; }
.empty-state p { font-size:.9rem; }

@media(max-width:640px) {
    .stats-row { grid-template-columns:1fr 1fr; }
    .stats-row .stat-card:last-child { display:none; }
    .form-grid { grid-template-columns:1fr; }
    .tenant-grid { grid-template-columns:1fr; }
}
.logo-upload-zone{border:2px dashed #cbd5e1;border-radius:10px;padding:18px 14px;
    text-align:center;cursor:pointer;transition:.2s;background:#fafafa;position:relative;}
.logo-upload-zone:hover,.logo-upload-zone.drag{border-color:var(--primary);background:#eff6ff;}
.logo-upload-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
.logo-preview{width:72px;height:72px;border-radius:10px;object-fit:contain;display:none;
    margin:0 auto 8px;border:1px solid #e2e8f0;}
.logo-preview.show{display:block;}
.logo-hint{font-size:.72rem;color:#94a3b8;margin-top:4px;}
</style>

<div class="tm-page">
    <!-- Top bar -->
    <div class="tm-topbar">
        <div>
            <h1>🏫 จัดการโรงเรียน</h1>
            <div class="sub">Platform Developer · Smart School Plus</div>
        </div>
        <button class="btn-create" onclick="scrollToAdd()" style="padding:10px 20px;font-size:.88rem;">
            ➕ เพิ่มโรงเรียนใหม่
        </button>
    </div>

    <!-- Stats -->
    <?php
        $total   = count($tenants);
        $active  = array_sum(array_column($tenants, 'is_active'));
        $inactive= $total - $active;
    ?>
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon blue">🏫</div>
            <div>
                <div class="stat-val"><?= $total ?></div>
                <div class="stat-lbl">โรงเรียนทั้งหมด</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">✅</div>
            <div>
                <div class="stat-val" style="color:#10b981"><?= $active ?></div>
                <div class="stat-lbl">เปิดใช้งาน</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon orange">⏸</div>
            <div>
                <div class="stat-val" style="color:#f59e0b"><?= $inactive ?></div>
                <div class="stat-lbl">ปิดชั่วคราว</div>
            </div>
        </div>
    </div>

    <!-- Tenant Cards -->
    <div class="tenant-grid" id="tenantGrid">
        <?php if (empty($tenants)): ?>
        <div class="empty-state" style="grid-column:1/-1">
            <div class="icon">🏗️</div>
            <p>ยังไม่มีโรงเรียนในระบบ<br>เพิ่มโรงเรียนแรกได้เลย</p>
        </div>
        <?php else: foreach ($tenants as $t):
            $isOn = (bool)$t['is_active'];
        ?>
        <div class="tenant-card <?= $isOn ? '' : 'inactive' ?>" id="card-<?= $t['id'] ?>">
            <div class="tc-header">
                <div class="tc-color-bar" style="background:<?= h($t['primary_color']) ?>"></div>
                <div class="tc-info">
                    <div class="tc-code"><?= h($t['school_code']) ?></div>
                    <div class="tc-name" title="<?= h($t['school_name']) ?>"><?= h($t['school_name']) ?></div>
                    <div class="tc-db"><?= h($t['db_name']) ?></div>
                </div>
                <span class="tc-badge <?= $isOn ? 'on' : 'off' ?>" id="badge-<?= $t['id'] ?>">
                    <?= $isOn ? '✅ เปิด' : '❌ ปิด' ?>
                </span>
            </div>
            <div class="tc-body">
                <div class="tc-stat">
                    <div class="tc-stat-val"><?= (int)$t['tbl_count'] ?></div>
                    <div class="tc-stat-lbl">ตารางใน DB</div>
                </div>
                <div class="tc-stat">
                    <div class="tc-stat-val"
                         style="display:flex;align-items:center;gap:6px;">
                        <span style="width:12px;height:12px;border-radius:3px;display:inline-block;background:<?= h($t['primary_color']) ?>"></span>
                        <?= h($t['primary_color']) ?>
                    </div>
                    <div class="tc-stat-lbl">สีหลัก</div>
                </div>
                <div class="tc-stat" style="grid-column:1/-1">
                    <div class="tc-stat-val" style="font-size:.8rem;font-family:'Share Tech Mono',monospace;color:var(--text-muted)">
                        <?= $t['created_at'] ? date('d/m/Y H:i', strtotime($t['created_at'])) : '—' ?>
                    </div>
                    <div class="tc-stat-lbl">วันที่สร้าง</div>
                </div>
            </div>
            <div class="tc-footer">
                <button class="tc-action toggle <?= $isOn ? 'active' : '' ?>" id="tbtn-<?= $t['id'] ?>"
                        onclick="toggleTenant(<?= $t['id'] ?>)">
                    <?= $isOn ? '⏸ ปิดชั่วคราว' : '▶ เปิดใช้งาน' ?>
                </button>
                <button class="tc-action edit"
                        data-id="<?= (int)$t['id'] ?>"
                        data-name="<?= h($t['school_name']) ?>"
                        data-color="<?= h($t['primary_color']) ?>"
                        onclick="openEdit(this.dataset.id,this.dataset.name,this.dataset.color)">
                    ✏️ แก้ไข
                </button>
                <button class="tc-action login"
                        onclick="copyCode('<?= h($t['school_code']) ?>')"
                        title="คัดลอกรหัสเพื่อ login">
                    📋 <?= h($t['school_code']) ?>
                </button>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- Add Panel -->
    <div class="add-panel" id="addPanel">
        <div class="add-panel-head" onclick="toggleAddPanel()">
            <h2>➕ เพิ่มโรงเรียนใหม่</h2>
            <span class="toggle-icon" id="addToggleIcon">▼</span>
        </div>
        <div class="add-panel-body open" id="addBody">
            <div class="notice">
                <span>⚠️</span>
                <div>ระบบจะสร้าง <strong>db_ssp_[รหัส]</strong> และ clone โครงสร้าง + lookup data จากโรงเรียนต้นแบบ
                อาจใช้เวลา <strong>10–30 วินาที</strong></div>
            </div>

            <!-- Step 1 -->
            <div class="step-row">
                <span class="step-badge">1</span>
                <span class="step-label">ข้อมูลโรงเรียน</span>
            </div>
            <div class="form-grid">
                <div class="fg" id="fg_code">
                    <label>รหัสโรงเรียน (School Code) *</label>
                    <input type="text" id="f_code" placeholder="เช่น SATIT2" maxlength="10"
                           oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9]/g,'');validateField('code')">
                    <span class="hint">A-Z และ 0-9 เท่านั้น ยาว 2-10 ตัว</span>
                    <span class="err-hint" id="err_code">—</span>
                </div>
                <div class="fg" id="fg_name">
                    <label>ชื่อโรงเรียน *</label>
                    <input type="text" id="f_name" placeholder="เช่น โรงเรียนสาธิตมหาวิทยาลัยฯ"
                           oninput="validateField('name')">
                    <span class="err-hint" id="err_name">—</span>
                </div>
                <div class="fg">
                    <label>สีหลักของโรงเรียน</label>
                    <div class="crow">
                        <input type="color" id="f_color" value="#1e3a8a"
                               oninput="document.getElementById('f_colorT').value=this.value">
                        <input type="text" id="f_colorT" value="#1e3a8a"
                               oninput="syncColor('f_color','f_colorT')">
                    </div>
                </div>
                <div class="fg">
                    <label>ชื่อแสดง (Display Name)</label>
                    <input type="text" id="f_adname" placeholder="เช่น ผู้ดูแลระบบสาธิตฯ">
                </div>
            </div>

            <hr class="divider">

            <!-- Step 2 -->
            <div class="step-row">
                <span class="step-badge">2</span>
                <span class="step-label">บัญชีผู้ดูแล (School Admin)</span>
            </div>
            <div class="form-grid">
                <div class="fg" id="fg_aduser">
                    <label>Username *</label>
                    <input type="text" id="f_aduser" placeholder="เช่น admin_satit" autocomplete="off"
                           oninput="validateField('aduser')">
                    <span class="err-hint" id="err_aduser">—</span>
                </div>
                <div class="fg" id="fg_adpass">
                    <label>Password *</label>
                    <div class="pw-wrap">
                        <input type="password" id="f_adpass" placeholder="อย่างน้อย 6 ตัวอักษร"
                               autocomplete="new-password" oninput="validateField('adpass')">
                        <button type="button" class="pw-eye" onclick="togglePw('f_adpass',this)">👁</button>
                    </div>
                    <span class="hint">≥ 6 ตัวอักษร</span>
                    <span class="err-hint" id="err_adpass">—</span>
                </div>
            </div>

            <!-- Logo Upload -->
            <div class="fg" style="grid-column:1/-1;margin-top:4px;">
                <label style="font-size:.8rem;font-weight:600;color:#374151;">🖼️ ตราโรงเรียน (Logo) <span style="color:#94a3b8;font-weight:400">— ไม่บังคับ</span></label>
                <div class="logo-upload-zone" id="logoZone"
                     ondragover="event.preventDefault();this.classList.add('drag')"
                     ondragleave="this.classList.remove('drag')"
                     ondrop="handleLogoDrop(event)">
                    <input type="file" id="f_logo" accept="image/png,image/jpeg,image/webp,image/svg+xml"
                           onchange="previewLogo(this)">
                    <img class="logo-preview" id="logoPreview" src="" alt="preview">
                    <div id="logoPlaceholder" style="text-align:center">
                        🖼️ <strong>คลิกหรือลากไฟล์ตราโรงเรียน</strong><br>
                        <span class="logo-hint">PNG, JPG, WEBP, SVG — ไม่เกิน 2MB</span>
                    </div>
                </div>
            </div>

            <!-- Progress -->
            <div class="progress-wrap" id="progressWrap">
                <div class="progress-step" id="progressStep">กำลังเชื่อมต่อ...</div>
                <div class="progress-bar-bg"><div class="progress-bar-fill" id="progressBar"></div></div>
            </div>

            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-top:4px;">
                <button class="btn-create" id="addBtn" onclick="doAddTenant()">
                    <span id="addBtnIcon">🏫</span>
                    <span id="addBtnText">สร้างโรงเรียนใหม่</span>
                </button>
                <span style="font-size:.78rem;color:var(--text-muted)">
                    หลังกดปุ่ม โปรดรอสักครู่ระบบกำลังตั้งค่า...
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Toast container -->
<div class="toast-wrap" id="toastWrap"></div>

<!-- Edit Modal -->
<div class="modal-bg" id="editModal">
    <div class="modal">
        <h3>✏️ แก้ไขข้อมูลโรงเรียน</h3>
        <input type="hidden" id="eId">
        <div class="fg" style="margin-bottom:13px;">
            <label>ชื่อโรงเรียน</label>
            <input type="text" id="eName">
        </div>
        <div class="fg">
            <label>สีหลัก</label>
            <div class="crow">
                <input type="color" id="eCol" oninput="document.getElementById('eColT').value=this.value">
                <input type="text" id="eColT" oninput="syncColor('eCol','eColT')">
            </div>
        </div>
        <div class="fg" style="margin-top:13px;">
            <label style="font-size:.8rem;font-weight:600;color:#374151;">🖼️ เปลี่ยนตราโรงเรียน <span style="color:#94a3b8;font-weight:400">— ไม่บังคับ</span></label>
            <div class="logo-upload-zone" id="eLogoZone"
                 ondragover="event.preventDefault();this.classList.add('drag')"
                 ondragleave="this.classList.remove('drag')"
                 ondrop="handleEditLogoDrop(event)"
                 style="padding:12px;min-height:70px;">
                <input type="file" id="eLogoFile" accept="image/png,image/jpeg,image/webp,image/svg+xml"
                       onchange="previewEditLogo(this)">
                <img id="eLogoPreview" src="" alt="" style="width:56px;height:56px;border-radius:8px;object-fit:contain;display:none;margin:0 auto 6px">
                <div id="eLogoPlaceholder" style="text-align:center;font-size:.8rem;color:#94a3b8">
                    🖼️ คลิกหรือลากไฟล์ตราใหม่<br><span style="font-size:.7rem">PNG, JPG, WEBP — ≤ 2MB</span>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="closeEdit()">ยกเลิก</button>
            <button class="btn-save" onclick="doEditTenant()">💾 บันทึก</button>
        </div>
    </div>
</div>

<script>
// Global error handler สำหรับ debug
window.addEventListener('error', function(e) {
    console.error('[TM Debug] Error:', e.message, 'at', e.filename + ':' + e.lineno + ':' + e.colno);
    if (e.message && e.message.includes('end of input')) {
        console.error('[TM Debug] Source near error:', 
            document.documentElement.outerHTML.split('\n').slice(e.lineno-2, e.lineno+2).join('\n'));
    }
});

// Force update Service Worker
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.getRegistrations().then(regs => {
        regs.forEach(r => r.update());
    });
}

const CSRF = <?= json_encode($csrf) ?>;

// ── Toast ─────────────────────────────────────────────────────
function toast(type, title, msg, dur=5000) {
    const wrap = document.getElementById('toastWrap');
    const icons = { ok:'✅', err:'❌', warn:'⚠️' };
    const el = document.createElement('div');
    el.className = 'toast ' + (type==='ok'?'':'') + (type==='err'?'err':'') + (type==='warn'?'warn':'');
    el.innerHTML = `<span class="toast-icon">${icons[type]||'ℹ️'}</span>
        <div class="toast-body"><div class="toast-title">${title}</div><div class="toast-msg">${msg}</div></div>
        <button class="toast-close" onclick="this.parentElement.remove()">✕</button>`;
    wrap.appendChild(el);
    if (dur>0) setTimeout(()=>el.remove(), dur);
}

// ── API helper ─────────────────────────────────────────────────
async function api(action, data, file = null) {
    const body = new FormData();
    body.append('action', action);
    body.append('csrf_token', CSRF);
    for (const [k,v] of Object.entries(data)) body.append(k, v);
    if (file) body.append('school_logo', file);
    let res;
    try {
        res = await fetch('api_tenant.php', {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        });
    } catch(networkErr) {
        throw new Error('ไม่สามารถเชื่อมต่อ server: ' + networkErr.message);
    }
    const text = await res.text();
    // หา JSON ใน response (อาจมี PHP warning นำหน้า)
    const jsonStart = text.indexOf('{');
    const jsonStr   = jsonStart >= 0 ? text.slice(jsonStart) : '';
    if (!jsonStr) {
        console.error('No JSON in response (HTTP ' + res.status + '):', text.substring(0, 300));
        if (res.status === 302 || res.status === 403) {
            return {ok: false, msg: '❌ Session หมดอายุ กรุณา reload หน้าเว็บ'};
        }
        throw new Error('ไม่ได้รับ JSON จาก server (HTTP ' + res.status + ')');
    }
    try {
        return JSON.parse(jsonStr);
    } catch(e) {
        console.error('Bad JSON:', jsonStr.substring(0, 200));
        throw new Error('JSON ไม่ถูกต้อง จาก server');
    }
}

// ── Validation ──────────────────────────────────────────────────
function setErr(field, msg) {
    const fg = document.getElementById('fg_'+field);
    const eh = document.getElementById('err_'+field);
    if (!fg || !eh) return;
    fg.classList.add('has-error');
    eh.textContent = msg;
}
function clearErr(field) {
    const fg = document.getElementById('fg_'+field);
    if (fg) fg.classList.remove('has-error');
}
function validateField(field) {
    const v = document.getElementById('f_'+field)?.value.trim();
    if (!v) return;
    clearErr(field);
    if (field==='code' && !/^[A-Z0-9]{2,10}$/.test(v)) setErr('code','ต้องเป็น A-Z/0-9 ยาว 2-10 ตัว');
    if (field==='adpass' && v.length < 6) setErr('adpass','ต้องมีอย่างน้อย 6 ตัวอักษร');
}
function validateAll() {
    let ok = true;
    const fields = {
        code:  [/^[A-Z0-9]{2,10}$/, 'รหัสต้องเป็น A-Z/0-9 ยาว 2-10 ตัว'],
        name:  [/.+/, 'กรุณากรอกชื่อโรงเรียน'],
        aduser:[/.+/, 'กรุณากรอก Username'],
        adpass:[/.{6,}/, 'Password ต้องมีอย่างน้อย 6 ตัวอักษร'],
    };
    for (const [field, [re, msg]] of Object.entries(fields)) {
        const v = document.getElementById('f_'+field)?.value.trim() || '';
        if (!re.test(v)) { setErr(field, msg); ok = false; }
        else clearErr(field);
    }
    return ok;
}

// ── Progress helper ─────────────────────────────────────────────
function setProgress(pct, msg) {
    document.getElementById('progressBar').style.width = pct + '%';
    document.getElementById('progressStep').textContent = msg;
    if (!document.getElementById('progressWrap').classList.contains('show'))
        document.getElementById('progressWrap').classList.add('show');
}
function hideProgress() {
    document.getElementById('progressWrap').classList.remove('show');
    document.getElementById('progressBar').style.width = '0%';
}

// ── Add Tenant ─────────────────────────────────────────────────
function previewLogo(input) {
    const file = input.files[0];
    if (!file) return;
    if (file.size > 2*1024*1024) { toast('err','ไฟล์ใหญ่เกิน 2MB',''); input.value=''; return; }
    const reader = new FileReader();
    reader.onload = e => {
        const img = document.getElementById('logoPreview');
        const ph  = document.getElementById('logoPlaceholder');
        img.src = e.target.result;
        img.classList.add('show');
        ph.style.display = 'none';
    };
    reader.readAsDataURL(file);
}
function handleLogoDrop(e) {
    e.preventDefault();
    document.getElementById('logoZone').classList.remove('drag');
    const file = e.dataTransfer.files[0];
    if (!file) return;
    const dt = new DataTransfer();
    dt.items.add(file);
    const inp = document.getElementById('f_logo');
    inp.files = dt.files;
    previewLogo(inp);
}

async function doAddTenant() {
    if (!validateAll()) {
        document.getElementById('addPanel').scrollIntoView({behavior:'smooth',block:'start'});
        return;
    }
    const btn  = document.getElementById('addBtn');
    const icon = document.getElementById('addBtnIcon');
    const text = document.getElementById('addBtnText');
    btn.disabled = true;
    icon.innerHTML = '<span class="spin"></span>';
    text.textContent = 'กำลังสร้าง...';

    setProgress(10, 'กำลังตรวจสอบข้อมูล...');
    const t1 = setTimeout(()=>setProgress(30,'กำลังสร้าง Database...'),1500);
    const t2 = setTimeout(()=>setProgress(60,'กำลัง Clone Schema...'),4000);
    const t3 = setTimeout(()=>setProgress(85,'กำลังสร้าง Admin User...'),8000);

    try {
        const logoFile = document.getElementById('f_logo').files[0];
        const r = await api('add_tenant', {
            school_code:    document.getElementById('f_code').value.trim(),
            school_name:    document.getElementById('f_name').value.trim(),
            primary_color:  document.getElementById('f_color').value,
            admin_display:  document.getElementById('f_adname').value.trim(),
            admin_username: document.getElementById('f_aduser').value.trim(),
            admin_password: document.getElementById('f_adpass').value,
        }, logoFile);
        clearTimeout(t1); clearTimeout(t2); clearTimeout(t3);
        setProgress(100, '✅ สำเร็จ!');

        if (r.ok) {
            toast('ok', 'สร้างโรงเรียนสำเร็จ!',
                `<strong>${r.tenant.school_name}</strong> (${r.tenant.school_code})<br>
                 Login โดยใส่รหัส: <code>${r.tenant.school_code}</code>`, 10000);
            appendCard(r.tenant);
            // clear form
            ['f_code','f_name','f_adname','f_aduser','f_adpass'].forEach(id=>{
                const el = document.getElementById(id);
                if(el) el.value='';
            });
            document.getElementById('f_color').value  = '#1e3a8a';
            document.getElementById('f_colorT').value = '#1e3a8a';
            // update stats
            updateStats();
        } else {
            toast('err', 'สร้างไม่สำเร็จ', r.msg);
            setProgress(0,'');
            hideProgress();
        }
    } catch(e) {
        clearTimeout(t1); clearTimeout(t2); clearTimeout(t3);
        toast('err', 'เชื่อมต่อ API ไม่ได้', e.message);
        hideProgress();
    }
    setTimeout(hideProgress, 2000);
    btn.disabled = false;
    icon.textContent = '🏫';
    text.textContent = 'สร้างโรงเรียนใหม่';
}

function appendCard(t) {
    const grid = document.getElementById('tenantGrid');
    const empty = grid.querySelector('.empty-state');
    if (empty) empty.remove();
    const el = document.createElement('div');
    el.className = 'tenant-card';
    el.id = 'card-' + t.id;
    el.dataset.id = t.id;

    const hdr = document.createElement('div'); hdr.className = 'tc-header';
    const bar = document.createElement('div'); bar.className = 'tc-color-bar';
    bar.style.background = t.primary_color;
    const info = document.createElement('div'); info.className = 'tc-info';
    info.innerHTML = '<div class="tc-code">' + esc(t.school_code) + '</div>' +
                     '<div class="tc-name">' + esc(t.school_name) + '</div>' +
                     '<div class="tc-db">' + esc(t.db_name) + '</div>';
    const badge = document.createElement('span');
    badge.className = 'tc-badge on'; badge.id = 'badge-' + t.id; badge.textContent = 'เปิด';
    hdr.appendChild(bar); hdr.appendChild(info); hdr.appendChild(badge);

    const body = document.createElement('div'); body.className = 'tc-body';
    body.innerHTML = '<div class="tc-stat"><div class="tc-stat-val">' + t.tbl_count + '</div><div class="tc-stat-lbl">ตารางใน DB</div></div>' +
                     '<div class="tc-stat"><div class="tc-stat-val">' + esc(t.primary_color) + '</div><div class="tc-stat-lbl">สีหลัก</div></div>' +
                     '<div class="tc-stat" style="grid-column:1/-1"><div class="tc-stat-val" style="font-size:.8rem">เพิ่งสร้าง</div><div class="tc-stat-lbl">วันที่สร้าง</div></div>';

    const footer = document.createElement('div'); footer.className = 'tc-footer';
    const btnToggle = document.createElement('button');
    btnToggle.className = 'tc-action toggle active'; btnToggle.id = 'tbtn-' + t.id;
    btnToggle.textContent = 'ปิดชั่วคราว';
    btnToggle.onclick = function() { toggleTenant(t.id); };
    const btnEdit = document.createElement('button');
    btnEdit.className = 'tc-action edit'; btnEdit.textContent = 'แก้ไข';
    btnEdit.onclick = function() { openEdit(t.id, t.school_name, t.primary_color); };
    const btnCopy = document.createElement('button');
    btnCopy.className = 'tc-action login'; btnCopy.textContent = t.school_code;
    btnCopy.title = 'คัดลอกรหัส'; btnCopy.onclick = function() { copyCode(t.school_code); };
    footer.appendChild(btnToggle); footer.appendChild(btnEdit); footer.appendChild(btnCopy);

    el.appendChild(hdr); el.appendChild(body); el.appendChild(footer);
    grid.appendChild(el);
}

function updateStats() {
    const cards  = document.querySelectorAll('.tenant-card');
    const active = document.querySelectorAll('.tc-badge.on').length;
    const vals   = document.querySelectorAll('.stat-val');
    if (vals[0]) vals[0].textContent = cards.length;
    if (vals[1]) vals[1].textContent = active;
    if (vals[2]) vals[2].textContent = cards.length - active;
}

// ── Toggle ──────────────────────────────────────────────────────
async function toggleTenant(id) {
    try {
        const r = await api('toggle_active', {tenant_id: id});
        if (r.ok) {
            const badge = document.getElementById('badge-'+id);
            const tbtn  = document.getElementById('tbtn-'+id);
            const card  = document.getElementById('card-'+id);
            if (r.is_active) {
                badge.className = 'tc-badge on'; badge.textContent = '✅ เปิด';
                tbtn.className  = 'tc-action toggle active'; tbtn.textContent = '⏸ ปิดชั่วคราว';
                card.classList.remove('inactive');
            } else {
                badge.className = 'tc-badge off'; badge.textContent = '❌ ปิด';
                tbtn.className  = 'tc-action toggle'; tbtn.textContent = '▶ เปิดใช้งาน';
                card.classList.add('inactive');
            }
            updateStats();
            toast('ok', 'เปลี่ยนสถานะสำเร็จ', r.msg);
        } else { toast('err', 'ผิดพลาด', r.msg); }
    } catch(e) { toast('err', 'API Error', e.message); }
}

// ── Copy School Code ────────────────────────────────────────────
function copyCode(code) {
    navigator.clipboard.writeText(code).then(()=>
        toast('ok', 'คัดลอกสำเร็จ!', `รหัสโรงเรียน <strong>${code}</strong> ถูกคัดลอกแล้ว`, 3000)
    ).catch(()=> {
        // fallback
        const inp = document.createElement('input');
        inp.value = code; document.body.appendChild(inp);
        inp.select(); document.execCommand('copy');
        document.body.removeChild(inp);
        toast('ok', 'คัดลอกสำเร็จ!', `รหัส <strong>${code}</strong>`, 3000);
    });
}

// ── Edit ────────────────────────────────────────────────────────
function openEdit(id, name, color) {
    document.getElementById('eId').value   = id;
    document.getElementById('eName').value = name;
    document.getElementById('eCol').value  = color;
    document.getElementById('eColT').value = color;
    // reset logo preview
    const elp = document.getElementById('eLogoPreview');
    const eph  = document.getElementById('eLogoPlaceholder');
    const elf  = document.getElementById('eLogoFile');
    if (elp) { elp.src=''; elp.style.display='none'; }
    if (eph) eph.style.display = 'block';
    if (elf) elf.value = '';
    document.getElementById('editModal').classList.add('open');
}
function previewEditLogo(input) {
    const file = input.files[0];
    if (!file) return;
    if (file.size > 2*1024*1024) { toast('err','ไฟล์ใหญ่เกิน 2MB',''); input.value=''; return; }
    const reader = new FileReader();
    reader.onload = e => {
        const img = document.getElementById('eLogoPreview');
        const ph  = document.getElementById('eLogoPlaceholder');
        img.src = e.target.result;
        img.style.display = 'block';
        ph.style.display  = 'none';
    };
    reader.readAsDataURL(file);
}
function handleEditLogoDrop(e) {
    e.preventDefault();
    document.getElementById('eLogoZone').classList.remove('drag');
    const file = e.dataTransfer.files[0];
    if (!file) return;
    const dt = new DataTransfer();
    dt.items.add(file);
    const inp = document.getElementById('eLogoFile');
    inp.files = dt.files;
    previewEditLogo(inp);
}
function closeEdit() { document.getElementById('editModal').classList.remove('open'); }
document.getElementById('editModal').addEventListener('click', e => { if(e.target===e.currentTarget) closeEdit(); });

async function doEditTenant() {
    const id    = document.getElementById('eId').value;
    const name  = document.getElementById('eName').value.trim();
    const color = document.getElementById('eCol').value;
    if (!name) return toast('warn', 'กรอกชื่อด้วย', '');
    // ป้องกัน double submit
    const saveBtn = document.querySelector('.btn-save');
    if (saveBtn) saveBtn.disabled = true;
    try {
        const eLogoFile = document.getElementById('eLogoFile')?.files[0] || null;
        const r = await api('edit_tenant', {tenant_id:id, school_name:name, primary_color:color}, eLogoFile);
        console.log('[Edit] full response:', JSON.stringify(r));
        if (r.ok) {
            closeEdit();
            toast('ok', 'อัปเดตสำเร็จ', r.msg);
            const card = document.getElementById('card-'+id);
            if (card) {
                card.querySelector('.tc-name').textContent = name;
                card.querySelector('.tc-color-bar').style.background = color;
                const swatches = card.querySelectorAll('.tc-stat-val span');
                swatches.forEach(s=>s.style.background=color);
            }
        } else { 
            console.error('[Edit] failed:', r);
            toast('err', 'ผิดพลาด', r.msg || JSON.stringify(r)); 
        }
    } catch(e) { 
        console.error('[Edit] exception:', e);
        toast('err', 'API Error', e.message); 
    } finally {
        if (saveBtn) saveBtn.disabled = false;
    }
}

// ── Add Panel toggle ────────────────────────────────────────────
function toggleAddPanel() {
    const body = document.getElementById('addBody');
    const icon = document.getElementById('addToggleIcon');
    const panel = document.getElementById('addPanel');
    const isOpen = body.classList.toggle('open');
    icon.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(-90deg)';
    panel.classList.toggle('highlight', isOpen);
}
function scrollToAdd() {
    const panel = document.getElementById('addPanel');
    const body  = document.getElementById('addBody');
    if (!body.classList.contains('open')) toggleAddPanel();
    panel.scrollIntoView({behavior:'smooth', block:'start'});
    setTimeout(()=>document.getElementById('f_code')?.focus(), 400);
}

// ── Utils ───────────────────────────────────────────────────────
function syncColor(picId, txtId) {
    const val = document.getElementById(txtId).value;
    if (/^#[0-9A-Fa-f]{6}$/.test(val)) document.getElementById(picId).value = val;
}
function togglePw(id, btn) {
    const inp = document.getElementById(id);
    inp.type = inp.type==='password' ? 'text' : 'password';
    btn.textContent = inp.type==='password' ? '👁' : '🙈';
}
function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Auto-highlight add panel on load if no tenants
<?php if(empty($tenants)): ?>
document.addEventListener('DOMContentLoaded', function() {
    var panel = document.getElementById('addPanel');
    if (panel) panel.classList.add('highlight');
    setTimeout(function() {
        var fc = document.getElementById('f_code');
        if (fc) fc.focus();
    }, 300);
});
<?php endif; ?>
</script>

<?php require_once 'footer.php'; ?>
