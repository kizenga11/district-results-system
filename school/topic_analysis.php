<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
require_role(['headmaster', 'teacher', 'district_admin', 'super_admin']);

$user      = current_user();
$role      = $user['role'];
$school_id = in_array($role, ['headmaster', 'teacher'], true) ? (int)$user['school_id'] : (int)($_GET['school_id'] ?? 0);

// District/super can pick a school
$schools = [];
if (in_array($role, ['district_admin', 'super_admin'], true)) {
    $schools = db()->query('SELECT id, name FROM schools WHERE status="active" ORDER BY name')->fetchAll();
    if (!$school_id && !empty($schools)) {
        $school_id = (int)$schools[0]['id'];
    }
}

// ── Get subjects activated for this school ──────────────────────
$subjects = [];
if ($school_id) {
    // Use school_subjects (district-activated) as primary source
    $subj = db()->prepare(
        'SELECT DISTINCT sub.id, sub.name, sub.code, sub.category
         FROM subjects sub
         JOIN school_subjects ss ON ss.subject_id = sub.id
         WHERE ss.school_id = :sid AND sub.status = "active"
         ORDER BY sub.category, sub.name'
    );
    $subj->execute([':sid' => $school_id]);
    $subjects = $subj->fetchAll();

    // Fallback: if no school_subjects, show subjects via teacher_assignments
    if (empty($subjects)) {
        $subj = db()->prepare(
            'SELECT DISTINCT sub.id, sub.name, sub.code, sub.category
             FROM subjects sub
             JOIN teacher_assignments ta ON ta.subject_id = sub.id
             WHERE ta.school_id = :sid AND sub.status = "active"
             ORDER BY sub.category, sub.name'
        );
        $subj->execute([':sid' => $school_id]);
        $subjects = $subj->fetchAll();
    }
}

$selected_subject_id = (int)($_GET['subject_id'] ?? ($subjects[0]['id'] ?? 0));

// ── Analysis data ───────────────────────────────────────────────
$analysis = [];
$lowest_topic = null;
$lowest_rate = 101;

if ($school_id && $selected_subject_id) {
    // Get all topics for this subject across all assignments at the school
    $rows = db()->prepare(
        'SELECT tpc.id AS topic_id, tpc.title AS topic_title, tpc.sort_order,
                lv.name AS level_name, lv.id AS level_id,
                u.full_name AS teacher_name,
                tt.id AS test_id, tt.attempt_no, tt.status AS test_status,
                tt.pass_rate, tt.approved_at
         FROM teacher_topics tpc
         JOIN teacher_assignments ta ON ta.id = tpc.teacher_assignment_id
         JOIN levels lv ON lv.id = ta.level_id
         JOIN users u ON u.id = ta.teacher_id
         LEFT JOIN topic_tests tt ON tt.teacher_topic_id = tpc.id
             AND tt.status = "approved"
             AND tt.id = (
                 SELECT MAX(tt2.id) FROM topic_tests tt2
                 WHERE tt2.teacher_topic_id = tpc.id AND tt2.status = "approved"
             )
         WHERE ta.school_id = :sid AND ta.subject_id = :subid
         ORDER BY lv.id, u.full_name, tpc.sort_order'
    );
    $rows->execute([':sid' => $school_id, ':subid' => $selected_subject_id]);
    $analysis = $rows->fetchAll();

    // Find lowest average topic across all classes
    foreach ($analysis as $a) {
        if ($a['test_status'] === 'approved' && $a['pass_rate'] !== null && (float)$a['pass_rate'] < $lowest_rate) {
            $lowest_rate = (float)$a['pass_rate'];
            $lowest_topic = $a;
        }
    }
}

render_header('Topic Analysis');
?>
<div class="page-heading"><h4>Topic Analysis<?php if($school_id):?> <?=e(db()->query('SELECT name FROM schools WHERE id='.$school_id)->fetchColumn())?><?php endif;?></h4></div>
<?php if(empty($schools)&&$school_id===0&&in_array($role,['district_admin','super_admin'])):?><div class="alert alert-warning">No active schools found.</div><?php render_footer();return;?><?php endif;?>
<form method="get" class="row g-2 mb-4 align-items-end"><?php if(!empty($schools)):?><div class="col-auto"><select name="school_id" class="form-select form-select-sm" onchange="this.form.submit()"><?php foreach($schools as $s):?><option value="<?=(int)$s['id']?>" <?=(int)$s['id']===$school_id?'selected':''?>><?=e($s['name'])?></option><?php endforeach;?></select></div><?php endif;?><div class="col-auto"><select name="subject_id" class="form-select form-select-sm" onchange="this.form.submit()"><?php foreach($subjects as $sub):?><option value="<?=(int)$sub['id']?>" <?=(int)$sub['id']===$selected_subject_id?'selected':''?>><?=e($sub['name'])?> (<?=e($sub['code'])?>)</option><?php endforeach;?></select></div></form>
<?php if(empty($analysis)):?><div class="text-center text-muted py-5">No topic data found for this subject.</div><?php else:?>
<?php if($lowest_topic):?><div class="alert alert-warning">Lowest Performing Topic: "<?=e($lowest_topic['topic_title'])?>" (Class: <?=e($lowest_topic['level_name'])?>, Teacher: <?=e($lowest_topic['teacher_name'])?>) -- Pass Rate: <?=(float)$lowest_topic['pass_rate']?>%</div><?php endif;?>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0 align-middle small"><thead class="table-light"><tr><th>#</th><th>Topic</th><th>Class</th><th>Teacher</th><th>Attempt</th><th>Pass Rate</th><th>Status</th><th>Approved On</th></tr></thead><tbody><?php foreach($analysis as $a):?><tr<?=$a['test_status']==='approved'&&$a['pass_rate']!==null&&(float)$a['pass_rate']<50?' class="table-danger"':''?>><td><?=(int)$a['sort_order']?></td><td><?=e($a['topic_title'])?></td><td><?=e($a['level_name'])?></td><td><?=e($a['teacher_name'])?></td><td>#<?=(int)$a['attempt_no']?></td><td><?php if($a['test_status']==='approved'):?><?=(float)$a['pass_rate']?>%<?php else:?>--<?php endif;?></td><td><?=$a['test_status']==='approved'?'Completed':'Not Yet'?></td><td><?=$a['approved_at']?e(date('d/m/Y',strtotime($a['approved_at']))):'--'?></td></tr><?php endforeach;?></tbody></table></div></div>
<?php $total=count($analysis);$completed=count(array_filter($analysis,fn($a)=>$a['test_status']==='approved'&&(float)$a['pass_rate']>75));$pct=$total>0?round($completed/$total*100,1):0;?>
<div class="row mt-4"><div class="col-md-4"><div class="card text-center py-2"><div class="fw-bold fs-4"><?=$total?></div><div class="small">Topics</div></div></div><div class="col-md-4"><div class="card text-center py-2"><div class="fw-bold fs-4"><?=$completed?></div><div class="small">Completed (Approved)</div></div></div><div class="col-md-4"><div class="card text-center py-2"><div class="fw-bold fs-4"><?=$pct?>%</div><div class="small">Progress</div></div></div></div>
<?php endif;?>
<?php render_footer();?>
