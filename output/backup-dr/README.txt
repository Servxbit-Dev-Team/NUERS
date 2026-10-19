NUERS 1.0.0 - Backup and Disaster Recovery Evidence
Edition 1.0 - 19 October 2026

This evidence accompanies the 39-page PDF. All restoration measurements are from an isolated synthetic local lab, not production infrastructure or full-service failover. The plan's four-hour RTO and fifteen-minute RPO are proposed targets awaiting approval and production-like testing.

Files
- lab-results.json: actual timings, data/control totals, scope and code hashes.
- run_isolated_recovery.php: executed synthetic lab runner; no production keys.
- governance-tests.xml: 15 tests / 156 assertions. Test timestamps are fixtures.
- quality-checks.json: PDF rendering/layout and evidence checks.
- evidence-manifest.json: SHA-256 of PDF and accompanying files.

Reproduction (qualified developer, isolated copy of the same project and dependencies)
Run: php output/backup-dr/run_isolated_recovery.php
The runner creates a new unique .local/backup-dr directory, loads an empty environment path, uses fresh SQLite storage and generates lab-only keys. It does not load the project's .env. Do not use this as a production backup command. A new run replaces the summary JSON in output/backup-dr; preserve this delivered edition first.

Data confidentiality
The bundle contains no production credentials, customer data or lab decryption keys. The private local lab directory is deliberately excluded. All financial values are synthetic fixtures. Independent review is pending. Hashes do not provide independent timestamping or a digital signature.

Design
Original NUERS logo assets were reused. Navy, blue and gold palette; original vector cover and four technical diagrams. A4 duplex layout with mirrored binding margins, searchable text, clickable contents and bookmarks.
