# Staging SSH and database access — prepared, not activated

Prepared 16 November 2026 for the owner's authorized VAPT tester. This adds infrastructure access; the application account handoff remains Admin and Merchant only.

## Exact access

- Existing Node A only: i-0c1a905d6656ce491, 10.85.0.70. Node B remains stopped.
- New Linux identity: `vapt_auditor`, ordinary shell; no sudo, docker group, AWS identity or production access.
- Proposed Cloudflare hostname: `ssh-vapt.nuers.net` using existing tunnel `251bf976-4f3a-43df-bfad-a5ce27a2e3c3`, routed to `ssh://127.0.0.1:2222`.
- Separate loopback SSH daemon; existing host SSH configuration and security-group ingress remain unchanged. No public SSH/DB port is opened.
- SSH public-key authentication only. A dedicated Ed25519 key has been generated locally for private handoff; the private key is not copied to the server or entered in Cloudflare.
- SSH key fingerprint: `SHA256:ZwyXUos8UMPn7HOLsz5h3M6YJhypapD/O73YvASvGys`.
- Expiry: 23 November 2026, 09:00 UTC / 17:00 Asia/Manila. Enforced by SSH key expiry plus persistent systemd timer that stops the dedicated SSH daemon, terminates this user's sessions, locks its account and locks the database identity. Existing full staging expiry is retained.
- Database identity: `vapt_reader`, SELECT and SHOW VIEW only on `nuers_vapt_multi`.*; limited to the Docker bridge gateway observed at deployment, with at most four connections and ten-second statements. No global privileges, writes, FILE, GRANT, administrative privileges or production schemas.
- Database access through SSH local forwarding only: tester localhost:13306 -> 10.85.0.70:3306. Do not publish a database Cloudflare hostname.
- A random database password is generated on staging and delivered using the existing private encrypted handoff certificate. Existing application credentials are not reused.

## Exposure and limits

Anyone holding the dedicated SSH private key can use this hostname until expiry/revocation. The proposed route uses OpenSSH key authentication; it does not claim Cloudflare email/SSO authentication, because no tester email/identity was supplied. A Cloudflare Access identity policy can be added separately when that identity is known.

The shell exposes ordinary system/package information and the user's own workspace. Root-owned staging runtime folders, Docker socket, host secrets and instance metadata must remain inaccessible. UID-scoped firewall rules allow new connections only to the synthetic application and database; other new outbound connections, including AWS metadata, are blocked. Existing SSH connections may send replies. IPv6 new outbound connections are blocked for this user.

Read-only database access includes synthetic account and transaction tables. These can contain authentication hashes/encrypted MFA fields; the tester must keep evidence private. This is an intentional additional privilege beyond application-role testing. Privileged OS scanning and database mutation testing are not included in this account.

## Cost

Reuse the running Node A and existing tunnel. No new instances, disks, paid plan, public IPv4 allocation or load balancer. Node B stays stopped. Retain the existing ≤USD25 temporary staging allowance and PHP100000 total monthly ceiling; no guarantee that a budget alert is a hard spending cap.

## Activation and verification gate

1. Confirm approval for the new Linux/DB identities and externally reachable SSH tunnel route.
2. Validate exact host, runtime database and secret permissions; abort if expected names already exist or expiry has passed.
3. Install scoped accounts/configuration and firewall guard, run `sshd -t`, install expiry before starting the SSH service.
4. Verify no sudo/docker membership, key-only login configuration, loopback binding, metadata denial, private file denial and exact database grants.
5. Publish only the new hostname through the existing tunnel; preserve the current HTTPS application route.
6. Test the externally routed SSH handshake and pinned host-key login, database SELECT success, write denial and disallowed forwarding denial. Confirm public application still works.
7. Deliver private key, pinned host fingerprint, database credential and detailed connection instructions privately. Do not place credentials in Git or general handbook.

## Revocation

Stop `nuers-vapt-tester-ssh.service`, expire/lock `vapt_auditor`, terminate only that user's processes, and lock `vapt_reader`. Remove the dedicated Cloudflare hostname during closure. Preserve synthetic evidence and existing runtime identities. Do not delete the underlying test database.

## Reference

Cloudflare's documented SSH public-hostname method supports client-side `cloudflared` and an SSH proxy command: https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/use-cases/ssh/ssh-cloudflared-authentication/
