-- ═══════════════════════════════════════════════════════════════════════
-- Iramba District Results Management System (iramba_rms)
-- DATABASE SCHEMA + SEEDS – file moja kamili
--
-- Jinsi ya kutumia:
--   1. Fungua phpMyAdmin → Import → chagua faili hili
--   2. AU kwenye terminal:
--        mysql -u root -p iramba_rms < sql/database.sql
--
-- Baada ya hii endesha seed ya test data:
--   php sql/seed_o_level.php   (kama hutaki a-level)
--   php sql/seed_full.php      (O-Level test data kamili)
-- ═══════════════════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS iramba_rms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE iramba_rms;

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────
-- TABLES
-- ─────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS schools (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(200) NOT NULL,
  code       VARCHAR(50)  NOT NULL UNIQUE,
  level      ENUM('o_level','a_level','both') NOT NULL DEFAULT 'o_level',
  ward       VARCHAR(120) NULL,
  phone      VARCHAR(50)  NULL,
  status     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS levels (
  id       TINYINT PRIMARY KEY,
  name     VARCHAR(50) NOT NULL,
  category ENUM('o_level','a_level') NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS grading_scales (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  category ENUM('o_level','a_level') NOT NULL,
  grade    VARCHAR(2)     NOT NULL,
  min_mark DECIMAL(6,2)   NOT NULL,
  max_mark DECIMAL(6,2)   NOT NULL,
  points   TINYINT        NULL,
  UNIQUE KEY uniq_scale (category, grade)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subjects (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  category           ENUM('o_level','a_level') NOT NULL,
  name               VARCHAR(120) NOT NULL,
  code               VARCHAR(30)  NOT NULL,
  abbr               VARCHAR(20)  NULL,
  is_principal       TINYINT(1)   NOT NULL DEFAULT 0,
  alevel_subject_type ENUM('principal','subsidiary','additional') NULL,
  has_practical      TINYINT(1)   NOT NULL DEFAULT 0,
  practical_max      INT          NOT NULL DEFAULT 0,
  status             ENUM('active','inactive') NOT NULL DEFAULT 'active',
  UNIQUE KEY uniq_subject (category, code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  school_id     INT          NULL,
  full_name     VARCHAR(200) NOT NULL,
  email         VARCHAR(200) NOT NULL UNIQUE,
  username      VARCHAR(80)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('super_admin','district_admin','headmaster','teacher') NOT NULL,
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS exams (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  category            ENUM('o_level','a_level') NOT NULL,
  name                VARCHAR(150) NOT NULL,
  year                INT          NOT NULL,
  term                ENUM('I','II','III') NULL,
  status              ENUM('draft','open','closed') NOT NULL DEFAULT 'draft',
  marks_open_from     DATE NULL,
  marks_open_to       DATE NULL,
  practical_open_from DATE NULL,
  practical_open_to   DATE NULL,
  created_by          INT  NULL,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_exams_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS exam_levels (
  exam_id  INT     NOT NULL,
  level_id TINYINT NOT NULL,
  PRIMARY KEY (exam_id, level_id),
  CONSTRAINT fk_exam_levels_exam  FOREIGN KEY (exam_id)  REFERENCES exams(id)  ON DELETE CASCADE,
  CONSTRAINT fk_exam_levels_level FOREIGN KEY (level_id) REFERENCES levels(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS students (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  school_id    INT          NOT NULL,
  level_id     TINYINT      NOT NULL,
  admission_no VARCHAR(60)  NOT NULL,
  full_name    VARCHAR(200) NOT NULL,
  sex          ENUM('M','F') NULL,
  status       ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_student (school_id, admission_no),
  CONSTRAINT fk_students_school FOREIGN KEY (school_id) REFERENCES schools(id)  ON DELETE CASCADE,
  CONSTRAINT fk_students_level  FOREIGN KEY (level_id)  REFERENCES levels(id)   ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_subjects (
  school_id  INT NOT NULL,
  subject_id INT NOT NULL,
  PRIMARY KEY (school_id, subject_id),
  CONSTRAINT fk_ss_school   FOREIGN KEY (school_id)  REFERENCES schools(id)  ON DELETE CASCADE,
  CONSTRAINT fk_ss_subject  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS student_subjects (
  student_id INT     NOT NULL,
  subject_id INT     NOT NULL,
  school_id  INT     NULL,
  level_id   TINYINT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id, subject_id),
  INDEX idx_student_subjects_school (school_id),
  INDEX idx_student_subjects_level  (level_id),
  CONSTRAINT fk_studsub_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_studsub_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  CONSTRAINT fk_student_subjects_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL,
  CONSTRAINT fk_student_subjects_level  FOREIGN KEY (level_id)  REFERENCES levels(id)  ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS alevel_combinations (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(20)  NOT NULL,
  name       VARCHAR(120) NULL,
  status     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_combo_code (code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS alevel_combination_subjects (
  combination_id INT NOT NULL,
  subject_id     INT NOT NULL,
  PRIMARY KEY (combination_id, subject_id),
  CONSTRAINT fk_combo_sub_combo   FOREIGN KEY (combination_id) REFERENCES alevel_combinations(id) ON DELETE CASCADE,
  CONSTRAINT fk_combo_sub_subject FOREIGN KEY (subject_id)     REFERENCES subjects(id)            ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_alevel_combinations (
  school_id      INT NOT NULL,
  combination_id INT NOT NULL,
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (school_id, combination_id),
  CONSTRAINT fk_sac_school FOREIGN KEY (school_id)      REFERENCES schools(id)             ON DELETE CASCADE,
  CONSTRAINT fk_sac_combo  FOREIGN KEY (combination_id) REFERENCES alevel_combinations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS student_combinations (
  student_id     INT NOT NULL,
  combination_id INT NOT NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id),
  CONSTRAINT fk_student_combo_student FOREIGN KEY (student_id)     REFERENCES students(id)            ON DELETE CASCADE,
  CONSTRAINT fk_student_combo_combo   FOREIGN KEY (combination_id) REFERENCES alevel_combinations(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS teacher_assignments (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT     NOT NULL,
  school_id  INT     NOT NULL,
  subject_id INT     NOT NULL,
  level_id   TINYINT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_teacher_assignment (teacher_id, school_id, subject_id, level_id),
  CONSTRAINT fk_ta_teacher FOREIGN KEY (teacher_id) REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_ta_school  FOREIGN KEY (school_id)  REFERENCES schools(id)  ON DELETE CASCADE,
  CONSTRAINT fk_ta_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ta_level   FOREIGN KEY (level_id)   REFERENCES levels(id)   ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS teacher_subjects (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  exam_id    INT     NOT NULL,
  school_id  INT     NOT NULL,
  teacher_id INT     NOT NULL,
  subject_id INT     NOT NULL,
  level_id   TINYINT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_assign (exam_id, school_id, teacher_id, subject_id, level_id),
  CONSTRAINT fk_ts_exam    FOREIGN KEY (exam_id)    REFERENCES exams(id)    ON DELETE CASCADE,
  CONSTRAINT fk_ts_school  FOREIGN KEY (school_id)  REFERENCES schools(id)  ON DELETE CASCADE,
  CONSTRAINT fk_ts_teacher FOREIGN KEY (teacher_id) REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_ts_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ts_level   FOREIGN KEY (level_id)   REFERENCES levels(id)   ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS marks (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  exam_id        INT          NOT NULL,
  student_id     INT          NOT NULL,
  subject_id     INT          NOT NULL,
  theory_mark    DECIMAL(6,2) NOT NULL,
  practical_mark DECIMAL(6,2) NULL,
  absent         TINYINT(1)   NOT NULL DEFAULT 0,
  total_percent  DECIMAL(6,2) NOT NULL,
  grade          VARCHAR(2)   NOT NULL,
  points         TINYINT      NULL,
  created_by     INT          NULL,
  created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_mark (exam_id, student_id, subject_id),
  CONSTRAINT fk_marks_exam    FOREIGN KEY (exam_id)    REFERENCES exams(id)    ON DELETE CASCADE,
  CONSTRAINT fk_marks_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_marks_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT,
  CONSTRAINT fk_marks_user    FOREIGN KEY (created_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS teacher_topics (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  teacher_assignment_id INT     NOT NULL,
  title                 VARCHAR(255) NOT NULL,
  competence            TEXT   NULL,
  sort_order            INT    NOT NULL DEFAULT 0,
  status                ENUM('planned','in_progress','completed') NOT NULL DEFAULT 'planned',
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tt_assign FOREIGN KEY (teacher_assignment_id) REFERENCES teacher_assignments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS teaching_progress_log (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  teacher_topic_id INT  NOT NULL,
  log_date         DATE NOT NULL,
  notes            TEXT NULL,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tpl_topic FOREIGN KEY (teacher_topic_id) REFERENCES teacher_topics(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS topic_tests (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  teacher_topic_id INT     NOT NULL,
  attempt_no       INT     NOT NULL DEFAULT 1,
  test_date        DATE    NULL,
  status           ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'draft',
  pass_rate        DECIMAL(6,2) NULL,
  submitted_by     INT     NULL,
  submitted_at     TIMESTAMP NULL,
  approved_by      INT     NULL,
  approved_at      TIMESTAMP NULL,
  reject_reason    TEXT    NULL,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ttest_topic    FOREIGN KEY (teacher_topic_id) REFERENCES teacher_topics(id) ON DELETE CASCADE,
  CONSTRAINT fk_ttest_submit   FOREIGN KEY (submitted_by)     REFERENCES users(id)          ON DELETE SET NULL,
  CONSTRAINT fk_ttest_approve  FOREIGN KEY (approved_by)      REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS topic_test_students (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  topic_test_id INT           NOT NULL,
  student_id    INT           NOT NULL,
  score         DECIMAL(6,2)  NULL,
  absent        TINYINT(1)    NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_tts (topic_test_id, student_id),
  CONSTRAINT fk_tts_test    FOREIGN KEY (topic_test_id) REFERENCES topic_tests(id) ON DELETE CASCADE,
  CONSTRAINT fk_tts_student FOREIGN KEY (student_id)   REFERENCES students(id)    ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT          NOT NULL,
  type       VARCHAR(60)  NOT NULL,
  title      VARCHAR(200) NOT NULL,
  message    TEXT         NOT NULL,
  is_read    TINYINT(1)   NOT NULL DEFAULT 0,
  ref_id     INT          NULL,
  ref_type   VARCHAR(50)  NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notif_user_read    (user_id, is_read),
  INDEX idx_notif_created      (created_at),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reg_sequences (
  school_id INT  NOT NULL,
  year      YEAR NOT NULL,
  next_seq  INT  NOT NULL DEFAULT 1,
  PRIMARY KEY (school_id, year),
  CONSTRAINT fk_regseq_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─────────────────────────────────────────────────────────────────────
-- INDEXES (performance)
-- ─────────────────────────────────────────────────────────────────────

CREATE INDEX idx_students_school_level_status   ON students             (school_id, level_id, status);
CREATE INDEX idx_marks_exam_subject              ON marks                (exam_id, subject_id);
CREATE INDEX idx_marks_student_subject           ON marks                (student_id, subject_id);
CREATE INDEX idx_notifications_user_read_created ON notifications        (user_id, is_read, created_at);
CREATE INDEX idx_ta_teacher_school               ON teacher_assignments  (teacher_id, school_id);
CREATE INDEX idx_teacher_topics_assign_status    ON teacher_topics       (teacher_assignment_id, status);
CREATE INDEX idx_exams_status_year               ON exams                (status, year);

SET FOREIGN_KEY_CHECKS = 1;

-- ═══════════════════════════════════════════════════════════════════════
-- SEEDS – data ya msingi inayohitajika mara zote
-- ═══════════════════════════════════════════════════════════════════════

-- ── 1. Levels ──────────────────────────────────────────────────────────
INSERT IGNORE INTO levels (id, name, category) VALUES
(1,'Form 1','o_level'),
(2,'Form 2','o_level'),
(3,'Form 3','o_level'),
(4,'Form 4','o_level'),
(5,'Form 5','a_level'),
(6,'Form 6','a_level');

-- ── 2. Grading Scales (final/corrected values) ─────────────────────────
INSERT IGNORE INTO grading_scales (category, grade, min_mark, max_mark, points) VALUES
('o_level','A', 75,  100,    1),
('o_level','B', 65,  74.999, 2),
('o_level','C', 50,  64.999, 3),
('o_level','D', 30,  49.999, 4),
('o_level','F',  0,  29.999, 5),
('a_level','A', 80,  100,    1),
('a_level','B', 70,  79.999, 2),
('a_level','C', 60,  69.999, 3),
('a_level','D', 50,  59.999, 4),
('a_level','E', 40,  49.999, 5),
('a_level','S', 35,  39.999, 6),
('a_level','F',  0,  34.999, 7);

-- ── 3. O-Level Subjects (NECTA approved) ──────────────────────────────
INSERT IGNORE INTO subjects (category, name, code, abbr, is_principal, has_practical, practical_max, status) VALUES
('o_level','Civics','011','CIV',0,0,0,'active'),
('o_level','History','012','HIST',0,0,0,'active'),
('o_level','Geography','013','GEO',0,0,0,'active'),
('o_level','Bible Knowledge','014','B/K',0,0,0,'active'),
('o_level','Elimu ya Dini ya Kiislamu (EDK)','015','EDK',0,0,0,'active'),
('o_level','Fine Art','016','F/A',0,0,0,'active'),
('o_level','Music','017','MUS',0,0,0,'active'),
('o_level','Physical Education','018','PE',0,0,0,'active'),
('o_level','Theatre Arts','019','T/A',0,0,0,'active'),
('o_level','Kiswahili','021','KISW',0,0,0,'active'),
('o_level','English Language','022','ENG',0,0,0,'active'),
('o_level','French Language','023','FRE',0,0,0,'active'),
('o_level','Literature in English','024','LIT',0,0,0,'active'),
('o_level','Arabic Language','025','ARAB',0,0,0,'active'),
('o_level','Chinese Language','026','CHN',0,0,0,'active'),
('o_level','Physics','031','PHY',0,0,0,'active'),
('o_level','Chemistry','032','CHEM',0,0,0,'active'),
('o_level','Biology','033','BIO',0,0,0,'active'),
('o_level','Agriculture','034','AGRI',0,0,0,'active'),
('o_level','Engineering Science','035','E/SC',0,0,0,'active'),
('o_level','Information and Computer Studies (ICS)','036','ICS',0,0,0,'active'),
('o_level','Basic Mathematics','041','B/MATH',0,0,0,'active'),
('o_level','Additional Mathematics','042','ADD/M',0,0,0,'active'),
('o_level','Food and Human Nutrition','051','FHN',0,0,0,'active'),
('o_level','Textiles and Garment Construction','052','TGC',0,0,0,'active'),
('o_level','Commerce','061','COMM',0,0,0,'active'),
('o_level','Book-Keeping','062','B/KP',0,0,0,'active'),
('o_level','Building Construction','071','B/CON',0,0,0,'active'),
('o_level','Architectural Draughting','072','ARCH',0,0,0,'active'),
('o_level','Civil Engineering Surveying','073','CES',0,0,0,'active'),
('o_level','Woodwork and Painting Engineering','074','WPE',0,0,0,'active'),
('o_level','Electrical Engineering','080','E/ENG',0,0,0,'active'),
('o_level','Electronics and Communication Engineering','081','ECE',0,0,0,'active'),
('o_level','Electrical Draughting','082','E/DRG',0,0,0,'active'),
('o_level','Electronics Draughting','083','EL/DR',0,0,0,'active'),
('o_level','Automotive Engineering','087','AUTO',0,0,0,'active'),
('o_level','Manufacturing Engineering','088','MFG',0,0,0,'active'),
('o_level','Engineering Drawing','091','E/DRW',0,0,0,'active');

-- ── 4. A-Level Subjects (NECTA approved) ──────────────────────────────
INSERT IGNORE INTO subjects (category, name, code, abbr, is_principal, alevel_subject_type, has_practical, practical_max, status) VALUES
('a_level','General Studies','111','G/S',0,'subsidiary',0,0,'active'),
('a_level','History','112','HIST',1,'principal',0,0,'active'),
('a_level','Geography','113','GEO',1,'principal',0,0,'active'),
('a_level','Divinity','114','DIV',1,'principal',0,0,'active'),
('a_level','Islamic Knowledge','115','I/K',1,'principal',0,0,'active'),
('a_level','Kiswahili','121','KISW',1,'principal',0,0,'active'),
('a_level','English Language','122','ENG',1,'principal',0,0,'active'),
('a_level','French Language','123','FRE',1,'principal',1,100,'active'),
('a_level','Arabic Language','125','ARAB',1,'principal',0,0,'active'),
('a_level','Physics','131','PHY',1,'principal',1,100,'active'),
('a_level','Chemistry','132','CHEM',1,'principal',1,100,'active'),
('a_level','Biology','133','BIO',1,'principal',1,100,'active'),
('a_level','Agriculture','134','AGRI',1,'principal',1,100,'active'),
('a_level','Computer Science','136','C/SC',1,'principal',1,100,'active'),
('a_level','Basic Applied Mathematics','141','B/AM',1,'principal',0,0,'active'),
('a_level','Advanced Mathematics','142','A/M',1,'principal',0,0,'active'),
('a_level','Economics','151','ECON',1,'principal',0,0,'active'),
('a_level','Commerce','152','COMM',1,'principal',0,0,'active'),
('a_level','Accountancy','153','ACCT',1,'principal',0,0,'active'),
('a_level','Food and Human Nutrition','155','FHN',1,'principal',1,100,'active');

-- ── 5. A-Level Combinations (13 NECTA-approved) ────────────────────────
INSERT IGNORE INTO alevel_combinations (code, name) VALUES
('PCM', 'Physics, Chemistry, Advanced Mathematics'),
('PCB', 'Physics, Chemistry, Biology'),
('PGM', 'Physics, Geography, Advanced Mathematics'),
('EGM', 'Economics, Geography, Advanced Mathematics'),
('CBG', 'Chemistry, Biology, Geography'),
('CBA', 'Chemistry, Biology, Agriculture'),
('CBN', 'Chemistry, Biology, Food and Human Nutrition'),
('HGL', 'History, Geography, English Language'),
('HGK', 'History, Geography, Kiswahili'),
('HKL', 'History, Kiswahili, English Language'),
('KLF', 'Kiswahili, English Language, French'),
('ECA', 'Economics, Commerce, Accountancy'),
('HGE', 'History, Geography, Economics');

-- ── 6. Combination ↔ Subjects links ────────────────────────────────────
-- SCIENCE
INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code='PCM' AND s.code IN ('131','132','142');

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code='PCB' AND s.code IN ('131','132','133');

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code='PGM' AND s.code IN ('131','113','142');

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code='EGM' AND s.code IN ('151','113','142');

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code='CBG' AND s.code IN ('132','133','113');

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code='CBA' AND s.code IN ('132','133','134');

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code='CBN' AND s.code IN ('132','133','155');

-- ARTS & BUSINESS
INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code='HGL' AND s.code IN ('112','113','122');

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code='HGK' AND s.code IN ('112','113','121');

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code='HKL' AND s.code IN ('112','121','122');

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code='KLF' AND s.code IN ('121','122','123');

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code='ECA' AND s.code IN ('151','152','153');

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code='HGE' AND s.code IN ('112','113','151');

-- ── 7. Super Admin
-- LOGIN: email=admin@iramba.go.tz  password=Admin@123
INSERT IGNORE INTO users (school_id, full_name, email, username, password_hash, role, status)
VALUES (NULL, 'Super Admin', 'admin@iramba.go.tz', 'super_admin',
        '$2y$10$lMqYAZi07uUboYqWb/.lVOV7y1cV8G1U7pAhU8y7kNzwDygagVXc2',
        'super_admin', 'active');
