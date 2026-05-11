-- Create database first:
-- CREATE DATABASE iramba_rms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE iramba_rms;

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS schools (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  code VARCHAR(50) NOT NULL UNIQUE,
  level ENUM('o_level','both') NOT NULL DEFAULT 'o_level',
  ward VARCHAR(120) NULL,
  phone VARCHAR(50) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  school_id INT NULL,
  full_name VARCHAR(200) NOT NULL,
  email VARCHAR(200) NOT NULL UNIQUE,
  username VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super_admin','district_admin','headmaster','teacher') NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS levels (
  id TINYINT PRIMARY KEY,
  name VARCHAR(50) NOT NULL,
  category ENUM('o_level','a_level') NOT NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO levels (id, name, category) VALUES
(1,'Form 1','o_level'),
(2,'Form 2','o_level'),
(3,'Form 3','o_level'),
(4,'Form 4','o_level'),
(5,'Form 5','a_level'),
(6,'Form 6','a_level');

CREATE TABLE IF NOT EXISTS subjects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category ENUM('o_level','a_level') NOT NULL,
  name VARCHAR(120) NOT NULL,
  code VARCHAR(30) NOT NULL,
  is_principal TINYINT(1) NOT NULL DEFAULT 0,
  has_practical TINYINT(1) NOT NULL DEFAULT 0,
  practical_max INT NOT NULL DEFAULT 0,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  UNIQUE KEY uniq_subject (category, code)
) ENGINE=InnoDB;

-- Schools activate subjects they teach (from district list)
CREATE TABLE IF NOT EXISTS school_subjects (
  school_id  INT NOT NULL,
  subject_id INT NOT NULL,
  PRIMARY KEY (school_id, subject_id),
  CONSTRAINT fk_ss_school   FOREIGN KEY (school_id)  REFERENCES schools(id)  ON DELETE CASCADE,
  CONSTRAINT fk_ss_subject  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Students assigned to specific subjects per class
CREATE TABLE IF NOT EXISTS student_subjects (
  student_id INT NOT NULL,
  subject_id INT NOT NULL,
  PRIMARY KEY (student_id, subject_id),
  CONSTRAINT fk_studsub_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_studsub_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS exams (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category ENUM('o_level','a_level') NOT NULL,
  name VARCHAR(150) NOT NULL,
  year INT NOT NULL,
  term ENUM('I','II','III') NULL,
  status ENUM('draft','open','closed') NOT NULL DEFAULT 'draft',
  marks_open_from DATE NULL,
  marks_open_to DATE NULL,
  practical_open_from DATE NULL,
  practical_open_to DATE NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_exams_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS exam_levels (
  exam_id INT NOT NULL,
  level_id TINYINT NOT NULL,
  PRIMARY KEY (exam_id, level_id),
  CONSTRAINT fk_exam_levels_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
  CONSTRAINT fk_exam_levels_level FOREIGN KEY (level_id) REFERENCES levels(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  school_id INT NOT NULL,
  level_id TINYINT NOT NULL,
  admission_no VARCHAR(60) NOT NULL,
  full_name VARCHAR(200) NOT NULL,
  sex ENUM('M','F') NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_student (school_id, admission_no),
  CONSTRAINT fk_students_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
  CONSTRAINT fk_students_level FOREIGN KEY (level_id) REFERENCES levels(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS teacher_subjects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  exam_id INT NOT NULL,
  school_id INT NOT NULL,
  teacher_id INT NOT NULL,
  subject_id INT NOT NULL,
  level_id TINYINT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_assign (exam_id, school_id, teacher_id, subject_id, level_id),
  CONSTRAINT fk_ts_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
  CONSTRAINT fk_ts_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
  CONSTRAINT fk_ts_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ts_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ts_level FOREIGN KEY (level_id) REFERENCES levels(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Permanent teacher assignments managed by headmaster
CREATE TABLE IF NOT EXISTS teacher_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  school_id INT NOT NULL,
  subject_id INT NOT NULL,
  level_id TINYINT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_teacher_assignment (teacher_id, school_id, subject_id, level_id),
  CONSTRAINT fk_ta_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ta_school  FOREIGN KEY (school_id)  REFERENCES schools(id) ON DELETE CASCADE,
  CONSTRAINT fk_ta_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ta_level   FOREIGN KEY (level_id)   REFERENCES levels(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS marks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  exam_id INT NOT NULL,
  student_id INT NOT NULL,
  subject_id INT NOT NULL,
  theory_mark DECIMAL(6,2) NOT NULL,
  practical_mark DECIMAL(6,2) NULL,
  total_percent DECIMAL(6,2) NOT NULL,
  grade VARCHAR(2) NOT NULL,
  points TINYINT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_mark (exam_id, student_id, subject_id),
  CONSTRAINT fk_marks_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
  CONSTRAINT fk_marks_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_marks_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT,
  CONSTRAINT fk_marks_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS grading_scales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category ENUM('o_level','a_level') NOT NULL,
  grade VARCHAR(2) NOT NULL,
  min_mark DECIMAL(6,2) NOT NULL,
  max_mark DECIMAL(6,2) NOT NULL,
  points TINYINT NULL,
  UNIQUE KEY uniq_scale (category, grade)
) ENGINE=InnoDB;

-- O-Level scale
INSERT IGNORE INTO grading_scales (category, grade, min_mark, max_mark, points) VALUES
('o_level','A',75,100,1),
('o_level','B',65,74.999,2),
('o_level','C',50,64.999,3),
('o_level','D',30,49.999,4),
('o_level','F',0,29.999,5);

-- A-Level scale
INSERT IGNORE INTO grading_scales (category, grade, min_mark, max_mark, points) VALUES
('a_level','A',80,100,1),
('a_level','B',70,79.999,2),
('a_level','C',60,69.999,3),
('a_level','D',50,59.999,4),
('a_level','E',40,49.999,5),
('a_level','S',35,39.999,6),
('a_level','F',0,34.999,7);

SET FOREIGN_KEY_CHECKS=1;

-- ── Schema upgrades (safe to run mara nyingi) ─────────────────
-- Inaongeza columns zinazokosekana bila kuvunja data iliyopo.

DROP PROCEDURE IF EXISTS _run_upgrades;
DELIMITER //
CREATE PROCEDURE _run_upgrades()
BEGIN
    -- Ongeza email kwa users kama haipo
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'users'
          AND COLUMN_NAME  = 'email'
    ) THEN
        ALTER TABLE users
          ADD COLUMN email VARCHAR(200) NOT NULL DEFAULT '' AFTER full_name,
          ADD UNIQUE KEY uniq_users_email (email);
    END IF;
END //
DELIMITER ;

CALL _run_upgrades();
DROP PROCEDURE IF EXISTS _run_upgrades;

-- ── Seed: super admin wa kwanza (password: Admin@123) ─────────
INSERT IGNORE INTO users (school_id, full_name, email, username, password_hash, role, status)
VALUES (NULL, 'Super Admin', 'admin@iramba.go.tz', 'super',
        '$2y$10$lMqYAZi07uUboYqWb/.lVOV7y1cV8G1U7pAhU8y7kNzwDygagVXc2',
        'super_admin', 'active');

-- Rekebisha email ya super admin kama ilikuwepo tupu (kutoka schema ya zamani)
UPDATE users SET email = 'admin@iramba.go.tz'
WHERE username = 'super' AND (email = '' OR email IS NULL);
