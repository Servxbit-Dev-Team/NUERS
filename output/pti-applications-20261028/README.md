# PTI application release and EIS alignment audit

Release: 2026-10-28.pti-applications.1. Deployed to nuers.net on 28 October 2026 at 22:31 PHT.

- Live Super Admin: /super-admin/pti-ptt defaults to PTI Applications; issued-authority register remains at ?view=register.
- Merchant owner: /merchant/pti-applications, also in Settings navigation.
- Features: drafts, registered identity snapshots, scoped encrypted attachments, preparation review, revision checks, administrative filing/outcome evidence, branded summary PDF and complete ZIP packet.
- Direct PTI filing is not connected. EIS BIR transport remains disabled. No taxpayer application was submitted to BIR or created on live during verification.
- Build: build-final.log (TypeScript + Laravel/static bundles passed).
- Existing readiness suites: 43 tests / 391 assertions passed (eis-alignment-tests.log).
- PTI + existing permit / durable workflow regression: 46 tests / 458 assertions passed (workflow-regression.log).
- Final PTI suite including ZIP integrity: 8 tests / 103 assertions passed (pti-tests.log).
- Synthetic UI draft creation and administrator page verified at loopback port 8794. Synthetic PDF rendered and both final pages visually inspected; final screenshots packet-final-1.png / packet-final-2.png. Test PDFs are not real applications.
- Live deployment: 522 file hashes matched; 9 protected application routes; migration verified; existing merchant financial, identity, credential, document and permit rows preserved by pre/post hashes; MFA and environment unchanged.
- Public live check: PTI page HTTP 200; anonymous application API HTTP 401.
- Authenticated live UI: PTI Applications page, empty application state and registered-taxpayer form options loaded. Screenshot: live-pti-applications.png.
- Private rollback backup on server: /var/www/vhosts/nuers.net/nuers-pti-applications-backup-20261028-143147.

See integration-alignment-audit.md for the remaining BIR adapter and certification gaps. Official public sources are linked there and in docs/knowledge/pti-application-workflow.md.
