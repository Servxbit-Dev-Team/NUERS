# Native sandboxed PDF runtime

Recovered read-only from the live NUERS hosts on 2026-11-14. `provenance-2026-11-14.json` records the production and isolated staging hashes. These files contain no application credentials or customer documents.

The PHP application invokes `client.py` using Chrome-compatible arguments. It accepts a NUERS-owned temporary document beneath the resolved private storage directory and sends only HTML to the Unix socket. `worker.py` validates the caller UID, limits request size/concurrency, injects a restrictive CSP, and launches sandboxed Chrome. The systemd service has a private network and cannot read the application directory. Do not disable Chrome's sandbox or expose the socket publicly.

The staging adaptation changed the caller UID, test storage path and inaccessible application directory; the worker source itself was byte-identical. Native PDF rendering passed on both isolated Linux nodes and both prepared production releases. These source files are archived for maintainability; the production PDF service was not replaced during this rollout.
