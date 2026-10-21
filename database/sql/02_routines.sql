-- ============================================================
-- CINÉLOG - komponen database: procedure, function, trigger, view
-- DELIMITER adalah perintah klien mysql/mariadb (bukan SQL). Jika menjalankan lewat
-- Laravel (DB::unprepared), hilangkan baris DELIMITER dan ganti '//' dengan ';'.
-- ============================================================
USE cinelog;

DELIMITER //

-- ------------------------------------------------------------
-- 1) PROCEDURE: hitung ulang agregat rating satu film
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_refresh_movie_rating//
CREATE PROCEDURE sp_refresh_movie_rating(IN p_movie_id BIGINT UNSIGNED)
BEGIN
  UPDATE movies
     SET reviews_count = (SELECT COUNT(*) FROM reviews WHERE movie_id = p_movie_id),
         avg_rating    = COALESCE((SELECT ROUND(AVG(rating), 2) FROM reviews WHERE movie_id = p_movie_id), 0)
   WHERE id = p_movie_id;
END//

-- ------------------------------------------------------------
-- 2) TRIGGER: jaga agregat tetap sinkron saat review berubah
-- ------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_reviews_ai//
CREATE TRIGGER trg_reviews_ai AFTER INSERT ON reviews FOR EACH ROW
BEGIN
  CALL sp_refresh_movie_rating(NEW.movie_id);
END//

DROP TRIGGER IF EXISTS trg_reviews_au//
CREATE TRIGGER trg_reviews_au AFTER UPDATE ON reviews FOR EACH ROW
BEGIN
  IF OLD.rating <> NEW.rating THEN
    INSERT INTO review_audit (review_id, user_id, movie_id, action, old_rating, new_rating)
    VALUES (OLD.id, OLD.user_id, OLD.movie_id, 'UPDATE', OLD.rating, NEW.rating);
  END IF;
  CALL sp_refresh_movie_rating(NEW.movie_id);
  IF OLD.movie_id <> NEW.movie_id THEN
    CALL sp_refresh_movie_rating(OLD.movie_id);
  END IF;
END//

DROP TRIGGER IF EXISTS trg_reviews_ad//
CREATE TRIGGER trg_reviews_ad AFTER DELETE ON reviews FOR EACH ROW
BEGIN
  INSERT INTO review_audit (review_id, user_id, movie_id, action, old_rating, new_rating)
  VALUES (OLD.id, OLD.user_id, OLD.movie_id, 'DELETE', OLD.rating, NULL);
  CALL sp_refresh_movie_rating(OLD.movie_id);
END//

-- Catatan penting: aksi foreign key (ON DELETE CASCADE) TIDAK mengaktifkan trigger.
-- Jika user dihapus, review-nya terhapus lewat CASCADE dan trigger di atas tidak jalan,
-- sehingga agregat film menjadi basi. Solusinya: hapus review secara EKSPLISIT sebelum user
-- dihapus. DELETE eksplisit di dalam trigger memang mengaktifkan trigger review di atas.
DROP TRIGGER IF EXISTS trg_users_bd//
CREATE TRIGGER trg_users_bd BEFORE DELETE ON users FOR EACH ROW
BEGIN
  DELETE FROM reviews WHERE user_id = OLD.id;
END//

-- ------------------------------------------------------------
-- 3) FUNCTION: weighted rating (rumus Bayesian ala IMDb)
--    WR = v/(v+m) * R + m/(v+m) * C
--    v = jumlah review film, R = rata-rata film, m = ambang minimal (5),
--    C = rata-rata seluruh review (default 7.0 bila belum ada review)
-- ------------------------------------------------------------
DROP FUNCTION IF EXISTS fn_weighted_rating//
CREATE FUNCTION fn_weighted_rating(p_movie_id BIGINT UNSIGNED)
RETURNS DECIMAL(4,2)
READS SQL DATA
BEGIN
  DECLARE v INT DEFAULT NULL;
  DECLARE r DECIMAL(6,3) DEFAULT 0;
  DECLARE c DECIMAL(6,3) DEFAULT 7.0;
  DECLARE m INT DEFAULT 5;

  SELECT reviews_count, avg_rating INTO v, r FROM movies WHERE id = p_movie_id;
  IF v IS NULL THEN
    RETURN NULL;
  END IF;
  SELECT COALESCE(AVG(rating), 7.0) INTO c FROM reviews;
  RETURN ROUND((v / (v + m)) * r + (m / (v + m)) * c, 2);
END//

-- ------------------------------------------------------------
-- 4) PROCEDURE + TRANSACTION: kirim / perbarui review dalam satu transaksi
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_upsert_review//
CREATE PROCEDURE sp_upsert_review(
  IN p_user_id BIGINT UNSIGNED,
  IN p_movie_id BIGINT UNSIGNED,
  IN p_rating TINYINT UNSIGNED,
  IN p_title VARCHAR(150),
  IN p_body TEXT,
  IN p_spoiler TINYINT(1)
)
BEGIN
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;
  INSERT INTO reviews (user_id, movie_id, rating, title, body, contains_spoiler)
  VALUES (p_user_id, p_movie_id, p_rating, p_title, p_body, p_spoiler)
  ON DUPLICATE KEY UPDATE
    rating = VALUES(rating),
    title = VALUES(title),
    body = VALUES(body),
    contains_spoiler = VALUES(contains_spoiler);
  COMMIT;
END//

DELIMITER ;

-- ------------------------------------------------------------
-- 5) VIEW: film dengan genre & sutradara (untuk daftar/katalog)
-- ------------------------------------------------------------
CREATE OR REPLACE VIEW v_movie_summary AS
SELECT m.id, m.tmdb_id, m.title, m.slug, m.release_date, m.poster_path, m.runtime,
       m.reviews_count, m.avg_rating,
       (SELECT GROUP_CONCAT(g.name ORDER BY g.name SEPARATOR ', ')
          FROM movie_genres mg JOIN genres g ON g.id = mg.genre_id
         WHERE mg.movie_id = m.id) AS genres,
       (SELECT GROUP_CONCAT(p.name ORDER BY p.name SEPARATOR ', ')
          FROM movie_credits c JOIN people p ON p.id = c.person_id
         WHERE c.movie_id = m.id AND c.credit_type = 'crew' AND c.role = 'Director') AS directors
  FROM movies m;

-- 6) VIEW: peringkat film berdasarkan weighted rating (hanya yang sudah punya review)
CREATE OR REPLACE VIEW v_top_movies AS
SELECT m.id, m.title, m.slug, m.release_date, m.poster_path,
       m.reviews_count, m.avg_rating,
       fn_weighted_rating(m.id) AS weighted_rating
  FROM movies m
 WHERE m.reviews_count > 0;
