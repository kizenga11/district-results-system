<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
require_role(['headmaster', 'teacher', 'district_admin', 'super_admin']);

$user  = current_user();
$role  = $user['role'];

// ── Helpers ────────────────────────────────────────────────────

function pct_to_grade(float $pct, string $cat): string
{
    if ($cat === 'o_level') {
        if ($pct >= 75) return 'A';
        if ($pct >= 65) return 'B';
        if ($pct >= 50) return 'C';
        if ($pct >= 30) return 'D';
        return 'F';
    }
    if ($pct >= 80) return 'A';
    if ($pct >= 70) return 'B';
    if ($pct >= 60) return 'C';
    if ($pct >= 50) return 'D';
    if ($pct >= 40) return 'E';
    if ($pct >= 35) return 'S';
    return 'F';
}

function gpa_to_letter(float $gpa, string $cat): string
{
    $r = (int)round($gpa);
    if ($cat === 'o_level') {
        return [1=>'A',2=>'B',3=>'C',4=>'D',5=>'F'][$r] ?? 'F';
    }
    return [1=>'A',2=>'B',3=>'C',4=>'D',5=>'E',6=>'S',7=>'F'][$r] ?? 'F';
}

function calc_student(array $marks, string $cat): array
{
    $all_pcts = array_map(fn($m) => (float)$m['total_percent'], $marks);
    $avg_pct  = count($all_pcts) ? round(array_sum($all_pcts) / count($all_pcts), 1) : 0.0;

    if ($cat === 'o_level') {
        $pts  = array_map(fn($m) => (int)($m['points'] ?? 5), $marks);
        sort($pts);
        $best = array_slice($pts, 0, 7);
        $agg  = array_sum($best);
        $cnt  = count($best);
        $gpa  = $cnt ? round($agg / $cnt, 2) : 0.0;
        $div  = $agg<=17?'I':($agg<=21?'II':($agg<=25?'III':($agg<=33?'IV':'0')));
    } else {
        $prin = array_filter($marks, fn($m) => (int)($m['is_principal']??0)===1);
        if (empty($prin)) {
            return ['div'=>'—','agg'=>0,'gpa'=>0.0,'avg_pct'=>$avg_pct,'grade'=>'—'];
        }
        $pts   = array_map(fn($m) => (int)($m['points']??7), $prin);
        sort($pts);
        $best3 = array_slice($pts, 0, 3);
        $agg   = array_sum($best3);
        $cnt   = count($best3);
        $gpa   = $cnt ? round($agg / $cnt, 2) : 0.0;
        $div   = $agg<=9?'I':($agg<=12?'II':($agg<=15?'III':($agg<=19?'IV':'0')));
    }
    return [
        'div'     => $div,
        'agg'     => $agg,
        'gpa'     => $gpa,
        'avg_pct' => $avg_pct,
        'grade'   => pct_to_grade($avg_pct, $cat),
    ];
}

// ── Resolve school ─────────────────────────────────────────────
$school_id   = null;
$schools     = [];
$teacher_id  = null;

if (in_array($role, ['headmaster', 'teacher'], true)) {
    $school_id = (int)($user['school_id'] ?? 0) ?: null;
    if ($role === 'teacher') $teacher_id = (int)$user['id'];
} else {
    $schools   = db()->query('SELECT id, name FROM schools WHERE status="active" ORDER BY name')->fetchAll();
    $school_id = (int)($_GET['school_id'] ?? 0) ?: null;
}

$exam_id   = (int)($_GET['exam_id']  ?? 0);
$level_id  = (int)($_GET['level_id'] ?? 0);
$view_mode = ($_GET['view'] ?? 'grade') === 'marks' ? 'marks' : 'grade';
$export    = (string)($_GET['export'] ?? '');

// ── Exams for this school (based on subjects, not marks presence) ──
$exams = [];
if ($school_id) {
    if ($teacher_id) {
        // Teacher: exams matching their assigned subject categories
        $stmt = db()->prepare(
            'SELECT DISTINCT e.id, e.name, e.year, e.category
             FROM exams e
             JOIN teacher_assignments ta ON ta.teacher_id = :tid AND ta.school_id = :sid
             JOIN subjects sub ON sub.id = ta.subject_id AND sub.category = e.category
             ORDER BY e.year DESC, e.name'
        );
        $stmt->execute([':tid' => $teacher_id, ':sid' => $school_id]);
    } else {
        // Headmaster: all exams matching this school's activated subject categories
        $stmt = db()->prepare(
            'SELECT DISTINCT e.id, e.name, e.year, e.category
             FROM exams e
             WHERE e.category IN (
                 SELECT DISTINCT sub.category
                 FROM school_subjects ss
                 JOIN subjects sub ON sub.id = ss.subject_id
                 WHERE ss.school_id = :s
             )
             ORDER BY e.year DESC, e.name'
        );
        $stmt->execute([':s' => $school_id]);
    }
    $exams = $stmt->fetchAll();

    // Fallback: no school_subjects configured → use school.level
    if (empty($exams)) {
        $stmt = db()->prepare(
            'SELECT e.id, e.name, e.year, e.category
             FROM exams e
             JOIN schools sc ON sc.id = :s2
             WHERE sc.level = "both" OR sc.level = e.category
             ORDER BY e.year DESC, e.name'
        );
        $stmt->execute([':s2' => $school_id]);
        $exams = $stmt->fetchAll();
    }
}

// ── Selected exam ──────────────────────────────────────────────
$exam = null;
if ($exam_id) {
    $stmt = db()->prepare('SELECT id, name, year, category FROM exams WHERE id = :id');
    $stmt->execute([':id' => $exam_id]);
    $exam = $stmt->fetch() ?: null;
}

// ── Levels (teachers: only levels they have assignments for) ───
$levels = [];
if ($exam && $school_id) {
    if ($teacher_id) {
        $stmt = db()->prepare(
            'SELECT DISTINCT lv.id, lv.name
             FROM students st
             JOIN marks m ON m.student_id = st.id AND m.exam_id = :eid
             JOIN levels lv ON lv.id = st.level_id
             JOIN teacher_assignments ta
               ON ta.level_id = st.level_id AND ta.school_id = :sid AND ta.teacher_id = :tid
             WHERE st.school_id = :sid2 AND st.status = "active"
             ORDER BY lv.id'
        );
        $stmt->execute([':eid'=>$exam_id,':sid'=>$school_id,':tid'=>$teacher_id,':sid2'=>$school_id]);
    } else {
        $stmt = db()->prepare(
            'SELECT DISTINCT lv.id, lv.name
             FROM students st
             JOIN marks m ON m.student_id = st.id AND m.exam_id = :eid
             JOIN levels lv ON lv.id = st.level_id
             WHERE st.school_id = :sid AND st.status = "active"
             ORDER BY lv.id'
        );
        $stmt->execute([':eid' => $exam_id, ':sid' => $school_id]);
    }
    $levels = $stmt->fetchAll();
    if ($levels && !$level_id) $level_id = (int)$levels[0]['id'];
}

// ── Main data computation ──────────────────────────────────────
$all_subjects   = [];
$students_data  = [];
$subj_stats     = [];
$sub_ids_sorted = [];
$subject_rank   = [];
$div_summary    = ['I'=>0,'II'=>0,'III'=>0,'IV'=>0,'0'=>0];
$school_summary = [];
$category       = ($exam['category'] ?? 'o_level');
$is_alevel      = $category === 'a_level';
$level_name     = '';
$school_name    = '';

if ($school_id) {
    $sn = db()->prepare('SELECT name FROM schools WHERE id = :id');
    $sn->execute([':id' => $school_id]);
    $school_name = (string)($sn->fetchColumn() ?: '');
}

if ($exam && $school_id && $level_id) {
    foreach ($levels as $lv) {
        if ((int)$lv['id'] === $level_id) { $level_name = $lv['name']; break; }
    }

    $stmt = db()->prepare(
        'SELECT m.student_id, m.subject_id,
                sub.name AS sname, sub.code AS scode,
                COALESCE(sub.abbr, sub.code) AS sabbr, sub.is_principal,
                m.grade, m.points, m.total_percent,
                st.full_name, st.admission_no, st.sex
         FROM marks m
         JOIN students st  ON st.id  = m.student_id
         JOIN subjects sub ON sub.id = m.subject_id
         WHERE m.exam_id = :eid AND st.school_id = :sid
           AND st.level_id = :lid AND st.status = "active"
         ORDER BY st.full_name, sub.name'
    );
    $stmt->execute([':eid'=>$exam_id,':sid'=>$school_id,':lid'=>$level_id]);

    foreach ($stmt->fetchAll() as $row) {
        $sid    = (int)$row['student_id'];
        $sub_id = (int)$row['subject_id'];
        $pts    = $row['points'] !== null ? (int)$row['points'] : null;

        if (!isset($students_data[$sid])) {
            $students_data[$sid] = [
                'id'  => $sid, 'full_name' => $row['full_name'],
                'admission_no' => $row['admission_no'], 'sex' => $row['sex'] ?? '—',
                'marks' => [],
            ];
        }
        $students_data[$sid]['marks'][$sub_id] = [
            'grade'=>$row['grade'], 'points'=>$pts,
            'total_percent'=>(float)$row['total_percent'],
            'is_principal'=>(int)$row['is_principal'],
            'code'=>$row['scode'],
            'abbr'=>$row['sabbr'],
        ];

        if (!isset($all_subjects[$sub_id])) {
            $all_subjects[$sub_id] = ['code'=>$row['scode'],'abbr'=>$row['sabbr'],'name'=>$row['sname'],'is_principal'=>(int)$row['is_principal']];
            $subj_stats[$sub_id]   = ['counts'=>[],'pts_sum'=>0,'pct_sum'=>0.0,'total'=>0,'pass'=>0];
        }
        $g = $row['grade'];
        $subj_stats[$sub_id]['counts'][$g] = ($subj_stats[$sub_id]['counts'][$g] ?? 0) + 1;
        $subj_stats[$sub_id]['pts_sum'] += $pts ?? ($is_alevel ? 7 : 5);
        $subj_stats[$sub_id]['pct_sum'] += (float)$row['total_percent'];
        $subj_stats[$sub_id]['total']++;
        // pass: O-Level pts 1-4, A-Level pts 1-6
        if ($pts !== null && $pts <= ($is_alevel ? 6 : 4)) {
            $subj_stats[$sub_id]['pass']++;
        }
    }

    // Sort subjects: principals first, then alpha
    uasort($all_subjects, fn($a,$b) =>
        $b['is_principal']!==$a['is_principal']
            ? $b['is_principal']-$a['is_principal']
            : strcmp($a['name'],$b['name'])
    );

    // Per-student: calc division, build subject summary
    foreach ($students_data as &$st) {
        $res = calc_student(array_values($st['marks']), $category);
        foreach ($res as $k => $v) $st[$k] = $v;
        if (isset($div_summary[$res['div']])) $div_summary[$res['div']]++;

        // Subject summary (ordered by all_subjects order)
        $parts = [];
        foreach ($all_subjects as $sub_id => $sub) {
            $m = $st['marks'][$sub_id] ?? null;
            if ($m) {
                $lbl = $sub['abbr'] ?? $sub['code'];
                $parts[] = $view_mode === 'marks'
                    ? $lbl.':'.number_format($m['total_percent'],0)
                    : $lbl.'-'.$m['grade'];
            }
        }
        $st['subject_summary'] = implode('  ', $parts);
    }
    unset($st);

    // Assign positions (rank by agg asc, then avg_pct desc for ties)
    $rank_list = $students_data;
    uasort($rank_list, fn($a,$b) =>
        $a['agg'] !== $b['agg'] ? $a['agg']-$b['agg'] : $b['avg_pct']<=>$a['avg_pct']
    );
    $pos=1; $prev_agg=null; $prev_avg=null; $prev_pos=1;
    foreach ($rank_list as $sid => $st) {
        if ($prev_agg===$st['agg'] && abs(($prev_avg??0)-$st['avg_pct'])<0.01) {
            $students_data[$sid]['position'] = $prev_pos;
        } else {
            $students_data[$sid]['position'] = $pos;
            $prev_pos = $pos;
        }
        $prev_agg = $st['agg']; $prev_avg = $st['avg_pct']; $pos++;
    }

    // Sort for display: position asc, name asc
    uasort($students_data, fn($a,$b) =>
        $a['position']!==$b['position'] ? $a['position']-$b['position'] : strcmp($a['full_name'],$b['full_name'])
    );

    // Subject stats finalize
    foreach ($subj_stats as &$ss) {
        $ss['gpa']       = $ss['total'] ? round($ss['pts_sum']/$ss['total'],2) : 0.0;
        $ss['avg_pct']   = $ss['total'] ? round($ss['pct_sum']/$ss['total'],1) : 0.0;
        $ss['pass_rate'] = $ss['total'] ? round($ss['pass']/$ss['total']*100,1) : 0.0;
        $ss['grade']     = gpa_to_letter($ss['gpa'], $category);
    }
    unset($ss);

    // Rank subjects by GPA asc (lower GPA = better performance)
    $sub_ids_sorted = array_keys($subj_stats);
    usort($sub_ids_sorted, fn($a,$b) => $subj_stats[$a]['gpa']<=>$subj_stats[$b]['gpa']);
    foreach ($sub_ids_sorted as $rank => $sub_id) {
        $subject_rank[$sub_id] = $rank + 1;
    }

    // School summary
    $n         = count($students_data);
    $gpas      = array_column($students_data,'gpa');
    $avgs      = array_column($students_data,'avg_pct');
    $school_gpa   = $n ? round(array_sum($gpas)/$n,2) : 0.0;
    $school_avg   = $n ? round(array_sum($avgs)/$n,1) : 0.0;
    $school_grade = gpa_to_letter($school_gpa, $category);
    $school_summary = [
        'total'=>$n,'gpa'=>$school_gpa,'avg_pct'=>$school_avg,'grade'=>$school_grade,
    ];

    $div_f = ['I'=>0,'II'=>0,'III'=>0,'IV'=>0,'0'=>0];
    $div_m = ['I'=>0,'II'=>0,'III'=>0,'IV'=>0,'0'=>0];
    foreach ($students_data as $st) {
        $d = $st['div'];
        if (isset($div_f[$d])) {
            strtoupper($st['sex']??'') === 'F' ? $div_f[$d]++ : $div_m[$d]++;
        }
    }
}

// ── CSV / Excel export ─────────────────────────────────────────
if ($export === 'excel' && !empty($students_data)) {
    $fname = 'analysis_'.preg_replace('/[^a-z0-9]/i','_',$exam['name']??'exam').'_'.$level_name.'.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$fname.'"');
    header('Pragma: no-cache');
    $out = fopen('php://output','w');
    fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

    fputcsv($out, [$school_name, $exam['name'].' '.$exam['year'], $level_name,
                   'School GPA: '.$school_summary['gpa'],
                   'School Avg: '.$school_summary['avg_pct'].'%',
                   'School Grade: '.$school_summary['grade']]);
    fputcsv($out, []);

    // Division summary
    fputcsv($out, ['DIVISION SUMMARY']);
    fputcsv($out, ['Division','Count','%']);
    $n = $school_summary['total'];
    foreach ($div_summary as $d => $cnt) {
        fputcsv($out, ['Div '.$d, $cnt, $n?round($cnt/$n*100,1).'%':'0%']);
    }
    fputcsv($out, []);

    // Subject analysis
    fputcsv($out, ['SUBJECT ANALYSIS']);
    $hdr = ['Rank','Subject','Students','A','B','C','D'];
    if ($is_alevel) $hdr = array_merge($hdr,['E','S']);
    $hdr = array_merge($hdr,['F','GPA','Avg %','Pass %','Grade']);
    fputcsv($out, $hdr);
    foreach ($sub_ids_sorted as $sub_id) {
        $sub = $all_subjects[$sub_id]; $ss = $subj_stats[$sub_id];
        $row = [$subject_rank[$sub_id],$sub['name'],$ss['total'],
                $ss['counts']['A']??0,$ss['counts']['B']??0,$ss['counts']['C']??0,$ss['counts']['D']??0];
        if ($is_alevel) $row = array_merge($row,[$ss['counts']['E']??0,$ss['counts']['S']??0]);
        $row = array_merge($row,[$ss['counts']['F']??0,$ss['gpa'],$ss['avg_pct'],$ss['pass_rate'].'%',$ss['grade']]);
        fputcsv($out, $row);
    }
    fputcsv($out, []);

    // Student list
    fputcsv($out, ['STUDENT RESULTS']);
    fputcsv($out, ['#','Reg No','Name','Sex','Division','Points','Avg %','Grade','Position','Subjects']);
    $i=1;
    foreach ($students_data as $st) {
        fputcsv($out, [$i++,$st['admission_no'],$st['full_name'],$st['sex'],
                       $st['div'],$st['agg'],$st['avg_pct'].'%',$st['grade'],
                       $st['position'],$st['subject_summary']]);
    }
    fclose($out);
    exit;
}

// ── Grades to show in subject table ───────────────────────────
$grade_cols = $is_alevel ? ['A','B','C','D','E','S','F'] : ['A','B','C','D','F'];

render_header('Exam Analysis');
?>

<div class="page-heading d-print-none"><h4>Exam Analysis</h4></div>
<div class="card mb-3 d-print-none"><div class="card-body py-2"><form method="get" class="row g-2 align-items-end" id="filterForm">
<?php if ($role !== 'headmaster'): ?>
<div class="col-12 col-md-3"><label class="form-label small fw-semibold mb-1">School</label><select name="school_id" class="form-select form-select-sm" id="selSchool"><option value="">-- Select school --</option>
<?php foreach ($schools as $sc): ?><option value="<?=(int)$sc['id']?>" <?=$school_id==$sc['id']?'selected':''?>><?=e($sc['name'])?></option><?php endforeach; ?></select></div>
<?php endif; ?>
<div class="col-12 col-md-3"><label class="form-label small fw-semibold mb-1">Exam</label><select name="exam_id" class="form-select form-select-sm" id="selExam" <?=!$exams?'disabled':''?>><option value="">-- Select exam --</option>
<?php foreach ($exams as $ex): ?><option value="<?=(int)$ex['id']?>" <?=$exam_id==$ex['id']?'selected':''?>><?=e($ex['name'])?> (<?=(int)$ex['year']?>) -- <?=$ex['category']==='o_level'?'O-Level':'A-Level'?></option><?php endforeach; ?></select></div>
<?php if ($levels): ?>
<div class="col-12 col-md-2"><label class="form-label small fw-semibold mb-1">Class</label><select name="level_id" class="form-select form-select-sm" id="selLevel"><?php foreach ($levels as $lv): ?><option value="<?=(int)$lv['id']?>" <?=$level_id==$lv['id']?'selected':''?>><?=e($lv['name'])?></option><?php endforeach; ?></select></div>
<?php endif; ?>
<?php if (!empty($students_data)): ?>
<div class="col-12 col-md-2"><label class="form-label small fw-semibold mb-1">Show as</label><select name="view" class="form-select form-select-sm" id="selView"><option value="grade" <?=$view_mode==='grade'?'selected':''?>>Grades (A,B,C...)</option><option value="marks" <?=$view_mode==='marks'?'selected':''?>>Marks (%)</option></select></div>
<?php endif; ?>
<div class="col-auto"><button type="submit" class="btn btn-primary btn-sm px-3">View</button></div>
</form></div></div>

<?php if (!empty($students_data)): ?>
<?php $export_params = array_filter(['school_id'=>$school_id,'exam_id'=>$exam_id,'level_id'=>$level_id,'view'=>$view_mode,'export'=>'excel']);
$export_url = url('school/exam_analysis.php?'.http_build_query($export_params)); ?>
<div class="d-none d-print-block mb-3"><h5 class="mb-0"><?=e($school_name)?></h5><div class="small"><?=e($exam['name'])?> <?=(int)$exam['year']?> -- <?=$is_alevel?'A-Level':'O-Level'?> -- <?=e($level_name)?></div></div>
<div class="d-flex gap-2 mb-3 d-print-none"><button onclick="window.print()" class="btn btn-outline-secondary btn-sm">Print</button><a href="<?=e($export_url)?>" class="btn btn-outline-success btn-sm">Export Excel</a></div>
<table class="rpt-tbl mb-2">
  <thead>
    <tr><th>SEX</th><th>I</th><th>II</th><th>III</th><th>IV</th><th>0</th></tr>
  </thead>
  <tbody>
    <tr>
      <td class="sex-cell">F</td>
      <td class="tc"><?=$div_f['I']?></td>
      <td class="tc"><?=$div_f['II']?></td>
      <td class="tc"><?=$div_f['III']?></td>
      <td class="tc<?=$div_f['IV']>0?' rpt-iv':''?>"><?=$div_f['IV']?></td>
      <td class="tc<?=$div_f['0']>0?' rpt-z':''?>"><?=$div_f['0']?></td>
    </tr>
    <tr>
      <td class="sex-cell">M</td>
      <td class="tc"><?=$div_m['I']?></td>
      <td class="tc"><?=$div_m['II']?></td>
      <td class="tc"><?=$div_m['III']?></td>
      <td class="tc<?=$div_m['IV']>0?' rpt-iv':''?>"><?=$div_m['IV']?></td>
      <td class="tc<?=$div_m['0']>0?' rpt-z':''?>"><?=$div_m['0']?></td>
    </tr>
    <tr>
      <td class="sex-cell">T</td>
      <td class="tc"><?=$div_summary['I']?></td>
      <td class="tc"><?=$div_summary['II']?></td>
      <td class="tc"><?=$div_summary['III']?></td>
      <td class="tc<?=$div_summary['IV']>0?' rpt-iv':''?>"><?=$div_summary['IV']?></td>
      <td class="tc<?=$div_summary['0']>0?' rpt-z':''?>"><?=$div_summary['0']?></td>
    </tr>
  </tbody>
</table>
<table class="rpt-tbl mb-3">
  <thead>
    <tr><th>Wanafunzi</th><th>GPA</th><th>Wastani%</th><th>Daraja</th></tr>
  </thead>
  <tbody>
    <tr>
      <td class="tc"><?=$school_summary['total']?></td>
      <td class="tc"><?=$school_summary['gpa']?></td>
      <td class="tc"><?=$school_summary['avg_pct']?>%</td>
      <td class="tc"><?=$school_summary['grade']?></td>
    </tr>
  </tbody>
</table>

<div class="table-responsive mb-3">
<table class="rpt-tbl" id="subjectTable" style="width:100%">
  <thead>
    <tr>
      <th>#</th>
      <th style="text-align:left">Somo<?=$is_alevel?' (P=principal)':''?></th>
      <th>Wanaf.</th>
      <?php foreach ($grade_cols as $gc): ?><th><?=$gc?></th><?php endforeach; ?>
      <th>GPA</th><th>Wastani%</th><th>Pass%</th><th>Daraja</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($sub_ids_sorted as $sub_id):
      $sub=$all_subjects[$sub_id]; $ss=$subj_stats[$sub_id]; $rank=$subject_rank[$sub_id];
    ?>
    <tr>
      <td class="tc"><?=$rank?></td>
      <td><?=e($sub['name'])?><?php if ($is_alevel&&$sub['is_principal']):?> <sup>P</sup><?php endif;?></td>
      <td class="tc"><?=$ss['total']?></td>
      <?php foreach ($grade_cols as $gc):?>
      <td class="tc"><?=$ss['counts'][$gc]??0?></td>
      <?php endforeach;?>
      <td class="tc"><?=$ss['gpa']?></td>
      <td class="tc"><?=$ss['avg_pct']?>%</td>
      <td class="tc"><?=$ss['pass_rate']?>%</td>
      <td class="tc"><?=$ss['grade']?></td>
    </tr>
    <?php endforeach;?>
  </tbody>
</table>
</div>
<div class="table-responsive">
<table class="rpt-tbl rpt-stbl" id="studentsTable">
  <thead>
    <tr>
      <th>#</th>
      <th>CNO</th>
      <th>Jina</th>
      <th>SEX</th>
      <th>DIV</th>
      <th>AGGT</th>
      <th>Wastani%</th>
      <th>Daraja</th>
      <th>Nafasi</th>
      <th>MASOMO</th>
    </tr>
  </thead>
  <tbody>
    <?php $i=1; foreach($students_data as $st): ?>
    <tr>
      <td class="tc rpt-pos"><?=$i++?></td>
      <td class="rpt-cno"><?=e($st['admission_no'])?></td>
      <td style="white-space:nowrap"><?=e($st['full_name'])?></td>
      <td class="tc"><?=e($st['sex'])?></td>
      <td class="tc rpt-div"><?=e($st['div'])?></td>
      <td class="tc rpt-agg<?=in_array($st['div'],['IV','0'])?' rpt-iv':''?>"><?=$st['agg']?:($st['div']==='—'?'—':$st['agg'])?></td>
      <td class="tc"><?=$st['avg_pct']?>%</td>
      <td class="tc"><?=$st['grade']?></td>
      <td class="tc"><?=$st['position']?></td>
      <td class="rpt-subjects"><?=e($st['subject_summary'])?></td>
    </tr>
    <?php endforeach;?>
  </tbody>
</table>
</div>
<p class="small mt-2 d-print-none"><?php if($is_alevel):?>P=Principal subject / Division: best 3 principals / GPA: 1(A)-7(F)<?php else:?>Division: best 7 subjects / GPA: 1(A)-5(F) / Pass=A-D<?php endif;?></p>

<?php elseif($exam&&$school_id&&$level_id):?><div class="alert alert-info">Hakuna alama zilizowekwa kwa mtihani na darasa hili.</div><?php elseif($school_id):?><div class="alert alert-light text-muted">Chagua mtihani kuona analysis.</div><?php elseif($role!=='headmaster'):?><div class="alert alert-light text-muted">Chagua shule ili uanze.</div><?php else:?><div class="alert alert-light text-muted">Hakuna data ya alama bado.</div><?php endif;?>
<style>
@media print {
  /* Filter card hidden; export/print buttons hidden via .d-print-none */
  .card.mb-3 { display: none !important; }
}
</style>
<script>(()=>{const f=document.getElementById('filterForm');const selSchool=document.getElementById('selSchool');const selExam=document.getElementById('selExam');const selLevel=document.getElementById('selLevel');const selView=document.getElementById('selView');selSchool?.addEventListener('change',()=>{if(selExam)selExam.value='';if(selLevel)selLevel.value='';f.submit()});selExam?.addEventListener('change',()=>{if(selLevel)selLevel.value='';f.submit()});selLevel?.addEventListener('change',()=>f.submit());selView?.addEventListener('change',()=>f.submit())})();</script>
<?php render_footer();?>
