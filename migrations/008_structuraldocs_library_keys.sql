-- 1.13.0: keys StructuralDocs's administrator makes for the library (sdlib_…,
-- StructuralDocs 2.19.0, its table library_keys), which belong to no account
-- but to a group there. The library keeps a user of its own for each, as for
-- an account, so that uploads and favourite marks have an owner here. This
-- column says which StructuralDocs key a user stands for; it is NULL for the
-- other users.

ALTER TABLE api_users
    ADD COLUMN structuraldocs_key_id INT UNSIGNED NULL,
    ADD UNIQUE KEY uq_api_users_structuraldocs_key (structuraldocs_key_id);

INSERT IGNORE INTO schema_migrations (version, applied_at) VALUES ('008_structuraldocs_library_keys', UTC_TIMESTAMP());
