<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
require_role(['super_admin', 'district_admin']);

$school_id = (int)($_GET['school_id'] ?? 0);

// ── All active schools ──────────────────────────────────────────
$schools = db()->query('SELECT id, name FROM schools WHERE status="active" ORDER BY name')->fetchAll();

// ── District-wide stats per subject ─────────────────────────────
$district_subject_stats = db()->query(
    'SELECT sub.id, sub.name, sub.code, sub.category,
            COUNT(tpc.id) AS total_topics,
            SUM(CASE WHEN tt.status = "approved" AND tt.pass_rate > 75 THEN 1 ELSE 0 END) AS completed_topics
     FROM subjects sub
     JOIN teacher_assignments ta ON ta.subject_id = sub.id
     JOIN teacher_topics tpc ON tpc.teacher_assignment_id = ta.id
     LEFT JOIN topic_tests tt ON tt.teacher_topic_id = tpc.id
         AND tt.status = "approved"
         AND tt.id = (
             SELECT MAX(tt2.id) FROM topic_tests tt2
             WHERE tt2.teacher_topic_id = tpc.id AND tt2.status = "approved"
         )
     GROUP BY sub.id, sub.name, sub.code, sub.category
     ORDER BY sub.category, sub.name'
)->fetchAll();

// ── Per-school per-subject stats ─────────────────────────────────
$school_subject_stats = [];
if ($school_id) {
    $sss = db()->prepare(
        'SELECT sub.id, sub.name, sub.code, sub.category,
                COUNT(tpc.id) AS total_topics,
                SUM(CASE WHEN tt.status = "approved" AND tt.pass_rate > 75 THEN 1 ELSE 0 END) AS completed_topics,
                sc.name AS school_name
         FROM schools sc
         JOIN teacher_assignments ta ON ta.school_id = sc.id
         JOIN subjects sub ON sub.id = ta.subject_id
         JOIN teacher_topics tpc ON tpc.teacher_assignment_id = ta.id
         LEFT JOIN topic_tests tt ON tt.teacher_topic_id = tpc.id
             AND tt.status = "approved"
             AND tt.id = (
                 SELECT MAX(tt2.id) FROM topic_tests tt2
                 WHERE tt2.teacher_topic_id = tpc.id AND tt2.status = "approved"
             )
         WHERE sc.id = :sid
         GROUP BY sub.id, sub.name, sub.code, sub.category, sc.name
         ORDER BY sub.category, sub.name'
    );
    $sss->execute([':sid' => $school_id]);
    $school_subject_stats = $sss->fetchAll();
}

// ── All schools summary (for the table below) ────────────────────
$all_schools_summary = db()->query(
    'SELECT sc.id AS school_id, sc.name AS school_name,
            COUNT(tpc.id) AS total_topics,
            SUM(CASE WHEN tt.status = "approved" AND tt.pass_rate > 75 THEN 1 ELSE 0 END) AS completed_topics
     FROM schools sc
     JOIN teacher_assignments ta ON ta.school_id = sc.id
     JOIN teacher_topics tpc ON tpc.teacher_assignment_id = ta.id
     LEFT JOIN topic_tests tt ON tt.teacher_topic_id = tpc.id
         AND tt.status = "approved"
         AND tt.id = (
             SELECT MAX(tt2.id) FROM topic_tests tt2
             WHERE tt2.teacher_topic_id = tpc.id AND tt2.status = "approved"
         )
     WHERE sc.status = "active"
     GROUP BY sc.id, sc.name
     ORDER BY sc.name'
)->fetchAll();

render_header('Teaching Progress Report');
?>
<div class="page-heading"><h4>District Teaching Progress Report</h4></div>
<div class="card mb-4"><div class="card-header"><strong>District-Wide Progress by Subject</strong></div><div class="table-responsive"><table class="table table-hover mb-0 align-middle small"><thead class="table-light"><tr><th>Subject</th><th>Code</th><th>Level</th><th>Total Topics</th><th>Completed</th><th>Progress</th></tr></thead><tbody><?php foreach($district_subject_stats as $ds):$pct=$ds['total_topics']>0?round((int)$ds['completed_topics']/(int)$ds['total_topics']*100,1):0;?><tr><td><?=e($ds['name'])?></td><td><?=e($ds['code'])?></td><td><?=$ds['category']==='o_level'?'O-Level':'A-Level'?></td><td><?=(int)$ds['total_topics']?></td><td><?=(int)$ds['completed_topics']?></td><td><?=$pct?>%</td></tr><?php endforeach;?></tbody></table></div></div>
<div class="card mb-4"><div class="card-header d-flex justify-content-between align-items-center"><strong>Progress by School</strong><div><select class="form-select form-select-sm" onchange="if(this.value)window.location='?school_id='+this.value" style="width:auto;display:inline-block"><option value="">-- All Schools --</option><?php foreach($schools as $s):?><option value="<?=(int)$s['id']?>" <?=(int)$s['id']===$school_id?'selected':''?>><?=e($s['name'])?></option><?php endforeach;?></select></div></div><div class="table-responsive"><table class="table table-hover mb-0 align-middle small"><thead class="table-light"><tr><th>School</th><th>Total Topics</th><th>Completed</th><th>Progress</th><th></th></tr></thead><tbody><?php foreach($all_schools_summary as $as):$pct=$as['total_topics']>0?round((int)$as['completed_topics']/(int)$as['total_topics']*100,1):0;?><tr><td><?=e($as['school_name'])?></td><td><?=(int)$as['total_topics']?></td><td><?=(int)$as['completed_topics']?></td><td><?=$pct?>%</td><td><a href="?school_id=<?=(int)$as['school_id']?>" class="btn btn-sm btn-outline-primary">View Subjects</a></td></tr><?php endforeach;?></tbody></table></div></div>
<?php if($school_id&&!empty($school_subject_stats)):?><div class="card"><div class="card-header"><strong><?=e($school_subject_stats[0]['school_name'])?> -- Subject Breakdown</strong></div><div class="table-responsive"><table class="table table-hover mb-0 align-middle small"><thead class="table-light"><tr><th>Subject</th><th>Code</th><th>Level</th><th>Total Topics</th><th>Completed</th><th>Progress</th></tr></thead><tbody><?php foreach($school_subject_stats as $ss):$pct=$ss['total_topics']>0?round((int)$ss['completed_topics']/(int)$ss['total_topics']*100,1):0;?><tr><td><?=e($ss['name'])?></td><td><?=e($ss['code'])?></td><td><?=$ss['category']==='o_level'?'O-Level':'A-Level'?></td><td><?=(int)$ss['total_topics']?></td><td><?=(int)$ss['completed_topics']?></td><td><?=$pct?>%</td></tr><?php endforeach;?></tbody></table></div></div><?php elseif($school_id):?><div class="alert alert-info">No topic data for this school.</div><?php endif;?>
<?php render_footer();?>
