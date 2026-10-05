-- Excel path: gumagawa ng 8 course elections + 7 positions bawat isa (kapalit ng mano-manong paggawa sa Admin).
-- Pagkatapos nito: i-import ang students, party_lists_8.xlsx sa BAWAT election, at ang candidates_<COURSE>.xlsx sa katugmang election.
SET NAMES utf8mb4;
INSERT INTO elections (title,description,course,start_date,end_date,is_active,is_hold,show_results,created_by) VALUES
('BSIT Student Council Election 2026-2027','LOADTEST - generated test election','BSIT','2026-09-30 00:00:00','2026-10-07 23:59:00',1,0,0,(SELECT MIN(id) FROM osa_staff WHERE role='admin')),
('BSBA Student Council Election 2026-2027','LOADTEST - generated test election','BSBA','2026-09-30 00:00:00','2026-10-07 23:59:00',1,0,0,(SELECT MIN(id) FROM osa_staff WHERE role='admin')),
('BSED Student Council Election 2026-2027','LOADTEST - generated test election','BSED','2026-09-30 00:00:00','2026-10-07 23:59:00',1,0,0,(SELECT MIN(id) FROM osa_staff WHERE role='admin')),
('BEED Student Council Election 2026-2027','LOADTEST - generated test election','BEED','2026-09-30 00:00:00','2026-10-07 23:59:00',1,0,0,(SELECT MIN(id) FROM osa_staff WHERE role='admin')),
('BSN Student Council Election 2026-2027','LOADTEST - generated test election','BSN','2026-09-30 00:00:00','2026-10-07 23:59:00',1,0,0,(SELECT MIN(id) FROM osa_staff WHERE role='admin')),
('BSPharm Student Council Election 2026-2027','LOADTEST - generated test election','BSPharm','2026-09-30 00:00:00','2026-10-07 23:59:00',1,0,0,(SELECT MIN(id) FROM osa_staff WHERE role='admin')),
('BSHM Student Council Election 2026-2027','LOADTEST - generated test election','BSHM','2026-09-30 00:00:00','2026-10-07 23:59:00',1,0,0,(SELECT MIN(id) FROM osa_staff WHERE role='admin')),
('BSA Student Council Election 2026-2027','LOADTEST - generated test election','BSA','2026-09-30 00:00:00','2026-10-07 23:59:00',1,0,0,(SELECT MIN(id) FROM osa_staff WHERE role='admin'));
INSERT INTO positions (election_id,title,max_votes,sort_order) SELECT e.id,t.t,1,t.o FROM elections e CROSS JOIN (SELECT 'President' t, 0 o UNION ALL SELECT 'Vice-President' t, 1 o UNION ALL SELECT 'Secretary' t, 2 o UNION ALL SELECT 'Treasurer' t, 3 o UNION ALL SELECT 'Auditor' t, 4 o UNION ALL SELECT 'Public Information Officer' t, 5 o UNION ALL SELECT 'Peace Officer' t, 6 o) t WHERE e.description LIKE 'LOADTEST%';
