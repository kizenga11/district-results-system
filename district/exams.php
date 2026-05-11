<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

require_role(['super_admin', 'district_admin']);

$errors      = [];
$edit_action = false;

if (is_post()) {
    csrf_verify();

    $action = (string)($_POST['action'] ?? '');

    // ── Change status ──────────────────────────────────────────
    if ($action === 'set_status') {
        $id         = (int)($_POST['exam_id'] ?? 0);
        $new_status = (string)($_POST['status'] ?? '');
        if ($id > 0 && in_array($new_status, ['draft', 'open', 'closed'], true)) {
            db()->prepare('UPDATE exams SET status = :s WHERE id = :id')
               ->execute([':s' => $new_status, ':id' => $id]);
            flash_set('success', 'Exam status has been updated.');
        }
        redirect('district/exams.php');
    }

    // ── Delete exam ────────────────────────────────────────────
    if ($action === 'delete_exam') {
        $id = (int)($_POST['exam_id'] ?? 0);
        if ($id > 0) {
            $has_marks = db()->prepare('SELECT 1 FROM marks WHERE exam_id = :id LIMIT 1');
            $has_marks->execute([':id' => $id]);
            if ($has_marks->fetch()) {
                flash_set('error', 'This exam has saved marks — it cannot be deleted.');
            } else {
                db()->prepare('DELETE FROM exams WHERE id = :id')->execute([':id' => $id]);
                flash_set('success', 'Exam has been deleted.');
            }
        }
        redirect('district/exams.php');
    }

    // ── Add exam ───────────────────────────────────────────────
    if ($action === 'add_exam') {
        $errors = validate_exam($_POST);

        if (empty($errors)) {
            $d   = clean_exam($_POST);
            $pdo = db();
            $pdo->prepare(
                'INSERT INTO exams (category, name, year, term, status,
                 marks_open_from, marks_open_to, practical_open_from, practical_open_to, created_by)
                 VALUES (:cat, :name, :year, :term, :status,
                 :mof, :mot, :pof, :pot, :by)'
            )->execute($d);

            $exam_id = (int)$pdo->lastInsertId();
            save_exam_levels($pdo, $exam_id, $_POST['levels'] ?? []);

            flash_set('success', 'Exam ' . $d[':name'] . ' has been added.');
            redirect('district/exams.php');
        }
    }

    // ── Edit exam ──────────────────────────────────────────────
    if ($action === 'edit_exam') {
        $edit_action = true;
        $id          = (int)($_POST['exam_id'] ?? 0);
        $errors      = validate_exam($_POST);

        if ($id <= 0) $errors[] = 'Invalid exam.';

        if (empty($errors)) {
            $d       = clean_exam($_POST);
            $d[':id'] = $id;
            db()->prepare(
                'UPDATE exams SET category=:cat, name=:name, year=:year, term=:term, status=:status,
                 marks_open_from=:mof, marks_open_to=:mot,
                 practical_open_from=:pof, practical_open_to=:pot WHERE id=:id'
            )->execute($d);

            $pdo = db();
            $pdo->prepare('DELETE FROM exam_levels WHERE exam_id = :id')->execute([':id' => $id]);
            save_exam_levels($pdo, $id, $_POST['levels'] ?? []);

            flash_set('success', 'Exam ' . $d[':name'] . ' has been updated.');
            redirect('district/exams.php');
        }
    }
}

// ── Fetch data ─────────────────────────────────────────────────
$exams = db()->query(
    'SELECT e.*,
            u.full_name AS created_by_name,
            COUNT(DISTINCT m.id) AS marks_count
     FROM exams e
     LEFT JOIN users u  ON u.id = e.created_by
     LEFT JOIN marks m  ON m.exam_id = e.id
     GROUP BY e.id
     ORDER BY e.year DESC, e.category, e.name'
)->fetchAll();

// Fetch levels per exam
$exam_levels_map = [];
$el_rows = db()->query('SELECT exam_id, level_id FROM exam_levels')->fetchAll();
foreach ($el_rows as $row) {
    $exam_levels_map[(int)$row['exam_id']][] = (int)$row['level_id'];
}

$all_levels = db()->query('SELECT * FROM levels ORDER BY id')->fetchAll();

$levels_o = array_filter($all_levels, fn($l) => $l['category'] === 'o_level');
$levels_a = array_filter($all_levels, fn($l) => $l['category'] === 'a_level');

$exams_json  = json_encode(array_column($exams, null, 'id'), JSON_HEX_TAG);
$el_map_json = json_encode($exam_levels_map, JSON_HEX_TAG);

$current_user = current_user();

$status_labels = [
    'draft'  => ['label' => 'Draft',  'badge' => 'bg-secondary'],
    'open'   => ['label' => 'Open',   'badge' => 'bg-success'],
    'closed' => ['label' => 'Closed', 'badge' => 'bg-danger'],
];

render_header('Exams');
?>

<div class="page-heading">
  <h4>Exams <span class="badge bg-secondary ms-1" style="font-size:.7rem"><?= count($exams) ?></span></h4>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAdd">
    + Add Exam
  </button>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if (empty($exams)): ?>
  <div class="text-center text-muted py-5">No exams yet. Add the first exam.</div>
<?php else: ?>
<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Exam Name</th>
          <th>Level</th>
          <th class="d-none d-sm-table-cell">Year</th>
          <th class="d-none d-md-table-cell">Term</th>
          <th>Classes</th>
          <th class="d-none d-md-table-cell">Marks Period</th>
          <th class="text-center d-none d-md-table-cell">Marks</th>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($exams as $i => $ex): ?>
        <?php
            $lvl_ids  = $exam_levels_map[(int)$ex['id']] ?? [];
            $lvl_names = array_map(
                fn($l) => $l['name'],
                array_filter($all_levels, fn($l) => in_array((int)$l['id'], $lvl_ids))
            );
            $sl = $status_labels[$ex['status']] ?? $status_labels['draft'];
        ?>
        <tr>
          <td class="text-muted small"><?= $i + 1 ?></td>
          <td class="fw-semibold"><?= e($ex['name']) ?></td>
          <td><span class="badge bg-light text-dark border"><?= $ex['category'] === 'o_level' ? 'O-Level' : 'A-Level' ?></span></td>
          <td class="d-none d-sm-table-cell"><?= (int)$ex['year'] ?></td>
          <td class="d-none d-md-table-cell"><?= $ex['term'] ? 'Term ' . e($ex['term']) : '<span class="text-muted">—</span>' ?></td>
          <td class="small"><?= $lvl_names ? implode(', ', array_map('htmlspecialchars', $lvl_names)) : '<span class="text-muted">—</span>' ?></td>
          <td class="small d-none d-md-table-cell">
            <?php if ($ex['marks_open_from'] && $ex['marks_open_to']): ?>
              <?= e(date('d/m/Y', strtotime($ex['marks_open_from']))) ?>
              – <?= e(date('d/m/Y', strtotime($ex['marks_open_to']))) ?>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-center d-none d-md-table-cell">
            <span class="badge bg-secondary"><?= (int)$ex['marks_count'] ?></span>
          </td>
          <td>
            <span class="badge <?= $sl['badge'] ?>"><?= $sl['label'] ?></span>
          </td>
          <td class="text-end">
            <div class="d-flex gap-1 justify-content-end flex-wrap">
              <!-- Edit -->
              <button class="btn btn-outline-primary btn-sm btn-edit"
                      data-id="<?= (int)$ex['id'] ?>"
                      data-bs-toggle="modal" data-bs-target="#modalEdit">
                Edit
              </button>

              <!-- Change status -->
              <div class="dropdown">
                <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                  Status
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <?php foreach (['draft' => 'Draft', 'open' => 'Open', 'closed' => 'Closed'] as $val => $lbl): ?>
                    <?php if ($val !== $ex['status']): ?>
                    <li>
                      <form method="post" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action"   value="set_status">
                        <input type="hidden" name="exam_id"  value="<?= (int)$ex['id'] ?>">
                        <input type="hidden" name="status"   value="<?= e($val) ?>">
                        <button class="dropdown-item" type="submit">→ <?= $lbl ?></button>
                      </form>
                    </li>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </ul>
              </div>

              <!-- Delete -->
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action"  value="delete_exam">
                <input type="hidden" name="exam_id" value="<?= (int)$ex['id'] ?>">
                <button class="btn btn-outline-danger btn-sm" type="submit"
                        onclick="return confirm('Delete exam <?= e(addslashes($ex['name'])) ?>?')">
                  Delete
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── Modal: Add Exam ────────────────────────────────────── -->
<div class="modal fade" id="modalAdd" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_exam">
        <div class="modal-header">
          <h5 class="modal-title">Add New Exam</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= exam_form_fields($_POST, $levels_o, $levels_a, []) ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Modal: Edit Exam ───────────────────────────────────── -->
<div class="modal fade" id="modalEdit" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" autocomplete="off" id="formEdit">
        <?= csrf_field() ?>
        <input type="hidden" name="action"  value="edit_exam">
        <input type="hidden" name="exam_id" id="editExamId">
        <div class="modal-header">
          <h5 class="modal-title">Edit Exam</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= exam_form_fields([], $levels_o, $levels_a, []) ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(() => {
  'use strict';

  const EXAMS    = <?= $exams_json ?>;
  const EL_MAP   = <?= $el_map_json ?>;

  // Show/hide practical dates based on category (only O-level has practical for most cases,
  // but we show it regardless — district can decide)
  function bindCategoryToggle(formEl) {
    const cat  = formEl.querySelector('[name="category"]');
    const lvlO = formEl.querySelector('.levels-o');
    const lvlA = formEl.querySelector('.levels-a');
    if (!cat) return;

    function toggle() {
      const isO = cat.value === 'o_level';
      if (lvlO) lvlO.style.display = isO ? '' : 'none';
      if (lvlA) lvlA.style.display = isO ? 'none' : '';
      // uncheck hidden checkboxes so they don't submit
      formEl.querySelectorAll('.levels-o input[type=checkbox]').forEach(c => { if (!isO) c.checked = false; });
      formEl.querySelectorAll('.levels-a input[type=checkbox]').forEach(c => { if (isO)  c.checked = false; });
    }
    cat.addEventListener('change', toggle);
    toggle();
  }

  bindCategoryToggle(document.getElementById('modalAdd'));
  bindCategoryToggle(document.getElementById('modalEdit'));

  // Populate edit modal
  document.querySelectorAll('.btn-edit').forEach(btn => {
    btn.addEventListener('click', () => {
      const ex = EXAMS[btn.dataset.id];
      if (!ex) return;
      const f  = document.getElementById('formEdit');

      f.querySelector('#editExamId').value              = ex.id;
      f.querySelector('[name="category"]').value        = ex.category;
      f.querySelector('[name="name"]').value            = ex.name;
      f.querySelector('[name="year"]').value            = ex.year;
      f.querySelector('[name="term"]').value            = ex.term ?? '';
      f.querySelector('[name="status"]').value          = ex.status;
      f.querySelector('[name="marks_open_from"]').value = ex.marks_open_from ?? '';
      f.querySelector('[name="marks_open_to"]').value   = ex.marks_open_to   ?? '';
      f.querySelector('[name="practical_open_from"]').value = ex.practical_open_from ?? '';
      f.querySelector('[name="practical_open_to"]').value   = ex.practical_open_to   ?? '';

      // Trigger category toggle first
      f.querySelector('[name="category"]').dispatchEvent(new Event('change'));

      // Check levels
      const checked = EL_MAP[ex.id] ?? [];
      f.querySelectorAll('[name="levels[]"]').forEach(chk => {
        chk.checked = checked.includes(parseInt(chk.value));
      });
    });
  });

  <?php if (!empty($errors) && $edit_action): ?>
  new bootstrap.Modal(document.getElementById('modalEdit')).show();
  <?php elseif (!empty($errors)): ?>
  new bootstrap.Modal(document.getElementById('modalAdd')).show();
  <?php endif; ?>
})();
</script>

<?php
render_footer();

// ── Helpers ────────────────────────────────────────────────────
function save_exam_levels(PDO $pdo, int $exam_id, array $levels): void
{
    $stmt = $pdo->prepare('INSERT IGNORE INTO exam_levels (exam_id, level_id) VALUES (:eid, :lid)');
    foreach ($levels as $lid) {
        $lid = (int)$lid;
        if ($lid > 0) $stmt->execute([':eid' => $exam_id, ':lid' => $lid]);
    }
}

function validate_exam(array $post): array
{
    $errors = [];
    $name   = trim((string)($post['name']     ?? ''));
    $year   = (int)($post['year']   ?? 0);
    $cat    = (string)($post['category'] ?? '');
    $term   = (string)($post['term'] ?? '');
    $levels = $post['levels'] ?? [];

    if ($name === '')                                        $errors[] = 'Exam name is required.';
    if ($year < 2000 || $year > 2100)                       $errors[] = 'Enter a valid year (2000–2100).';
    if (!in_array($cat, ['o_level', 'a_level'], true))       $errors[] = 'Please select a valid level.';
    if ($term !== '' && !in_array($term, ['I','II','III'], true)) $errors[] = 'Invalid term.';
    if (empty($levels))                                      $errors[] = 'Please select at least one class.';

    // Validate date ranges if provided
    $mof = trim((string)($post['marks_open_from'] ?? ''));
    $mot = trim((string)($post['marks_open_to']   ?? ''));
    $pof = trim((string)($post['practical_open_from'] ?? ''));
    $pot = trim((string)($post['practical_open_to']   ?? ''));

    if (($mof !== '') !== ($mot !== '')) $errors[] = 'Please enter both start and end dates for marks together.';
    if ($mof !== '' && $mot !== '' && $mof > $mot) $errors[] = 'Marks end date must be after the start date.';
    if (($pof !== '') !== ($pot !== '')) $errors[] = 'Please enter both start and end dates for practical together.';
    if ($pof !== '' && $pot !== '' && $pof > $pot) $errors[] = 'Practical end date must be after the start date.';

    return $errors;
}

function clean_exam(array $post): array
{
    $nullif = fn(string $v) => trim($v) !== '' ? trim($v) : null;
    return [
        ':cat'    => (string)($post['category'] ?? 'o_level'),
        ':name'   => trim((string)($post['name'] ?? '')),
        ':year'   => (int)($post['year'] ?? date('Y')),
        ':term'   => $nullif((string)($post['term'] ?? '')),
        ':status' => in_array($post['status'] ?? '', ['draft','open','closed']) ? $post['status'] : 'draft',
        ':mof'    => $nullif((string)($post['marks_open_from'] ?? '')),
        ':mot'    => $nullif((string)($post['marks_open_to']   ?? '')),
        ':pof'    => $nullif((string)($post['practical_open_from'] ?? '')),
        ':pot'    => $nullif((string)($post['practical_open_to']   ?? '')),
        ':by'     => (int)(current_user()['id'] ?? 0) ?: null,
    ];
}

function exam_form_fields(array $post, array $levels_o, array $levels_a, array $checked_ids): string
{
    $p       = fn(string $k, string $d = '') => e((string)($post[$k] ?? $d));
    $year    = $p('year', (string)date('Y'));
    $cat     = $p('category', 'o_level');

    $cat_o   = $cat === 'o_level' ? ' selected' : '';
    $cat_a   = $cat === 'a_level' ? ' selected' : '';
    $disp_o  = $cat !== 'a_level' ? '' : 'display:none';
    $disp_a  = $cat === 'a_level' ? '' : 'display:none';

    $terms = [''=>'— No Term —','I'=>'Term I','II'=>'Term II','III'=>'Term III'];
    $term_opts = '';
    foreach ($terms as $v => $l) {
        $sel = $p('term') === $v ? ' selected' : '';
        $term_opts .= "<option value=\"{$v}\"{$sel}>{$l}</option>";
    }

    $statuses = ['draft'=>'Draft','open'=>'Open','closed'=>'Closed'];
    $st_opts  = '';
    foreach ($statuses as $v => $l) {
        $sel = $p('status', 'draft') === $v ? ' selected' : '';
        $st_opts .= "<option value=\"{$v}\"{$sel}>{$l}</option>";
    }

    $build_levels = function(array $levels, string $css_class, string $disp) use ($checked_ids, $post): string {
        $html = "<div class=\"{$css_class}\" style=\"{$disp}\"><div class=\"row g-2\">";
        $posted = $post['levels'] ?? $checked_ids;
        foreach ($levels as $l) {
            $chk = in_array((string)$l['id'], array_map('strval', $posted)) ? ' checked' : '';
            $html .= '<div class="col-6 col-sm-4"><div class="form-check">';
            $html .= '<input class="form-check-input" type="checkbox" name="levels[]" value="' . (int)$l['id'] . '"' . $chk . '>';
            $html .= '<label class="form-check-label">' . e($l['name']) . '</label>';
            $html .= '</div></div>';
        }
        $html .= '</div></div>';
        return $html;
    };

    $lvls_o = $build_levels($levels_o, 'levels-o', $disp_o);
    $lvls_a = $build_levels($levels_a, 'levels-a', $disp_a);

    return <<<HTML
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Level <span class="text-danger">*</span></label>
        <select class="form-select" name="category" required>
          <option value="o_level"{$cat_o}>O-Level</option>
          <option value="a_level"{$cat_a}>A-Level</option>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Exam Name <span class="text-danger">*</span></label>
        <input class="form-control" name="name" required value="{$p('name')}">
      </div>
      <div class="col-md-4">
        <label class="form-label">Year <span class="text-danger">*</span></label>
        <input class="form-control" type="number" name="year" required
               min="2000" max="2100" value="{$year}">
      </div>
      <div class="col-md-4">
        <label class="form-label">Term</label>
        <select class="form-select" name="term">{$term_opts}</select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Status</label>
        <select class="form-select" name="status">{$st_opts}</select>
      </div>

      <div class="col-12">
        <label class="form-label">Classes <span class="text-danger">*</span></label>
        {$lvls_o}
        {$lvls_a}
      </div>

      <div class="col-12"><hr class="my-1"><p class="text-muted small mb-1">Marks Entry Period (optional)</p></div>
      <div class="col-md-6">
        <label class="form-label">Start Date</label>
        <input class="form-control" type="date" name="marks_open_from" value="{$p('marks_open_from')}">
      </div>
      <div class="col-md-6">
        <label class="form-label">End Date</label>
        <input class="form-control" type="date" name="marks_open_to" value="{$p('marks_open_to')}">
      </div>

      <div class="col-12"><p class="text-muted small mb-1">Practical Period (optional)</p></div>
      <div class="col-md-6">
        <label class="form-label">Start Date</label>
        <input class="form-control" type="date" name="practical_open_from" value="{$p('practical_open_from')}">
      </div>
      <div class="col-md-6">
        <label class="form-label">End Date</label>
        <input class="form-control" type="date" name="practical_open_to" value="{$p('practical_open_to')}">
      </div>
    </div>
    HTML;
}
