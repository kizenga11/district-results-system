SET FOREIGN_KEY_CHECKS=0;
TRUNCATE TABLE school_subjects;
TRUNCATE TABLE student_subjects;
TRUNCATE TABLE marks;
TRUNCATE TABLE grading_scales;
TRUNCATE TABLE subjects;
SET FOREIGN_KEY_CHECKS=1;

INSERT INTO subjects (category, name, code, abbr, is_principal, has_practical, practical_max, status) VALUES
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
