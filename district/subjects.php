<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

require_role(['super_admin', 'district_admin']);

$errors      = [];
$edit_action = false;

if (is_post()) {
    csrf_verify();

    $action = (string)($_POST['action'] ?? '');

    // ── Toggle status ──────────────────────────────────────────
    if ($action === 'toggle_status') {
        $id = (int)($_POST['subject_id'] ?? 0);
        if ($id > 0) {
            db()->prepare('UPDATE subjects SET status = IF(status="active","inactive","active") WHERE id = :id')
               ->execute([':id' => $id]);
            flash_set('success', 'Subject status has been updated.');
        }
        redirect('district/subjects.php');
    }

    // ── Delete subject ─────────────────────────────────────────
    if ($action === 'delete_subject') {
        $id = (int)($_POST['subject_id'] ?? 0);
        if ($id > 0) {
            $in_use = db()->prepare('SELECT 1 FROM marks WHERE subject_id = :id LIMIT 1');
            $in_use->execute([':id' => $id]);
            if ($in_use->fetch()) {
                flash_set('error', 'This subject has saved marks — it cannot be deleted. Deactivate it first.');
            } else {
                db()->prepare('DELETE FROM subjects WHERE id = :id')->execute([':id' => $id]);
                flash_set('success', 'Subject has been deleted.');
            }
        }
        redirect('district/subjects.php');
    }

    // ── Add subject ────────────────────────────────────────────
    if ($action === 'add_subject') {
        $errors = validate_subject($_POST);

        if (empty($errors)) {
            $check = db()->prepare('SELECT id FROM subjects WHERE category = :cat AND code = :code LIMIT 1');
            $check->execute([':cat' => $_POST['category'], ':code' => strtoupper(trim((string)($_POST['code'] ?? '')))]);
            if ($check->fetch()) $errors[] = 'Subject code is already in use for this level.';
        }

        if (empty($errors)) {
            $d = clean_subject($_POST);
            db()->prepare(
                'INSERT INTO subjects (category, name, code, abbr, is_principal, alevel_subject_type, has_practical, practical_max, status)
                 VALUES (:cat, :name, :code, :abbr, :is_principal, :a_type, :has_p, :p_max, :status)'
            )->execute($d);
            flash_set('success', 'Subject ' . $d[':name'] . ' has been added.');
            redirect('district/subjects.php');
        }
    }

    // ── Edit subject ───────────────────────────────────────────
    if ($action === 'edit_subject') {
        $edit_action = true;
        $id          = (int)($_POST['subject_id'] ?? 0);
        $errors      = validate_subject($_POST);

        if ($id <= 0) $errors[] = 'Invalid subject.';

        if (empty($errors)) {
            $check = db()->prepare('SELECT id FROM subjects WHERE category = :cat AND code = :code AND id != :id LIMIT 1');
            $check->execute([
                ':cat'  => $_POST['category'],
                ':code' => strtoupper(trim((string)($_POST['code'] ?? ''))),
                ':id'   => $id,
            ]);
            if ($check->fetch()) $errors[] = 'Subject code is already in use by another subject in this level.';
        }

        if (empty($errors)) {
            $d = clean_subject($_POST);
            $d[':id'] = $id;
            db()->prepare(
                'UPDATE subjects SET category=:cat, name=:name, code=:code, abbr=:abbr,
                 is_principal=:is_principal, alevel_subject_type=:a_type,
                 has_practical=:has_p, practical_max=:p_max, status=:status WHERE id=:id'
            )->execute($d);
            flash_set('success', 'Subject ' . $d[':name'] . ' has been updated.');
            redirect('district/subjects.php');
        }
    }
}

// ── Fetch subjects grouped by category ────────────────────────
$subjects = db()->query(
    'SELECT s.*, COUNT(m.id) AS marks_count
     FROM subjects s
     LEFT JOIN marks m ON m.subject_id = s.id
     GROUP BY s.id
     ORDER BY s.category, s.name'
)->fetchAll();

$grouped = ['o_level' => [], 'a_level' => []];
foreach ($subjects as &$s) {
    $s['abbr']               = $s['abbr']               ?? '';
    $s['alevel_subject_type'] = $s['alevel_subject_type'] ?? '';
    $grouped[$s['category']][] = $s;
}
unset($s);

$subjects_json = json_encode(array_column($subjects, null, 'id'), JSON_HEX_TAG);

render_header('Subjects');
?>

<div class="page-heading">
  <h4>Subjects <span class="badge bg-secondary ms-1" style="font-size:.7rem"><?= count($subjects) ?></span></h4>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAdd">
    + Add Subject
  </button>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php foreach ([['o_level', 'O-Level'], ['a_level', 'A-Level']] as [$cat, $label]): ?>
  <?php if (empty($grouped[$cat])): continue; endif; ?>
  <div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold"><?= $label ?> <span class="badge bg-secondary ms-1"><?= count($grouped[$cat]) ?></span></div>
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Subject Name</th>
            <th class="d-none d-sm-table-cell">Code</th>
            <th class="d-none d-sm-table-cell">Abbr</th>
            <?php if ($cat === 'a_level'): ?>
              <th class="text-center d-none d-sm-table-cell">Type</th>
            <?php endif; ?>
            <th class="text-center">Practical</th>
            <th class="text-center d-none d-md-table-cell">Max Marks (Practical)</th>
            <th class="text-center d-none d-md-table-cell">Saved Marks</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($grouped[$cat] as $i => $s): ?>
          <tr>
            <td class="text-muted small"><?= $i + 1 ?></td>
            <td class="fw-semibold"><?= e($s['name']) ?></td>
            <td class="d-none d-sm-table-cell"><span class="badge bg-light text-dark border"><?= e($s['code']) ?></span></td>
            <td class="d-none d-sm-table-cell">
              <?= ($s['abbr'] ?? '') !== '' ? '<span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25">'.e($s['abbr']).'</span>' : '<span class="text-muted">—</span>' ?>
            </td>
            <?php if ($cat === 'a_level'): ?>
            <td class="text-center d-none d-sm-table-cell">
              <?php
                $t = (string)($s['alevel_subject_type'] ?? '');
                if ($t === '') $t = $s['is_principal'] ? 'principal' : 'subsidiary';
              ?>
              <span class="badge bg-light text-dark border"><?= e(ucfirst($t)) ?></span>
            </td>
            <?php endif; ?>
            <td class="text-center">
              <?php if ($s['has_practical']): ?>
                <span class="badge bg-info text-dark">Yes</span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center d-none d-md-table-cell">
              <?= $s['has_practical'] ? (int)$s['practical_max'] : '<span class="text-muted">—</span>' ?>
            </td>
            <td class="text-center d-none d-md-table-cell">
              <span class="badge bg-secondary"><?= (int)$s['marks_count'] ?></span>
            </td>
            <td>
              <?php if ($s['status'] === 'active'): ?>
                <span class="badge bg-success">Active</span>
              <?php else: ?>
                <span class="badge bg-danger">Inactive</span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <div class="d-flex gap-1 justify-content-end">
                <button class="btn btn-outline-primary btn-sm btn-edit"
                        data-id="<?= (int)$s['id'] ?>"
                        data-bs-toggle="modal" data-bs-target="#modalEdit">
                  Edit
                </button>

                <form method="post" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle_status">
                  <input type="hidden" name="subject_id" value="<?= (int)$s['id'] ?>">
                  <button class="btn btn-outline-secondary btn-sm" type="submit"
                          onclick="return confirm('Change the status of this subject?')">
                    <?= $s['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                  </button>
                </form>

                <form method="post" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_subject">
                  <input type="hidden" name="subject_id" value="<?= (int)$s['id'] ?>">
                  <button class="btn btn-outline-danger btn-sm" type="submit"
                          onclick="return confirm('Delete subject <?= e(addslashes($s['name'])) ?>? This action cannot be undone.')">
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
<?php endforeach; ?>

<?php if (empty($subjects)): ?>
  <div class="text-center text-muted py-5">No subjects yet. Add the first subject.</div>
<?php endif; ?>

<!-- ── Modal: Add Subject ─────────────────────────────────── -->
<div class="modal fade" id="modalAdd" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_subject">
        <div class="modal-header">
          <h5 class="modal-title">Add New Subject</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= subject_form_fields($_POST) ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Modal: Edit Subject ────────────────────────────────── -->
<div class="modal fade" id="modalEdit" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" autocomplete="off" id="formEdit">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="edit_subject">
        <input type="hidden" name="subject_id" id="editSubjectId">
        <div class="modal-header">
          <h5 class="modal-title">Edit Subject</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= subject_form_fields([]) ?>
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

  const SUBJECTS = <?= $subjects_json ?>;

  // Show/hide practical_max based on has_practical checkbox
  function bindPractical(formEl) {
    const chk = formEl.querySelector('[name="has_practical"]');
    const row = formEl.querySelector('.practical-max-row');
    if (!chk || !row) return;
    function toggle() {
      row.style.display = chk.checked ? '' : 'none';
      const inp = row.querySelector('input');
      if (inp) {
        inp.required = chk.checked;
        inp.disabled = !chk.checked;
      }
    }
    chk.addEventListener('change', toggle);
    toggle();
  }

  bindPractical(document.getElementById('modalAdd'));
  bindPractical(document.getElementById('modalEdit'));

  // Show/hide A-Level subject type based on category
  function bindAlevelType(modalEl) {
    const formEl = modalEl.querySelector('form');
    if (!formEl) return;
    const catSel = formEl.querySelector('[name="category"]');
    const row = formEl.querySelector('.alevel-type-row');
    const typeSel = formEl.querySelector('[name="alevel_subject_type"]');
    if (!catSel || !row || !typeSel) return;

    function toggle() {
      const isA = catSel.value === 'a_level';
      row.style.display = isA ? '' : 'none';
      typeSel.required = isA;
      typeSel.disabled = !isA;
      if (!isA) typeSel.value = '';
    }

    catSel.addEventListener('change', toggle);
    toggle();
  }

  bindAlevelType(document.getElementById('modalAdd'));
  bindAlevelType(document.getElementById('modalEdit'));

  // Populate edit modal
  document.querySelectorAll('.btn-edit').forEach(btn => {
    btn.addEventListener('click', () => {
      const s = SUBJECTS[btn.dataset.id];
      if (!s) return;
      const f = document.getElementById('formEdit');
      f.querySelector('#editSubjectId').value          = s.id;
      const catSel = f.querySelector('[name="category"]');
      catSel.value = s.category;
      catSel.dispatchEvent(new Event('change'));
      f.querySelector('[name="name"]').value           = s.name;
      f.querySelector('[name="code"]').value           = s.code;
      f.querySelector('[name="abbr"]').value           = s.abbr ?? '';
      f.querySelector('[name="status"]').value         = s.status;

      const aTypeSel = f.querySelector('[name="alevel_subject_type"]');
      if (aTypeSel) {
        aTypeSel.value = s.alevel_subject_type ?? '';
      }

      const chk = f.querySelector('[name="has_practical"]');
      chk.checked = s.has_practical == 1;
      chk.dispatchEvent(new Event('change'));

      const maxInp = f.querySelector('[name="practical_max"]');
      if (maxInp) maxInp.value = s.practical_max ?? 0;
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
function validate_subject(array $post): array
{
    $errors = [];
    $name     = trim((string)($post['name']     ?? ''));
    $code     = trim((string)($post['code']     ?? ''));
    $category = (string)($post['category'] ?? '');
    $a_type   = (string)($post['alevel_subject_type'] ?? '');
    $has_p    = isset($post['has_practical']);
    $p_max    = (int)($post['practical_max'] ?? 0);

    if ($name === '')                                          $errors[] = 'Subject name is required.';
    if ($code === '')                                          $errors[] = 'Subject code is required.';
    if (!in_array($category, ['o_level', 'a_level'], true))   $errors[] = 'Please select a valid level.';
    if ($category === 'a_level' && !in_array($a_type, ['principal','subsidiary','additional'], true)) $errors[] = 'Please select A-Level subject type.';
    if ($has_p && $p_max <= 0)                                $errors[] = 'Enter the maximum practical marks (greater than 0).';

    return $errors;
}

function clean_subject(array $post): array
{
    $has_p = isset($post['has_practical']) ? 1 : 0;
    $cat = (string)($post['category'] ?? 'o_level');
    $a_type = $cat === 'a_level' ? (string)($post['alevel_subject_type'] ?? '') : '';
    $is_principal = ($cat === 'a_level' && $a_type === 'principal') ? 1 : 0;
    $abbr = strtoupper(trim((string)($post['abbr'] ?? '')));
    return [
        ':cat'    => $cat,
        ':name'   => trim((string)($post['name'] ?? '')),
        ':code'   => strtoupper(trim((string)($post['code'] ?? ''))),
        ':abbr'   => $abbr !== '' ? $abbr : null,
        ':is_principal' => $is_principal,
        ':a_type' => ($cat === 'a_level' && in_array($a_type, ['principal','subsidiary','additional'], true)) ? $a_type : null,
        ':has_p'  => $has_p,
        ':p_max'  => $has_p ? max(0, (int)($post['practical_max'] ?? 0)) : 0,
        ':status' => in_array($post['status'] ?? '', ['active','inactive']) ? $post['status'] : 'active',
    ];
}

function subject_form_fields(array $post): string
{
    $p = fn(string $k, string $d = '') => e((string)($post[$k] ?? $d));

    $cat_o  = $p('category', 'o_level') === 'o_level' ? ' selected' : '';
    $cat_a  = $p('category', 'o_level') === 'a_level' ? ' selected' : '';
    $a_type = (string)($post['alevel_subject_type'] ?? '');
    $at_pr  = $a_type === 'principal'  ? ' selected' : '';
    $at_su  = $a_type === 'subsidiary' ? ' selected' : '';
    $at_ad  = $a_type === 'additional' ? ' selected' : '';
    $a_disp = ($p('category', 'o_level') === 'a_level') ? '' : 'display:none';
    $has_p  = !empty($post['has_practical']) ? ' checked' : '';
    $p_max  = e((string)((int)($post['practical_max'] ?? 40)));
    $p_disp = !empty($post['has_practical']) ? '' : 'display:none';
    $st_act = $p('status', 'active') === 'active'   ? ' selected' : '';
    $st_in  = $p('status', 'active') === 'inactive' ? ' selected' : '';

    return <<<HTML
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label">Level <span class="text-danger">*</span></label>
        <select class="form-select" name="category" required>
          <option value="o_level"{$cat_o}>O-Level</option>
          <option value="a_level"{$cat_a}>A-Level</option>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label">Subject Name <span class="text-danger">*</span></label>
        <input class="form-control" name="name" required value="{$p('name')}">
      </div>
      <div class="col-12">
        <label class="form-label">Code <span class="text-danger">*</span></label>
        <input class="form-control text-uppercase" name="code" required
               maxlength="30" placeholder="e.g. 031, 022" value="{$p('code')}">
      </div>
      <div class="col-12">
        <label class="form-label">Abbreviation (Kifupi)</label>
        <input class="form-control text-uppercase" name="abbr"
               maxlength="20" placeholder="e.g. PHY, KISW, CHEM" value="{$p('abbr')}">
        <div class="form-text">Kifupi kinachoonyeshwa kwenye matokeo. Ikiachwa wazi, code itatumika.</div>
      </div>

      <div class="col-12 alevel-type-row" style="{$a_disp}">
        <label class="form-label">A-Level Subject Type <span class="text-danger">*</span></label>
        <select class="form-select" name="alevel_subject_type">
          <option value="">-- Select --</option>
          <option value="principal"{$at_pr}>Principal</option>
          <option value="subsidiary"{$at_su}>Subsidiary</option>
          <option value="additional"{$at_ad}>Additional</option>
        </select>
      </div>

      <div class="col-12">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="has_practical" id="hasPractical"{$has_p}>
          <label class="form-check-label" for="hasPractical">This subject has Practical</label>
        </div>
      </div>
      <div class="col-12 practical-max-row" style="{$p_disp}">
        <label class="form-label">Maximum Practical Marks <span class="text-danger">*</span></label>
        <input class="form-control" type="number" name="practical_max" min="1" max="100" value="{$p_max}">
      </div>
      <div class="col-12">
        <label class="form-label">Status</label>
        <select class="form-select" name="status">
          <option value="active"{$st_act}>Active</option>
          <option value="inactive"{$st_in}>Inactive</option>
        </select>
      </div>
    </div>
    HTML;
}
