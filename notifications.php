<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/notifications.php';

require_auth();

$user    = current_user();
$user_id = (int)$user['id'];

// Mark single notification as read via GET link
if (isset($_GET['read'])) {
    $nid = (int)$_GET['read'];
    if ($nid > 0) notify_mark_read($nid, $user_id);
    redirect('notifications.php');
}

// POST actions
if (is_post()) {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'mark_read') {
        $nid = (int)($_POST['notification_id'] ?? 0);
        if ($nid > 0) notify_mark_read($nid, $user_id);
        redirect('notifications.php');
    }
    if ($action === 'mark_all_read') {
        notify_mark_all_read($user_id);
        flash_set('success', 'All notifications marked as read.');
        redirect('notifications.php');
    }
    if ($action === 'delete') {
        $nid = (int)($_POST['notification_id'] ?? 0);
        if ($nid > 0) {
            try {
                db()->prepare('DELETE FROM notifications WHERE id=:id AND user_id=:uid')
                    ->execute([':id' => $nid, ':uid' => $user_id]);
            } catch (\Exception $e) {}
        }
        redirect('notifications.php');
    }
}

// Get all notifications
try {
    $stmt = db()->prepare(
        'SELECT * FROM notifications WHERE user_id=:uid ORDER BY created_at DESC LIMIT 200'
    );
    $stmt->execute([':uid' => $user_id]);
    $all_notifs = $stmt->fetchAll();
} catch (\Exception $e) {
    $all_notifs = [];
}

$unread_total = count(array_filter($all_notifs, fn($n) => !$n['is_read']));

render_header('Notifications');
?>

<div class="page-heading">
  <h4>
    Notifications
    <?php if ($unread_total > 0): ?>
      <span class="badge bg-danger ms-2" style="font-size:.7rem"><?= $unread_total ?> new</span>
    <?php endif; ?>
  </h4>
  <div class="d-flex gap-2">
    <?php if ($unread_total > 0): ?>
    <form method="post" class="d-inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="mark_all_read">
      <button type="submit" class="btn btn-outline-secondary btn-sm">Mark All Read</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if (empty($all_notifs)): ?>
<div class="card">
  <div class="card-body text-center py-5">
    <div style="font-size:3rem;color:#cbd5e1;margin-bottom:.75rem">
      <svg xmlns="http://www.w3.org/2000/svg" width="56" height="56" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 16a2 2 0 0 0 2-2H6a2 2 0 0 0 2 2M8 1.918l-.797.161A4 4 0 0 0 4 6c0 .628-.134 2.197-.459 3.742-.16.767-.376 1.566-.663 2.258h10.244c-.287-.692-.502-1.49-.663-2.258C12.134 8.197 12 6.628 12 6a4 4 0 0 0-3.203-3.92zM14.22 12c.223.447.481.801.78 1H1c.299-.199.557-.553.78-1C2.68 10.2 3 6.88 3 6c0-2.42 1.72-4.44 4.005-4.901a1 1 0 1 1 1.99 0A5 5 0 0 1 13 6c0 .88.32 4.2 1.22 6"/>
      </svg>
    </div>
    <div class="text-muted">No notifications yet.</div>
  </div>
</div>
<?php else: ?>

<?php
// Group by day
$grouped = [];
foreach ($all_notifs as $n) {
    $day_key = date('Y-m-d', strtotime($n['created_at']));
    $grouped[$day_key][] = $n;
}

$dot_map = [
    'success'   => '#22c55e',
    'danger'    => '#ef4444',
    'warning'   => '#f59e0b',
    'info'      => '#3b82f6',
    'secondary' => '#94a3b8',
];

$today_key     = date('Y-m-d');
$yesterday_key = date('Y-m-d', strtotime('-1 day'));

foreach ($grouped as $day_key => $items):
    if ($day_key === $today_key)         $day_label = 'Today';
    elseif ($day_key === $yesterday_key) $day_label = 'Yesterday';
    else                                 $day_label = date('d M Y', strtotime($day_key));
?>
<div class="text-muted small fw-semibold mb-2 mt-3" style="text-transform:uppercase;letter-spacing:.5px;font-size:.68rem">
  <?= e($day_label) ?>
</div>
<div class="card mb-1">
  <?php foreach ($items as $n):
      $cfg       = notify_type_config($n['type']);
      $dot_color = $dot_map[$cfg['color']] ?? '#94a3b8';
      $unread_bg = !$n['is_read'] ? 'background:#f0f7ff;border-left:3px solid #2563eb;' : '';
  ?>
  <div class="notif-card-item" style="<?= $unread_bg ?>">
    <div style="padding-top:4px;flex-shrink:0">
      <div style="width:10px;height:10px;border-radius:50%;background:<?= $dot_color ?>"></div>
    </div>
    <div style="flex:1;min-width:0">
      <div class="d-flex justify-content-between align-items-start gap-2">
        <div style="flex:1;min-width:0">
          <div style="font-size:.875rem;font-weight:600;color:#1e293b">
            <?= e($n['title']) ?>
            <?php if (!$n['is_read']): ?>
              <span class="badge bg-primary ms-1" style="font-size:.6rem;vertical-align:middle">New</span>
            <?php endif; ?>
          </div>
          <div style="font-size:.82rem;color:#64748b;margin-top:.2rem;line-height:1.4">
            <?= e($n['message']) ?>
          </div>
        </div>
        <div style="flex-shrink:0;text-align:right">
          <div style="font-size:.72rem;color:#94a3b8;white-space:nowrap">
            <?= e(notify_time_ago($n['created_at'])) ?>
          </div>
          <span class="badge bg-light border text-muted mt-1" style="font-size:.65rem">
            <?= e($cfg['label']) ?>
          </span>
        </div>
      </div>
    </div>
    <div style="flex-shrink:0;display:flex;align-items:center;gap:.25rem">
      <div class="d-flex gap-1 flex-nowrap">
      <?php if (!$n['is_read']): ?>
      <form method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mark_read">
        <input type="hidden" name="notification_id" value="<?= (int)$n['id'] ?>">
        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Mark as read">✓</button>
      </form>
      <?php endif; ?>
      <form method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="notification_id" value="<?= (int)$n['id'] ?>">
        <button type="submit" class="btn btn-sm btn-outline-danger"
                title="Delete notification"
                onclick="return confirm('Delete this notification?')">×</button>
      </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php endif; ?>

<?php render_footer(); ?>
