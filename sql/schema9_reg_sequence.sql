-- Track next registration number sequence per school per year
-- Auto-increments atomically to prevent duplicate reg numbers

CREATE TABLE IF NOT EXISTS reg_sequences (
    school_id  INT  NOT NULL,
    year       YEAR NOT NULL,
    next_seq   INT  NOT NULL DEFAULT 1,
    PRIMARY KEY (school_id, year),
    CONSTRAINT fk_regseq_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB;
