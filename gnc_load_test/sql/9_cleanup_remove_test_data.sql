-- Binubura LAHAT ng test data (mga election na may description 'LOADTEST...' at mga estudyanteng @loadtest.invalid).
-- Hindi ginagalaw ang tunay mong students/elections. Cascade ang candidates, positions, party lists, votes, vouchers.
DELETE FROM elections WHERE description LIKE 'LOADTEST%';
DELETE FROM students  WHERE email LIKE '%@loadtest.invalid';
DROP TABLE IF EXISTS _lt_voters, _lt_pw, _lt_vp, _lt_cand, _lt_cnt;
SELECT (SELECT COUNT(*) FROM students) students_left, (SELECT COUNT(*) FROM elections) elections_left;
