<?php
/**
 * api_auto_grade.php — AI Auto-Grading Engine (Phase 4)
 * รับ telemetry จาก Virtual Lab → ตรวจคะแนน → บันทึก DB → sync gradebook
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/env_loader.php';
require_once 'config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once 'db.php';
require_once __DIR__ . '/rate_limiter.php';
checkApiRateLimit();

// ── 1. Auth ───────────────────────────────────────────────────
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    echo json_encode(['status'=>'error','message'=>'Unauthorized. Students only.']);
    exit;
}
$student_id = (int)$_SESSION['user_id'];

// ── 2. Parse JSON payload ─────────────────────────────────────
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data || !is_array($data)) {
    echo json_encode(['status'=>'error','message'=>'Invalid JSON payload.']);
    exit;
}

$assignment_id = isset($data['assignment_id']) ? (int)$data['assignment_id'] : 0;
$csrf_token    = $data['csrf_token'] ?? '';
$telemetry     = isset($data['telemetry']) && is_array($data['telemetry']) ? $data['telemetry'] : [];

// ── 3. CSRF ───────────────────────────────────────────────────
if (!isset($_SESSION['csrf_token']) || $csrf_token !== $_SESSION['csrf_token']) {
    echo json_encode(['status'=>'error','message'=>'CSRF validation failed.']);
    exit;
}

// ── 4. Free Play ──────────────────────────────────────────────
if ($assignment_id === 0) {
    echo json_encode([
        'status'      => 'success',
        'final_score' => 0,
        'grade'       => '-',
        'breakdown'   => ['✅ โหมดทดลองอิสระ (Free Play) ไม่มีการบันทึกคะแนน'],
        'message'     => 'Free play mode.'
    ]);
    exit;
}

// ── helper: ดึง nested value อย่างปลอดภัย ────────────────────
function tget(array $arr, string $key, $default = null) {
    return isset($arr[$key]) ? $arr[$key] : $default;
}

try {
    // ── 5. ดึง Assignment + Settings ─────────────────────────
    $stmt = $pdo->prepare("
        SELECT a.id, a.title, a.reward_points, a.max_score, a.game_settings,
               gc.id AS gradebook_col_id
        FROM assignments a
        LEFT JOIN gradebook_columns gc ON gc.assignment_id = a.id
        WHERE a.id = ? AND a.is_active = 1
    ");
    $stmt->execute([$assignment_id]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assignment) {
        echo json_encode(['status'=>'error','message'=>'Assignment not found or inactive.']);
        exit;
    }

    $settings  = json_decode($assignment['game_settings'] ?? '{}', true) ?: [];
    $max_score = floatval($assignment['max_score'] ?? 100);
    $tolerance = floatval(tget($settings, 'grading_rubric.tolerance_percent', 5)) / 100;
    // nested key support
    if (isset($settings['grading_rubric']['tolerance_percent'])) {
        $tolerance = floatval($settings['grading_rubric']['tolerance_percent']) / 100;
    }

    // ── 6. แยก Telemetry fields (null-safe) ──────────────────
    $actions      = is_array(tget($telemetry,'actions'))      ? $telemetry['actions']      : [];
    $beaker_items = is_array(tget($telemetry,'beaker_items')) ? $telemetry['beaker_items'] : [];
    $hp_remaining = max(0, min(100, (int)tget($telemetry,'hp_remaining', 100)));
    $spill_count  = max(0, (int)tget($actions,'spill_count', 0));
    $safety_viol  = max(0, (int)tget($actions,'safety_violations', 0));
    $exploded     = (bool)tget($actions,'exploded', false);
    $did_stir     = (bool)tget($actions,'did_stir', false);
    $rxn_done     = (bool)tget($actions,'reactionProcessed', false);
    $toxic_exp    = max(0, (int)tget($actions,'toxicExposures', 0));
    $boil_exp     = max(0, (int)tget($actions,'boilingExplosions', 0));

    // ── 7. Grading Engine ─────────────────────────────────────
    $score     = 100.0;
    $breakdown = [];
    $ai_hints  = [];   // คำแนะนำเฉพาะบุคคลจาก AI

    // 7a. HP = 0 → fail ทันที
    if ($hp_remaining <= 0) {
        $score = 0;
        $breakdown[] = "❌ HP = 0: ได้รับบาดเจ็บสาหัสจนไม่สามารถทดลองต่อได้ (-100)";
        $ai_hints[]  = "ครั้งหน้าให้สวมแว่นตาและถุงมือก่อนเริ่มทดลองทุกครั้ง และหลีกเลี่ยงการผสมสารโดยไม่ทราบผลลัพธ์";
    } else {
        $breakdown[] = "✅ ความปลอดภัย: HP เหลือ {$hp_remaining}%";

        // 7b. Safety violations (-10/ครั้ง, สูงสุด -30)
        if ($safety_viol > 0) {
            $pen = min(30, $safety_viol * 10);
            $score -= $pen;
            $breakdown[] = "❌ ละเมิดความปลอดภัย {$safety_viol} ครั้ง (-{$pen})";
            $ai_hints[]  = "ต้องสวมแว่นตาและถุงมือก่อนเริ่มทดลองเสมอ โดยเฉพาะเมื่อใช้กรดหรือด่าง";
        }

        // 7c. Explosion (-50)
        if ($exploded) {
            $score -= 50;
            $breakdown[] = "💥 เกิดการระเบิด (-50): ควบคุมความร้อนผิดพลาด";
            $ai_hints[]  = "อย่าให้ความร้อนเกินจุดเดือดของสาร ควรเริ่มจากอุณหภูมิต่ำก่อน";
        }

        // 7d. Toxic exposure (-5/ครั้ง)
        if ($toxic_exp > 0) {
            $pen = min(20, $toxic_exp * 5);
            $score -= $pen;
            $breakdown[] = "☣️ สัมผัสสารพิษ {$toxic_exp} ครั้ง (-{$pen})";
            $ai_hints[]  = "ใช้อุปกรณ์ป้องกันครบถ้วนเมื่อทำงานกับสารที่มีพิษ";
        }

        // 7e. Boiling explosion (-15/ครั้ง)
        if ($boil_exp > 0) {
            $pen = min(30, $boil_exp * 15);
            $score -= $pen;
            $breakdown[] = "🌡️ บีกเกอร์ระเบิดจากความร้อน {$boil_exp} ครั้ง (-{$pen})";
            $ai_hints[]  = "ลดไฟและคนสารก่อนที่ของเหลวจะถึงจุดเดือด";
        }

        // 7f. Spill (-5/ครั้งเกิน limit)
        $max_spill = (int)tget($settings,'max_spill_allowed', 1);
        if ($spill_count > $max_spill) {
            $pen = min(20, ($spill_count - $max_spill) * 5);
            $score -= $pen;
            $breakdown[] = "💧 สารหกเกินกำหนด " . ($spill_count - $max_spill) . " ครั้ง (-{$pen})";
        }

        // 7g. Stirring required
        $req_stir = isset($settings['required_stirring']) && $settings['required_stirring'] == 1;
        if ($req_stir) {
            if ($did_stir) {
                $breakdown[] = "✅ คนสารละลายถูกต้อง";
            } else {
                $score -= 10;
                $breakdown[] = "🥄 ลืมคนสารละลาย (-10)";
                $ai_hints[]  = "สารบางชนิดละลายช้า ต้องคนเพื่อให้ปฏิกิริยาสมบูรณ์";
            }
        }

        // 7h. ตรวจสารเคมีที่ใช้ + ปริมาณ
        $strict = isset($settings['strict_amount']) && $settings['strict_amount'] == 1;
        $chem_ids = [];
        if (!empty($settings['target_chem1'])) $chem_ids[] = ['id'=>(int)$settings['target_chem1'],'amt'=>floatval($settings['amount_chem1']??0),'key'=>'chem1'];
        if (!empty($settings['target_chem2'])) $chem_ids[] = ['id'=>(int)$settings['target_chem2'],'amt'=>floatval($settings['amount_chem2']??0),'key'=>'chem2'];

        foreach ($chem_ids as $req) {
            if ($req['id'] <= 0) continue;
            $stmt_c = $pdo->prepare("SELECT name, formula FROM chemicals WHERE id = ?");
            $stmt_c->execute([$req['id']]);
            $chem = $stmt_c->fetch(PDO::FETCH_ASSOC);
            if (!$chem) continue;

            $found = false; $used_amt = 0.0;
            foreach ($beaker_items as $item) {
                $item_id   = (int)tget($item,'id',0);
                $item_name = trim(tget($item,'name',''));
                if ($item_id === $req['id'] || strcasecmp($item_name, $chem['name']) === 0) {
                    $found    = true;
                    $used_amt = floatval(tget($item,'amount',0));
                    break;
                }
            }

            if (!$found) {
                $score -= 20;
                $breakdown[] = "❌ ไม่พบสาร: {$chem['name']} ({$chem['formula']}) (-20)";
                $ai_hints[]  = "ต้องใส่{$chem['name']} ลงในบีกเกอร์ก่อนส่งรายงาน";
            } else {
                if ($strict && $req['amt'] > 0) {
                    $diff    = abs($used_amt - $req['amt']);
                    $allowed = $req['amt'] * $tolerance;
                    if ($diff > $allowed) {
                        $score -= 15;
                        $breakdown[] = "⚖️ ปริมาณ {$chem['name']} ผิด: ต้องการ {$req['amt']} แต่ใส่ {$used_amt} (-15)";
                        $ai_hints[]  = "ฝึกตวงสาร {$chem['name']} ให้แม่นยำ ±" . ($tolerance*100) . "%";
                    } else {
                        $breakdown[] = "✅ ตวงสาร {$chem['name']} แม่นยำ ({$used_amt} / {$req['amt']})";
                    }
                } else {
                    $breakdown[] = "✅ ใช้สาร {$chem['name']} ถูกต้อง";
                }
            }
        }

        // 7i. ตรวจ reaction_type ที่คาดหวัง
        $expected_rxn = tget($settings,'expected_reaction_type','');
        $actual_rxn   = tget($actions,'last_reaction_type','');
        if ($expected_rxn && $actual_rxn) {
            if ($actual_rxn === $expected_rxn) {
                $breakdown[] = "✅ เกิดปฏิกิริยาถูกประเภท ({$expected_rxn})";
            } else {
                $score -= 15;
                $breakdown[] = "⚗️ ปฏิกิริยาไม่ตรง (คาดหวัง {$expected_rxn} แต่ได้ {$actual_rxn}) (-15)";
                $ai_hints[]  = "ตรวจสอบสารที่ใช้ว่าตรงกับโจทย์หรือไม่";
            }
        }

        // 7j. ตรวจว่ากดประมวลผลหรือไม่
        if (!$rxn_done) {
            $score -= 10;
            $breakdown[] = "⚗️ ไม่ได้ประมวลผลปฏิกิริยา (-10)";
        } else {
            $breakdown[] = "✅ ประมวลผลปฏิกิริยาแล้ว";
        }

        // 7k. Safety equipment bonus
        $wore_goggles = (bool)tget($actions,'wore_goggles', false);
        $wore_gloves  = (bool)tget($actions,'wore_gloves',  false);
        $req_goggles  = isset($settings['safety_goggles']) && $settings['safety_goggles'] == 1;
        $req_gloves   = isset($settings['safety_gloves'])  && $settings['safety_gloves']  == 1;

        if ($req_goggles && !$wore_goggles) {
            $score -= 10;
            $breakdown[] = "🥽 ไม่สวมแว่นนิรภัย (-10)";
        }
        if ($req_gloves && !$wore_gloves) {
            $score -= 10;
            $breakdown[] = "🧤 ไม่สวมถุงมือ (-10)";
        }
    }

    // ── 8. Clamp + Grade ─────────────────────────────────────
    $score = max(0.0, min(100.0, round($score, 1)));
    $grade = 'F';
    if ($score >= 80) $grade = 'A';
    elseif ($score >= 70) $grade = 'B';
    elseif ($score >= 60) $grade = 'C';
    elseif ($score >= 50) $grade = 'D';

    if ($score == 100) {
        array_unshift($breakdown, "🌟 สมบูรณ์แบบ! ปฏิบัติการทดลองได้อย่างไร้ที่ติ");
    } elseif ($score >= 80) {
        array_unshift($breakdown, "👍 ทำได้ดีมาก! มีข้อผิดพลาดเล็กน้อยเท่านั้น");
    }

    // XP
    $max_xp   = (int)($assignment['reward_points'] ?? 0);
    $earned_xp = (int)floor($max_xp * ($score / 100));

    // ── 9. Claude AI Feedback ────────────────────────────────
    $claude_feedback = '';
    $personalized_hints = $ai_hints ?: [];
    try {
        $reaction_summary = implode(', ', array_slice(array_map(function($r) {
            return ($r['c1']??'?') . '+' . ($r['c2']??'?') . '→' . ($r['product']??'?') . '(' . ($r['rxnType']??'') . ')';
        }, $data['reaction_log'] ?? []), 0, 8));

        $claude_prompt = "วิเคราะห์การทดลองเคมีนี้ในภาษาไทย:\n" .
            "ปฏิกิริยาที่เกิด: {$reaction_summary}\n" .
            "PPE: แว่นตา=" . ($wore_goggles ? 'ใส่' : 'ไม่ใส่') . " ถุงมือ=" . ($wore_gloves ? 'ใส่' : 'ไม่ใส่') . "\n" .
            "HP: {$hp_remaining}% | คะแนน: {$score}/100\n\n" .
            "ตอบใน 3 ส่วน (กระชับ ไม่เกิน 150 คำ):\n" .
            "**จุดแข็ง:** สิ่งที่นักเรียนทำได้ดี\n" .
            "**ปรับปรุง:** สิ่งที่ควรระวัง\n" .
            "**สรุป:** หลักการวิทยาศาสตร์ที่ได้เรียนรู้";

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model'      => 'claude-sonnet-4-6',
                'max_tokens' => 600,
                'system'     => 'คุณเป็นผู้เชี่ยวชาญเคมีที่วิเคราะห์ผลการทดลองนักเรียน ตอบภาษาไทย กระชับ ให้กำลังใจ',
                'messages'   => [['role'=>'user','content'=>$claude_prompt]],
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $resp) {
            $resp_data = json_decode($resp, true);
            $claude_feedback = $resp_data['content'][0]['text'] ?? '';
            // แยก hints จาก feedback
            if ($claude_feedback) {
                $personalized_hints = [$claude_feedback];
            }
        }
    } catch (Exception $e) {
        error_log('[AutoGrade] Claude API error: ' . $e->getMessage());
    }

    $ai_fb = json_encode([
        'evaluation_time'    => date('Y-m-d H:i:s'),
        'score_breakdown'    => $breakdown,
        'personalized_hints' => $personalized_hints ?: ['ทำได้ดี! ทบทวนสมการปฏิกิริยาเพื่อเข้าใจลึกขึ้น'],
        'claude_analysis'    => $claude_feedback,
        'beaker_snapshot'    => $beaker_items,
        'raw_telemetry'      => $telemetry,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $summary_text = implode("\n", $breakdown);

    // ── 10. บันทึก lab_reports ────────────────────────────────
    $stmt_chk = $pdo->prepare("SELECT id FROM lab_reports WHERE student_id=? AND assignment_id=?");
    $stmt_chk->execute([$student_id, $assignment_id]);
    $existing = $stmt_chk->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $pdo->prepare("
            UPDATE lab_reports
            SET final_score=?, earned_xp=?, grade=?, hp_remaining=?,
                report_summary=?, ai_feedback=?
            WHERE id=?
        ")->execute([$score, $earned_xp, $grade, $hp_remaining, $summary_text, $ai_fb, $existing['id']]);
        $report_id = $existing['id'];
    } else {
        $pdo->prepare("
            INSERT INTO lab_reports
              (student_id, assignment_id, final_score, earned_xp, grade, hp_remaining, report_summary, ai_feedback)
            VALUES (?,?,?,?,?,?,?,?)
        ")->execute([$student_id, $assignment_id, $score, $earned_xp, $grade, $hp_remaining, $summary_text, $ai_fb]);
        $report_id = (int)$pdo->lastInsertId();
    }

    // ── 11. Sync student_assignments ─────────────────────────
    try {
        $graded_id = $pdo->query("SELECT id FROM sys_task_statuses WHERE status_key='graded' LIMIT 1")->fetchColumn() ?: 3;

        $stmt_sa = $pdo->prepare("SELECT id FROM student_assignments WHERE student_id=? AND assignment_id=?");
        $stmt_sa->execute([$student_id, $assignment_id]);
        if ($row_sa = $stmt_sa->fetch()) {
            $pdo->prepare("
                UPDATE student_assignments
                SET status_id=?, score=?, submitted_at=NOW(), graded_at=NOW()
                WHERE id=?
            ")->execute([$graded_id, $score, $row_sa['id']]);
        } else {
            $pdo->prepare("
                INSERT INTO student_assignments (student_id, assignment_id, status_id, score, submitted_at, graded_at)
                VALUES (?,?,?,?,NOW(),NOW())
            ")->execute([$student_id, $assignment_id, $graded_id, $score]);
        }
    } catch (PDOException $e) {
        error_log('[AutoGrade] student_assignments sync failed: ' . $e->getMessage());
    }

    // ── 12. Sync gradebook_scores ─────────────────────────────
    // หา gradebook_column ที่ link กับ assignment นี้
    try {
        $col_id = $assignment['gradebook_col_id'];
        if ($col_id) {
            $stmt_gb = $pdo->prepare("SELECT id FROM gradebook_scores WHERE column_id=? AND student_id=?");
            $stmt_gb->execute([$col_id, $student_id]);
            if ($gb_row = $stmt_gb->fetch()) {
                $pdo->prepare("UPDATE gradebook_scores SET score=?, note=?, updated_at=NOW() WHERE id=?")
                    ->execute([$score, "Auto-graded by Lab Engine (Report #{$report_id})", $gb_row['id']]);
            } else {
                $pdo->prepare("INSERT INTO gradebook_scores (column_id, student_id, score, note) VALUES (?,?,?,?)")
                    ->execute([$col_id, $student_id, $score, "Auto-graded by Lab Engine (Report #{$report_id})"]);
            }
        }
    } catch (PDOException $e) {
        error_log('[AutoGrade] gradebook sync failed: ' . $e->getMessage());
    }

    // ── 13. Response ──────────────────────────────────────────
    echo json_encode([
        'status'      => 'success',
        'final_score' => $score,
        'earned_xp'   => $earned_xp,
        'grade'       => $grade,
        'breakdown'   => $breakdown,
        'hints'       => $ai_hints,
        'report_id'   => $report_id,
        'message'     => 'Evaluation completed.'
    ]);

} catch (PDOException $e) {
    error_log('[AutoGrade] DB Error: ' . $e->getMessage());
    echo json_encode(['status'=>'error','message'=>'Database error.','debug'=>$e->getMessage()]);
}
?>