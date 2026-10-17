-- Structural Canvas CPT library: initial schema.
-- MySQL 5.7+ / 8.x or MariaDB 10.3+. Applied by bin/migrate.php, or paste
-- into phpMyAdmin (SQL tab) on an empty database.

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per user; the key lives on the row, so a user has at most one
-- key. Only the HMAC-SHA256 hash is stored. Revoking clears the hash.
CREATE TABLE api_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    key_hint VARCHAR(8) NULL,
    key_created_at DATETIME NULL,
    revoked_at DATETIME NULL,
    last_used_at DATETIME NULL,
    can_write TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_api_users_name (name),
    UNIQUE KEY uq_api_users_key_hash (key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per DOV CPT (sondering). Position and name come from the
-- current revision's XML.
CREATE TABLE cpt_records (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    dov_id CHAR(11) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(100) NULL,
    lambert_x DOUBLE NULL,
    lambert_y DOUBLE NULL,
    latitude DOUBLE NULL,
    longitude DOUBLE NULL,
    current_revision_id INT UNSIGNED NULL,
    first_retrieved_at DATETIME NOT NULL,
    last_checked_at DATETIME NOT NULL,
    UNIQUE KEY uq_cpt_records_dov_id (dov_id),
    KEY ix_cpt_records_position (latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every distinct XML DOV ever returned for a CPT, by SHA-256 of the exact
-- bytes. Rows are never overwritten; only last_seen_at moves.
-- content holds the XML, gzip-compressed when encoding = 'gzip'.
CREATE TABLE cpt_revisions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    record_id INT UNSIGNED NOT NULL,
    revision_no INT UNSIGNED NOT NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    byte_length INT UNSIGNED NOT NULL,
    encoding VARCHAR(10) CHARACTER SET ascii NOT NULL,
    content MEDIUMBLOB NOT NULL,
    retrieved_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    fetched_by INT UNSIGNED NULL,
    UNIQUE KEY uq_cpt_revisions_sha (record_id, sha256),
    UNIQUE KEY uq_cpt_revisions_no (record_id, revision_no),
    CONSTRAINT fk_cpt_revisions_record FOREIGN KEY (record_id) REFERENCES cpt_records (id),
    CONSTRAINT fk_cpt_revisions_user FOREIGN KEY (fetched_by) REFERENCES api_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cpt_records
    ADD CONSTRAINT fk_cpt_records_revision FOREIGN KEY (current_revision_id) REFERENCES cpt_revisions (id);

-- A user's own CPTs (GEF or CSV text). Separate table and id space
-- ('p-' + 16 hex), visible only to the owner, never mixed with DOV rows.
CREATE TABLE private_cpts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(18) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_id INT UNSIGNED NOT NULL,
    name VARCHAR(200) NOT NULL,
    format VARCHAR(8) CHARACTER SET ascii NOT NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    byte_length INT UNSIGNED NOT NULL,
    encoding VARCHAR(10) CHARACTER SET ascii NOT NULL,
    content MEDIUMBLOB NOT NULL,
    latitude DOUBLE NULL,
    longitude DOUBLE NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_private_cpts_public_id (public_id),
    UNIQUE KEY uq_private_cpts_owner_sha (owner_id, sha256),
    KEY ix_private_cpts_owner_position (owner_id, latitude, longitude),
    CONSTRAINT fk_private_cpts_owner FOREIGN KEY (owner_id) REFERENCES api_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every request this server made to DOV: the budget for the DOV rate
-- limits, the "not found" cache and an audit trail.
CREATE TABLE dov_fetches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    dov_id CHAR(11) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id INT UNSIGNED NULL,
    fetched_at DATETIME NOT NULL,
    http_status SMALLINT UNSIGNED NOT NULL,
    outcome VARCHAR(20) CHARACTER SET ascii NOT NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    byte_length INT UNSIGNED NULL,
    duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    KEY ix_dov_fetches_id_time (dov_id, fetched_at),
    KEY ix_dov_fetches_time (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fixed-window rate limit counters.
CREATE TABLE rate_limits (
    bucket VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_start INT UNSIGNED NOT NULL,
    hits INT UNSIGNED NOT NULL,
    PRIMARY KEY (bucket, window_start),
    KEY ix_rate_limits_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version, applied_at) VALUES ('001_initial', UTC_TIMESTAMP());
