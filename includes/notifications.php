<?php

declare(strict_types=1);

/**
 * Send a notification to a user.
 */
function notify_send(int $user_id, string $type, string $title, string $message, int $ref_id = 0, string $ref_type = ''): void
{
    try {
        db()->prepare(
            'INSERT INTO notifications (user_id, type, title, message, ref_id, ref_type)
             VALUES (:uid, :type, :title, :msg, :rid, :rtype)'
        )->execute([
            ':uid'   => $user_id,
            ':type'  => $type,
            ':title' => $title,
            ':msg'   => $message,
            ':rid'   => $ref_id ?: null,
            ':rtype' => $ref_type ?: null,
        ]);
    } catch (\Exception $e) {
        // Fail silently
    }
}

/**
 * Check if a notification of this type was already sent (avoid duplicates).
 */
function notify_exists(int $user_id, string $type, int $ref_id, string $ref_type, int $days = 30): bool
{
    try {
        $stmt = db()->prepare(
            'SELECT 1 FROM notifications
             WHERE user_id=:uid AND type=:type AND ref_id=:rid AND ref_type=:rtype
             AND created_at > DATE_SUB(NOW(), INTERVAL :days DAY)
             LIMIT 1'
        );
        $stmt->execute([':uid' => $user_id, ':type' => $type, ':rid' => $ref_id, ':rtype' => $ref_type, ':days' => $days]);
        return (bool)$stmt->fetchColumn();
    } catch (\Exception $e) {
        return false;
    }
}

/**
 * Count unread notifications.
 */
function notify_unread_count(int $user_id): int
{
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=:uid AND is_read=0');
        $stmt->execute([':uid' => $user_id]);
        return (int)$stmt->fetchColumn();
    } catch (\Exception $e) {
        return 0;
    }
}

/**
 * Get recent notifications.
 */
function notify_recent(int $user_id, int $limit = 5): array
{
    try {
        $stmt = db()->prepare(
            'SELECT * FROM notifications WHERE user_id=:uid ORDER BY created_at DESC LIMIT ' . $limit
        );
        $stmt->execute([':uid' => $user_id]);
        return $stmt->fetchAll();
    } catch (\Exception $e) {
        return [];
    }
}

/**
 * Mark a single notification as read.
 */
function notify_mark_read(int $id, int $user_id): void
{
    try {
        db()->prepare('UPDATE notifications SET is_read=1 WHERE id=:id AND user_id=:uid')
            ->execute([':id' => $id, ':uid' => $user_id]);
    } catch (\Exception $e) {}
}

/**
 * Mark all notifications as read.
 */
function notify_mark_all_read(int $user_id): void
{
    try {
        db()->prepare('UPDATE notifications SET is_read=1 WHERE user_id=:uid')
            ->execute([':uid' => $user_id]);
    } catch (\Exception $e) {}
}

/**
 * Check for overdue topics (in_progress for more than 14 days with no activity).
 * Called once per day per teacher.
 */
function notify_check_topic_overdue(int $teacher_id): void
{
    try {
        $stmt = db()->prepare(
            'SELECT tt.id, tt.title, sub.name AS subject_name, lv.name AS level_name
             FROM teacher_topics tt
             JOIN teacher_assignments ta ON ta.id = tt.teacher_assignment_id
             JOIN subjects sub ON sub.id = ta.subject_id
             JOIN levels lv ON lv.id = ta.level_id
             WHERE ta.teacher_id = :tid
             AND tt.status = "in_progress"
             AND COALESCE(
                 (SELECT MAX(tpl.log_date) FROM teaching_progress_log tpl
                  WHERE tpl.teacher_topic_id = tt.id),
                 DATE(tt.created_at)
             ) < DATE_SUB(CURDATE(), INTERVAL 14 DAY)'
        );
        $stmt->execute([':tid' => $teacher_id]);
        $overdue = $stmt->fetchAll();

        foreach ($overdue as $topic) {
            if (!notify_exists($teacher_id, 'topic_overdue', (int)$topic['id'], 'teacher_topic', 7)) {
                notify_send(
                    $teacher_id,
                    'topic_overdue',
                    'Topic Not Completed — Please Follow Up',
                    'Topic "' . $topic['title'] . '" (' . $topic['subject_name'] . ' · ' . $topic['level_name'] . ') is still "In Progress" but has had no activity for over 14 days. Please continue.',
                    (int)$topic['id'],
                    'teacher_topic'
                );
            }
        }
    } catch (\Exception $e) {}
}

/**
 * Check school performance and notify headmaster.
 * Called once per day.
 */
function notify_check_school_performance(int $headmaster_id, int $school_id): void
{
    if ($school_id <= 0) return;
    try {
        $stmt = db()->prepare(
            'SELECT e.id, e.name,
                    COUNT(DISTINCT m.student_id) AS student_count,
                    AVG(m.total_percent) AS avg_percent
             FROM exams e
             JOIN marks m ON m.exam_id = e.id
             JOIN students st ON st.id = m.student_id AND st.school_id = :sid
             WHERE e.status = "closed"
             AND e.marks_open_to >= DATE_SUB(NOW(), INTERVAL 60 DAY)
             GROUP BY e.id, e.name
             HAVING student_count >= 5'
        );
        $stmt->execute([':sid' => $school_id]);
        $exams = $stmt->fetchAll();

        foreach ($exams as $exam) {
            $avg      = (float)$exam['avg_percent'];
            $exam_id  = (int)$exam['id'];
            $avg_fmt  = round($avg, 1);

            if ($avg >= 60 && !notify_exists($headmaster_id, 'school_good_performance', $exam_id, 'exam')) {
                notify_send(
                    $headmaster_id,
                    'school_good_performance',
                    'Great Performance — Congratulations!',
                    'Your school performed well in exam "' . $exam['name'] . '" with an average of ' . $avg_fmt . '%. Keep it up!',
                    $exam_id,
                    'exam'
                );
            } elseif ($avg < 40 && !notify_exists($headmaster_id, 'school_poor_performance', $exam_id, 'exam')) {
                notify_send(
                    $headmaster_id,
                    'school_poor_performance',
                    'Performance Needs Attention',
                    'Your school average in exam "' . $exam['name'] . '" is ' . $avg_fmt . '%. Please investigate the causes and take action.',
                    $exam_id,
                    'exam'
                );
            }
        }
    } catch (\Exception $e) {}
}

/**
 * Get color and label configuration for notification types.
 */
function notify_type_config(string $type): array
{
    return match ($type) {
        'student_registered'      => ['color' => 'success',   'label' => 'Registration'],
        'topic_test_submitted'    => ['color' => 'info',      'label' => 'Test'],
        'topic_test_approved'     => ['color' => 'success',   'label' => 'Approved'],
        'topic_test_rejected'     => ['color' => 'danger',    'label' => 'Rejected'],
        'topic_overdue'           => ['color' => 'warning',   'label' => 'Overdue'],
        'school_good_performance' => ['color' => 'success',   'label' => 'Performance'],
        'school_poor_performance' => ['color' => 'danger',    'label' => 'Performance'],
        default                   => ['color' => 'secondary', 'label' => 'Notification'],
    };
}

/**
 * Format time as "x minutes ago" style.
 */
function notify_time_ago(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return (int)($diff / 60) . 'm ago';
    if ($diff < 86400)  return (int)($diff / 3600) . 'h ago';
    if ($diff < 604800) return (int)($diff / 86400) . 'd ago';
    return date('d/m/Y', strtotime($datetime));
}
