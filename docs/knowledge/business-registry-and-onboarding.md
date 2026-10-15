# Business registry, onboarding and RDO mapping — 15 October 2026

## Implemented pages

- `/super-admin/merchants`: real merchant registry, search, business/status/EIS/date/region/city/RDO/industry/VAT/account filters, pagination, profile, edit and review modals, complete filtered CSV export, template and actor-scoped CSV preview/atomic commit.
- `/super-admin/taxpayer-onboarding`: shared application records, evaluation/BIR-validation/EIS-setup/onboarded/rejected stages, five-item evidence checklist, reviewer reason, process guide and requirements cards. Platform approval is separate from BIR authority. Evidence references are persisted; a protected evidence-file repository is not yet implemented.
- `/activate-business`: expiring single-use invitation, encrypted server payload and hashed lookup token, strong confirmed password, explicit current policy acceptance. Invitations use URL fragments; raw invitation tokens are not query parameters. No automatic email is sent, account email is not falsely marked verified, and an existing login is never overwritten.
- `/super-admin/rdo-mappings`: complete reference office directory and city/barangay rule editor, PSGC hierarchy validation, evidence/source, enable/disable and optimistic revisions. Changes are audited and affect future lookups, not taxpayer transfers.

Registry writes require admin/super-admin; BIR is read-only here. TIN checks are local format/duplicate checks, not official BIR verification. Suspension/inactivation revokes linked merchant sessions and unused invitations. Financial document snapshots are preserved. The old generic merchant mutation path is disabled; the legacy merchant profile update path remains a review item because its identity edits are not revision-checked against this registry.

## RDO coverage and limits

The catalogue contains **124 reference offices**, with **105 named city/municipality rules and five barangay rules** for 1,642 PSGC cities/municipalities. This is not complete nationwide jurisdiction coverage. `scripts/build-rdo-named-localities.py` matches specifically named localities and records provenance; it does not infer province-wide coverage or nearest offices. Special zones and split cities require specific evidence. Unmapped or conflicting addresses require confirmation against the taxpayer's registration.

Caloocan City `1380100000`, including Barangay 179 `1380100179`, selects **RDO 027 — Caloocan City**. RDO 045 is SMART, reflecting the cited reorganization. A legacy local unsplit code 021 remains in existing records but is excluded from the current selection catalogue. It is not silently deleted or reassigned.

Sources:

- [BIR Citizens Charter regional/RDO directory, 2024](https://bir-cdn.bir.gov.ph/BIR/pdf/citizens-charter-rr-rdo-2024-v3.pdf)
- [BIR Tax Calendar, 2024](https://bir-cdn.bir.gov.ph/local/pdf/2024-bir-tax-calendar.pdf)
- [RAO 2-2024, SMART jurisdiction](https://bir-cdn.bir.gov.ph/BIR/pdf/RAO%20No.%202-2024.pdf)
- [RAO 6-2025](https://bir-cdn.bir.gov.ph/BIR/pdf/RAO%20No.%206-2025.pdf)

These are dated references, not proof of an exhaustive current jurisdiction dataset. User confirmed no additional complete jurisdiction file is available. Directory office addresses alone do not establish all cities/barangays under an RDO.

## Verification

`BusinessRegistryTest` has nine tests for role restrictions, retired legacy paths, TIN uniqueness, revisions, checklist decisions, session revocation, CSV actor scoping and atomicity, invitation replay/expiry, PSGC mapping precedence and named locality consistency. Browser checks covered the actual Caloocan/Barangay 179 selection, real registry and onboarding rows, profile/create modals and RDO counts. Screenshots are in `.local/registered-businesses-dashboard.jpg`, `.local/taxpayer-onboarding-dashboard.jpg`, and `.local/caloocan-rdo-auto.jpg`.
