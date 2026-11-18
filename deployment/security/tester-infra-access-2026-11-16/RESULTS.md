# Staging tester infrastructure access - 16 November 2026

Approved and activated: ssh-vapt.nuers.net -> ssh://127.0.0.1:2222 through the existing staging tunnel. Node A only; Node B remains stopped. Existing application route preserved; public login returned HTTP 200.

## Verified

- Provisioning SSM: 36cb250c-ddfc-41c0-b557-39da9beadeef, Success.
- Non-root vapt_auditor (UID/GID 1001), no supplemental groups, sudo denied.
- Key login through the public tunnel succeeded with the pinned host key.
- Login without a key rejected; forwarding to an unapproved port rejected.
- Private runtime reads, Docker socket and AWS metadata connectivity denied.
- Staging app and DB allowed; Node B connectivity denied.
- MariaDB protocol-10 handshake passed through loopback-only local forward.
- Database grants: SELECT, SHOW VIEW on nuers_vapt_multi; global USAGE only. Host grant 10.85.0.70, maximum 4 connections, 10-second statements.
- SSH listener bound to 127.0.0.1:2222, password and interactive password authentication disabled.
- Expiring public key, persistent expiry timer active for 23 November 09:00 UTC / 17:00 PHT.
- Expiry guard boundary checks passed before, exactly at, and after the deadline without changing server time.
- NoNewPrivileges enabled; SSH service limit 128 tasks, 512 MiB, 50% of one CPU.
- SSH host fingerprint SHA256:crE/UnHovsq0tXvn0wRB0GkkeYu510bnlGXF0J1vPoU.
- Infrastructure guide (4 pages) and revised handbook (26 pages) rendered and visually checked.

## Correction during testing

Initial DB connectivity was rejected because Docker DNAT changes the filter destination to 172.17.0.2. Added only that verified DB container IP and port 3306 to the UID egress guard; app/DB then passed and metadata/Node B stayed denied. SSM fa994f63-2aca-42d6-8262-c32faf28b089.

## Completed private handoff and database retest

Owner explicitly approved retrieving/decrypting the new vapt_reader encrypted credential package to the private git-ignored local folder and including it in the confidential handoff. SHA-256 verified during retrieval; plaintext credentials were never displayed in tool output. Previous automatic approval rejection is resolved by that approval.

The initial MySQL 9.5 CLI could not load mysql_native_password. Switched verification to the existing PHP PDO/mysqlnd driver without changing server authentication.

Actual SSH-forwarded database connections originate at 10.85.0.70, not the Docker bridge gateway. Narrowly renamed the grant from vapt_reader@172.17.0.1 to vapt_reader@10.85.0.70, preserving the password and read-only permissions; updated the expiry helper host file. SSM 218efc9e-3453-4672-8e37-444d2ff39509, Success.

Retest passed: correct database/current-user identity, ten-second statement limit, synthetic profile count read, UPDATE denied (1142), mysql.user SELECT denied (1142). Zero-row write test changed no records. Temporary client config was removed and local SSH forward closed.

Complete confidential ZIP now includes the dedicated SSH key, pinned host key, SSH client config, database-credentials.txt, README and final four-page guide. It supersedes the earlier SSH-only ZIP. File permissions are 0600 and location is git-ignored. No production or existing application credentials were copied or changed.

No new instance, disk, load balancer or paid Cloudflare upgrade. Existing staging resources and expiry retained; the monthly total was not independently recalculated in this access task.
