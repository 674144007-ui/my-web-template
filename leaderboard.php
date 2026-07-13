<?php
// leaderboard.php - หอเกียรติยศ (Phase 3: Mobile Leaderboard Redesign)
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

requireLogin();
$page_title = "หอเกียรติยศนักเคมี (Leaderboard) - โรงเรียนบ้านคาวิทยา";
require_once 'header.php';
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--lc:<?= h($tenant['primary_color'] ?? '#1e3a8a') ?>;}
*{box-sizing:border-box}
.lb-wrap{max-width:900px;margin:0 auto;padding:24px 20px 80px;font-family:'Prompt',sans-serif}
.btn-back{display:inline-flex;align-items:center;gap:8px;color:#64748b;text-decoration:none;font-weight:600;margin-bottom:18px;font-size:.88rem;transition:.2s}
.btn-back:hover{color:#0f172a;transform:translateX(-4px)}

/* Hero */
.lb-hero{background:var(--lc);border-radius:18px;padding:28px 32px;margin-bottom:24px;
    color:#fff;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px}
.lb-hero::after{content:'🏆';position:absolute;right:20px;bottom:-10px;font-size:7rem;opacity:.12}
.lb-tag{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;
    letter-spacing:.08em;text-transform:uppercase;background:rgba(255,255,255,.15);
    border:1px solid rgba(255,255,255,.25);padding:3px 11px;border-radius:20px;margin-bottom:8px}
.lb-hero h1{font-size:1.6rem;font-weight:700;margin:0 0 4px}
.lb-hero .sub{font-size:.85rem;opacity:.85;margin:0}

/* Top 3 */
.lb-podium{display:flex;justify-content:center;align-items:flex-end;gap:16px;margin-bottom:28px;padding:0 10px}
.lb-pod{background:#fff;border:1.5px solid #e2e8f0;border-radius:16px;padding:20px 16px;
    text-align:center;flex:1;max-width:180px;position:relative;transition:.2s}
.lb-pod:hover{transform:translateY(-3px);box-shadow:0 10px 28px rgba(0,0,0,.08)}
.lb-pod.rank1{border-color:gold;background:linear-gradient(135deg,#fffbeb,#fff);
    transform:translateY(-12px);max-width:200px}
.lb-pod.rank2{border-color:silver}
.lb-pod.rank3{border-color:#cd7f32}
.lb-pod .crown{font-size:2rem;margin-bottom:6px}
.lb-pod .pod-rank{font-size:.72rem;font-weight:700;color:#94a3b8;letter-spacing:.06em;text-transform:uppercase;margin-bottom:6px}
.lb-pod .pod-name{font-size:.9rem;font-weight:700;color:#0f172a;margin-bottom:4px;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.lb-pod .pod-class{font-size:.72rem;color:#64748b;margin-bottom:8px}
.lb-pod .pod-xp{font-size:1.1rem;font-weight:700;color:var(--lc)}
.lb-pod .pod-badges{display:flex;justify-content:center;gap:3px;flex-wrap:wrap;margin-top:6px}

/* Section */
.lb-sec{font-size:.7rem;font-weight:700;letter-spacing:.1em;color:#94a3b8;text-transform:uppercase;
    margin:0 0 14px;display:flex;align-items:center;gap:8px}
.lb-sec::after{content:'';flex:1;height:1px;background:#f1f5f9}

/* List */
.lb-list{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden}
.lb-row{display:grid;grid-template-columns:44px 1fr auto;align-items:center;gap:12px;
    padding:13px 18px;border-bottom:1px solid #f9fafb;transition:.12s}
.lb-row:last-child{border-bottom:none}
.lb-row:hover{background:#fafafa}
.lb-rank-num{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;
    font-size:.8rem;font-weight:700;background:#f1f5f9;color:#64748b;flex-shrink:0}
.lb-rank-num.top{background:var(--lc);color:#fff}
.lb-info .name{font-size:.9rem;font-weight:600;color:#0f172a}
.lb-info .meta{font-size:.73rem;color:#94a3b8;margin-top:1px}
.lb-info .badges{display:flex;gap:3px;margin-top:4px}
.lb-right{text-align:right}
.lb-xp{font-size:1rem;font-weight:700;color:var(--lc)}
.lb-quests{font-size:.72rem;color:#94a3b8}

/* Empty */
.lb-empty{text-align:center;padding:52px 20px;color:#94a3b8}
.lb-empty .ei{font-size:3rem;margin-bottom:12px}

@media(max-width:640px){.lb-podium{gap:8px}.lb-pod{padding:14px 10px}
    .lb-wrap{padding:14px 14px 60px}}
</style>

<div class="lb-wrap">
  <a href="dashboard_student.php" class="btn-back">← กลับแดชบอร์ด</a>

  <div class="lb-hero">
    <div style="position:relative;z-index:1">
      <div class="lb-tag">🏆 Leaderboard</div>
      <h1>หอเกียรติยศนักเคมี</h1>
      <p class="sub"><?= h($tenant['school_name'] ?? 'โรงเรียน') ?> · อันดับจากคะแนนแล็บสะสม</p>
    </div>
  </div>

  <?php if (!empty($error_msg)): ?>
  <div style="background:#fee2e2;border:1px solid #fecaca;color:#b91c1c;padding:12px 16px;border-radius:10px;margin-bottom:20px;font-size:.85rem">❌ <?= h($error_msg) ?></div>
  <?php endif; ?>

  <?php if (empty($leaderboard_data)): ?>
  <div class="lb-empty"><div class="ei">📊</div><p>ยังไม่มีข้อมูลอันดับในขณะนี้<br>เริ่มทำแล็บเพื่อสะสม XP !</p></div>
  <?php else: ?>

  <!-- Top 3 Podium -->
  <?php if (count($leaderboard_data) >= 3): ?>
  <div class="lb-podium">
    <?php
    $podium = [
      isset($leaderboard_data[1]) ? [$leaderboard_data[1],'rank2','🥈','2nd'] : null,
      [$leaderboard_data[0],'rank1','👑','1st'],
      isset($leaderboard_data[2]) ? [$leaderboard_data[2],'rank3','🥉','3rd'] : null,
    ];
    foreach($podium as $p):
      if(!$p) continue;
      [$u,$cls,$crown,$lbl] = $p;
      $badges = $student_badges_map[$u['id']] ?? [];
    ?>
    <div class="lb-pod <?= $cls ?>">
      <div class="crown"><?= $crown ?></div>
      <div class="pod-rank"><?= $lbl ?></div>
      <div class="pod-name"><?= h($u['display_name']) ?></div>
      <div class="pod-class"><?= h($u['class_name'] ?? '') ?></div>
      <div class="pod-xp"><?= number_format($u['total_xp']) ?> XP</div>
      <div class="pod-badges">
        <?php foreach(array_slice($badges,0,3) as $bk):
          $bi = $badge_icons[$bk] ?? null;
          if($bi) echo '<span title="'.$bi['title'].'">'.$bi['icon'].'</span>';
        endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Full List -->
  <div class="lb-sec">อันดับทั้งหมด</div>
  <div class="lb-list">
    <?php foreach($leaderboard_data as $i => $u):
      $rank = $i + 1;
      $badges = $student_badges_map[$u['id']] ?? [];
      $isTop  = $rank <= 3;
    ?>
    <div class="lb-row">
      <div class="lb-rank-num <?= $isTop?'top':'' ?>"><?= $rank ?></div>
      <div class="lb-info">
        <div class="name"><?= h($u['display_name']) ?></div>
        <div class="meta"><?= h($u['class_name'] ?? '') ?> · <?= $u['total_quests'] ?> quest</div>
        <?php if($badges): ?>
        <div class="badges">
          <?php foreach(array_slice($badges,0,4) as $bk):
            $bi = $badge_icons[$bk] ?? null;
            if($bi) echo '<span title="'.$bi['title'].'">'.$bi['icon'].'</span>';
          endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="lb-right">
        <div class="lb-xp"><?= number_format($u['total_xp']) ?> XP</div>
        <div class="lb-quests"><?= $u['total_quests'] ?> quest</div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
