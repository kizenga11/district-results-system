-- Add abbreviation column to subjects and populate all NECTA subjects
-- Run once in phpMyAdmin. If "Duplicate column" error on ALTER TABLE, skip it and run UPDATEs only.

ALTER TABLE subjects ADD COLUMN abbr VARCHAR(20) NULL AFTER code;

-- O-Level abbreviations (codes 011–091)
UPDATE subjects SET abbr='CIV'    WHERE code='011' AND abbr IS NULL;
UPDATE subjects SET abbr='HIST'   WHERE code='012' AND abbr IS NULL;
UPDATE subjects SET abbr='GEO'    WHERE code='013' AND abbr IS NULL;
UPDATE subjects SET abbr='B/K'    WHERE code='014' AND abbr IS NULL;
UPDATE subjects SET abbr='EDK'    WHERE code='015' AND abbr IS NULL;
UPDATE subjects SET abbr='F/A'    WHERE code='016' AND abbr IS NULL;
UPDATE subjects SET abbr='MUS'    WHERE code='017' AND abbr IS NULL;
UPDATE subjects SET abbr='PE'     WHERE code='018' AND abbr IS NULL;
UPDATE subjects SET abbr='T/A'    WHERE code='019' AND abbr IS NULL;
UPDATE subjects SET abbr='KISW'   WHERE code='021' AND abbr IS NULL;
UPDATE subjects SET abbr='ENG'    WHERE code='022' AND abbr IS NULL;
UPDATE subjects SET abbr='FRE'    WHERE code='023' AND abbr IS NULL;
UPDATE subjects SET abbr='LIT'    WHERE code='024' AND abbr IS NULL;
UPDATE subjects SET abbr='ARAB'   WHERE code='025' AND abbr IS NULL;
UPDATE subjects SET abbr='CHN'    WHERE code='026' AND abbr IS NULL;
UPDATE subjects SET abbr='PHY'    WHERE code='031' AND abbr IS NULL;
UPDATE subjects SET abbr='CHEM'   WHERE code='032' AND abbr IS NULL;
UPDATE subjects SET abbr='BIO'    WHERE code='033' AND abbr IS NULL;
UPDATE subjects SET abbr='AGRI'   WHERE code='034' AND abbr IS NULL;
UPDATE subjects SET abbr='E/SC'   WHERE code='035' AND abbr IS NULL;
UPDATE subjects SET abbr='ICS'    WHERE code='036' AND abbr IS NULL;
UPDATE subjects SET abbr='B/MATH' WHERE code='041' AND abbr IS NULL;
UPDATE subjects SET abbr='ADD/M'  WHERE code='042' AND abbr IS NULL;
UPDATE subjects SET abbr='FHN'    WHERE code='051' AND abbr IS NULL;
UPDATE subjects SET abbr='TGC'    WHERE code='052' AND abbr IS NULL;
UPDATE subjects SET abbr='COMM'   WHERE code='061' AND abbr IS NULL;
UPDATE subjects SET abbr='B/KP'   WHERE code='062' AND abbr IS NULL;
UPDATE subjects SET abbr='B/CON'  WHERE code='071' AND abbr IS NULL;
UPDATE subjects SET abbr='ARCH'   WHERE code='072' AND abbr IS NULL;
UPDATE subjects SET abbr='CES'    WHERE code='073' AND abbr IS NULL;
UPDATE subjects SET abbr='WPE'    WHERE code='074' AND abbr IS NULL;
UPDATE subjects SET abbr='E/ENG'  WHERE code='080' AND abbr IS NULL;
UPDATE subjects SET abbr='ECE'    WHERE code='081' AND abbr IS NULL;
UPDATE subjects SET abbr='E/DRG'  WHERE code='082' AND abbr IS NULL;
UPDATE subjects SET abbr='EL/DR'  WHERE code='083' AND abbr IS NULL;
UPDATE subjects SET abbr='AUTO'   WHERE code='087' AND abbr IS NULL;
UPDATE subjects SET abbr='MFG'    WHERE code='088' AND abbr IS NULL;
UPDATE subjects SET abbr='E/DRW'  WHERE code='091' AND abbr IS NULL;

-- A-Level abbreviations (codes 111–155)
UPDATE subjects SET abbr='G/S'    WHERE code='111' AND abbr IS NULL;
UPDATE subjects SET abbr='HIST'   WHERE code='112' AND abbr IS NULL;
UPDATE subjects SET abbr='GEO'    WHERE code='113' AND abbr IS NULL;
UPDATE subjects SET abbr='DIV'    WHERE code='114' AND abbr IS NULL;
UPDATE subjects SET abbr='I/K'    WHERE code='115' AND abbr IS NULL;
UPDATE subjects SET abbr='KISW'   WHERE code='121' AND abbr IS NULL;
UPDATE subjects SET abbr='ENG'    WHERE code='122' AND abbr IS NULL;
UPDATE subjects SET abbr='FRE'    WHERE code='123' AND abbr IS NULL;
UPDATE subjects SET abbr='ARAB'   WHERE code='125' AND abbr IS NULL;
UPDATE subjects SET abbr='PHY'    WHERE code='131' AND abbr IS NULL;
UPDATE subjects SET abbr='CHEM'   WHERE code='132' AND abbr IS NULL;
UPDATE subjects SET abbr='BIO'    WHERE code='133' AND abbr IS NULL;
UPDATE subjects SET abbr='AGRI'   WHERE code='134' AND abbr IS NULL;
UPDATE subjects SET abbr='C/SC'   WHERE code='136' AND abbr IS NULL;
UPDATE subjects SET abbr='B/AM'   WHERE code='141' AND abbr IS NULL;
UPDATE subjects SET abbr='A/M'    WHERE code='142' AND abbr IS NULL;
UPDATE subjects SET abbr='ECON'   WHERE code='151' AND abbr IS NULL;
UPDATE subjects SET abbr='COMM'   WHERE code='152' AND abbr IS NULL;
UPDATE subjects SET abbr='ACCT'   WHERE code='153' AND abbr IS NULL;
UPDATE subjects SET abbr='FHN'    WHERE code='155' AND abbr IS NULL;
