-- ═══════════════════════════════════════════════════════════════
-- Performance Index Recommendations
-- ═══════════════════════════════════════════════════════════════
-- Run this AFTER deploying code changes. Safe to re-run.
-- Uses IF NOT EXISTS / IF EXISTS checks where possible.

-- ── 1. students: composite for exam result/analysis joins ─────
-- Most exam queries join: students.school_id + level_id + status
CREATE INDEX idx_students_school_level_status
    ON students (school_id, level_id, status);

-- ── 2. marks: composite for subject-level analysis ────────────
-- Analysis pages GROUP/aggregate by subject within an exam
CREATE INDEX idx_marks_exam_subject
    ON marks (exam_id, subject_id);

-- ── 3. marks: composite for student-level lookups ─────────────
-- Used when loading marks for a specific student across exams
CREATE INDEX idx_marks_student_subject
    ON marks (student_id, subject_id);

-- ── 4. notifications: composite for the "unread count" + list ─
-- Layout.php queries: user_id + is_read + order by created_at DESC
CREATE INDEX idx_notifications_user_read_created
    ON notifications (user_id, is_read, created_at DESC);

-- ── 5. teacher_assignments: composite for teacher dashboard ───
-- Dashboard queries: COUNT(*) WHERE teacher_id = ?
-- Already covered by uniq_teacher_assignment (teacher_id, school_id, subject_id, level_id)
-- This additional index provides a leaner lookup path:
CREATE INDEX idx_ta_teacher_school
    ON teacher_assignments (teacher_id, school_id);

-- ── 6. teacher_topics: composite for overdue check ────────────
-- notify_check_topic_overdue queries teacher_assignment_id + status
CREATE INDEX idx_teacher_topics_assign_status
    ON teacher_topics (teacher_assignment_id, status);

-- ── 7. exams: index for status-based lookups ──────────────────
-- Dashboard and filters query: WHERE status = 'open' / 'closed'
CREATE INDEX idx_exams_status_year
    ON exams (status, year DESC);

-- ═══════════════════════════════════════════════════════════════
-- ANALYZE tables to update optimizer statistics
-- ═══════════════════════════════════════════════════════════════
ANALYZE TABLE schools;
ANALYZE TABLE users;
ANALYZE TABLE students;
ANALYZE TABLE marks;
ANALYZE TABLE exams;
ANALYZE TABLE subjects;
ANALYZE TABLE notifications;
ANALYZE TABLE teacher_assignments;
ANALYZE TABLE teacher_topics;
