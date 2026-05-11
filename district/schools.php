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
        $sid = (int)($_POST['school_id'] ?? 0);
        if ($sid > 0) {
            db()->prepare('UPDATE schools SET status = IF(status="active","inactive","active") WHERE id = :id')
               ->execute([':id' => $sid]);
            flash_set('success', 'School status has been updated.');
        }
        redirect('district/schools.php');
    }

    // ── Delete school ──────────────────────────────────────────
    if ($action === 'delete_school') {
        $sid = (int)($_POST['school_id'] ?? 0);
        if ($sid > 0) {
            // Check if school has users or students attached
            $has_users = db()->prepare('SELECT 1 FROM users WHERE school_id = :id LIMIT 1');
            $has_users->execute([':id' => $sid]);

            $has_students = db()->prepare('SELECT 1 FROM students WHERE school_id = :id LIMIT 1');
            $has_students->execute([':id' => $sid]);

            if ($has_users->fetch() || $has_students->fetch()) {
                flash_set('error', 'This school has users or students — it cannot be deleted. Deactivate it first.');
            } else {
                db()->prepare('DELETE FROM schools WHERE id = :id')->execute([':id' => $sid]);
                flash_set('success', 'School has been deleted.');
            }
        }
        redirect('district/schools.php');
    }

    // ── Add school ─────────────────────────────────────────────
    if ($action === 'add_school') {
        [$errors] = validate_school_input($_POST);

        if (empty($errors)) {
            $check = db()->prepare('SELECT id FROM schools WHERE code = :c LIMIT 1');
            $check->execute([':c' => strtoupper(trim((string)($_POST['code'] ?? '')))]);
            if ($check->fetch()) $errors[] = 'School code is already in use.';
        }

        if (empty($errors)) {
            $d = clean_school_input($_POST);
            db()->prepare(
                'INSERT INTO schools (name, code, level, ward, phone, status)
                 VALUES (:name, :code, :level, :ward, :phone, :status)'
            )->execute($d);
            flash_set('success', 'School ' . $d[':name'] . ' has been added.');
            redirect('district/schools.php');
        }
    }

    // ── Edit school ────────────────────────────────────────────
    if ($action === 'edit_school') {
        $edit_action = true;
        $sid = (int)($_POST['school_id'] ?? 0);
        [$errors] = validate_school_input($_POST);

        if ($sid <= 0) $errors[] = 'Invalid school.';

        if (empty($errors)) {
            $check = db()->prepare('SELECT id FROM schools WHERE code = :c AND id != :id LIMIT 1');
            $check->execute([':c' => strtoupper(trim((string)($_POST['code'] ?? ''))), ':id' => $sid]);
            if ($check->fetch()) $errors[] = 'School code is already in use by another school.';
        }

        if (empty($errors)) {
            $d = clean_school_input($_POST);
            $d[':id'] = $sid;
            db()->prepare(
                'UPDATE schools SET name=:name, code=:code, level=:level,
                 ward=:ward, phone=:phone, status=:status WHERE id=:id'
            )->execute($d);
            flash_set('success', 'School ' . $d[':name'] . ' has been updated.');
            redirect('district/schools.php');
        }
    }
}

// ── Fetch data ─────────────────────────────────────────────────
$schools = db()->query(
    'SELECT s.*,
            COUNT(DISTINCT u.id)  AS user_count,
            COUNT(DISTINCT st.id) AS student_count
     FROM schools s
     LEFT JOIN users    u  ON u.school_id  = s.id
     LEFT JOIN students st ON st.school_id = s.id
     GROUP BY s.id
     ORDER BY s.name'
)->fetchAll();

$schools_json = json_encode(array_column($schools, null, 'id'), JSON_HEX_TAG);

render_header('Schools');
?>

<div class="page-heading">
  <h4>Schools <span class="badge bg-secondary ms-1" style="font-size:.7rem"><?= count($schools) ?></span></h4>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAdd">
    + Add School
  </button>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>School Name</th>
          <th class="d-none d-sm-table-cell">Code</th>
          <th>Level</th>
          <th class="d-none d-md-table-cell">Ward</th>
          <th class="d-none d-md-table-cell">Phone</th>
          <th class="text-center d-none d-md-table-cell">Users</th>
          <th class="text-center">Students</th>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($schools as $i => $s): ?>
        <tr>
          <td class="text-muted small"><?= $i + 1 ?></td>
          <td class="fw-semibold"><?= e($s['name']) ?></td>
          <td class="d-none d-sm-table-cell"><span class="badge bg-light text-dark border"><?= e($s['code']) ?></span></td>
          <td class="small"><?= $s['level'] === 'o_level' ? 'O-Level' : 'O & A Level' ?></td>
          <td class="small d-none d-md-table-cell"><?= $s['ward'] ? e($s['ward']) : '<span class="text-muted">—</span>' ?></td>
          <td class="small d-none d-md-table-cell"><?= $s['phone'] ? e($s['phone']) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-center d-none d-md-table-cell">
            <span class="badge bg-secondary"><?= (int)$s['user_count'] ?></span>
          </td>
          <td class="text-center">
            <span class="badge bg-secondary"><?= (int)$s['student_count'] ?></span>
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
                <input type="hidden" name="school_id" value="<?= (int)$s['id'] ?>">
                <button class="btn btn-outline-secondary btn-sm" type="submit"
                        onclick="return confirm('Change the status of this school?')">
                  <?= $s['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                </button>
              </form>

              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_school">
                <input type="hidden" name="school_id" value="<?= (int)$s['id'] ?>">
                <button class="btn btn-outline-danger btn-sm" type="submit"
                        onclick="return confirm('Delete school <?= e(addslashes($s['name'])) ?>? This action cannot be undone.')">
                  Delete
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($schools)): ?>
          <tr><td colspan="10" class="text-center text-muted py-4">No schools found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Modal: Add School ──────────────────────────────────── -->
<div class="modal fade" id="modalAdd" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_school">
        <div class="modal-header">
          <h5 class="modal-title">Add New School</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= school_form_fields($_POST) ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Modal: Edit School ─────────────────────────────────── -->
<div class="modal fade" id="modalEdit" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" autocomplete="off" id="formEdit">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="edit_school">
        <input type="hidden" name="school_id" id="editSchoolId">
        <div class="modal-header">
          <h5 class="modal-title">Edit School</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= school_form_fields([]) ?>
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

  const SCHOOLS = <?= $schools_json ?>;

  document.querySelectorAll('.btn-edit').forEach(btn => {
    btn.addEventListener('click', () => {
      const s = SCHOOLS[btn.dataset.id];
      if (!s) return;
      const f = document.getElementById('formEdit');
      f.querySelector('#editSchoolId').value     = s.id;
      f.querySelector('[name="name"]').value     = s.name;
      f.querySelector('[name="code"]').value     = s.code;
      f.querySelector('[name="level"]').value    = s.level;
      f.querySelector('[name="ward"]').value     = s.ward  ?? '';
      f.querySelector('[name="phone"]').value    = s.phone ?? '';
      f.querySelector('[name="status"]').value   = s.status;
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
function validate_school_input(array $post): array
{
    $errors = [];
    $name  = trim((string)($post['name']  ?? ''));
    $code  = trim((string)($post['code']  ?? ''));
    $level = (string)($post['level'] ?? '');

    if ($name === '')                                     $errors[] = 'School name is required.';
    if ($code === '')                                     $errors[] = 'School code is required.';
    if (!in_array($level, ['o_level', 'both'], true))    $errors[] = 'Please select a valid level.';

    return [$errors];
}

function clean_school_input(array $post): array
{
    return [
        ':name'   => trim((string)($post['name']   ?? '')),
        ':code'   => strtoupper(trim((string)($post['code'] ?? ''))),
        ':level'  => (string)($post['level']  ?? 'o_level'),
        ':ward'   => trim((string)($post['ward']   ?? '')) ?: null,
        ':phone'  => trim((string)($post['phone']  ?? '')) ?: null,
        ':status' => in_array($post['status'] ?? '', ['active','inactive']) ? $post['status'] : 'active',
    ];
}

function school_form_fields(array $post): string
{
    $p = fn(string $k, string $d = '') => e((string)($post[$k] ?? $d));

    $level_o    = $p('level', 'o_level') === 'o_level' ? ' selected' : '';
    $level_both = $p('level', 'o_level') === 'both'    ? ' selected' : '';
    $st_active  = $p('status', 'active') === 'active'   ? ' selected' : '';
    $st_inactive= $p('status', 'active') === 'inactive' ? ' selected' : '';

    return <<<HTML
    <div class="row g-3">
      <div class="col-md-8">
        <label class="form-label">School Name <span class="text-danger">*</span></label>
        <input class="form-control" name="name" required value="{$p('name')}">
      </div>
      <div class="col-md-4">
        <label class="form-label">Code / Number <span class="text-danger">*</span></label>
        <input class="form-control text-uppercase" name="code" required
               maxlength="50" placeholder="e.g. SCHOOL001" value="{$p('code')}">
      </div>
      <div class="col-md-4">
        <label class="form-label">Level <span class="text-danger">*</span></label>
        <select class="form-select" name="level" required>
          <option value="o_level"{$level_o}>O-Level only</option>
          <option value="both"{$level_both}>O-Level &amp; A-Level</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Ward</label>
        <input class="form-control" name="ward" value="{$p('ward')}">
      </div>
      <div class="col-md-4">
        <label class="form-label">Phone</label>
        <input class="form-control" name="phone" type="tel" value="{$p('phone')}">
      </div>
      <div class="col-md-4">
        <label class="form-label">Status</label>
        <select class="form-select" name="status">
          <option value="active"{$st_active}>Active</option>
          <option value="inactive"{$st_inactive}>Inactive</option>
        </select>
      </div>
    </div>
    HTML;
}
