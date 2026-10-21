-- ============================================================
-- Uji trigger, function, procedure, constraint.
-- Jalankan SETELAH 01_schema.sql, 02_routines.sql, dan impor data.
-- Aman dijalankan ulang: membersihkan data uji di awal & akhir.
--   mariadb -h127.0.0.1 -P3307 -ucinelog -p cinelog < database/tests/test_routines.sql
-- Setiap baris SELECT diawali kolom `uji` agar mudah dibaca.
-- ============================================================
USE cinelog;

DELETE FROM users WHERE email LIKE '%@uji.cinelog.test';
INSERT INTO users (name, email, password) VALUES
  ('Uji Satu', 'u1@uji.cinelog.test', 'x'),
  ('Uji Dua',  'u2@uji.cinelog.test', 'x');
SET @u1 = (SELECT id FROM users WHERE email = 'u1@uji.cinelog.test');
SET @u2 = (SELECT id FROM users WHERE email = 'u2@uji.cinelog.test');
SET @m  = (SELECT id FROM movies ORDER BY id LIMIT 1);

-- T1: trigger INSERT -> agregat naik
INSERT INTO reviews (user_id, movie_id, rating) VALUES (@u1, @m, 8), (@u2, @m, 6);
SELECT 'T1 insert: harapan count=2 avg=7.00' AS uji, reviews_count, avg_rating FROM movies WHERE id = @m;

-- T2: trigger UPDATE -> agregat berubah + audit tercatat
UPDATE reviews SET rating = 10 WHERE user_id = @u2 AND movie_id = @m;
SELECT 'T2 update: harapan count=2 avg=9.00' AS uji, reviews_count, avg_rating FROM movies WHERE id = @m;
SELECT 'T2 audit: harapan 1 baris UPDATE 6->10' AS uji, action, old_rating, new_rating
  FROM review_audit WHERE movie_id = @m AND action = 'UPDATE' ORDER BY id DESC LIMIT 1;

-- T3: trigger DELETE -> agregat turun + audit tercatat
DELETE FROM reviews WHERE user_id = @u1 AND movie_id = @m;
SELECT 'T3 delete: harapan count=1 avg=10.00' AS uji, reviews_count, avg_rating FROM movies WHERE id = @m;
SELECT 'T3 audit: harapan DELETE rating lama 8' AS uji, action, old_rating
  FROM review_audit WHERE movie_id = @m AND action = 'DELETE' ORDER BY id DESC LIMIT 1;

-- T4: function weighted rating = (v/(v+5))*R + (5/(v+5))*C ; C = rata-rata SEMUA review.
-- Tambah satu review (rating 2) di film lain agar C berbeda dari R, jadi uji ini bermakna.
-- Harapan: film @m (v=1, R=10), C=(10+2)/2=6  ->  1/6*10 + 5/6*6 = 6.67
SET @m2 = (SELECT id FROM movies ORDER BY id LIMIT 1 OFFSET 1);
INSERT INTO reviews (user_id, movie_id, rating) VALUES (@u1, @m2, 2);
SELECT 'T4 function: harapan 6.67' AS uji,
       fn_weighted_rating(@m) AS dari_function,
       ROUND((1/6)*10 + (5/6)*(SELECT AVG(rating) FROM reviews), 2) AS hitung_manual;
DELETE FROM reviews WHERE user_id = @u1 AND movie_id = @m2;

-- T5: procedure upsert -> insert baru lalu update review yang sama (tetap 1 baris per user+film)
CALL sp_upsert_review(@u1, @m, 4, 'Judul', 'Isi', 0);
CALL sp_upsert_review(@u1, @m, 6, 'Judul 2', 'Isi 2', 1);
SELECT 'T5 upsert: harapan 1 baris rating=6' AS uji, COUNT(*) AS baris, MAX(rating) AS rating
  FROM reviews WHERE user_id = @u1 AND movie_id = @m;

-- T6: view
SELECT 'T6 view v_top_movies: film uji muncul' AS uji, title, reviews_count, avg_rating, weighted_rating
  FROM v_top_movies WHERE id = @m;
SELECT 'T6 view v_movie_summary' AS uji, title, genres, directors FROM v_movie_summary WHERE id = @m;

-- T7: constraint CHECK menolak rating di luar 1-10 (harapan: ERROR pada perintah berikut)
-- Dijalankan lewat prosedur agar skrip tidak berhenti; hasil ditampilkan sebagai teks.
DELIMITER //
DROP PROCEDURE IF EXISTS tmp_test_check//
CREATE PROCEDURE tmp_test_check(IN p_user BIGINT UNSIGNED, IN p_movie BIGINT UNSIGNED)
BEGIN
  DECLARE EXIT HANDLER FOR SQLEXCEPTION SELECT 'T7 CHECK rating=11 ditolak (benar)' AS uji;
  INSERT INTO reviews (user_id, movie_id, rating) VALUES (p_user, p_movie, 11);
  SELECT 'T7 GAGAL: rating 11 diterima' AS uji;
END//
DELIMITER ;
-- pakai user baru agar tidak bentrok dengan UNIQUE (user_id, movie_id)
INSERT INTO users (name, email, password) VALUES ('Uji Tiga', 'u3@uji.cinelog.test', 'x');
CALL tmp_test_check((SELECT id FROM users WHERE email = 'u3@uji.cinelog.test'), @m);
DROP PROCEDURE tmp_test_check;

-- T8: menghapus user harus ikut menyegarkan agregat film (trigger trg_users_bd)
SELECT 'T8 sebelum hapus user: harapan count=2' AS uji, reviews_count, avg_rating FROM movies WHERE id = @m;
DELETE FROM users WHERE email LIKE '%@uji.cinelog.test';
SELECT 'T8 sesudah hapus user: harapan count=0 avg=0.00' AS uji, reviews_count, avg_rating FROM movies WHERE id = @m;

-- Bersihkan jejak audit data uji
DELETE FROM review_audit WHERE movie_id IN (@m, @m2);
