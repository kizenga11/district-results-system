<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
require_role(['headmaster', 'district_admin', 'super_admin']);

$user = current_user();
$role = $user['role'];

// ── Division calculator ────────────────────────────────────────
function calc_division(array $marks, string $category): array
{
    if ($category === 'o_level') {
        if (empty($marks)) return ['div' => '—', 'agg' => 0];
        $pts  = array_map(fn($m) => (int)($m['points'] ?? 5), $marks);
        sort($pts);
        $agg  = array_sum(array_slice($pts, 0, 7));
        $div  = $agg <= 17 ? 'I' : ($agg <= 21 ? 'II' : ($agg <= 25 ? 'III' : ($agg <= 33 ? 'IV' : '0')));
        return ['div' => $div, 'agg' => $agg];
    }
    if ($category === 'a_level') {
        $principals = array_filter($marks, fn($m) => (int)($m['is_principal'] ?? 0) === 1);
        if (empty($principals)) return ['div' => '—', 'agg' => 0];
        $pts   = array_map(fn($m) => (int)($m['points'] ?? 7), $principals);
        sort($pts);
        $agg   = array_sum(array_slice($pts, 0, 3));
        $div   = $agg <= 9 ? 'I' : ($agg <= 12 ? 'II' : ($agg <= 15 ? 'III' : ($agg <= 19 ? 'IV' : '0')));
        return ['div' => $div, 'agg' => $agg];
    }
    return ['div' => '—', 'agg' => 0];
}

function grade_cls(string $g): string {
    return match($g) { 'A' => 'ga', 'B' => 'gb', 'C' => 'gc', 'D' => 'gd', 'F' => 'gf', default => '' };
}
function div_cls(string $d): string {
    return match($d) { 'I' => 'di', 'II' => 'dii', 'III' => 'diii', 'IV' => 'div4', '0' => 'd0', default => 'dna' };
}

// ── Resolve school ─────────────────────────────────────────────
$school_id = null;
$schools   = [];

if ($role === 'headmaster') {
    $school_id = (int)($user['school_id'] ?? 0) ?: null;
} else {
    $schools   = db()->query('SELECT id, name FROM schools WHERE status="active" ORDER BY name')->fetchAll();
    $school_id = (int)($_GET['school_id'] ?? 0) ?: null;
}

$exam_id  = (int)($_GET['exam_id']  ?? 0);
$level_id = (int)($_GET['level_id'] ?? 0);

// ── Exams for this school ──────────────────────────────────────
$exams = [];
if ($school_id) {
    $stmt = db()->prepare(
        'SELECT DISTINCT e.id, e.name, e.year, e.category
         FROM exams e
         WHERE e.category IN (
             SELECT DISTINCT sub.category FROM school_subjects ss
             JOIN subjects sub ON sub.id = ss.subject_id
             WHERE ss.school_id = :s
         )
         ORDER BY e.year DESC, e.name'
    );
    $stmt->execute([':s' => $school_id]);
    $exams = $stmt->fetchAll();

    if (empty($exams)) {
        $stmt = db()->prepare(
            'SELECT e.id, e.name, e.year, e.category
             FROM exams e JOIN schools sc ON sc.id = :s2
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

// ── Levels that have marks ─────────────────────────────────────
$levels = [];
if ($exam && $school_id) {
    $stmt = db()->prepare(
        'SELECT DISTINCT lv.id, lv.name
         FROM students st
         JOIN marks m ON m.student_id = st.id AND m.exam_id = :eid
         JOIN levels lv ON lv.id = st.level_id
         WHERE st.school_id = :sid AND st.status = "active"
         ORDER BY lv.id'
    );
    $stmt->execute([':eid' => $exam_id, ':sid' => $school_id]);
    $levels = $stmt->fetchAll();
    if ($levels && !$level_id) $level_id = (int)$levels[0]['id'];
}

// ── Fetch marks & compute results ─────────────────────────────
$all_subjects  = [];
$students_data = [];
$div_summary   = ['I' => 0, 'II' => 0, 'III' => 0, 'IV' => 0, '0' => 0];

if ($exam && $school_id && $level_id) {
    $stmt = db()->prepare(
        'SELECT m.student_id, m.subject_id,
                sub.name AS subject_name, sub.code AS subject_code,
                COALESCE(sub.abbr, sub.code) AS subject_abbr, sub.is_principal,
                m.grade, m.points, m.total_percent,
                st.full_name, st.admission_no, st.sex
         FROM marks m
         JOIN students st ON st.id = m.student_id
         JOIN subjects sub ON sub.id = m.subject_id
         WHERE m.exam_id = :eid AND st.school_id = :sid AND st.level_id = :lid AND st.status = "active"
         ORDER BY st.full_name, sub.name'
    );
    $stmt->execute([':eid' => $exam_id, ':sid' => $school_id, ':lid' => $level_id]);

    foreach ($stmt->fetchAll() as $row) {
        $sid = (int)$row['student_id'];
        if (!isset($students_data[$sid])) {
            $students_data[$sid] = [
                'full_name'    => $row['full_name'],
                'admission_no' => $row['admission_no'],
                'sex'          => $row['sex'],
                'marks'        => [],
                'div'          => '—',
                'agg'          => 0,
            ];
        }
        $sub_id = (int)$row['subject_id'];
        $students_data[$sid]['marks'][$sub_id] = [
            'grade'         => $row['grade'],
            'points'        => $row['points'] !== null ? (int)$row['points'] : null,
            'total_percent' => (float)$row['total_percent'],
            'is_principal'  => (int)$row['is_principal'],
        ];
        if (!isset($all_subjects[$sub_id])) {
            $all_subjects[$sub_id] = [
                'code'         => $row['subject_code'],
                'abbr'         => $row['subject_abbr'],
                'name'         => $row['subject_name'],
                'is_principal' => (int)$row['is_principal'],
            ];
        }
    }

    uasort($all_subjects, fn($a, $b) =>
        $b['is_principal'] !== $a['is_principal']
            ? $b['is_principal'] - $a['is_principal']
            : strcmp($a['name'], $b['name'])
    );

    foreach ($students_data as &$st) {
        $info = calc_division(array_values($st['marks']), $exam['category']);
        $st['div'] = $info['div'];
        $st['agg'] = $info['agg'];
        if (isset($div_summary[$info['div']])) $div_summary[$info['div']]++;
    }
    unset($st);

    $div_f = ['I'=>0,'II'=>0,'III'=>0,'IV'=>0,'0'=>0];
    $div_m = ['I'=>0,'II'=>0,'III'=>0,'IV'=>0,'0'=>0];
    foreach ($students_data as $st) {
        $d = $st['div'];
        if (isset($div_f[$d])) {
            strtoupper($st['sex']??'') === 'F' ? $div_f[$d]++ : $div_m[$d]++;
        }
    }

    $dord = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, '0' => 5, '—' => 6];
    uasort($students_data, fn($a, $b) =>
        ($dord[$a['div']] ?? 6) !== ($dord[$b['div']] ?? 6)
            ? ($dord[$a['div']] ?? 6) - ($dord[$b['div']] ?? 6)
            : $a['agg'] - $b['agg']
    );
}

// ── Subject analysis ───────────────────────────────────────────
$subj_analysis = [];
if (!empty($all_subjects) && !empty($students_data)) {
    foreach ($all_subjects as $sub_id => $sub) {
        $subj_analysis[$sub_id] = [
            'code'     => $sub['code'],
            'name'     => $sub['name'],
            'is_p'     => $sub['is_principal'],
            'n'        => 0, 'nm' => 0, 'nf' => 0,
            'sum'      => 0.0,
            'pts_sum'  => 0,
            'g'        => [
                'A' => [0, 0, 0],   // [total, male, female]
                'B' => [0, 0, 0],
                'C' => [0, 0, 0],
                'D' => [0, 0, 0],
                'E' => [0, 0, 0],
                'S' => [0, 0, 0],
                'F' => [0, 0, 0],
            ],
        ];
    }

    foreach ($students_data as $st) {
        $female = strtoupper($st['sex'] ?? '') === 'F';
        foreach ($st['marks'] as $sub_id => $m) {
            if (!isset($subj_analysis[$sub_id])) continue;
            $subj_analysis[$sub_id]['n']++;
            $female ? $subj_analysis[$sub_id]['nf']++ : $subj_analysis[$sub_id]['nm']++;
            $subj_analysis[$sub_id]['sum']     += $m['total_percent'];
            $subj_analysis[$sub_id]['pts_sum'] += ($m['points'] ?? 5);
            $g = $m['grade'];
            if (isset($subj_analysis[$sub_id]['g'][$g])) {
                $subj_analysis[$sub_id]['g'][$g][0]++;
                $female
                    ? $subj_analysis[$sub_id]['g'][$g][2]++
                    : $subj_analysis[$sub_id]['g'][$g][1]++;
            }
        }
    }

    foreach ($subj_analysis as $sub_id => $sa) {
        if ($sa['n'] === 0) {
            $subj_analysis[$sub_id] += ['avg' => 0.0, 'grade' => 'F', 'gpa' => 0.0, 'pass_rate' => 0.0, 'rank' => 0];
            continue;
        }
        $avg = round($sa['sum'] / $sa['n'], 1);
        $grade = $avg >= 75 ? 'A' : ($avg >= 65 ? 'B' : ($avg >= 50 ? 'C' : ($avg >= 30 ? 'D' : 'F')));
        $gpa   = round($sa['pts_sum'] / $sa['n'], 2);
        $pass  = $sa['g']['A'][0] + $sa['g']['B'][0] + $sa['g']['C'][0];
        $subj_analysis[$sub_id]['avg']       = $avg;
        $subj_analysis[$sub_id]['grade']     = $grade;
        $subj_analysis[$sub_id]['gpa']       = $gpa;
        $subj_analysis[$sub_id]['pass_rate'] = round($pass / $sa['n'] * 100, 1);
    }

    uasort($subj_analysis, fn($a, $b) => $b['avg'] <=> $a['avg']);
    $r = 1;
    foreach ($subj_analysis as $sub_id => $_) { $subj_analysis[$sub_id]['rank'] = $r++; }
}

$is_alevel  = ($exam['category'] ?? '') === 'a_level';
$level_name = '';
foreach ($levels as $lv) {
    if ((int)$lv['id'] === $level_id) { $level_name = $lv['name']; break; }
}

render_header('Exam Results');
?>
<div class="page-heading d-print-none"><h4>Exam Results</h4></div>

<?php if ($exam && $school_id && $level_id): ?>
<div class="d-none d-print-block mb-2" style="border-bottom:2px solid #333;padding-bottom:4pt;margin-bottom:6pt">
  <strong style="font-size:11pt"><?= e($exam['name'] ?? '') ?> (<?= (int)($exam['year'] ?? '') ?>)</strong>
  &nbsp;—&nbsp;<?= $is_alevel ? 'A-Level' : 'O-Level' ?>
  &nbsp;|&nbsp;<?= e($level_name) ?>
</div>
<?php endif; ?>

<!-- ── Filter form ──────────────────────────────────────────── -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end" id="filterForm">
      <?php if ($role !== 'headmaster'): ?>
      <div class="col-12 col-md-3">
        <label class="form-label small fw-semibold mb-1">School</label>
        <select name="school_id" class="form-select form-select-sm" id="selSchool">
          <option value="">— Select school —</option>
          <?php foreach ($schools as $sc): ?>
          <option value="<?= (int)$sc['id'] ?>" <?= $school_id == $sc['id'] ? 'selected' : '' ?>><?= e($sc['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-12 col-md-3">
        <label class="form-label small fw-semibold mb-1">Exam</label>
        <select name="exam_id" class="form-select form-select-sm" id="selExam" <?= !$exams ? 'disabled' : '' ?>>
          <option value="">— Select exam —</option>
          <?php foreach ($exams as $ex): ?>
          <option value="<?= (int)$ex['id'] ?>" <?= $exam_id == $ex['id'] ? 'selected' : '' ?>>
            <?= e($ex['name']) ?> (<?= (int)$ex['year'] ?>) — <?= $ex['category'] === 'o_level' ? 'O-Level' : 'A-Level' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($levels): ?>
      <div class="col-12 col-md-2">
        <label class="form-label small fw-semibold mb-1">Class</label>
        <select name="level_id" class="form-select form-select-sm" id="selLevel">
          <?php foreach ($levels as $lv): ?>
          <option value="<?= (int)$lv['id'] ?>" <?= $level_id == $lv['id'] ? 'selected' : '' ?>><?= e($lv['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary btn-sm px-3">View</button>
      </div>
    </form>
  </div>
</div>

<?php if ($exam && !empty($students_data)):
    $total = count($students_data);
?>

<!-- ── Division summary table ───────────────────────────────── -->
<table class="rpt-tbl mb-2">
  <thead>
    <tr><th>SEX</th><th>I</th><th>II</th><th>III</th><th>IV</th><th>0</th></tr>
  </thead>
  <tbody>
    <tr>
      <td class="sex-cell">F</td>
      <td class="tc"><?= $div_f['I'] ?></td>
      <td class="tc"><?= $div_f['II'] ?></td>
      <td class="tc"><?= $div_f['III'] ?></td>
      <td class="tc<?= $div_f['IV'] > 0 ? ' rpt-iv' : '' ?>"><?= $div_f['IV'] ?></td>
      <td class="tc<?= $div_f['0'] > 0 ? ' rpt-z' : '' ?>"><?= $div_f['0'] ?></td>
    </tr>
    <tr>
      <td class="sex-cell">M</td>
      <td class="tc"><?= $div_m['I'] ?></td>
      <td class="tc"><?= $div_m['II'] ?></td>
      <td class="tc"><?= $div_m['III'] ?></td>
      <td class="tc<?= $div_m['IV'] > 0 ? ' rpt-iv' : '' ?>"><?= $div_m['IV'] ?></td>
      <td class="tc<?= $div_m['0'] > 0 ? ' rpt-z' : '' ?>"><?= $div_m['0'] ?></td>
    </tr>
    <tr>
      <td class="sex-cell">T</td>
      <td class="tc"><?= $div_summary['I'] ?></td>
      <td class="tc"><?= $div_summary['II'] ?></td>
      <td class="tc"><?= $div_summary['III'] ?></td>
      <td class="tc<?= $div_summary['IV'] > 0 ? ' rpt-iv' : '' ?>"><?= $div_summary['IV'] ?></td>
      <td class="tc<?= $div_summary['0'] > 0 ? ' rpt-z' : '' ?>"><?= $div_summary['0'] ?></td>
    </tr>
  </tbody>
</table>

<!-- ── Student results list ─────────────────────────────────── -->
<div class="table-responsive mb-4">
  <table class="rpt-tbl rpt-stbl">
    <thead>
      <tr>
        <th>#</th>
        <th>CNO</th>
        <th>SEX</th>
        <th>AGGT</th>
        <th>DIV</th>
        <th>DETAILED&nbsp;SUBJECTS</th>
      </tr>
    </thead>
    <tbody>
    <?php $pos = 1; foreach ($students_data as $st):
      $dc = div_cls($st['div']);
    ?>
      <tr>
        <td class="tc rpt-pos"><?= $pos++ ?></td>
        <td class="rpt-cno"><?= e($st['admission_no']) ?></td>
        <td class="tc"><?= e(strtoupper($st['sex'] ?? '')) ?></td>
        <td class="tc rpt-agg<?= in_array($st['div'], ['IV','0']) ? ' rpt-iv' : '' ?>"><?= $st['agg'] > 0 ? $st['agg'] : '—' ?></td>
        <td class="tc rpt-div <?= $dc ?>"><?= $st['div'] !== '—' ? e($st['div']) : '—' ?></td>
        <td class="rpt-subjects"><?php
          $parts = [];
          foreach ($all_subjects as $sub_id => $sub) {
              $m = $st['marks'][$sub_id] ?? null;
              if (!$m) continue;
              $gc  = grade_cls($m['grade']);
              $sup = ($is_alevel && $sub['is_principal']) ? '<sup>P</sup>' : '';
              $parts[] = e($sub['abbr'] ?? $sub['code']) . $sup . " - <span class=\"rsubj-grade {$gc}\">'". e($m['grade']) ."'</span>";
          }
          echo implode(' ', $parts);
        ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ── Subject Analysis ─────────────────────────────────────── -->
<?php if (!empty($subj_analysis)):
  $tot_m = count(array_filter($students_data, fn($s) => strtoupper($s['sex'] ?? '') === 'M'));
  $tot_f = $total - $tot_m;
?>
<div class="table-responsive mt-3">
  <table class="rpt-tbl" style="width:100%">
    <thead>
      <tr>
        <th>#</th>
        <th style="text-align:left">Somo<?= $is_alevel ? ' (P=principal)' : '' ?></th>
        <th>Wanaf.</th>
        <th>M</th>
        <th>F</th>
        <th>Wastani%</th>
        <th>Daraja</th>
        <th>GPA</th>
        <th>Pass%</th>
        <th>A</th>
        <th>B</th>
        <th>C</th>
        <th>D</th>
        <?php if ($is_alevel): ?><th>E</th><th>S</th><?php endif; ?>
        <th>F</th>
      </tr>
    </thead>
    <tbody>
      <?php
        $grade_cols = $is_alevel ? ['A','B','C','D','E','S','F'] : ['A','B','C','D','F'];
        foreach ($subj_analysis as $sa):
          $gc = grade_cls($sa['grade']);
      ?>
      <tr>
        <td class="tc"><?= $sa['rank'] ?></td>
        <td><?= e($sa['code']) ?><?= ($is_alevel && $sa['is_p']) ? '<sup>P</sup>' : '' ?> — <?= e($sa['name']) ?></td>
        <td class="tc"><?= $sa['n'] ?></td>
        <td class="tc"><?= $sa['nm'] ?></td>
        <td class="tc"><?= $sa['nf'] ?></td>
        <td class="tc"><?= number_format($sa['avg'], 1) ?>%</td>
        <td class="tc"><span class="rsubj-grade <?= $gc ?>"><?= $sa['grade'] ?></span></td>
        <td class="tc"><?= number_format($sa['gpa'], 2) ?></td>
        <td class="tc<?= $sa['pass_rate'] >= 70 ? ' di' : ($sa['pass_rate'] >= 50 ? ' diii' : ' rpt-z') ?>"><?= number_format($sa['pass_rate'], 1) ?>%</td>
        <?php foreach ($grade_cols as $g):
          [$total_g, $m_g, $f_g] = $sa['g'][$g];
        ?>
        <td class="tc"><?= $total_g > 0 ? $total_g : '—' ?></td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="2" style="background:#d4eaf7;font-weight:700">Jumla: <?= $total ?> (<?= $tot_m ?>M, <?= $tot_f ?>F)<?= $is_alevel ? ' · P=Principal' : '' ?></td>
        <td colspan="<?= $is_alevel ? 14 : 12 ?>" style="background:#d4eaf7;font-size:.72rem;color:#374151">Pass% = A+B+C · GPA: 1(A)–<?=$is_alevel?7:5?>(F) · chini ni bora</td>
      </tr>
    </tfoot>
  </table>
</div>
<?php endif; ?>

<?php elseif ($exam && $school_id && $level_id): ?>
<div class="alert alert-info">No results found for this exam and class.</div>
<?php elseif ($school_id && $exam_id): ?>
<div class="alert alert-light text-muted">Select a class to view results.</div>
<?php elseif ($school_id): ?>
<div class="alert alert-light text-muted">Select an exam to view results.</div>
<?php elseif ($role !== 'headmaster'): ?>
<div class="alert alert-light text-muted">Select a school to begin.</div>
<?php else: ?>
<div class="alert alert-light text-muted">No exam results available yet.</div>
<?php endif; ?>

<style>
@media print {
  .card.mb-3 { display: none !important; }
}
</style>

<script>
(() => {
  const selSchool = document.getElementById('selSchool');
  const selExam   = document.getElementById('selExam');
  const selLevel  = document.getElementById('selLevel');
  const form      = document.getElementById('filterForm');
  selSchool?.addEventListener('change', () => { selExam && (selExam.value=''); selLevel && (selLevel.value=''); form.submit(); });
  selExam?.addEventListener('change',   () => { selLevel && (selLevel.value=''); form.submit(); });
  selLevel?.addEventListener('change',  () => form.submit());
})();
</script>
<?php render_footer(); ?>
