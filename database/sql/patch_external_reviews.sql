-- Tabel ULASAN DARI SUMBER LUAR (saat ini: TMDB).
--
-- Sengaja dipisah dari tabel `reviews` (milik pengguna CINELOG):
--   * reviews.user_id wajib menunjuk ke pengguna asli, sedangkan penulis ulasan TMDB bukan pengguna kita.
--   * trigger rata-rata rating menghitung dari `reviews`; ulasan luar tidak boleh ikut mengubah avg_rating.
--
-- File ini TIDAK dijalankan otomatis (folder initdb hanya jalan saat volume masih kosong).
-- Jalankan sekali, dari root proyek (PowerShell):
--   Get-Content -Raw database\sql\patch_external_reviews.sql | docker compose exec -T mariadb sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
-- Aman diulang (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS external_reviews (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  movie_id         BIGINT UNSIGNED NOT NULL,
  source           VARCHAR(20)     NOT NULL DEFAULT 'tmdb',
  source_review_id VARCHAR(64)     NOT NULL,
  author           VARCHAR(150)    NOT NULL,
  author_username  VARCHAR(150)    NULL,
  rating           DECIMAL(3,1)    NULL,
  content          MEDIUMTEXT      NOT NULL,
  language         VARCHAR(10)     NULL,
  source_url       VARCHAR(500)    NULL,
  posted_at        DATETIME        NULL,
  fetched_at       TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT uq_extrev_source UNIQUE (source, source_review_id),
  CONSTRAINT chk_extrev_rating CHECK (rating IS NULL OR rating BETWEEN 0 AND 10),
  KEY idx_extrev_movie (movie_id, posted_at),
  CONSTRAINT fk_extrev_movie FOREIGN KEY (movie_id) REFERENCES movies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
