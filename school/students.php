<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

require_role(['super_admin', 'district_admin', 'headmaster']);

$user    = current_user();
$role    = $user['role'];
$self_school = (int)($user['school_id'] ?? 0);

// Headmaster sees only their school
$fixed_school = ($role === 'headmaster') ? $self_school : 0;

$errors      = [];
$edit_action = false;
$csv_results = null;

// ── Reference data ─────────────────────────────────────────────
$schools    = ($fixed_school === 0)
    ? db()->query('SELECT id, name FROM schools WHERE status="active" ORDER BY name')->fetchAll()
    : [];
$all_levels = db()->query('SELECT * FROM levels ORDER BY id')->fetchAll();

// level name → id map (case-insensitive)
$level_map = [];
foreach ($all_levels as $lv) {
    $level_map[strtolower($lv['name'])] = (int)$lv['id'];
}

// ── Template download ──────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    $school_col = ($fixed_school === 0) ? '"school_name",' : '';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="template_wanafunzi.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel
    fputcsv($out, array_filter([
        $fixed_school === 0 ? 'school_name' : null,
        'admission_no', 'full_name', 'level', 'sex', 'status'
    ]));
    // Example rows
    $levels_ex = array_column($all_levels, 'name');
    $ex_school = $fixed_school === 0 ? ['School Name'] : [];
    fputcsv($out, array_merge($ex_school, ['S0001', 'Student Full Name', $levels_ex[0] ?? 'Form 1', 'M', 'active']));
    fputcsv($out, array_merge($ex_school, ['S0002', 'Another Name',      $levels_ex[0] ?? 'Form 1', 'F', 'active']));
    // Hint rows
    fputcsv($out, []);
    fputcsv($out, ['# Instructions:']);
    fputcsv($out, ['# level: ' . implode(' | ', $levels_ex)]);
    fputcsv($out, ['# sex: M or F (or leave blank)']);
    fputcsv($out, ['# status: active or inactive (default: active)']);
    fclose($out);
    exit;
}

// ── Filter ─────────────────────────────────────────────────────
$filter_school = $fixed_school ?: (int)($_GET['school_id'] ?? 0);
$filter_level  = (int)($_GET['level_id']  ?? 0);
$filter_status = ($_GET['status'] ?? 'active');
if (!in_array($filter_status, ['active','inactive','all'], true)) $filter_status = 'active';

// ── POST ───────────────────────────────────────────────────────
if (is_post()) {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'toggle_status') {
        $id = (int)($_POST['student_id'] ?? 0);
        if ($id > 0) {
            db()->prepare('UPDATE students SET status = IF(status="active","inactive","active") WHERE id=:id')
               ->execute([':id' => $id]);
            flash_set('success', 'Student status has been updated.');
        }
        redirect(current_url_with_filters($filter_school, $filter_level, $filter_status));
    }

    if ($action === 'delete_student') {
        $id = (int)($_POST['student_id'] ?? 0);
        if ($id > 0) {
            $has_marks = db()->prepare('SELECT 1 FROM marks WHERE student_id=:id LIMIT 1');
            $has_marks->execute([':id' => $id]);
            if ($has_marks->fetch()) {
                flash_set('error', 'This student has saved marks — cannot be deleted. Deactivate first.');
            } else {
                db()->prepare('DELETE FROM students WHERE id=:id')->execute([':id' => $id]);
                flash_set('success', 'Student deleted.');
            }
        }
        redirect(current_url_with_filters($filter_school, $filter_level, $filter_status));
    }

    if ($action === 'add_student') {
        $errors = validate_add_student($_POST, $fixed_school);

        if (empty($errors)) {
            $d      = clean_add_student($_POST, $fixed_school);
            $sc_row = db()->prepare('SELECT code FROM schools WHERE id=:id LIMIT 1');
            $sc_row->execute([':id' => $d[':school_id']]);
            $sc_code = (string)($sc_row->fetchColumn() ?: 'S');

            $pdo = db();
            $pdo->beginTransaction();
            try {
                $adm_no = build_admission_no($pdo, $d[':school_id'], $sc_code, (int)date('Y'));
                $d[':admission_no'] = $adm_no;
                $pdo->prepare(
                    'INSERT INTO students (school_id, level_id, admission_no, full_name, sex, status)
                     VALUES (:school_id, :level_id, :admission_no, :full_name, :sex, :status)'
                )->execute($d);
                $new_student_id = (int)$pdo->lastInsertId();
                $pdo->commit();
            } catch (Throwable $ex) {
                $pdo->rollBack();
                $errors[] = 'Failed to save student. Please try again.';
                error_log('add_student error: ' . $ex->getMessage());
            }

            if (empty($errors)) {
                if ($role !== 'headmaster') {
                    $hm_q = db()->prepare('SELECT id FROM users WHERE school_id=:sid AND role="headmaster" AND status="active" LIMIT 1');
                    $hm_q->execute([':sid' => $d[':school_id']]);
                    $hm_row = $hm_q->fetch();
                    if ($hm_row) {
                        notify_send((int)$hm_row['id'], 'student_registered', 'New Student Registered',
                            'Student ' . $d[':full_name'] . ' (Reg: ' . $d[':admission_no'] . ') has been registered at your school by ' . $user['full_name'] . '.',
                            $new_student_id, 'student');
                    }
                }
                flash_set('success', 'Student ' . $d[':full_name'] . ' added. Reg: ' . $d[':admission_no']);
                redirect(current_url_with_filters($filter_school, $filter_level, $filter_status));
            }
        }
    }

    // ── Bulk register ──────────────────────────────────────────
    if ($action === 'bulk_add') {
        $bulk_school = $fixed_school ?: (int)($_POST['bulk_school_id'] ?? 0);
        $bulk_year   = (int)($_POST['bulk_year'] ?? date('Y'));

        if (!$bulk_school) {
            flash_set('error', 'Tafadhali chagua shule.');
            redirect(current_url_with_filters($filter_school, $filter_level, $filter_status));
        }
        if ($bulk_year < 2000 || $bulk_year > 2100) $bulk_year = (int)date('Y');

        $sc_q = db()->prepare('SELECT code FROM schools WHERE id=:id LIMIT 1');
        $sc_q->execute([':id' => $bulk_school]);
        $sc_code = (string)($sc_q->fetchColumn() ?: 'S');

        $names   = (array)($_POST['bname']   ?? []);
        $levels  = (array)($_POST['blevel']  ?? []);
        $sexes   = (array)($_POST['bsex']    ?? []);

        $pdo = db();
        $pdo->beginTransaction();
        $inserted = 0;
        $bulk_errors = [];

        try {
            $ins = $pdo->prepare(
                'INSERT INTO students (school_id, level_id, admission_no, full_name, sex, status)
                 VALUES (:school_id, :level_id, :admission_no, :full_name, :sex, "active")'
            );

            foreach ($names as $i => $raw_name) {
                $name     = trim((string)$raw_name);
                $level_id = (int)($levels[$i] ?? 0);
                $sex_raw  = strtoupper(trim((string)($sexes[$i] ?? '')));
                $sex      = in_array($sex_raw, ['M','F'], true) ? $sex_raw : null;

                if ($name === '' && $level_id === 0) continue; // skip empty rows
                if ($name === '')   { $bulk_errors[] = 'Safu ' . ($i+1) . ': Jina linahitajika.'; continue; }
                if (!$level_id)     { $bulk_errors[] = 'Safu ' . ($i+1) . ': Chagua darasa.'; continue; }

                $adm_no = build_admission_no($pdo, $bulk_school, $sc_code, $bulk_year);
                $ins->execute([
                    ':school_id'    => $bulk_school,
                    ':level_id'     => $level_id,
                    ':admission_no' => $adm_no,
                    ':full_name'    => $name,
                    ':sex'          => $sex,
                ]);
                $inserted++;
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $bulk_errors[] = 'Hitilafu ya hifadhidata. Tafadhali jaribu tena.';
            error_log('bulk_add error: ' . $ex->getMessage());
        }

        if ($inserted > 0)      flash_set('success', "Wanafunzi {$inserted} wamesajiliwa.");
        if (!empty($bulk_errors)) flash_set('error', implode(' | ', $bulk_errors));
        redirect(current_url_with_filters($filter_school, $filter_level, $filter_status));
    }

    if ($action === 'import_csv') {
        $upload = $_FILES['csv_file'] ?? null;
        $imp_school = $fixed_school ?: (int)($_POST['import_school_id'] ?? 0);

        if (!$imp_school) {
            flash_set('error', 'Please select a school before importing CSV.');
            redirect(current_url_with_filters($filter_school, $filter_level, $filter_status));
        }

        if (!$upload || $upload['error'] !== UPLOAD_ERR_OK) {
            flash_set('error', 'Please select a CSV file.');
            redirect(current_url_with_filters($filter_school, $filter_level, $filter_status));
        }

        $ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            flash_set('error', 'File must be a CSV.');
            redirect(current_url_with_filters($filter_school, $filter_level, $filter_status));
        }

        $handle = fopen($upload['tmp_name'], 'r');
        // Strip UTF-8 BOM if present
        $bom = fread($handle, 3);
        if ($bom !== chr(0xEF).chr(0xBB).chr(0xBF)) rewind($handle);

        $header = fgetcsv($handle);
        if (!$header) {
            flash_set('error', 'The CSV file is empty or invalid.');
            fclose($handle);
            redirect(current_url_with_filters($filter_school, $filter_level, $filter_status));
        }

        // Normalize header keys
        $header = array_map(fn($h) => strtolower(trim((string)$h)), $header);

        $col = fn(string $key) => array_search($key, $header, true);
        $has_school_col = $col('school_name') !== false;

        $school_map = [];
        if ($has_school_col) {
            foreach (db()->query('SELECT id, name FROM schools WHERE status="active"')->fetchAll() as $sc) {
                $school_map[strtolower(trim($sc['name']))] = (int)$sc['id'];
            }
        }

        $insert = db()->prepare(
            'INSERT INTO students (school_id, level_id, admission_no, full_name, sex, status)
             VALUES (:school_id, :level_id, :admission_no, :full_name, :sex, :status)
             ON DUPLICATE KEY UPDATE full_name=VALUES(full_name), sex=VALUES(sex), status=VALUES(status)'
        );

        $ok = 0; $skipped = []; $row_num = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $row_num++;
            // Skip comment/empty rows
            if (empty(array_filter($row)) || str_starts_with(trim($row[0] ?? ''), '#')) continue;

            $get = function(string $key) use ($row, $col): string {
                $i = $col($key);
                return $i !== false ? trim((string)($row[$i] ?? '')) : '';
            };

            $adm      = strtoupper($get('admission_no'));
            $name     = $get('full_name');
            $level_nm = strtolower($get('level'));
            $sex_raw  = strtoupper($get('sex'));
            $status   = strtolower($get('status')) ?: 'active';
            $sex      = in_array($sex_raw, ['M','F'], true) ? $sex_raw : null;

            $row_school = $imp_school;
            if ($has_school_col) {
                $sc_name = strtolower($get('school_name'));
                $row_school = $school_map[$sc_name] ?? 0;
            }

            $level_id = $level_map[$level_nm] ?? 0;

            $row_errors = [];
            if ($adm   === '') $row_errors[] = 'admission_no missing';
            if ($name  === '') $row_errors[] = 'full_name missing';
            if (!$level_id)    $row_errors[] = "level '{$get('level')}' not recognised";
            if (!$row_school)  $row_errors[] = 'school not recognised';
            if (!in_array($status, ['active','inactive'], true)) $status = 'active';

            if ($row_errors) {
                $skipped[] = "Row {$row_num}: " . implode(', ', $row_errors);
                continue;
            }

            $insert->execute([
                ':school_id'    => $row_school,
                ':level_id'     => $level_id,
                ':admission_no' => $adm,
                ':full_name'    => $name,
                ':sex'          => $sex,
                ':status'       => $status,
            ]);
            $ok++;
        }
        fclose($handle);

        $csv_results = ['ok' => $ok, 'skipped' => $skipped];
        if ($ok > 0) {
            flash_set('success', "{$ok} student(s) imported/updated from CSV.");
        }
        if ($skipped) {
            flash_set('error', count($skipped) . ' row(s) skipped. See details below.');
        }
        // Don't redirect — show results on page
    }

    if ($action === 'edit_student') {
        $edit_action = true;
        $sid         = (int)($_POST['student_id'] ?? 0);
        $errors      = validate_student($_POST, $fixed_school);
        if ($sid <= 0) $errors[] = 'Invalid student.';

        if (empty($errors)) {
            $d = clean_student($_POST, $fixed_school);
            $dup = db()->prepare('SELECT id FROM students WHERE school_id=:s AND admission_no=:a AND id!=:id LIMIT 1');
            $dup->execute([':s' => $d[':school_id'], ':a' => $d[':admission_no'], ':id' => $sid]);
            if ($dup->fetch()) $errors[] = 'Registration number is already in use by another student.';
        }

        if (empty($errors)) {
            $d = clean_student($_POST, $fixed_school);
            $d[':id'] = $sid;
            db()->prepare(
                'UPDATE students SET school_id=:school_id, level_id=:level_id, admission_no=:admission_no,
                 full_name=:full_name, sex=:sex, status=:status WHERE id=:id'
            )->execute($d);
            flash_set('success', 'Student ' . $d[':full_name'] . ' updated.');
            redirect(current_url_with_filters($filter_school, $filter_level, $filter_status));
        }
    }
}

// ── Build query ────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];

if ($filter_school > 0) {
    $where[]  = 'st.school_id = :school_id';
    $params[':school_id'] = $filter_school;
} elseif ($fixed_school > 0) {
    $where[]  = 'st.school_id = :school_id';
    $params[':school_id'] = $fixed_school;
}

if ($filter_level > 0) {
    $where[]  = 'st.level_id = :level_id';
    $params[':level_id'] = $filter_level;
}

if ($filter_status !== 'all') {
    $where[]  = 'st.status = :status';
    $params[':status'] = $filter_status;
}

$sql = 'SELECT st.*, lv.name AS level_name, sc.name AS school_name
        FROM students st
        JOIN levels  lv ON lv.id = st.level_id
        JOIN schools sc ON sc.id = st.school_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY lv.id, st.full_name';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Group by level for display
$grouped = [];
foreach ($students as $s) {
    $grouped[$s['level_name']][] = $s;
}

$students_json = json_encode(array_column($students, null, 'id'), JSON_HEX_TAG);

// Current school name for heading
$school_name = '';
if ($filter_school > 0) {
    foreach (array_merge($schools, [['id' => $self_school, 'name' => '']]) as $sc) {
        if ((int)$sc['id'] === $filter_school) { $school_name = $sc['name']; break; }
    }
    if (!$school_name) {
        $r = db()->prepare('SELECT name FROM schools WHERE id=:id LIMIT 1');
        $r->execute([':id' => $filter_school]);
        $school_name = (string)($r->fetchColumn() ?: '');
    }
}

render_header('Students');
?>

<!-- ── Filters ────────────────────────────────────────────────── -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <?php if ($fixed_school === 0): ?>
      <div class="col-12 col-md-4">
        <label class="form-label small mb-1">School</label>
        <select class="form-select form-select-sm" name="school_id" onchange="this.form.submit()">
          <option value="">— All Schools —</option>
          <?php foreach ($schools as $sc): ?>
            <option value="<?= (int)$sc['id'] ?>" <?= $filter_school === (int)$sc['id'] ? 'selected' : '' ?>>
              <?= e($sc['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-6 col-md-3">
        <label class="form-label small mb-1">Class</label>
        <select class="form-select form-select-sm" name="level_id" onchange="this.form.submit()">
          <option value="">— All Classes —</option>
          <?php foreach ($all_levels as $lv): ?>
            <option value="<?= (int)$lv['id'] ?>" <?= $filter_level === (int)$lv['id'] ? 'selected' : '' ?>>
              <?= e($lv['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">Status</label>
        <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
          <option value="active"   <?= $filter_status === 'active'   ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
          <option value="all"      <?= $filter_status === 'all'      ? 'selected' : '' ?>>All</option>
        </select>
      </div>
      <?php if ($fixed_school === 0): ?>
        <input type="hidden" name="school_id" value="<?= $filter_school ?: '' ?>">
      <?php endif; ?>
      <div class="col-auto">
        <a href="<?= e(url('school/students.php')) ?>" class="btn btn-outline-secondary btn-sm">Clear</a>
      </div>
    </form>
  </div>
</div>

<!-- ── Heading ─────────────────────────────────────────────────── -->
<div class="page-heading">
  <h4>
    Students
    <?php if ($school_name): ?><span class="text-muted fw-normal fs-6">— <?= e($school_name) ?></span><?php endif; ?>
    <span class="badge bg-secondary ms-2" style="font-size:.7rem"><?= count($students) ?></span>
  </h4>
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= e(url('school/students.php?action=download_template' . ($fixed_school === 0 ? '' : ''))) ?>"
       class="btn btn-outline-secondary btn-sm">
      ↓ Template CSV
    </a>
    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalImport">
      ↑ Import CSV
    </button>
    <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalBulk">
      + Sajili Wengi
    </button>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAdd">
      + Mwanafunzi Mmoja
    </button>
  </div>
</div>

<?php if ($csv_results !== null && !empty($csv_results['skipped'])): ?>
<div class="card mb-3 border-warning">
  <div class="card-header text-warning fw-semibold">Skipped Rows</div>
  <div class="card-body py-2">
    <ul class="mb-0 small">
      <?php foreach ($csv_results['skipped'] as $msg): ?>
        <li><?= e($msg) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<!-- ── Table (grouped by level) ────────────────────────────────── -->
<?php if (empty($students)): ?>
  <div class="text-center text-muted py-5">No students match the selected filters.</div>
<?php else: ?>

<?php foreach ($grouped as $level_name => $rows): ?>
<div class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><?= e($level_name) ?></span>
    <span class="badge bg-secondary"><?= count($rows) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Registration No.</th>
          <th>Full Name</th>
          <th class="d-none d-sm-table-cell">Gender</th>
          <?php if ($fixed_school === 0): ?><th class="d-none d-md-table-cell">School</th><?php endif; ?>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i => $st): ?>
        <tr>
          <td class="text-muted small"><?= $i + 1 ?></td>
          <td><span class="badge bg-light text-dark border"><?= e($st['admission_no']) ?></span></td>
          <td class="fw-semibold"><?= e($st['full_name']) ?></td>
          <td class="d-none d-sm-table-cell">
            <?php if ($st['sex'] === 'M'): ?>
              <span class="badge bg-primary bg-opacity-10 text-primary">Male</span>
            <?php elseif ($st['sex'] === 'F'): ?>
              <span class="badge bg-danger bg-opacity-10 text-danger">Female</span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <?php if ($fixed_school === 0): ?>
          <td class="small d-none d-md-table-cell"><?= e($st['school_name']) ?></td>
          <?php endif; ?>
          <td>
            <span class="badge <?= $st['status'] === 'active' ? 'bg-success' : 'bg-danger' ?>">
              <?= $st['status'] === 'active' ? 'Active' : 'Inactive' ?>
            </span>
          </td>
          <td class="text-end">
            <div class="d-flex gap-1 justify-content-end">
              <button class="btn btn-outline-primary btn-sm btn-edit"
                      data-id="<?= (int)$st['id'] ?>"
                      data-bs-toggle="modal" data-bs-target="#modalEdit">
                Edit
              </button>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action"     value="toggle_status">
                <input type="hidden" name="student_id" value="<?= (int)$st['id'] ?>">
                <button class="btn btn-outline-secondary btn-sm" type="submit"
                        onclick="return confirm('Change this student\'s status?')">
                  <?= $st['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                </button>
              </form>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action"     value="delete_student">
                <input type="hidden" name="student_id" value="<?= (int)$st['id'] ?>">
                <button class="btn btn-outline-danger btn-sm" type="submit"
                        onclick="return confirm('Delete student <?= e(addslashes($st['full_name'])) ?>?')">
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
<?php endif; ?>

<!-- ── Modal: Sajili Wengi ───────────────────────────────── -->
<div class="modal fade" id="modalBulk" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form method="post" autocomplete="off" id="formBulk">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="bulk_add">
        <div class="modal-header">
          <h5 class="modal-title">Sajili Wanafunzi Wengi</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">

          <!-- School + Year selectors -->
          <div class="row g-2 mb-3">
            <?php if ($fixed_school === 0): ?>
            <div class="col-12 col-md-5">
              <label class="form-label small fw-semibold mb-1">Shule <span class="text-danger">*</span></label>
              <select class="form-select form-select-sm" name="bulk_school_id" required>
                <option value="">— Chagua Shule —</option>
                <?php foreach ($schools as $sc): ?>
                  <option value="<?= (int)$sc['id'] ?>"><?= e($sc['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php else: ?>
            <input type="hidden" name="bulk_school_id" value="<?= $fixed_school ?>">
            <?php endif; ?>
            <div class="col-6 col-md-3">
              <label class="form-label small fw-semibold mb-1">Mwaka</label>
              <input type="number" class="form-control form-control-sm" name="bulk_year"
                     value="<?= (int)date('Y') ?>" min="2000" max="2100">
            </div>
            <div class="col-6 col-md-4 d-flex align-items-end">
              <div class="alert alert-info py-1 px-2 mb-0 small w-100">
                Nambari za usajili zitazalishwa moja kwa moja.
              </div>
            </div>
          </div>

          <!-- Bulk table -->
          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-1" id="bulkTable">
              <thead class="table-light">
                <tr>
                  <th style="width:32px" class="text-center text-muted">#</th>
                  <th>Jina Kamili <span class="text-danger">*</span></th>
                  <th style="width:160px">Darasa <span class="text-danger">*</span></th>
                  <th style="width:110px">Jinsia</th>
                  <th style="width:36px"></th>
                </tr>
              </thead>
              <tbody id="bulkRows">
                <?php
                $level_opts_bulk = '<option value="">— Chagua —</option>';
                foreach ($all_levels as $lv) {
                    $level_opts_bulk .= '<option value="' . (int)$lv['id'] . '">' . e($lv['name']) . '</option>';
                }
                for ($ri = 0; $ri < 5; $ri++): ?>
                <tr>
                  <td class="text-center text-muted small row-num"><?= $ri + 1 ?></td>
                  <td><input type="text" class="form-control form-control-sm" name="bname[]" placeholder="Jina la mwanafunzi"></td>
                  <td>
                    <select class="form-select form-select-sm" name="blevel[]">
                      <?= $level_opts_bulk ?>
                    </select>
                  </td>
                  <td>
                    <select class="form-select form-select-sm" name="bsex[]">
                      <option value="">—</option>
                      <option value="M">M — Kiume</option>
                      <option value="F">F — Kike</option>
                    </select>
                  </td>
                  <td class="text-center">
                    <button type="button" class="btn btn-outline-danger btn-sm btn-remove-row py-0 px-1" title="Ondoa safu">×</button>
                  </td>
                </tr>
                <?php endfor; ?>
              </tbody>
            </table>
          </div>

          <div class="d-flex gap-2 mt-1">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnAddRow">
              + Ongeza Safu
            </button>
            <span class="text-muted small align-self-center" id="rowCount">Safu 5</span>
          </div>

        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Funga</button>
          <button type="submit" class="btn btn-success">Hifadhi Wote</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Modal: Add ────────────────────────────────────────── -->
<div class="modal fade" id="modalAdd" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_student">
        <div class="modal-header">
          <h5 class="modal-title">Add Student</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= student_form_fields($_POST, $schools, $all_levels, $fixed_school, $filter_school) ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Modal: Edit ────────────────────────────────────────── -->
<div class="modal fade" id="modalEdit" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" autocomplete="off" id="formEdit">
        <?= csrf_field() ?>
        <input type="hidden" name="action"     value="edit_student">
        <input type="hidden" name="student_id" id="editStudentId">
        <div class="modal-header">
          <h5 class="modal-title">Edit Student</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= student_form_fields([], $schools, $all_levels, $fixed_school, $filter_school) ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Modal: Import CSV ────────────────────────────────────── -->
<div class="modal fade" id="modalImport" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import_csv">
        <div class="modal-header">
          <h5 class="modal-title">Import Students via CSV</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">

            <?php if ($fixed_school === 0): ?>
            <div class="col-12">
              <label class="form-label">School <span class="text-danger">*</span></label>
              <select class="form-select" name="import_school_id">
                <option value="">— Select School (if CSV has no school_name column) —</option>
                <?php foreach ($schools as $sc): ?>
                  <option value="<?= (int)$sc['id'] ?>"><?= e($sc['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">If the CSV has a <code>school_name</code> column, the school will be read from there.</div>
            </div>
            <?php else: ?>
            <input type="hidden" name="import_school_id" value="<?= $fixed_school ?>">
            <?php endif; ?>

            <div class="col-12">
              <label class="form-label">CSV File <span class="text-danger">*</span></label>
              <input class="form-control" type="file" name="csv_file" accept=".csv,.txt" required>
            </div>

            <div class="col-12">
              <div class="alert alert-info mb-0 small p-2">
                <strong>CSV Format:</strong><br>
                Required columns: <code>admission_no</code>, <code>full_name</code>, <code>level</code><br>
                Optional columns: <code>sex</code> (M/F), <code>status</code> (active/inactive)<br>
                <?php if ($fixed_school === 0): ?>
                To import multiple schools: add a <code>school_name</code> column<br>
                <?php endif; ?>
                Existing records will be updated by admission_no.<br>
                <a href="<?= e(url('school/students.php?action=download_template')) ?>" class="fw-semibold">
                  ↓ Download Template
                </a>
              </div>
            </div>

          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Import</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(() => {
  'use strict';
  const STUDENTS = <?= $students_json ?>;

  document.querySelectorAll('.btn-edit').forEach(btn => {
    btn.addEventListener('click', () => {
      const s = STUDENTS[btn.dataset.id];
      if (!s) return;
      const f = document.getElementById('formEdit');
      f.querySelector('#editStudentId').value           = s.id;
      f.querySelector('[name="full_name"]').value       = s.full_name;
      f.querySelector('[name="admission_no"]').value    = s.admission_no;
      f.querySelector('[name="level_id"]').value        = s.level_id;
      f.querySelector('[name="sex"]').value             = s.sex ?? '';
      f.querySelector('[name="status"]').value          = s.status;
      const sc = f.querySelector('[name="school_id"]');
      if (sc) sc.value = s.school_id;
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
function current_url_with_filters(int $school, int $level, string $status): string
{
    $q = [];
    if ($school) $q[] = 'school_id=' . $school;
    if ($level)  $q[] = 'level_id='  . $level;
    if ($status && $status !== 'active') $q[] = 'status=' . urlencode($status);
    return 'school/students.php' . ($q ? '?' . implode('&', $q) : '');
}

function validate_student(array $post, int $fixed_school): array
{
    $errors = [];
    $name  = trim((string)($post['full_name']    ?? ''));
    $adm   = trim((string)($post['admission_no'] ?? ''));
    $level = (int)($post['level_id'] ?? 0);
    $school = $fixed_school ?: (int)($post['school_id'] ?? 0);
    $sex   = (string)($post['sex'] ?? '');

    if ($name === '')  $errors[] = 'Full name is required.';
    if ($adm  === '')  $errors[] = 'Registration number is required.';
    if ($level === 0)  $errors[] = 'Please select a class.';
    if ($school === 0) $errors[] = 'Please select a school.';
    if ($sex !== '' && !in_array($sex, ['M','F'], true)) $errors[] = 'Invalid gender.';

    return $errors;
}

function clean_student(array $post, int $fixed_school): array
{
    $sex = (string)($post['sex'] ?? '');
    return [
        ':school_id'    => $fixed_school ?: (int)($post['school_id'] ?? 0),
        ':level_id'     => (int)($post['level_id'] ?? 0),
        ':admission_no' => strtoupper(trim((string)($post['admission_no'] ?? ''))),
        ':full_name'    => trim((string)($post['full_name'] ?? '')),
        ':sex'          => in_array($sex, ['M','F'], true) ? $sex : null,
        ':status'       => in_array($post['status'] ?? '', ['active','inactive']) ? $post['status'] : 'active',
    ];
}

function student_form_fields(array $post, array $schools, array $levels, int $fixed_school, int $filter_school): string
{
    $p = fn(string $k, string $d = '') => e((string)($post[$k] ?? $d));

    // School select (hidden for headmaster)
    $school_html = '';
    if ($fixed_school === 0) {
        $opts = '<option value="">— Select School —</option>';
        foreach ($schools as $sc) {
            $sel = ((int)($post['school_id'] ?? $filter_school)) === (int)$sc['id'] ? ' selected' : '';
            $opts .= '<option value="' . (int)$sc['id'] . '"' . $sel . '>' . e($sc['name']) . '</option>';
        }
        $school_html = '<div class="col-12"><label class="form-label">School <span class="text-danger">*</span></label>'
            . '<select class="form-select" name="school_id" required>' . $opts . '</select></div>';
    } else {
        $school_html = '<input type="hidden" name="school_id" value="' . $fixed_school . '">';
    }

    // Level options
    $lvl_opts = '<option value="">— Select Class —</option>';
    foreach ($levels as $lv) {
        $sel = $p('level_id') === (string)$lv['id'] ? ' selected' : '';
        $lvl_opts .= '<option value="' . (int)$lv['id'] . '"' . $sel . '>' . e($lv['name']) . '</option>';
    }

    $sex_m   = $p('sex') === 'M' ? ' selected' : '';
    $sex_f   = $p('sex') === 'F' ? ' selected' : '';
    $st_act  = $p('status', 'active') === 'active'   ? ' selected' : '';
    $st_in   = $p('status', 'active') === 'inactive' ? ' selected' : '';

    return <<<HTML
    <div class="row g-3">
      {$school_html}
      <div class="col-12">
        <label class="form-label">Full Name <span class="text-danger">*</span></label>
        <input class="form-control" name="full_name" required value="{$p('full_name')}">
      </div>
      <div class="col-md-6">
        <label class="form-label">Registration No. <span class="text-danger">*</span></label>
        <input class="form-control text-uppercase" name="admission_no" required
               placeholder="e.g. S0001" value="{$p('admission_no')}">
      </div>
      <div class="col-md-6">
        <label class="form-label">Class <span class="text-danger">*</span></label>
        <select class="form-select" name="level_id" required>
          {$lvl_opts}
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Gender</label>
        <select class="form-select" name="sex">
          <option value="">— Select —</option>
          <option value="M"{$sex_m}>Male (M)</option>
          <option value="F"{$sex_f}>Female (F)</option>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Status</label>
        <select class="form-select" name="status">
          <option value="active"{$st_act}>Active</option>
          <option value="inactive"{$st_in}>Inactive</option>
        </select>
      </div>
    </div>
    HTML;
}
