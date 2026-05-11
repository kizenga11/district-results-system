-- Schema 3: Teaching Progress feature
-- Safe to run multiple times.

SET FOREIGN_KEY_CHECKS=0;

DROP PROCEDURE IF EXISTS _schema3_upgrades;
DELIMITER //
CREATE PROCEDURE _schema3_upgrades()
BEGIN
    CREATE TABLE IF NOT EXISTS teacher_topics (
      id INT AUTO_INCREMENT PRIMARY KEY,
      teacher_assignment_id INT NOT NULL,
      title VARCHAR(255) NOT NULL,
      competence TEXT NULL,
      sort_order INT NOT NULL DEFAULT 0,
      status ENUM('planned','in_progress','completed') NOT NULL DEFAULT 'planned',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_tt_assign FOREIGN KEY (teacher_assignment_id) REFERENCES teacher_assignments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS teaching_progress_log (
      id INT AUTO_INCREMENT PRIMARY KEY,
      teacher_topic_id INT NOT NULL,
      log_date DATE NOT NULL,
      notes TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_tpl_topic FOREIGN KEY (teacher_topic_id) REFERENCES teacher_topics(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS topic_tests (
      id INT AUTO_INCREMENT PRIMARY KEY,
      teacher_topic_id INT NOT NULL,
      attempt_no INT NOT NULL DEFAULT 1,
      test_date DATE NULL,
      status ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'draft',
      pass_rate DECIMAL(6,2) NULL,
      submitted_by INT NULL,
      submitted_at TIMESTAMP NULL,
      approved_by INT NULL,
      approved_at TIMESTAMP NULL,
      reject_reason TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_ttest_topic FOREIGN KEY (teacher_topic_id) REFERENCES teacher_topics(id) ON DELETE CASCADE,
      CONSTRAINT fk_ttest_submit FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_ttest_approve FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS topic_test_students (
      id INT AUTO_INCREMENT PRIMARY KEY,
      topic_test_id INT NOT NULL,
      student_id INT NOT NULL,
      score DECIMAL(6,2) NULL,
      absent TINYINT(1) NOT NULL DEFAULT 0,
      CONSTRAINT fk_tts_test FOREIGN KEY (topic_test_id) REFERENCES topic_tests(id) ON DELETE CASCADE,
      CONSTRAINT fk_tts_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
      UNIQUE KEY uniq_tts (topic_test_id, student_id)
    ) ENGINE=InnoDB;
END //
DELIMITER ;

CALL _schema3_upgrades();
DROP PROCEDURE IF EXISTS _schema3_upgrades;

SET FOREIGN_KEY_CHECKS=1;
