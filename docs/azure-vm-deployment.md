# Azure VM deployment runbook

This runbook describes the Wasaa Lifestyle single-VM deployment used for the student demo. It assumes Ubuntu 24.04, Nginx, PHP-FPM 8.3, MySQL 8, and a private Flask/Gunicorn recommender on one Azure VM. Keep the VM's static public IP and monitor Azure Cost Management while it is running.

## Network and access

- Restrict SSH (TCP 22) in the VM's Network Security Group to the administrator's current public IP. Do not open MySQL (3306) or the recommender (5001) to the internet.
- Allow HTTP (80) and HTTPS (443) only as needed. The application is not safe for password entry until HTTPS is configured and verified.
- Store the SSH private key outside the repository and limit its Windows file permissions. Never upload it to GitHub or paste it into chat.
- Keep Azure budget alerts enabled. Deallocating the VM stops compute billing, but attached disks and IP resources may continue to incur charges. Confirm current costs in Azure Cost Management.

## Install and prepare the VM

Use an Ubuntu 24.04 x64 VM. Install Nginx, MySQL, PHP 8.3 with the extensions required by Laravel, Composer, Git, Python 3, and Python virtual-environment support. Confirm PHP-FPM is available at `/run/php/php8.3-fpm.sock` before enabling the Nginx site.

Create the MySQL database and a dedicated application user. Do not configure Laravel to connect as MySQL `root`; Ubuntu's root account commonly uses socket authentication. Grant the application user access only to the Wasaa database, and keep its generated password only in the VM's `.env` file.

Clone the repository into `/var/www/wasaa`. Install production PHP dependencies from `web/`:

```bash
cd /var/www/wasaa/web
composer install --no-dev --prefer-dist --optimize-autoloader
```

Create `/var/www/wasaa/web/.env` from the production configuration checklist below. Do not copy a development `.env` into Git or overwrite a live `.env` during updates. On a new installation only, generate `APP_KEY` once after setting up `.env` and before migrating; preserve it for all later deployments because it encrypts stored integration tokens:

```bash
cd /var/www/wasaa/web
php artisan key:generate --force
```

## Build browser assets

The frontend requires Node.js `>=22.12.0` on the build machine. Build assets from `web/` on the VM or a trusted build machine:

```bash
cd /var/www/wasaa/web
npm ci
npm run build
```

On Windows PowerShell, use `npm.cmd ci` and `npm.cmd run build`. Transfer the complete `web/public/build/` directory, including `manifest.json` and its referenced files, to the VM using SCP. Copy it into `/var/www/wasaa/web/public/build/` and ensure it is readable by Nginx. Do not commit `node_modules/` or generated build output. GitHub Actions runs the same production build on pull requests and pushes to `main`.

## Install the recommender

Use a Python version supported by the pinned ML dependencies. Create the virtual environment under `/var/www/wasaa/ml/slot-recommender/.venv` and install production requirements:

```bash
cd /var/www/wasaa/ml/slot-recommender
python3 -m venv .venv
.venv/bin/pip install --upgrade pip
.venv/bin/pip install -r requirements-prod.txt
```

The trained `models/rf_model.joblib` and generated `data/processed/completion_rate_lookup.csv` are intentionally ignored by Git; the model is large and contains generated artifacts. Transfer the artifacts through a secure channel to those exact paths. Do not commit them. Install `deploy/azure/wasaa-recommender.service`, then enable it:

```bash
sudo cp /var/www/wasaa/deploy/azure/wasaa-recommender.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now wasaa-recommender
```

The service binds to `127.0.0.1:5001`, loads the model once at startup, and must not be exposed in the Azure NSG. Confirm it reports `model_loaded: true`:

```bash
curl -fsS http://127.0.0.1:5001/health
sudo systemctl status wasaa-recommender --no-pager
```

## Nginx and Laravel

Install `deploy/azure/nginx-wasaa.conf` as `/etc/nginx/sites-available/wasaa`, enable it under `sites-enabled`, disable the default site, and set the document root to `/var/www/wasaa/web/public`. The template is for the temporary HTTP/IP stage; configure a real hostname and HTTPS before using accounts publicly. Do not copy this HTTP-only template over a later Certbot-managed HTTPS configuration.

Set ownership so Nginx/PHP-FPM can read application files and write only to Laravel's `storage/` and `bootstrap/cache/` directories. For the `azureuser` deployment account used by this VM, the web tree can be owned by `azureuser:www-data`; keep `.env` mode `640` so only that account and the PHP-FPM group can read it. Create the public storage symlink and apply the database migrations:

```bash
cd /var/www/wasaa/web
php artisan storage:link
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
sudo nginx -t
sudo systemctl reload nginx
```

Do not repeatedly run seeders against a populated database: the default test user has a fixed unique email. Do not run `key:generate` during updates.

## Production configuration checklist

Set these values in the VM's private `.env`; keep secrets out of Git and documentation:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-hostname.example
SESSION_SECURE_COOKIE=true
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=wasaa_lifestyle
DB_USERNAME=wasaa_app
SLOT_RECOMMENDER_URL=http://127.0.0.1:5001
```

Also configure a real SMTP provider and sender (`MAIL_*`) before public registration. `MAIL_MAILER=log` writes verification and recovery messages to logs instead of sending them. Set Google OAuth credentials and register the exact HTTPS callback URI with Google before enabling Calendar connections.

For a temporary visual demo over the VM IP, `APP_URL=http://<static-public-ip>` and `SESSION_SECURE_COOKIE=false` may be needed for assets and cookies. This is HTTP and must not be used for passwords, account creation, or real user data. Move to HTTPS before account testing or sharing the app as a usable service. A separate subdomain can point to the VM without changing a GitHub Pages apex domain.

## Updates and recovery

After merging a change, take a database backup before schema changes, then deploy the merged commit:

```bash
cd /var/www/wasaa
git fetch origin
git checkout main
git pull --ff-only origin main
cd web
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
```

Rebuild/deploy `public/build/` when frontend source or dependencies change. Restart `wasaa-recommender` when its code, dependencies, or model artifacts change. Check Nginx, PHP-FPM, Laravel, and recommender logs after each deployment. Keep database backups outside the VM and document a restore before relying on the demo data.

## Current deployment status

The Azure VM has served the app over its public IP, and the recommender health endpoint has returned `model_loaded: true`. Domain DNS and HTTPS, production SMTP delivery, and Google OAuth with the final HTTPS callback remain separate setup tasks. Until HTTPS and mail are configured and verified, treat the IP-hosted site as a visual demo only.
