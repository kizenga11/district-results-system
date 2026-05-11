<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

require_role(['super_admin', 'district_admin']);

$user = current_user();

// Global combinations (apply to all schools)

$errors = [];
$edit_action = false;

function subject_is_principal(array $s): bool
{
    if (($s['category'] ?? '') !== 'a_level') return false;
    if (!empty($s['alevel_subject_type'])) {
        return (string)$s['alevel_subject_type'] === 'principal';
    }
    return (int)($s['is_principal'] ?? 0) === 1;
}

$back_to = 'district/alevel_combinations.php';

if (is_post()) {
    csrf_verify();

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete_combo') {
        $id = (int)($_POST['combo_id'] ?? 0);
        if ($id > 0) {
            $in_use = db()->prepare('SELECT 1 FROM student_combinations sc WHERE sc.combination_id=:id LIMIT 1');
            $in_use->execute([':id' => $id]);
            if ($in_use->fetch()) {
                flash_set('error', 'This combination is already assigned to students. It cannot be deleted.');
            } else {
                db()->prepare('DELETE FROM alevel_combinations WHERE id=:id')->execute([':id' => $id]);
                flash_set('success', 'Combination deleted.');
            }
        }
        redirect($back_to);
    }

    if ($action === 'add_combo' || $action === 'edit_combo') {
        $edit_action = $action === 'edit_combo';
        $combo_id = (int)($_POST['combo_id'] ?? 0);

        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        $principal_ids = array_map('intval', (array)($_POST['principal_subjects'] ?? []));
        $other_ids = array_map('intval', (array)($_POST['other_subjects'] ?? []));

        $principal_ids = array_values(array_unique(array_filter($principal_ids)));
        $other_ids = array_values(array_unique(array_filter($other_ids)));

        if ($code === '') $errors[] = 'Combination code is required (e.g. PCB).';
        if (count($principal_ids) !== 3) $errors[] = 'Select exactly 3 Principal subjects.';

        // Fetch active A-Level subjects from district master list
        $stmt = db()->prepare(
            'SELECT sub.id, sub.name, sub.code, sub.category, sub.is_principal, sub.alevel_subject_type
             FROM subjects sub
             WHERE sub.category = "a_level" AND sub.status = "active"
             ORDER BY sub.name'
        );
        $stmt->execute();
        $active_subjects = $stmt->fetchAll();
        $active_map = [];
        foreach ($active_subjects as $s) $active_map[(int)$s['id']] = $s;

        foreach ($principal_ids as $sid) {
            if (!isset($active_map[$sid])) $errors[] = 'One or more Principal subjects are not activated for this school.';
        }
        foreach ($other_ids as $sid) {
            if (!isset($active_map[$sid])) $errors[] = 'One or more Other subjects are not activated for this school.';
        }

        foreach ($principal_ids as $sid) {
            if (isset($active_map[$sid]) && !subject_is_principal($active_map[$sid])) {
                $errors[] = 'One or more selected Principal subjects are not marked as Principal.';
            }
        }

        $overlap = array_intersect($principal_ids, $other_ids);
        if (!empty($overlap)) {
            $errors[] = 'A subject cannot be selected in both Principal and Other.';
        }

        if (empty($errors)) {
            if ($edit_action) {
                if ($combo_id <= 0) {
                    $errors[] = 'Invalid combination.';
                } else {
                    $chk = db()->prepare('SELECT 1 FROM alevel_combinations WHERE code=:c AND id<>:id LIMIT 1');
                    $chk->execute([':c' => $code, ':id' => $combo_id]);
                    if ($chk->fetch()) $errors[] = 'Combination code already exists.';
                }
            } else {
                $chk = db()->prepare('SELECT 1 FROM alevel_combinations WHERE code=:c LIMIT 1');
                $chk->execute([':c' => $code]);
                if ($chk->fetch()) $errors[] = 'Combination code already exists.';
            }
        }

        if (empty($errors)) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                if ($edit_action) {
                    $pdo->prepare('UPDATE alevel_combinations SET code=:c, name=:n WHERE id=:id')
                        ->execute([':c' => $code, ':n' => ($name === '' ? null : $name), ':id' => $combo_id]);

                    $pdo->prepare('DELETE FROM alevel_combination_subjects WHERE combination_id=:id')
                        ->execute([':id' => $combo_id]);

                    $new_id = $combo_id;
                } else {
                    $pdo->prepare('INSERT INTO alevel_combinations (code, name, status) VALUES (:c,:n,"active")')
                        ->execute([':c' => $code, ':n' => ($name === '' ? null : $name)]);
                    $new_id = (int)$pdo->lastInsertId();
                }

                $all_subject_ids = array_merge($principal_ids, $other_ids);
                $ins = $pdo->prepare('INSERT INTO alevel_combination_subjects (combination_id, subject_id) VALUES (:cid,:sid)');
                foreach ($all_subject_ids as $sid) {
                    $ins->execute([':cid' => $new_id, ':sid' => $sid]);
                }

                $pdo->commit();
                flash_set('success', $edit_action ? 'Combination updated.' : 'Combination created.');
                redirect($back_to);
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    }
}

// Fetch combos list
$stmt = db()->prepare(
    'SELECT c.*, COUNT(cs.subject_id) AS subject_count
     FROM alevel_combinations c
     LEFT JOIN alevel_combination_subjects cs ON cs.combination_id = c.id
     GROUP BY c.id
     ORDER BY c.code'
);
$stmt->execute();
$combos = $stmt->fetchAll();

// Fetch active A-Level subjects for select options
$stmt = db()->prepare(
    'SELECT sub.id, sub.name, sub.code, sub.category, sub.is_principal, sub.alevel_subject_type
     FROM subjects sub
     WHERE sub.category = "a_level" AND sub.status = "active"
     ORDER BY sub.name'
);
$stmt->execute();
$active_subjects = $stmt->fetchAll();

$principal_subjects = [];
$other_subjects = [];
foreach ($active_subjects as $s) {
    if (subject_is_principal($s)) {
        $principal_subjects[] = $s;
    } else {
        $other_subjects[] = $s;
    }
}

// combo -> subjects map
$stmt = db()->prepare(
    'SELECT c.id AS combo_id, sub.id AS subject_id
     FROM alevel_combinations c
     JOIN alevel_combination_subjects cs ON cs.combination_id = c.id
     JOIN subjects sub ON sub.id = cs.subject_id'
);
$stmt->execute();
$combo_subject_rows = $stmt->fetchAll();
$combo_subject_map = [];
foreach ($combo_subject_rows as $r) {
    $combo_subject_map[(int)$r['combo_id']][] = (int)$r['subject_id'];
}

$combos_json = json_encode([ 'combos' => $combos, 'combo_subjects' => $combo_subject_map ], JSON_HEX_TAG);

render_header('A-Level Combinations');
?>

<style>
.subject-chips-wrap{display:flex;flex-wrap:wrap;gap:6px;padding:10px;border:1.5px solid #dee2e6;border-radius:8px;min-height:54px;background:#f8f9fa;transition:border-color .15s}
.subject-chips-wrap.has-error{border-color:#dc3545!important}
.subject-chip{display:inline-flex;align-items:center;gap:6px;padding:5px 13px;border:1.5px solid #dee2e6;border-radius:20px;cursor:pointer;background:#fff;transition:all .15s ease;font-size:.85rem;user-select:none;margin:0;line-height:1.4;overflow-wrap:break-word;word-break:break-word}
.subject-chip:hover{border-color:#0d6efd;background:#eef3ff}
.subject-chip.selected{border-color:#0d6efd;background:#0d6efd;color:#fff}
.subject-chip.selected small{color:rgba(255,255,255,.7)!important}
.subject-chip.chip-disabled{opacity:.38;pointer-events:none}
.subject-chip .chip-check{width:14px;height:14px;border-radius:50%;border:1.5px solid currentColor;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:9px}
.subject-chip.selected .chip-check::after{content:'✓'}
</style>

<div class="page-heading">
  <h4>A-Level Combinations</h4>
</div>

<div class="alert alert-info py-2 small">
  Combinations created here are <strong>global</strong>. Headmasters will activate them per school.
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if (empty($principal_subjects)): ?>
  <div class="alert alert-warning">
    No Principal subjects activated for this school. Activate Principal subjects first.
  </div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Code</th>
          <th>Name</th>
          <th class="text-center">Subjects</th>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($combos as $i => $c): ?>
          <tr>
            <td class="text-muted"><?= $i + 1 ?></td>
            <td class="fw-semibold"><span class="badge bg-light text-dark border"><?= e($c['code']) ?></span></td>
            <td><?= e($c['name'] ?? '—') ?></td>
            <td class="text-center"><span class="badge bg-secondary"><?= (int)$c['subject_count'] ?></span></td>
            <td>
              <?php if ($c['status'] === 'active'): ?>
                <span class="badge bg-success">Active</span>
              <?php else: ?>
                <span class="badge bg-danger">Inactive</span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <div class="d-flex gap-1 justify-content-end">
                <button class="btn btn-outline-primary btn-sm btn-edit" data-id="<?= (int)$c['id'] ?>" data-bs-toggle="modal" data-bs-target="#modalEdit">Edit</button>

                <form method="post" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_combo">
                  <input type="hidden" name="combo_id" value="<?= (int)$c['id'] ?>">
                  <button class="btn btn-outline-danger btn-sm" type="submit" onclick="return confirm('Delete this combination?')">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>

        <?php if (empty($combos)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No combinations yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal Add -->
<div class="modal fade" id="modalAdd" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_combo">
        <div class="modal-header">
          <h5 class="modal-title">Add Combination</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= combo_form_fields($_POST, $principal_subjects, $other_subjects) ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Edit -->
<div class="modal fade" id="modalEdit" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" autocomplete="off" id="formEdit">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="edit_combo">
        <input type="hidden" name="combo_id" id="editComboId">
        <div class="modal-header">
          <h5 class="modal-title">Edit Combination</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= combo_form_fields([], $principal_subjects, $other_subjects, 'edit_') ?>
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
  const DATA = <?= $combos_json ?>;

  function updatePrincipalState(form) {
    const pWrap = form.querySelector('.principal-chips');
    const counter = form.querySelector('.principal-counter');
    const chips = pWrap.querySelectorAll('.subject-chip');
    const checked = pWrap.querySelectorAll('.principal-cb:checked').length;

    counter.textContent = checked + ' / 3';
    counter.className = 'badge principal-counter ms-1 ' + (checked === 3 ? 'bg-success' : (checked > 3 ? 'bg-danger' : 'bg-secondary'));

    chips.forEach(chip => {
      const cb = chip.querySelector('.principal-cb');
      if (checked >= 3 && !cb.checked) chip.classList.add('chip-disabled');
      else chip.classList.remove('chip-disabled');
    });
  }

  function initChips(form) {
    const pWrap = form.querySelector('.principal-chips');
    const oWrap = form.querySelector('.other-chips');

    pWrap.querySelectorAll('.subject-chip').forEach(chip => {
      chip.addEventListener('click', e => {
        e.preventDefault();
        if (chip.classList.contains('chip-disabled')) return;
        const cb = chip.querySelector('.principal-cb');
        cb.checked = !cb.checked;
        chip.classList.toggle('selected', cb.checked);
        updatePrincipalState(form);
        pWrap.classList.remove('has-error');
        form.querySelector('.principal-err').style.display = 'none';
      });
    });

    oWrap && oWrap.querySelectorAll('.subject-chip').forEach(chip => {
      chip.addEventListener('click', e => {
        e.preventDefault();
        const cb = chip.querySelector('.other-cb');
        cb.checked = !cb.checked;
        chip.classList.toggle('selected', cb.checked);
      });
    });

    updatePrincipalState(form);

    form.addEventListener('submit', e => {
      const pWrap = form.querySelector('.principal-chips');
      const errEl = form.querySelector('.principal-err');
      const checked = pWrap.querySelectorAll('.principal-cb:checked').length;
      if (checked !== 3) {
        e.preventDefault();
        errEl.style.display = '';
        pWrap.classList.add('has-error');
      }
    });
  }

  function setChips(wrap, ids, cbClass) {
    const set = new Set(ids.map(n => parseInt(n)));
    wrap.querySelectorAll('.subject-chip').forEach(chip => {
      const sid = parseInt(chip.dataset.sid);
      const cb = chip.querySelector('.' + cbClass);
      const sel = set.has(sid);
      cb.checked = sel;
      chip.classList.toggle('selected', sel);
    });
  }

  function resetForm(form) {
    form.querySelectorAll('.subject-chip').forEach(chip => {
      chip.classList.remove('selected', 'chip-disabled');
      chip.querySelector('input[type=checkbox]').checked = false;
    });
    const counter = form.querySelector('.principal-counter');
    counter.textContent = '0 / 3';
    counter.className = 'badge bg-secondary principal-counter ms-1';
    form.querySelector('.principal-err').style.display = 'none';
    form.querySelector('.principal-chips').classList.remove('has-error');
  }

  document.querySelectorAll('#modalAdd form, #formEdit').forEach(initChips);

  document.getElementById('modalAdd').addEventListener('hidden.bs.modal', () => {
    resetForm(document.querySelector('#modalAdd form'));
  });

  document.querySelectorAll('.btn-edit').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = parseInt(btn.dataset.id);
      const combo = (DATA.combos || []).find(c => parseInt(c.id) === id);
      if (!combo) return;
      const subjIds = (DATA.combo_subjects || {})[id] || [];

      const f = document.getElementById('formEdit');
      f.querySelector('#editComboId').value = id;
      f.querySelector('[name="code"]').value = combo.code ?? '';
      f.querySelector('[name="name"]').value = combo.name ?? '';

      const pWrap = f.querySelector('.principal-chips');
      const oWrap = f.querySelector('.other-chips');

      const principalIds = [], otherIds = [];
      subjIds.forEach(sid => {
        if (pWrap.querySelector(`[data-sid="${sid}"]`)) principalIds.push(sid);
        else otherIds.push(sid);
      });

      setChips(pWrap, principalIds, 'principal-cb');
      oWrap && setChips(oWrap, otherIds, 'other-cb');
      updatePrincipalState(f);
    });
  });
})();
</script>

<?php
render_footer();

function combo_form_fields(array $post, array $principal_subjects, array $other_subjects, string $prefix = ''): string
{
    $p = fn(string $k, string $d = '') => e((string)($post[$k] ?? $d));
    $sel_principals = array_map('intval', (array)($post['principal_subjects'] ?? []));
    $sel_others     = array_map('intval', (array)($post['other_subjects'] ?? []));

    $principal_chips = '';
    foreach ($principal_subjects as $s) {
        $sid     = (int)$s['id'];
        $checked = in_array($sid, $sel_principals, true);
        $cls     = $checked ? ' selected' : '';
        $attr    = $checked ? ' checked' : '';
        $principal_chips .= '<label class="subject-chip' . $cls . '" data-sid="' . $sid . '">'
            . '<input type="checkbox" name="principal_subjects[]" value="' . $sid . '" class="d-none principal-cb"' . $attr . '>'
            . '<span class="chip-check"></span>'
            . '<span>' . e($s['name']) . ' <small class="text-muted">(' . e($s['code']) . ')</small></span>'
            . '</label>';
    }
    if ($principal_chips === '') {
        $principal_chips = '<span class="text-muted small">No principal subjects activated.</span>';
    }

    $other_chips = '';
    foreach ($other_subjects as $s) {
        $sid     = (int)$s['id'];
        $checked = in_array($sid, $sel_others, true);
        $cls     = $checked ? ' selected' : '';
        $attr    = $checked ? ' checked' : '';
        $type    = (string)($s['alevel_subject_type'] ?? '');
        $badge   = $type !== '' ? ' <small class="text-muted">· ' . e(ucfirst($type)) . '</small>' : '';
        $other_chips .= '<label class="subject-chip' . $cls . '" data-sid="' . $sid . '">'
            . '<input type="checkbox" name="other_subjects[]" value="' . $sid . '" class="d-none other-cb"' . $attr . '>'
            . '<span>' . e($s['name']) . ' <small class="text-muted">(' . e($s['code']) . ')</small>' . $badge . '</span>'
            . '</label>';
    }
    if ($other_chips === '') {
        $other_chips = '<span class="text-muted small">No other subjects available.</span>';
    }

    $init_count = count($sel_principals);
    $counter_cls = $init_count === 3 ? 'bg-success' : 'bg-secondary';

    return <<<HTML
    <div class="row g-3">
      <div class="col-12 col-md-4">
        <label class="form-label">Code <span class="text-danger">*</span></label>
        <input class="form-control text-uppercase" name="code" maxlength="20" placeholder="e.g. PCB" required value="{$p('code')}">
      </div>
      <div class="col-12 col-md-8">
        <label class="form-label">Name (optional)</label>
        <input class="form-control" name="name" placeholder="e.g. Science Combination" value="{$p('name')}">
      </div>

      <div class="col-12">
        <div class="d-flex align-items-center gap-1 mb-1">
          <label class="form-label mb-0 fw-semibold">Principal Subjects <span class="text-danger">*</span></label>
          <span class="badge {$counter_cls} principal-counter ms-1">{$init_count} / 3</span>
        </div>
        <div class="subject-chips-wrap principal-chips">
          {$principal_chips}
        </div>
        <div class="text-danger small mt-1 principal-err" style="display:none">Please select exactly 3 principal subjects.</div>
      </div>

      <div class="col-12">
        <div class="d-flex align-items-center gap-1 mb-1">
          <label class="form-label mb-0 fw-semibold">Other Subjects</label>
          <span class="text-muted small">(optional)</span>
        </div>
        <div class="subject-chips-wrap other-chips">
          {$other_chips}
        </div>
      </div>
    </div>
    HTML;
}
