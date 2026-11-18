-- Applied 2026-11-14 through the separate managed RDS operator.
-- No active rehearsal consumers were found. Do not reuse the production identity
-- for a restarted rehearsal deployment: provision a separate scoped identity first.
-- These queries intentionally omit authentication clauses and credential hashes.
SELECT TABLE_SCHEMA, PRIVILEGE_TYPE, IS_GRANTABLE
FROM information_schema.SCHEMA_PRIVILEGES
WHERE GRANTEE = "'nuers_runtime'@'%'";
-- Apply only when the four rehearsal grants are present (not blindly on every deploy):
-- REVOKE SELECT, INSERT, UPDATE, DELETE
--     ON `nuers_rehearsal_20261106`.* FROM 'nuers_runtime'@'%';
-- Verified remaining grants: four DML privileges on nuers_production_20261106 only;
-- global USAGE, no GRANT OPTION, no per-table grants. Runtime reads against
-- rehearsal were denied on both live hosts; production reads remained allowed.
