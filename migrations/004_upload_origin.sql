-- 1.3.0: where an upload's readings come from: a data file (GEF, CSV, a
-- spreadsheet), a table in a PDF report, or digitised from a plot in a PDF
-- report (less exact). Uploads made before came from data files.

ALTER TABLE private_cpts
    ADD COLUMN data_origin VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'file' AFTER visibility;

INSERT IGNORE INTO schema_migrations (version, applied_at) VALUES ('004_upload_origin', UTC_TIMESTAMP());
