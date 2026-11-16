-- 1.10.0: OnWord is now StructuralDocs, a new site with a database of its
-- own whose account numbers start again. The column naming the account a
-- user stands for takes the new name, and the users made for OnWord
-- accounts are unlinked and revoked: a number of the old site would
-- otherwise stand for someone else on the new one. Their uploads keep
-- them as owner; their names get "-onword" so the person's address is free
-- for the user StructuralDocs' account makes at its first request.

ALTER TABLE api_users
    DROP INDEX uq_api_users_onword,
    CHANGE COLUMN onword_user_id structuraldocs_user_id INT UNSIGNED NULL;

UPDATE api_users
    SET name = CONCAT(LEFT(name, 46), '-onword', id),
        revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP()),
        structuraldocs_user_id = NULL
    WHERE structuraldocs_user_id IS NOT NULL;

ALTER TABLE api_users
    ADD UNIQUE KEY uq_api_users_structuraldocs (structuraldocs_user_id);

INSERT IGNORE INTO schema_migrations (version, applied_at) VALUES ('007_structuraldocs_accounts', UTC_TIMESTAMP());
