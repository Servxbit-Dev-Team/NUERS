# Report source and optional MFA correction

Base: production-20261115-readiness1. Candidate: production-20261116-report-mfa1.

Use the already-approved private staging artifact, SHA-256 58d5687a244547e2f5194e99e4d4d35e626e9b4f072d8552c95517a2718c1219. Select 254 application/build files, not tester fixtures or credentials. All 16 changed existing file hashes match the captured production and original staging baselines. No environment changes or production test accounts.

Prepare an inactive release on both existing web nodes, verify artifact and baseline hashes, retain old hashed assets for rolling compatibility, preserve environment bytes, configure root-owned code and nuers-owned storage/cache, and run the native isolated SQLite authenticated matrix plus optional MFA/report-source probes. No production records are used by the native probe.

Only after both native probes pass, use the established managed RDS deployment identity over verified TLS/SSM forwarding to add nullable VARCHAR(255) merchant_transactions.source_api_key_name with instant DDL and register the migration. Existing records are not backfilled. Compare existing account enrollment state before/after migration. Runtime database privileges remain DML-only.

Drain and switch one web node at a time while the other stays healthy. Verify hashes, environment, permissions, services and HTTP authentication boundaries, then register and await healthy status. Roll back the release symlink if a node fails. The additive nullable column is left in place on rollback. No new compute resources or paid services are created.
