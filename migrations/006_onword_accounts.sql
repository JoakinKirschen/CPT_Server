-- 1.8.0: accounts of an OnWord site. With "onword.enabled" in the config,
-- a key made in OnWord (onw_…) is accepted as a library key: the library
-- reads the account and its rights from OnWord's tables in the same
-- database, and keeps a user of its own for it, so that uploads and
-- favourite marks have an owner here. This column says which OnWord
-- account a user stands for; it is NULL for users made with bin/keys.php.

ALTER TABLE api_users
    ADD COLUMN onword_user_id INT UNSIGNED NULL,
    ADD UNIQUE KEY uq_api_users_onword (onword_user_id);

INSERT IGNORE INTO schema_migrations (version, applied_at) VALUES ('006_onword_accounts', UTC_TIMESTAMP());
