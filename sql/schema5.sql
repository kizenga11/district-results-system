-- Schema 5: Notifications
-- Salama kutekeleza mara nyingi.

SET FOREIGN_KEY_CHECKS=0;

DROP PROCEDURE IF EXISTS _schema5_upgrades;
DELIMITER //
CREATE PROCEDURE _schema5_upgrades()
BEGIN
    CREATE TABLE IF NOT EXISTS notifications (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      type VARCHAR(60) NOT NULL,
      title VARCHAR(200) NOT NULL,
      message TEXT NOT NULL,
      is_read TINYINT(1) NOT NULL DEFAULT 0,
      ref_id INT NULL,
      ref_type VARCHAR(50) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      INDEX idx_notif_user_read (user_id, is_read),
      INDEX idx_notif_created (created_at)
    ) ENGINE=InnoDB;
END //
DELIMITER ;

CALL _schema5_upgrades();
DROP PROCEDURE IF EXISTS _schema5_upgrades;

SET FOREIGN_KEY_CHECKS=1;
