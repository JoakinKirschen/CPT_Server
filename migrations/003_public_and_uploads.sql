-- 1.2.0: groups of keys, and uploaded CPTs with a visibility, the accuracy
-- of their location and what the quality check read from them. Uploads made
-- before stay private and keep no location method.

ALTER TABLE api_users
    ADD COLUMN group_name VARCHAR(64) NULL AFTER can_write,
    ADD KEY ix_api_users_group (group_name);

ALTER TABLE private_cpts
    ADD COLUMN visibility VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'private' AFTER format,
    ADD COLUMN location_method VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER longitude,
    ADD COLUMN location_accuracy_m INT UNSIGNED NULL AFTER location_method,
    ADD COLUMN location_text VARCHAR(200) NULL AFTER location_accuracy_m,
    ADD COLUMN test_date DATE NULL AFTER location_text,
    ADD COLUMN row_count INT UNSIGNED NULL AFTER test_date,
    ADD COLUMN depth_min_m DOUBLE NULL AFTER row_count,
    ADD COLUMN depth_max_m DOUBLE NULL AFTER depth_min_m,
    ADD KEY ix_private_cpts_sha (sha256),
    ADD KEY ix_private_cpts_position (latitude, longitude);

INSERT IGNORE INTO schema_migrations (version, applied_at) VALUES ('003_public_and_uploads', UTC_TIMESTAMP());
