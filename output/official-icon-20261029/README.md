# Official NUERS icon deployment

Applied the user-supplied official icon to the shared website, admin, merchant and monitoring favicon; mobile touch/manifest icons; legacy icon asset; error-page head; and compact navigation branding.

- Source: `/Users/marksioson/Downloads/Glossy Blue N Logo with Pixel Tiles.png`
- Original source SHA-256: `b50631c79afbad1d155a9d2580b369991f28060bdf56f0b19bcf2f4cd64c239d`
- Artwork retained with original transparency; only icon renditions were resized and packaged.
- Browser PNG: 48px; ICO: 16/32/48/256px; Apple touch: 180px; application icons: 192/512px.
- Cache version: `b50631c79afb`
- Both production builds passed (`build.log`); template syntax and whitespace checks passed.
- Initial deployment: 535 file checksums verified; follow-up: 8 checksums verified.
- Live HTTP verification: five route heads and seven icon/manifest files passed; all HTTP 200, asset checksums matched. Manifest served as application/json.
- Final browser check: authenticated page loaded with official favicon links and compact navigation icon. Screenshot: `live-official-icon.png`.
- Final release: `2026-10-29.official-icon.2`; entry: `/assets/index-DGYJeIZu.js`.
- Recovery backups: `nuers-official-icon-backup-20261028-234351`, `nuers-official-icon-backup-20261028-234509` under private server vhost directory.
- Database and merchant records untouched. Existing full NUERS wordmark retained.
