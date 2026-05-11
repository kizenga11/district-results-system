<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
require_role(['district_admin', 'super_admin']);

// ── Helpers (da_ prefix avoids collisions if both pages are ever included) ──

function da_pct_to_grade(float $pct, string $cat): string
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

function da_calc_student(array $marks, string $cat): array
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
        'grade'   => da_pct_to_grade($avg_pct, $cat),
    ];
}

function da_gpa_to_letter(float $gpa, string $cat): string
{
    $r = (int)round($gpa);
    if ($cat === 'o_level') {
        return [1=>'A',2=>'B',3=>'C',4=>'D',5=>'F'][$r] ?? 'F';
    }
    return [1=>'A',2=>'B',3=>'C',4=>'D',5=>'E',6=>'S',7=>'F'][$r] ?? 'F';
}

function da_grade_badge(string $g): string { return $g; }
function da_div_badge(string $d): string { return $d==='—'?'—':'Div '.$d; }
function da_xml_cell($val, string $type = 'String'): string
{
    $v = htmlspecialchars((string)$val, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    return "<Cell><Data ss:Type=\"{$type}\">{$v}</Data></Cell>";
}

function da_xml_row(array $cells): string
{
    return '<Row>' . implode('', $cells) . '</Row>' . "\n";
}

// ── Filters ────────────────────────────────────────────────────
$exam_id  = (int)($_GET['exam_id']  ?? 0);
$level_id = (int)($_GET['level_id'] ?? 0);
$export   = (string)($_GET['export'] ?? '');

// All exams in the system (not filtered by marks — new exams should be visible too)
$exams = db()->query(
    'SELECT id, name, year, category FROM exams ORDER BY year DESC, name'
)->fetchAll();

$exam = null;
if ($exam_id) {
    $stmt = db()->prepare('SELECT id, name, year, category FROM exams WHERE id = :id');
    $stmt->execute([':id' => $exam_id]);
    $exam = $stmt->fetch() ?: null;
}

$category   = (string)($exam['category'] ?? 'o_level');
$is_alevel  = $category === 'a_level';
$level_name = '';

// Levels that have marks for this exam (district-wide)
$levels = [];
if ($exam) {
    $stmt = db()->prepare(
        'SELECT DISTINCT lv.id, lv.name
         FROM students st
         JOIN marks m ON m.student_id = st.id AND m.exam_id = :eid
         JOIN levels lv ON lv.id = st.level_id
         WHERE st.status = "active"
         ORDER BY lv.id'
    );
    $stmt->execute([':eid' => $exam_id]);
    $levels = $stmt->fetchAll();
    if ($levels && !$level_id) $level_id = (int)$levels[0]['id'];
    foreach ($levels as $lv) {
        if ((int)$lv['id'] === $level_id) { $level_name = $lv['name']; break; }
    }
}

// All active schools index
$all_schools_list = db()->query('SELECT id, name FROM schools WHERE status="active" ORDER BY name')->fetchAll();
$schools_by_id    = [];
foreach ($all_schools_list as $sc) $schools_by_id[(int)$sc['id']] = $sc['name'];

// ── Main data aggregation ──────────────────────────────────────
$all_students    = [];
$schools_data    = [];
$dist_subjects   = [];
$dist_subj_stats = [];
$dist_div_gen    = [
    'I' =>['M'=>0,'F'=>0], 'II' =>['M'=>0,'F'=>0],
    'III'=>['M'=>0,'F'=>0], 'IV' =>['M'=>0,'F'=>0], '0'=>['M'=>0,'F'=>0],
];
$dist_div_totals = ['I'=>0,'II'=>0,'III'=>0,'IV'=>0,'0'=>0];
$enrolled_total  = 0;
$sat_total       = 0;
$top10           = [];
$bottom10        = [];
$sub_ids_sorted  = [];
$subject_rank    = [];
$dist_total      = 0;
$dist_gpa        = 0.0;
$dist_avg        = 0.0;
$dist_grade      = '—';
$dist_pass_rate  = 0.0;

if ($exam && $level_id) {

    // District enrollment for this level
    $stmt = db()->prepare('SELECT COUNT(*) FROM students WHERE level_id = :lid AND status = "active"');
    $stmt->execute([':lid' => $level_id]);
    $enrolled_total = (int)$stmt->fetchColumn();

    // Per-school enrollment
    $stmt = db()->prepare(
        'SELECT school_id, COUNT(*) AS cnt FROM students
         WHERE level_id = :lid AND status = "active" GROUP BY school_id'
    );
    $stmt->execute([':lid' => $level_id]);
    $school_enrolled = [];
    foreach ($stmt->fetchAll() as $row) {
        $school_enrolled[(int)$row['school_id']] = (int)$row['cnt'];
    }

    // All marks: exam × level × all schools
    $stmt = db()->prepare(
        'SELECT m.student_id, m.subject_id,
                sub.name AS sname, sub.code AS scode,
                COALESCE(sub.abbr, sub.code) AS sabbr, sub.is_principal,
                m.grade, m.points, m.total_percent,
                st.full_name, st.admission_no, st.sex, st.school_id
         FROM marks m
         JOIN students st  ON st.id  = m.student_id
         JOIN subjects sub ON sub.id = m.subject_id
         WHERE m.exam_id = :eid AND st.level_id = :lid AND st.status = "active"
         ORDER BY st.school_id, st.full_name, sub.name'
    );
    $stmt->execute([':eid' => $exam_id, ':lid' => $level_id]);

    foreach ($stmt->fetchAll() as $row) {
        $sid    = (int)$row['student_id'];
        $sub_id = (int)$row['subject_id'];
        $pts    = $row['points'] !== null ? (int)$row['points'] : null;
        $sch_id = (int)$row['school_id'];

        if (!isset($all_students[$sid])) {
            $all_students[$sid] = [
                'id'           => $sid,
                'full_name'    => $row['full_name'],
                'admission_no' => $row['admission_no'],
                'sex'          => $row['sex'] ?? '—',
                'school_id'    => $sch_id,
                'school_name'  => $schools_by_id[$sch_id] ?? '—',
                'marks'        => [],
            ];
        }
        $all_students[$sid]['marks'][$sub_id] = [
            'grade'         => $row['grade'],
            'points'        => $pts,
            'total_percent' => (float)$row['total_percent'],
            'is_principal'  => (int)$row['is_principal'],
            'code'          => $row['scode'],
            'abbr'          => $row['sabbr'],
        ];

        if (!isset($dist_subjects[$sub_id])) {
            $dist_subjects[$sub_id]   = ['code'=>$row['scode'],'abbr'=>$row['sabbr'],'name'=>$row['sname'],'is_principal'=>(int)$row['is_principal']];
            $dist_subj_stats[$sub_id] = ['counts'=>[],'pts_sum'=>0,'pct_sum'=>0.0,'total'=>0,'pass'=>0];
        }
        $g = $row['grade'];
        $dist_subj_stats[$sub_id]['counts'][$g] = ($dist_subj_stats[$sub_id]['counts'][$g] ?? 0) + 1;
        $dist_subj_stats[$sub_id]['pts_sum'] += $pts ?? ($is_alevel ? 7 : 5);
        $dist_subj_stats[$sub_id]['pct_sum'] += (float)$row['total_percent'];
        $dist_subj_stats[$sub_id]['total']++;
        if ($pts !== null && $pts <= ($is_alevel ? 6 : 4)) {
            $dist_subj_stats[$sub_id]['pass']++;
        }
    }

    // Per-student: calc division, accumulate into school buckets
    foreach ($all_students as &$st) {
        $res    = da_calc_student(array_values($st['marks']), $category);
        foreach ($res as $k => $v) $st[$k] = $v;

        $div = $res['div'];
        $sex = ($st['sex'] === 'M') ? 'M' : 'F';
        if (isset($dist_div_gen[$div])) {
            $dist_div_gen[$div][$sex]++;
            $dist_div_totals[$div]++;
        }

        $sch_id = $st['school_id'];
        if (!isset($schools_data[$sch_id])) {
            $schools_data[$sch_id] = [
                'name'     => $schools_by_id[$sch_id] ?? '—',
                'enrolled' => $school_enrolled[$sch_id] ?? 0,
                'sat'      => 0,
                'div'      => ['I'=>0,'II'=>0,'III'=>0,'IV'=>0,'0'=>0],
                'gpas'     => [],
                'avgs'     => [],
            ];
        }
        $schools_data[$sch_id]['sat']++;
        if (isset($schools_data[$sch_id]['div'][$div])) $schools_data[$sch_id]['div'][$div]++;
        $schools_data[$sch_id]['gpas'][] = $st['gpa'];
        $schools_data[$sch_id]['avgs'][] = $st['avg_pct'];
    }
    unset($st);

    $sat_total = count($all_students);

    // Finalize per-school stats
    foreach ($schools_data as &$sd) {
        $n              = count($sd['gpas']);
        $sd['gpa']      = $n ? round(array_sum($sd['gpas']) / $n, 2) : 0.0;
        $sd['avg_pct']  = $n ? round(array_sum($sd['avgs']) / $n, 1) : 0.0;
        $sd['grade']    = da_gpa_to_letter($sd['gpa'], $category);
        $pass_cnt       = $sd['div']['I'] + $sd['div']['II'] + $sd['div']['III'] + $sd['div']['IV'];
        $sd['pass_rate']= $sd['sat'] ? round($pass_cnt / $sd['sat'] * 100, 1) : 0.0;
        unset($sd['gpas'], $sd['avgs']);
    }
    unset($sd);

    // Rank schools by GPA asc (lower = better)
    uasort($schools_data, fn($a, $b) => $a['gpa'] <=> $b['gpa']);
    $rank = 1;
    foreach ($schools_data as &$sd) { $sd['rank'] = $rank++; }
    unset($sd);

    // Finalize subject stats
    foreach ($dist_subj_stats as &$ss) {
        $ss['gpa']       = $ss['total'] ? round($ss['pts_sum'] / $ss['total'], 2) : 0.0;
        $ss['avg_pct']   = $ss['total'] ? round($ss['pct_sum'] / $ss['total'], 1) : 0.0;
        $ss['pass_rate'] = $ss['total'] ? round($ss['pass'] / $ss['total'] * 100, 1) : 0.0;
        $ss['grade']     = da_gpa_to_letter($ss['gpa'], $category);
    }
    unset($ss);

    // Sort subjects for column display: principals first, then alphabetical
    uasort($dist_subjects, fn($a,$b) =>
        $b['is_principal'] !== $a['is_principal']
            ? $b['is_principal'] - $a['is_principal']
            : strcmp($a['name'], $b['name'])
    );

    // Rank subjects by GPA asc
    $sub_ids_sorted = array_keys($dist_subj_stats);
    usort($sub_ids_sorted, fn($a, $b) => $dist_subj_stats[$a]['gpa'] <=> $dist_subj_stats[$b]['gpa']);
    foreach ($sub_ids_sorted as $r => $sub_id) {
        $subject_rank[$sub_id] = $r + 1;
    }

    // District position ranking — exclude students with no valid division
    $valid_students = array_filter($all_students, fn($st) => $st['div'] !== '—');
    uasort($valid_students, fn($a, $b) =>
        $a['agg'] !== $b['agg'] ? $a['agg'] - $b['agg'] : $b['avg_pct'] <=> $a['avg_pct']
    );
    $pos = 1; $prev_agg = null; $prev_avg = null; $prev_pos = 1;
    foreach ($valid_students as $sid => $st) {
        if ($prev_agg === $st['agg'] && abs(($prev_avg??0) - $st['avg_pct']) < 0.01) {
            $all_students[$sid]['dist_pos'] = $prev_pos;
        } else {
            $all_students[$sid]['dist_pos'] = $pos;
            $prev_pos = $pos;
        }
        $prev_agg = $st['agg']; $prev_avg = $st['avg_pct']; $pos++;
    }
    foreach ($valid_students as $sid => &$st) {
        $st['dist_pos'] = $all_students[$sid]['dist_pos'] ?? '—';
    }
    unset($st);

    $top10    = array_slice($valid_students, 0, 10, true);
    $bottom10 = array_slice(array_reverse($valid_students, true), 0, 10, true);

    // District-level aggregates
    $dist_total     = count($all_students);
    $dist_gpa       = $dist_total ? round(array_sum(array_column($all_students,'gpa')) / $dist_total, 2) : 0.0;
    $dist_avg       = $dist_total ? round(array_sum(array_column($all_students,'avg_pct')) / $dist_total, 1) : 0.0;
    $dist_grade     = da_gpa_to_letter($dist_gpa, $category);
    $pass_cnt       = $dist_div_totals['I'] + $dist_div_totals['II'] + $dist_div_totals['III'] + $dist_div_totals['IV'];
    $dist_pass_rate = $sat_total ? round($pass_cnt / $sat_total * 100, 1) : 0.0;
}

// ── SpreadsheetML multi-sheet Excel export ─────────────────────
if ($export === 'excel' && !empty($all_students)) {
    $fname = 'district_analysis_'.preg_replace('/[^a-z0-9]/i','_',$exam['name']??'exam').'_'.$level_name.'.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$fname.'"');
    header('Pragma: no-cache');

    $exam_label = ($exam['name']??'').' '.($exam['year']??'').' — '.($is_alevel?'A-Level':'O-Level').' — '.$level_name;

    echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
    echo '<?mso-application progid="Excel.Sheet"?>'."\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'."\n";
    echo ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'."\n";

    // ── Sheet 1: District Summary ──────────────────────────────
    echo '<Worksheet ss:Name="Summary">'."\n".'<Table>'."\n";

    echo da_xml_row([da_xml_cell('DISTRICT ANALYSIS — '.$exam_label)]);
    echo da_xml_row([]);
    echo da_xml_row([da_xml_cell('Enrolled'), da_xml_cell($enrolled_total,'Number'),
                     da_xml_cell(''), da_xml_cell('Sat'), da_xml_cell($sat_total,'Number')]);
    echo da_xml_row([da_xml_cell('District GPA'), da_xml_cell($dist_gpa,'Number'),
                     da_xml_cell(''), da_xml_cell('Avg%'), da_xml_cell($dist_avg,'Number')]);
    echo da_xml_row([da_xml_cell('District Grade'), da_xml_cell($dist_grade),
                     da_xml_cell(''), da_xml_cell('Pass% (Div I-IV)'), da_xml_cell($dist_pass_rate,'Number')]);
    echo da_xml_row([]);

    // Division by gender
    echo da_xml_row([da_xml_cell('DIVISION BY GENDER')]);
    echo da_xml_row(array_map('da_xml_cell', ['Division','Boys (M)','Girls (F)','Total','%']));
    foreach (['I','II','III','IV','0'] as $d) {
        $m   = $dist_div_gen[$d]['M'];
        $f   = $dist_div_gen[$d]['F'];
        $tot = $m + $f;
        $pct = $sat_total ? round($tot / $sat_total * 100, 1) : 0.0;
        echo da_xml_row([da_xml_cell('Div '.$d), da_xml_cell($m,'Number'), da_xml_cell($f,'Number'), da_xml_cell($tot,'Number'), da_xml_cell($pct,'Number')]);
    }
    $tot_m = array_sum(array_column($dist_div_gen,'M'));
    $tot_f = array_sum(array_column($dist_div_gen,'F'));
    echo da_xml_row([da_xml_cell('TOTAL'), da_xml_cell($tot_m,'Number'), da_xml_cell($tot_f,'Number'), da_xml_cell($sat_total,'Number'), da_xml_cell(100,'Number')]);
    echo da_xml_row([]);

    // School ranking
    echo da_xml_row([da_xml_cell('SCHOOL RANKING')]);
    $shdr = ['#','School','Enr','Sat','Div I','Div II','Div III','Div IV','Div 0','GPA','Avg%','Pass%','Grade'];
    echo da_xml_row(array_map('da_xml_cell', $shdr));
    foreach ($schools_data as $sch_id => $sd) {
        echo da_xml_row([
            da_xml_cell($sd['rank'],'Number'), da_xml_cell($sd['name']),
            da_xml_cell($sd['enrolled'],'Number'), da_xml_cell($sd['sat'],'Number'),
            da_xml_cell($sd['div']['I'],'Number'), da_xml_cell($sd['div']['II'],'Number'),
            da_xml_cell($sd['div']['III'],'Number'), da_xml_cell($sd['div']['IV'],'Number'),
            da_xml_cell($sd['div']['0'],'Number'),
            da_xml_cell($sd['gpa'],'Number'), da_xml_cell($sd['avg_pct'],'Number'),
            da_xml_cell($sd['pass_rate'],'Number'), da_xml_cell($sd['grade']),
        ]);
    }
    echo da_xml_row([]);

    // Subject analysis (district-wide)
    echo da_xml_row([da_xml_cell('SUBJECT ANALYSIS — DISTRICT WIDE')]);
    $subj_hdr = ['#','Subject','Students','A','B','C','D'];
    if ($is_alevel) $subj_hdr = array_merge($subj_hdr, ['E','S']);
    $subj_hdr = array_merge($subj_hdr, ['F','GPA','Avg%','Pass%','Grade']);
    echo da_xml_row(array_map('da_xml_cell', $subj_hdr));
    foreach ($sub_ids_sorted as $sub_id) {
        $sub = $dist_subjects[$sub_id]; $ss = $dist_subj_stats[$sub_id];
        $rc = [
            da_xml_cell($subject_rank[$sub_id],'Number'), da_xml_cell($sub['name']),
            da_xml_cell($ss['total'],'Number'),
            da_xml_cell($ss['counts']['A']??0,'Number'), da_xml_cell($ss['counts']['B']??0,'Number'),
            da_xml_cell($ss['counts']['C']??0,'Number'), da_xml_cell($ss['counts']['D']??0,'Number'),
        ];
        if ($is_alevel) {
            $rc[] = da_xml_cell($ss['counts']['E']??0,'Number');
            $rc[] = da_xml_cell($ss['counts']['S']??0,'Number');
        }
        $rc[] = da_xml_cell($ss['counts']['F']??0,'Number');
        $rc[] = da_xml_cell($ss['gpa'],'Number');
        $rc[] = da_xml_cell($ss['avg_pct'],'Number');
        $rc[] = da_xml_cell($ss['pass_rate'],'Number');
        $rc[] = da_xml_cell($ss['grade']);
        echo da_xml_row($rc);
    }

    echo '</Table>'."\n".'</Worksheet>'."\n";

    // ── Per-school sheets ──────────────────────────────────────
    foreach ($schools_data as $sch_id => $sd) {
        $sheet_name = substr(preg_replace('/[\/\\\?\*\[\]:]+/','_', $sd['name']), 0, 31);

        echo '<Worksheet ss:Name="'.htmlspecialchars($sheet_name, ENT_XML1, 'UTF-8').'">'."\n".'<Table>'."\n";

        echo da_xml_row([da_xml_cell($sd['name'].' — '.$exam_label)]);
        echo da_xml_row([da_xml_cell('District Rank'), da_xml_cell($sd['rank'],'Number'),
                         da_xml_cell(''), da_xml_cell('GPA'), da_xml_cell($sd['gpa'],'Number'),
                         da_xml_cell(''), da_xml_cell('Avg%'), da_xml_cell($sd['avg_pct'],'Number'),
                         da_xml_cell(''), da_xml_cell('Pass%'), da_xml_cell($sd['pass_rate'],'Number')]);
        echo da_xml_row([]);
        echo da_xml_row(array_map('da_xml_cell',
            ['#','Reg No','Name','Sex','Division','Points','Avg%','Grade','School Rank','District Rank']
        ));

        // Students for this school, sorted by aggregate
        $sch_students = array_filter($all_students, fn($st) => $st['school_id'] === $sch_id);
        uasort($sch_students, fn($a,$b) =>
            $a['agg'] !== $b['agg'] ? $a['agg']-$b['agg'] : $b['avg_pct']<=>$a['avg_pct']
        );
        // Assign school-level position
        $sp=1; $sp_pa=null; $sp_pv=null; $sp_pp=1;
        foreach ($sch_students as &$sst) {
            if ($sp_pa===$sst['agg'] && abs(($sp_pv??0)-$sst['avg_pct'])<0.01) {
                $sst['sch_pos'] = $sp_pp;
            } else {
                $sst['sch_pos'] = $sp; $sp_pp = $sp;
            }
            $sp_pa = $sst['agg']; $sp_pv = $sst['avg_pct']; $sp++;
        }
        unset($sst);

        $i = 1;
        foreach ($sch_students as $sst) {
            echo da_xml_row([
                da_xml_cell($i++,'Number'),
                da_xml_cell($sst['admission_no']),
                da_xml_cell($sst['full_name']),
                da_xml_cell($sst['sex']),
                da_xml_cell($sst['div']),
                da_xml_cell($sst['agg'],'Number'),
                da_xml_cell($sst['avg_pct'],'Number'),
                da_xml_cell($sst['grade']),
                da_xml_cell($sst['sch_pos'],'Number'),
                da_xml_cell($sst['dist_pos'] ?? '—'),
            ]);
        }

        echo '</Table>'."\n".'</Worksheet>'."\n";
    }

    echo '</Workbook>'."\n";
    exit;
}

// ── Build export URL ───────────────────────────────────────────
$export_url = '';
if (!empty($all_students)) {
    $export_url = url('district/exam_analysis.php?'.http_build_query(array_filter([
        'exam_id'  => $exam_id,
        'level_id' => $level_id,
        'export'   => 'excel',
    ])));
}

$grade_cols  = $is_alevel ? ['A','B','C','D','E','S','F'] : ['A','B','C','D','F'];
$div_colors  = ['I'=>'success','II'=>'primary','III'=>'info','IV'=>'warning','0'=>'danger'];

render_header('District Exam Analysis');
?>
<div class="page-heading d-print-none"><h4>District Exam Analysis</h4></div>
<div class="card mb-3 d-print-none"><div class="card-body py-2"><form method="get" class="row g-2 align-items-end" id="filterForm">
<div class="col-12 col-md-4"><label class="form-label small fw-semibold mb-1">Exam</label><select name="exam_id" class="form-select form-select-sm" id="selExam"><option value="">-- Select exam --</option>
<?php foreach($exams as $ex):?><option value="<?=(int)$ex['id']?>" <?=$exam_id==$ex['id']?'selected':''?>><?=e($ex['name'])?> (<?=(int)$ex['year']?>) -- <?=$ex['category']==='o_level'?'O-Level':'A-Level'?></option><?php endforeach;?></select></div>
<?php if($levels):?><div class="col-12 col-md-3"><label class="form-label small fw-semibold mb-1">Class / Form</label><select name="level_id" class="form-select form-select-sm" id="selLevel"><?php foreach($levels as $lv):?><option value="<?=(int)$lv['id']?>" <?=$level_id==$lv['id']?'selected':''?>><?=e($lv['name'])?></option><?php endforeach;?></select></div><?php endif;?>
<div class="col-auto"><button type="submit" class="btn btn-primary btn-sm px-3">View</button></div>
</form></div></div>
<?php if(!empty($all_students)):?>
<div class="d-none d-print-block mb-2"><h5 class="mb-0">DISTRICT ANALYSIS</h5><div class="small"><?=e($exam['name'])?> <?=(int)$exam['year']?> — <?=$is_alevel?'A-Level':'O-Level'?> — <?=e($level_name)?></div></div>
<div class="d-flex gap-2 mb-2 d-print-none"><button onclick="window.print()" class="btn btn-outline-secondary btn-sm">Print</button><a href="<?=e($export_url)?>" class="btn btn-outline-success btn-sm">Export Excel</a></div>

<!-- Muhtasari wa wilaya -->
<table class="rpt-tbl mb-2">
  <thead>
    <tr><th>Waliojiunga</th><th>Walifanya</th><th>%</th><th>GPA</th><th>Wastani%</th><th>Pass% (I-IV)</th><th>Daraja</th></tr>
  </thead>
  <tbody>
    <tr>
      <td class="tc"><?=$enrolled_total?></td>
      <td class="tc"><?=$sat_total?></td>
      <td class="tc"><?=$enrolled_total?round($sat_total/$enrolled_total*100,0).'%':'--'?></td>
      <td class="tc"><?=$dist_gpa?></td>
      <td class="tc"><?=$dist_avg?>%</td>
      <td class="tc"><?=$dist_pass_rate?>%</td>
      <td class="tc"><?=$dist_grade?></td>
    </tr>
  </tbody>
</table>

<!-- Mgawanyo kwa jinsia -->
<?php $t_m = array_sum(array_column($dist_div_gen,'M')); $t_f = array_sum(array_column($dist_div_gen,'F')); ?>
<table class="rpt-tbl mb-3">
  <thead>
    <tr><th>SEX</th><th>I</th><th>II</th><th>III</th><th>IV</th><th>0</th></tr>
  </thead>
  <tbody>
    <tr>
      <td class="sex-cell">M</td>
      <td class="tc"><?=$dist_div_gen['I']['M']?></td>
      <td class="tc"><?=$dist_div_gen['II']['M']?></td>
      <td class="tc"><?=$dist_div_gen['III']['M']?></td>
      <td class="tc<?=$dist_div_gen['IV']['M']>0?' rpt-iv':''?>"><?=$dist_div_gen['IV']['M']?></td>
      <td class="tc<?=$dist_div_gen['0']['M']>0?' rpt-z':''?>"><?=$dist_div_gen['0']['M']?></td>
    </tr>
    <tr>
      <td class="sex-cell">F</td>
      <td class="tc"><?=$dist_div_gen['I']['F']?></td>
      <td class="tc"><?=$dist_div_gen['II']['F']?></td>
      <td class="tc"><?=$dist_div_gen['III']['F']?></td>
      <td class="tc<?=$dist_div_gen['IV']['F']>0?' rpt-iv':''?>"><?=$dist_div_gen['IV']['F']?></td>
      <td class="tc<?=$dist_div_gen['0']['F']>0?' rpt-z':''?>"><?=$dist_div_gen['0']['F']?></td>
    </tr>
    <tr>
      <td class="sex-cell">T</td>
      <td class="tc"><?=$dist_div_totals['I']?></td>
      <td class="tc"><?=$dist_div_totals['II']?></td>
      <td class="tc"><?=$dist_div_totals['III']?></td>
      <td class="tc<?=$dist_div_totals['IV']>0?' rpt-iv':''?>"><?=$dist_div_totals['IV']?></td>
      <td class="tc<?=$dist_div_totals['0']>0?' rpt-z':''?>"><?=$dist_div_totals['0']?></td>
    </tr>
  </tbody>
</table>

<!-- Orodha ya shule kwa nafasi -->
<div class="table-responsive mb-3">
<table class="rpt-tbl" style="width:100%">
  <thead>
    <tr>
      <th>#</th>
      <th style="text-align:left">Shule (<?=count($schools_data)?>)</th>
      <th>Waliojiunga</th>
      <th>Walifanya</th>
      <th>DI</th><th>DII</th><th>DIII</th><th>DIV</th><th>D0</th>
      <th>GPA</th><th>Wastani%</th><th>Pass%</th><th>Daraja</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach($schools_data as $sch_id=>$sd): ?>
    <tr>
      <td class="tc"><?=$sd['rank']?></td>
      <td><?=e($sd['name'])?></td>
      <td class="tc"><?=$sd['enrolled']?></td>
      <td class="tc"><?=$sd['sat']?></td>
      <td class="tc di"><?=$sd['div']['I']?></td>
      <td class="tc dii"><?=$sd['div']['II']?></td>
      <td class="tc diii"><?=$sd['div']['III']?></td>
      <td class="tc<?=$sd['div']['IV']>0?' rpt-iv':''?>"><?=$sd['div']['IV']?></td>
      <td class="tc<?=$sd['div']['0']>0?' rpt-z':''?>"><?=$sd['div']['0']?></td>
      <td class="tc"><?=$sd['gpa']?></td>
      <td class="tc"><?=$sd['avg_pct']?>%</td>
      <td class="tc<?=$sd['pass_rate']>=70?' di':($sd['pass_rate']>=50?' diii':' rpt-z')?>"><?=$sd['pass_rate']?>%</td>
      <td class="tc"><?=$sd['grade']?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<!-- Uchambuzi wa masomo — wilaya nzima -->
<div class="table-responsive mb-3">
<table class="rpt-tbl" style="width:100%">
  <thead>
    <tr>
      <th>#</th>
      <th style="text-align:left">Somo<?=$is_alevel?' (P=principal)':''?></th>
      <th>Wanaf.</th>
      <?php foreach($grade_cols as $gc): ?><th><?=$gc?></th><?php endforeach; ?>
      <th>GPA</th><th>Wastani%</th><th>Pass%</th><th>Daraja</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach($sub_ids_sorted as $sub_id):
      $sub=$dist_subjects[$sub_id]; $ss=$dist_subj_stats[$sub_id]; $rank=$subject_rank[$sub_id];
    ?>
    <tr>
      <td class="tc"><?=$rank?></td>
      <td><?=e($sub['name'])?><?php if($is_alevel&&$sub['is_principal']):?> <sup>P</sup><?php endif;?></td>
      <td class="tc"><?=$ss['total']?></td>
      <?php foreach($grade_cols as $gc):?>
      <td class="tc"><?=$ss['counts'][$gc]??0?></td>
      <?php endforeach;?>
      <td class="tc"><?=$ss['gpa']?></td>
      <td class="tc"><?=$ss['avg_pct']?>%</td>
      <td class="tc<?=$ss['pass_rate']>=70?' di':($ss['pass_rate']>=50?' diii':' rpt-z')?>"><?=$ss['pass_rate']?>%</td>
      <td class="tc"><?=$ss['grade']?></td>
    </tr>
    <?php endforeach;?>
  </tbody>
  <tfoot>
    <tr>
      <td colspan="2" style="background:#d4eaf7;font-weight:700">Jumla: <?=$sat_total?> wanafunzi</td>
      <td colspan="<?=count($grade_cols)+5?>" style="background:#d4eaf7;font-size:.72rem;color:#374151">Pass% = A+B+C · GPA: A=1, F=<?=$is_alevel?7:5?> (chini ni bora)</td>
    </tr>
  </tfoot>
</table>
</div>

<!-- Wanafunzi 10 Bora / 10 wa Mwisho -->
<div class="row g-3 mb-2">
  <div class="col-12 col-xl-6">
    <div class="small fw-bold mb-1">Wanafunzi 10 Bora — Wilaya</div>
    <div class="table-responsive">
    <table class="rpt-tbl" style="width:100%">
      <thead>
        <tr><th>#</th><th style="text-align:left">Jina</th><th>SEX</th><th>DIV</th><th>Pt</th><th>Avg%</th><th style="text-align:left">Shule</th></tr>
      </thead>
      <tbody>
        <?php foreach($top10 as $st): ?>
        <tr>
          <td class="tc"><?=$st['dist_pos']?></td>
          <td style="white-space:nowrap"><?=e($st['full_name'])?></td>
          <td class="tc"><?=e($st['sex'])?></td>
          <td class="tc di"><?=e($st['div'])?></td>
          <td class="tc"><?=$st['agg']?></td>
          <td class="tc"><?=$st['avg_pct']?>%</td>
          <td><?=e($st['school_name'])?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <div class="col-12 col-xl-6">
    <div class="small fw-bold mb-1">Wanafunzi 10 wa Mwisho — Wilaya</div>
    <div class="table-responsive">
    <table class="rpt-tbl" style="width:100%">
      <thead>
        <tr><th>#</th><th style="text-align:left">Jina</th><th>SEX</th><th>DIV</th><th>Pt</th><th>Avg%</th><th style="text-align:left">Shule</th></tr>
      </thead>
      <tbody>
        <?php foreach($bottom10 as $st): ?>
        <tr>
          <td class="tc"><?=$st['dist_pos']?></td>
          <td style="white-space:nowrap"><?=e($st['full_name'])?></td>
          <td class="tc"><?=e($st['sex'])?></td>
          <td class="tc rpt-z"><?=e($st['div'])?></td>
          <td class="tc rpt-z"><?=$st['agg']?></td>
          <td class="tc"><?=$st['avg_pct']?>%</td>
          <td><?=e($st['school_name'])?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<p class="small mt-1 d-print-none"><?php if($is_alevel):?>P=Principal / Division: best 3 principals / GPA: 1(A)-7(F)<?php else:?>Division: best 7 / GPA: 1(A)-5(F) / Pass=A-D<?php endif;?></p>

<?php elseif($exam&&$level_id):?><div class="alert alert-info">No marks recorded for this exam and class.</div><?php elseif($exam_id):?><div class="alert alert-light text-muted">Select class to view analysis.</div><?php else:?><div class="alert alert-light text-muted">Select exam to start.</div><?php endif;?>
<style>
@media print {
  .card.mb-3 { display: none !important; }
}
</style>
<script>(()=>{const f=document.getElementById('filterForm');const selExam=document.getElementById('selExam');const selLevel=document.getElementById('selLevel');selExam?.addEventListener('change',()=>{if(selLevel)selLevel.value='';f.submit()});selLevel?.addEventListener('change',()=>f.submit())})();</script>
<?php render_footer();?>
