-- ── A-Level Subjects & Combinations Seed ──────────────────────────
-- Safe to run multiple times (INSERT IGNORE / DELETE before insert).

SET FOREIGN_KEY_CHECKS=0;

-- Remove old A-Level data before re-seeding
DELETE FROM alevel_combination_subjects;
DELETE FROM school_alevel_combinations;
DELETE FROM student_combinations;
DELETE FROM alevel_combinations;
DELETE FROM subjects WHERE category = 'a_level';

SET FOREIGN_KEY_CHECKS=1;

-- ── 1. A-Level Subjects ────────────────────────────────────────────

INSERT INTO subjects (category, name, code, abbr, is_principal, alevel_subject_type, has_practical, practical_max, status) VALUES
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

-- ── 2. Combinations (13 NECTA-approved) ────────────────────────────

INSERT INTO alevel_combinations (code, name) VALUES
('PCM','Physics, Chemistry, Advanced Mathematics'),
('PCB','Physics, Chemistry, Biology'),
('PGM','Physics, Geography, Advanced Mathematics'),
('EGM','Economics, Geography, Advanced Mathematics'),
('CBG','Chemistry, Biology, Geography'),
('CBA','Chemistry, Biology, Agriculture'),
('CBN','Chemistry, Biology, Food and Human Nutrition'),
('HGL','History, Geography, English Language'),
('HGK','History, Geography, Kiswahili'),
('HKL','History, Kiswahili, English Language'),
('KLF','Kiswahili, English Language, French'),
('ECA','Economics, Commerce, Accountancy'),
('HGE','History, Geography, Economics');

-- ── 3. Link Subjects to Combinations ───────────────────────────────
-- SCIENCE CLUSTER

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code = 'PCM' AND s.code IN ('131','132','142');

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code = 'PCB' AND s.code IN ('131','132','133');

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code = 'PGM' AND s.code IN ('131','113','142');

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code = 'EGM' AND s.code IN ('151','113','142');

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code = 'CBG' AND s.code IN ('132','133','113');

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code = 'CBA' AND s.code IN ('132','133','134');

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code = 'CBN' AND s.code IN ('132','133','155');

-- ARTS & BUSINESS CLUSTER

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code = 'HGL' AND s.code IN ('112','113','122');

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code = 'HGK' AND s.code IN ('112','113','121');

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code = 'HKL' AND s.code IN ('112','121','122');

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code = 'KLF' AND s.code IN ('121','122','123');

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code = 'ECA' AND s.code IN ('151','152','153');

INSERT IGNORE INTO alevel_combination_subjects (combination_id, subject_id)
SELECT c.id, s.id FROM alevel_combinations c, subjects s
WHERE c.code = 'HGE' AND s.code IN ('112','113','151');
