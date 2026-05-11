-- Schema 4: Grading scale corrections
-- Safe to run multiple times.

SET FOREIGN_KEY_CHECKS=0;

-- ── Fix O-Level F: points NULL → 5 ───────────────────────────
UPDATE grading_scales
SET points = 5
WHERE category = 'o_level' AND grade = 'F' AND (points IS NULL OR points <> 5);

-- ── Fix A-Level F range: was 0–39.999, now 0–34.999 ──────────
UPDATE grading_scales
SET max_mark = 34.999
WHERE category = 'a_level' AND grade = 'F' AND max_mark <> 34.999;

-- ── Add A-Level S grade (35–39.999, pts 6) ───────────────────
INSERT IGNORE INTO grading_scales (category, grade, min_mark, max_mark, points)
VALUES ('a_level', 'S', 35, 39.999, 6);

-- ── Fix A-Level F: points NULL → 7 ───────────────────────────
UPDATE grading_scales
SET points = 7
WHERE category = 'a_level' AND grade = 'F' AND (points IS NULL OR points <> 7);

-- ── Fix existing marks in DB ──────────────────────────────────

-- O-Level F marks: set points = 5 where they were stored as NULL
UPDATE marks m
JOIN exams e ON e.id = m.exam_id
SET m.points = 5
WHERE m.grade = 'F'
  AND e.category = 'o_level'
  AND m.points IS NULL;

-- A-Level: marks with total 35–39.999% were wrongly graded F; reclassify as S
UPDATE marks m
JOIN exams e ON e.id = m.exam_id
SET m.grade = 'S', m.points = 6
WHERE e.category = 'a_level'
  AND m.total_percent >= 35
  AND m.total_percent <= 39.999
  AND m.grade = 'F';

SET FOREIGN_KEY_CHECKS=1;
