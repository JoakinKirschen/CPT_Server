-- 1.5.0: favourite boreholes. A key that may write marks a DOV borehole as
-- a favourite (one whose data is of higher quality); everyone sees the mark,
-- and who set it and when. One mark per borehole: marking it again changes
-- who set it. The borehole need not be in the library: the mark is kept by
-- its DOV id.

CREATE TABLE borehole_favourites (
    dov_id CHAR(11) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    set_by INT UNSIGNED NOT NULL,
    set_at DATETIME NOT NULL,
    CONSTRAINT fk_borehole_favourites_user FOREIGN KEY (set_by) REFERENCES api_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version, applied_at) VALUES ('005_borehole_favourites', UTC_TIMESTAMP());
