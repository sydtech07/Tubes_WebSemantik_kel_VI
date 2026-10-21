-- ============================================================
-- CINÉLOG - skema database relasional
-- Diuji pada MariaDB 10.11. Sintaks dijaga kompatibel dengan MySQL 8.
-- Dijalankan otomatis oleh Docker saat volume masih kosong.
-- ============================================================
CREATE DATABASE IF NOT EXISTS cinelog CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cinelog;

-- ------------------------------------------------------------
-- users : kolom dibuat kompatibel dengan autentikasi bawaan Laravel
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name              VARCHAR(100)    NOT NULL,
  email             VARCHAR(190)    NOT NULL,
  email_verified_at TIMESTAMP       NULL,
  password          VARCHAR(255)    NOT NULL,
  role              ENUM('user','admin') NOT NULL DEFAULT 'user',
  remember_token    VARCHAR(100)    NULL,
  created_at        TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT uq_users_email UNIQUE (email)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- movies : katalog film (hasil impor pipeline) + agregat review
-- reviews_count & avg_rating DIKELOLA TRIGGER, bukan oleh impor.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS movies (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tmdb_id            INT UNSIGNED    NOT NULL,
  imdb_id            VARCHAR(15)     NULL,
  wikidata_id        VARCHAR(20)     NULL,
  title              VARCHAR(255)    NOT NULL,
  original_title     VARCHAR(255)    NULL,
  slug               VARCHAR(300)    NOT NULL,
  tagline            VARCHAR(500)    NULL,
  overview_en        TEXT            NULL,
  overview_id        TEXT            NULL,
  release_date       DATE            NOT NULL,
  runtime            SMALLINT UNSIGNED NULL,
  original_language  VARCHAR(10)     NULL,
  poster_path        VARCHAR(255)    NULL,
  backdrop_path      VARCHAR(255)    NULL,
  homepage           VARCHAR(500)    NULL,
  budget             BIGINT UNSIGNED NULL,
  revenue            BIGINT UNSIGNED NULL,
  tmdb_popularity    DECIMAL(10,3)   NULL,
  tmdb_vote_average  DECIMAL(3,1)    NULL,
  tmdb_vote_count    INT UNSIGNED    NULL,
  status             VARCHAR(30)     NULL,
  -- agregat review pengguna CINÉLOG (diisi trigger)
  reviews_count      INT UNSIGNED    NOT NULL DEFAULT 0,
  avg_rating         DECIMAL(4,2)    NOT NULL DEFAULT 0.00,
  created_at         TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT uq_movies_tmdb UNIQUE (tmdb_id),
  CONSTRAINT uq_movies_slug UNIQUE (slug),
  CONSTRAINT chk_movies_runtime CHECK (runtime IS NULL OR runtime > 0),
  CONSTRAINT chk_movies_avg CHECK (avg_rating BETWEEN 0 AND 10),
  KEY idx_movies_release (release_date),
  KEY idx_movies_popularity (tmdb_popularity),
  KEY idx_movies_avg (avg_rating, reviews_count),
  FULLTEXT KEY ft_movies_title (title, original_title)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- genres  &  movie_genres  (relasi many-to-many)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS genres (
  id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tmdb_id INT UNSIGNED NOT NULL,
  name    VARCHAR(60)  NOT NULL,
  PRIMARY KEY (id),
  CONSTRAINT uq_genres_tmdb UNIQUE (tmdb_id),
  CONSTRAINT uq_genres_name UNIQUE (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS movie_genres (
  movie_id BIGINT UNSIGNED NOT NULL,
  genre_id INT UNSIGNED    NOT NULL,
  PRIMARY KEY (movie_id, genre_id),
  KEY idx_mg_genre (genre_id),
  CONSTRAINT fk_mg_movie FOREIGN KEY (movie_id) REFERENCES movies (id) ON DELETE CASCADE,
  CONSTRAINT fk_mg_genre FOREIGN KEY (genre_id) REFERENCES genres (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- people (aktor, sutradara, penulis)  &  movie_credits
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS people (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tmdb_id              INT UNSIGNED    NOT NULL,
  name                 VARCHAR(200)    NOT NULL,
  gender               TINYINT UNSIGNED NULL COMMENT '0=tidak diketahui, 1=perempuan, 2=laki-laki, 3=non-biner (kode TMDB)',
  known_for_department VARCHAR(60)     NULL,
  profile_path         VARCHAR(255)    NULL,
  PRIMARY KEY (id),
  CONSTRAINT uq_people_tmdb UNIQUE (tmdb_id),
  KEY idx_people_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS movie_credits (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  movie_id      BIGINT UNSIGNED NOT NULL,
  person_id     BIGINT UNSIGNED NOT NULL,
  credit_type   ENUM('cast','crew') NOT NULL,
  role          VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'nama karakter (cast) atau jabatan (crew)',
  department    VARCHAR(60)     NULL,
  billing_order SMALLINT UNSIGNED NULL COMMENT 'urutan kemunculan di daftar pemeran',
  PRIMARY KEY (id),
  CONSTRAINT uq_credit UNIQUE (movie_id, person_id, credit_type, role),
  KEY idx_credit_person (person_id),
  KEY idx_credit_movie_type (movie_id, credit_type, billing_order),
  CONSTRAINT fk_credit_movie  FOREIGN KEY (movie_id)  REFERENCES movies (id) ON DELETE CASCADE,
  CONSTRAINT fk_credit_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- reviews : rating 1-10 + ulasan. Satu user hanya boleh satu review per film.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reviews (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          BIGINT UNSIGNED NOT NULL,
  movie_id         BIGINT UNSIGNED NOT NULL,
  rating           TINYINT UNSIGNED NOT NULL,
  title            VARCHAR(150)    NULL,
  body             TEXT            NULL,
  contains_spoiler TINYINT(1)      NOT NULL DEFAULT 0,
  created_at       TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT uq_reviews_user_movie UNIQUE (user_id, movie_id),
  CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 10),
  KEY idx_reviews_movie_created (movie_id, created_at),
  CONSTRAINT fk_reviews_user  FOREIGN KEY (user_id)  REFERENCES users (id)  ON DELETE CASCADE,
  CONSTRAINT fk_reviews_movie FOREIGN KEY (movie_id) REFERENCES movies (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- watchlists : daftar "ingin ditonton"
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS watchlists (
  user_id  BIGINT UNSIGNED NOT NULL,
  movie_id BIGINT UNSIGNED NOT NULL,
  added_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, movie_id),
  KEY idx_watch_movie (movie_id),
  CONSTRAINT fk_watch_user  FOREIGN KEY (user_id)  REFERENCES users (id)  ON DELETE CASCADE,
  CONSTRAINT fk_watch_movie FOREIGN KEY (movie_id) REFERENCES movies (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- review_audit : jejak perubahan/penghapusan review (diisi trigger).
-- Sengaja TANPA foreign key agar jejak tetap ada setelah data asli dihapus.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS review_audit (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  review_id  BIGINT UNSIGNED NOT NULL,
  user_id    BIGINT UNSIGNED NOT NULL,
  movie_id   BIGINT UNSIGNED NOT NULL,
  action     ENUM('UPDATE','DELETE') NOT NULL,
  old_rating TINYINT UNSIGNED NULL,
  new_rating TINYINT UNSIGNED NULL,
  changed_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_review (review_id)
) ENGINE=InnoDB;
