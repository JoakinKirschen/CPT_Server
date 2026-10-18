-- Structural Canvas CPT library: DOV boreholes (boringen) and
-- interpretations (interpretaties, e.g. lithological descriptions), kept
-- like the CPTs: one record per DOV id, every distinct XML a revision.
-- The CPT tables are not changed. Applied by bin/migrate.php, or paste into
-- phpMyAdmin (SQL tab) after 001_initial.sql.

CREATE TABLE borehole_records (
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
    UNIQUE KEY uq_borehole_records_dov_id (dov_id),
    KEY ix_borehole_records_position (latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE borehole_revisions (
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
    UNIQUE KEY uq_borehole_revisions_sha (record_id, sha256),
    UNIQUE KEY uq_borehole_revisions_no (record_id, revision_no),
    CONSTRAINT fk_borehole_revisions_record FOREIGN KEY (record_id) REFERENCES borehole_records (id),
    CONSTRAINT fk_borehole_revisions_user FOREIGN KEY (fetched_by) REFERENCES api_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE borehole_records
    ADD CONSTRAINT fk_borehole_records_revision FOREIGN KEY (current_revision_id) REFERENCES borehole_revisions (id);

CREATE TABLE interpretation_records (
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
    UNIQUE KEY uq_interpretation_records_dov_id (dov_id),
    KEY ix_interpretation_records_position (latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE interpretation_revisions (
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
    UNIQUE KEY uq_interpretation_revisions_sha (record_id, sha256),
    UNIQUE KEY uq_interpretation_revisions_no (record_id, revision_no),
    CONSTRAINT fk_interpretation_revisions_record FOREIGN KEY (record_id) REFERENCES interpretation_records (id),
    CONSTRAINT fk_interpretation_revisions_user FOREIGN KEY (fetched_by) REFERENCES api_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE interpretation_records
    ADD CONSTRAINT fk_interpretation_records_revision FOREIGN KEY (current_revision_id) REFERENCES interpretation_revisions (id);

-- The fetch log names the dataset: the "not found" cache is per dataset,
-- the DOV budgets count every fetch. Existing rows are CPT fetches.
ALTER TABLE dov_fetches
    ADD COLUMN kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'cpt' AFTER id,
    ADD KEY ix_dov_fetches_kind_id_time (kind, dov_id, fetched_at);

INSERT IGNORE INTO schema_migrations (version, applied_at) VALUES ('002_boreholes', UTC_TIMESTAMP());
