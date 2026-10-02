# Installing Office Planner

Office Planner is plain PHP — no build step, no Composer, no Node, no separate database server
(SQLite is the default). Installing is essentially "copy the files and open the page."

## Requirements

- PHP 8+ with the `pdo_sqlite` extension (on by default in almost all PHP installs).
- A web server that runs PHP (Apache, nginx+PHP-FPM, or just PHP's built-in server for local use).
- Write permission for the web-server user on the data directory (see step 3).

## 1. Set the passwords

Open `config.php` and edit the two lines at the top:

```php
const AUTH_PASSWORD  = 'user';    // shared password for the whole tracker
const ADMIN_PASSWORD = 'admin';   // separate password for the "Setup rooms" page
```

Change them to real words. Both are **off by default** — you switch each on later in
Setup rooms → Access control. Changing a password signs everyone out of that gate (that's how you
revoke access). You can also leave them off entirely for a fully open, trust-based setup.

## 2. Copy the files to your server

Upload the whole folder to your web space (e.g. `~/public_html/office-planner/` or a vhost root).
Everything except the data directory is just code.

## 3. Make the data directory writable

The app stores its SQLite database and backups in the directory named by `DATA_DIR` in `config.php`
(default: `data/` inside the app). It is created automatically on first load, but the web-server user
must be able to write there. If auto-creation fails (strict permissions), create it yourself and make
it writable, e.g.:

```bash
mkdir data && chmod 775 data
```

## 4. Open the app

Visit the URL in a browser. On first load the app creates the database, writes the protective
`.htaccess` files, and seeds a set of **demo rooms** (all the room types, with example names) so you
can see how everything works. Delete or edit those from the **Setup rooms** page once you're ready.

### Local trial

```bash
cd office-planner
php -S localhost:8000
```
then open http://localhost:8000.

## 5. Protect the data from the web (important on nginx)

The data directory sits inside the app folder and is shielded by an auto-written `.htaccess`
("Deny from all") — which works on **Apache**. **nginx ignores `.htaccess`**, so there you must
either add a server rule:

```nginx
location ^~ /data/ { deny all; return 404; }
```

or move the data outside the web root by editing `config.php`:

```php
const DATA_DIR = __DIR__ . '/../office-planner-data';
```

**Verify:** open `https://yoursite/…/data/tracker.sqlite` once — you should get *Forbidden/404*, not a
download. (See the README's "Deploying" section for details.)

## 6. (Optional) Use MySQL instead of SQLite

SQLite needs nothing extra and is recommended for small installs. To use MySQL instead:

1. Create a database (optionally from `schema_mysql.sql`; the app also creates its tables on first run).
2. In `config.php` set `$USE_SQLITE = false` and fill in `$MYSQL_HOST / $MYSQL_DB / $MYSQL_USER /
   $MYSQL_PASS`.

(Backups are automatic only in SQLite mode; for MySQL, use your usual `mysqldump` routine.)

## 7. (Optional) Scheduled backups via cron

Backups happen automatically on page load (at most once a day) with no cron needed. If you also want a
guaranteed daily snapshot regardless of traffic, add a cron job that runs `backup.php` — see the
comment at the top of that file for the exact `crontab` line.

## Upgrading later

Replace the code files with a newer version, but **leave the data directory in place** — it holds the
live database and backups. The app migrates the database schema automatically on first load after an
upgrade, so existing data carries over with nothing to re-enter. Then hard-refresh the page in the
browser (Ctrl/Cmd+Shift+R) so the updated JavaScript loads.
