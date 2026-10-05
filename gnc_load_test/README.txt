GNC-OSA PORTAL - LOAD TEST DATA
================================
Laman: 8 course elections (BSIT, BSBA, BSED, BEED, BSN, BSPharm, BSHM, BSA) - bawat isa ay may
7 positions, 8 party lists at 55 candidates (52 may party, 3 independent; estudyante ng course mismo).
27,016 estudyante (2022-2026 batches). Ang bawat estudyante ay bumoboto sa election ng SARILING course.
Login ng test student: Student ID + password "student123" (SQL) o password = Student ID (Excel import).

A) VIA SQL (test data lang, ~15 segundo) - HIWALAY na ang system at ang test data
  1. phpMyAdmin > gumawa ng BAGONG walang-laman na database na gnc_osa_portal > Import
     > ang gnc_osa_portal.sql (nasa labas ng folder na ito; ito ang system mismo)
  2. Piliin ang database na gnc_osa_portal > Import > sql/gnc_osa_portal_TEST_DATA_ONLY_27k.sql
     (Kung "max upload size" ang error: C:\xampp\mysql\bin\mysql.exe -u root gnc_osa_portal < gnc_osa_portal_TEST_DATA_ONLY_27k.sql)
  3. Ayaw ng votes/vouchers? Palitan ang "SET @turnout = 70;" (malapit sa dulo ng file) ng 0 bago i-import.

B) VIA EXCEL IMPORT (sinusubukan ang importer)
  1. Import ang orihinal na gnc_osa_portal.sql sa bagong database.
  2. sql/2a_create_course_elections_for_excel_path.sql -> gumagawa ng 8 elections + positions
     (o gawin mano-mano sa Admin > Elections; eksakto ang spelling ng positions:
      President, Vice-President, Secretary, Treasurer, Auditor, Public Information Officer, Peace Officer)
  3. Admin > Students > Import > excel/students_10k.xlsx (o 20k / 30k; ~9k / ~18k / 27,016 ang aktwal na bilang)
  4. Sa BAWAT election: Party Lists > Import > excel/party_lists_8.xlsx
                        Candidates > Import > excel/candidates_by_course/candidates_<COURSE>.xlsx (katugmang course)
  5. sql/2b_assign_parties_after_excel_import.sql (walang party column ang candidate importer)
  6. Optional votes: patakbuhin ang PART 2 (mula "-- PART 2: VOUCHERS + VOTES") ng FULL sql file.

CLEANUP: sql/9_cleanup_remove_test_data.sql binubura ang lahat ng test data (hindi ginagalaw ang tunay mong data).
