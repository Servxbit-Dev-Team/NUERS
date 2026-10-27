# NUERS Live Server Error Fix

The live `Server Error` happens when Laravel can run but cannot read the live database.

Upload the rebuilt static frontend first:

- Extract `/private/tmp/nuers-net-static-api-path-fix.zip`
- Upload the extracted files to the `nuers.net` web root
- Confirm `index.html` references `/assets/index-BEysnBvz.js`

This build routes frontend API calls through `https://nuers.net/public/api/...` instead of the root `/api/...` SPA fallback.

For the MySQL deployment on `nuers.net`, update the server `.env`:

- Set `APP_ENV=production`
- Set `APP_DEBUG=false`
- Set `APP_URL=https://nuers.net`
- Set `DB_CONNECTION=mysql`
- Set `DB_HOST` to the production database host (kept out of the repository)
- Set `DB_PORT=3306`
- Set `DB_DATABASE` to the production database name
- Set `DB_USERNAME` to the production database user
- Set `DB_PASSWORD` to the production database password
- Remove any old SQLite-only `DB_DATABASE=/Applications/XAMPP/...` line

After upload, clear Laravel cached config:

```sh
php artisan optimize:clear
```

If SSH is unavailable, delete this cached config file in Plesk File Manager:

```text
bootstrap/cache/config.php
```

The remote MySQL database has the NUERS schema and data. The screenshot account currently exists in MySQL as a Business Account/Merchant:

```text
m.sioson@servxbit.com
```
