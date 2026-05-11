-- Schema 2 (upgrade) - run AFTER schema.sql on the same database
-- Safe to run multiple times.

SET FOREIGN_KEY_CHECKS=0;

DROP PROCEDURE IF EXISTS _schema2_upgrades;
DELIMITER //
CREATE PROCEDURE _schema2_upgrades()
BEGIN
    -- 1) Ensure subjects has is_principal
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'subjects'
          AND COLUMN_NAME  = 'is_principal'
    ) THEN
        ALTER TABLE subjects
            ADD COLUMN is_principal TINYINT(1) NOT NULL DEFAULT 0 AFTER code;
    END IF;

    -- 1b) A-Level subject type (principal/subsidiary/additional)
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'subjects'
          AND COLUMN_NAME  = 'alevel_subject_type'
    ) THEN
        ALTER TABLE subjects
            ADD COLUMN alevel_subject_type ENUM('principal','subsidiary','additional') NULL AFTER is_principal;

        -- Backfill based on existing is_principal for A-Level
        UPDATE subjects
        SET alevel_subject_type = CASE
            WHEN category = 'a_level' AND is_principal = 1 THEN 'principal'
            WHEN category = 'a_level' AND is_principal = 0 THEN 'subsidiary'
            ELSE NULL
        END
        WHERE alevel_subject_type IS NULL;
    END IF;

    -- Keep compatibility: if alevel_subject_type is set to principal, ensure is_principal=1
    UPDATE subjects
    SET is_principal = 1
    WHERE category = 'a_level' AND alevel_subject_type = 'principal' AND is_principal <> 1;

    -- 2) Ensure school_subjects table exists
    CREATE TABLE IF NOT EXISTS school_subjects (
      school_id  INT NOT NULL,
      subject_id INT NOT NULL,
      PRIMARY KEY (school_id, subject_id),
      CONSTRAINT fk_ss_school   FOREIGN KEY (school_id)  REFERENCES schools(id)  ON DELETE CASCADE,
      CONSTRAINT fk_ss_subject  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;

    -- 3) Ensure student_subjects table exists
    CREATE TABLE IF NOT EXISTS student_subjects (
      student_id INT NOT NULL,
      subject_id INT NOT NULL,
      PRIMARY KEY (student_id, subject_id),
      CONSTRAINT fk_studsub_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
      CONSTRAINT fk_studsub_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;

    -- 4) Add helpful columns to student_subjects for faster filtering & validation
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'student_subjects'
          AND COLUMN_NAME  = 'school_id'
    ) THEN
        ALTER TABLE student_subjects
            ADD COLUMN school_id INT NULL AFTER subject_id,
            ADD INDEX idx_student_subjects_school (school_id),
            ADD CONSTRAINT fk_student_subjects_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL;

        -- Backfill school_id from students
        UPDATE student_subjects ss
        JOIN students s ON s.id = ss.student_id
        SET ss.school_id = s.school_id
        WHERE ss.school_id IS NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'student_subjects'
          AND COLUMN_NAME  = 'level_id'
    ) THEN
        ALTER TABLE student_subjects
            ADD COLUMN level_id TINYINT NULL AFTER school_id,
            ADD INDEX idx_student_subjects_level (level_id),
            ADD CONSTRAINT fk_student_subjects_level FOREIGN KEY (level_id) REFERENCES levels(id) ON DELETE SET NULL;

        -- Backfill level_id from students
        UPDATE student_subjects ss
        JOIN students s ON s.id = ss.student_id
        SET ss.level_id = s.level_id
        WHERE ss.level_id IS NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'student_subjects'
          AND COLUMN_NAME  = 'created_at'
    ) THEN
        ALTER TABLE student_subjects
            ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
    END IF;

    -- 5) A-Level combinations (GLOBAL) + activation per school
    -- Legacy schema had alevel_combinations(school_id,...). We migrate to:
    -- - alevel_combinations: global list
    -- - school_alevel_combinations: per-school activation/status

    -- Ensure global combinations table exists
    CREATE TABLE IF NOT EXISTS alevel_combinations (
      id INT AUTO_INCREMENT PRIMARY KEY,
      code VARCHAR(20) NOT NULL,
      name VARCHAR(120) NULL,
      status ENUM('active','inactive') NOT NULL DEFAULT 'active',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_combo_code (code)
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS alevel_combination_subjects (
      combination_id INT NOT NULL,
      subject_id INT NOT NULL,
      PRIMARY KEY (combination_id, subject_id),
      CONSTRAINT fk_combo_sub_combo FOREIGN KEY (combination_id) REFERENCES alevel_combinations(id) ON DELETE CASCADE,
      CONSTRAINT fk_combo_sub_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS school_alevel_combinations (
      school_id INT NOT NULL,
      combination_id INT NOT NULL,
      status ENUM('active','inactive') NOT NULL DEFAULT 'active',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (school_id, combination_id),
      CONSTRAINT fk_sac_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
      CONSTRAINT fk_sac_combo  FOREIGN KEY (combination_id) REFERENCES alevel_combinations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS student_combinations (
      student_id INT NOT NULL,
      combination_id INT NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (student_id),
      CONSTRAINT fk_student_combo_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
      CONSTRAINT fk_student_combo_combo FOREIGN KEY (combination_id) REFERENCES alevel_combinations(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB;

    -- Migrate legacy per-school combinations if present
    IF EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'alevel_combinations'
          AND COLUMN_NAME  = 'school_id'
    ) THEN
        -- 1) Create new global tables (temp names)
        CREATE TABLE IF NOT EXISTS alevel_combinations_global (
          id INT AUTO_INCREMENT PRIMARY KEY,
          code VARCHAR(20) NOT NULL,
          name VARCHAR(120) NULL,
          status ENUM('active','inactive') NOT NULL DEFAULT 'active',
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_combo_code (code)
        ) ENGINE=InnoDB;

        CREATE TABLE IF NOT EXISTS alevel_combination_subjects_global (
          combination_id INT NOT NULL,
          subject_id INT NOT NULL,
          PRIMARY KEY (combination_id, subject_id),
          CONSTRAINT fk_combo_sub_combo_g FOREIGN KEY (combination_id) REFERENCES alevel_combinations_global(id) ON DELETE CASCADE,
          CONSTRAINT fk_combo_sub_subject_g FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB;

        CREATE TABLE IF NOT EXISTS school_alevel_combinations_global (
          school_id INT NOT NULL,
          combination_id INT NOT NULL,
          status ENUM('active','inactive') NOT NULL DEFAULT 'active',
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (school_id, combination_id),
          CONSTRAINT fk_sac_school_g FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
          CONSTRAINT fk_sac_combo_g  FOREIGN KEY (combination_id) REFERENCES alevel_combinations_global(id) ON DELETE CASCADE
        ) ENGINE=InnoDB;

        -- 2) Populate global combos (merge by code)
        INSERT IGNORE INTO alevel_combinations_global (code, name, status, created_at)
        SELECT UPPER(TRIM(code)) AS code,
               NULLIF(MAX(NULLIF(TRIM(name), '')), '') AS name,
               'active' AS status,
               MIN(created_at) AS created_at
        FROM alevel_combinations
        GROUP BY UPPER(TRIM(code));

        -- 3) Subjects mapping: old combo -> code -> new combo
        INSERT IGNORE INTO alevel_combination_subjects_global (combination_id, subject_id)
        SELECT cg.id AS combination_id, cs.subject_id
        FROM alevel_combinations c
        JOIN alevel_combinations_global cg ON cg.code = UPPER(TRIM(c.code))
        JOIN alevel_combination_subjects cs ON cs.combination_id = c.id;

        -- 4) School activation (status per school)
        INSERT IGNORE INTO school_alevel_combinations_global (school_id, combination_id, status, created_at)
        SELECT c.school_id, cg.id,
               c.status,
               c.created_at
        FROM alevel_combinations c
        JOIN alevel_combinations_global cg ON cg.code = UPPER(TRIM(c.code));

        -- 5) Migrate student_combinations to global combo ids if it exists
        IF EXISTS (
            SELECT 1 FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'student_combinations'
        ) THEN
            -- student -> old combo -> code -> new combo
            UPDATE student_combinations sc
            JOIN alevel_combinations c_old ON c_old.id = sc.combination_id
            JOIN alevel_combinations_global c_new ON c_new.code = UPPER(TRIM(c_old.code))
            SET sc.combination_id = c_new.id;
        END IF;

        -- 6) Swap tables: keep legacy backups
        RENAME TABLE alevel_combination_subjects TO alevel_combination_subjects_legacy;
        RENAME TABLE alevel_combinations TO alevel_combinations_legacy;
        RENAME TABLE school_alevel_combinations TO school_alevel_combinations_legacy;

        RENAME TABLE alevel_combinations_global TO alevel_combinations;
        RENAME TABLE alevel_combination_subjects_global TO alevel_combination_subjects;
        RENAME TABLE school_alevel_combinations_global TO school_alevel_combinations;
    END IF;

    -- 6) Permanent teacher assignments (headmaster managed)
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

END //
DELIMITER ;

CALL _schema2_upgrades();
DROP PROCEDURE IF EXISTS _schema2_upgrades;

SET FOREIGN_KEY_CHECKS=1;
