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

The optional commitment-risk model is separate from the slot recommender. Keep its artifact and model card under `/var/www/wasaa/ml/no-show/models/`, outside `web/public/`. The service template disables risk inference by default. Do not enable it until an approved-real artifact and evaluation-approved alert threshold exist.

Transfer only approved model artifacts and their matching model card over an authenticated encrypted channel (for example, SCP using the protected SSH key). Never copy the raw user dataset to Git, the web root, or an unprotected workstation. Record checksums at the trusted source and compare after transfer (`Get-FileHash -Algorithm SHA256` in Windows PowerShell; `sha256sum` on Ubuntu). Stage the files outside the web root, then install with owner `azureuser:www-data`, directory mode `750`, and file mode `640`:

```bash
sha256sum /secure-transfer/commitment_risk.joblib /secure-transfer/model_card.json
```

Run the same command after transfer and compare the values before installing.

```bash
sudo install -d -o azureuser -g www-data -m 750 /var/www/wasaa/ml/no-show/models
sudo install -o azureuser -g www-data -m 640 /secure-transfer/commitment_risk.joblib /var/www/wasaa/ml/no-show/models/commitment_risk.joblib
sudo install -o azureuser -g www-data -m 640 /secure-transfer/model_card.json /var/www/wasaa/ml/no-show/models/model_card.json
sudo systemctl restart wasaa-recommender
sudo journalctl -u wasaa-recommender -n 50 --no-pager
```

The running process accepts only an `approved-real` model card whose feature list and bundle version match. A bad, missing, or synthetic-only model must remain unavailable; the existing slot-recommender routes should continue to start. Keep copies of the prior artifact pair outside the repository so a reviewed update can be rolled back. Never enable the risk model just to make the health check pass.

## Laravel scheduler on Azure

The commitment-risk alert command runs synchronously from Laravel's scheduler; it does not dispatch queue jobs, so no `queue:work` worker is required for this feature. Install the tracked systemd service and timer after deploying the Laravel code:

```bash
sudo cp /var/www/wasaa/deploy/azure/wasaa-scheduler.service /etc/systemd/system/
sudo cp /var/www/wasaa/deploy/azure/wasaa-scheduler.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now wasaa-scheduler.timer
```

Confirm the Laravel task and timer are present, then inspect recent scheduler output:

```bash
cd /var/www/wasaa/web
php artisan schedule:list
sudo systemctl list-timers wasaa-scheduler.timer --all
sudo systemctl start wasaa-scheduler.service
sudo journalctl -u wasaa-scheduler.service -n 50 --no-pager
```

The command exits without model requests or alerts unless Laravel's `COMMITMENT_RISK_ENABLED` and a valid `COMMITMENT_RISK_ALERT_THRESHOLD` are deliberately configured. Both remain disabled/unset until evaluation approval. When disabled, ordinary schedule CRUD and conflict handling remain available.

To disable the add-on, set `COMMITMENT_RISK_ENABLED=false` and leave `COMMITMENT_RISK_ALERT_THRESHOLD` empty in the Laravel `.env`; in a systemd override set `WASAA_RISK_MODEL_ENABLED=false`. Then run `php artisan optimize:clear`, `php artisan config:cache`, and `sudo systemctl restart wasaa-recommender`. This does not disable ordinary schedule creation, editing, conflict checking, or the existing slot recommender. Do not enable either risk flag or set a threshold until the approved-real artifact and its evaluation have been reviewed.

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
COMMITMENT_RISK_ENABLED=false
COMMITMENT_RISK_ALERT_THRESHOLD=
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

Keep the model service private during updates. Verify both its local bind and its external reachability from a different network:

```bash
sudo ss -lntp | grep ':5001'
curl -fsS http://127.0.0.1:5001/health
```

The listener must be `127.0.0.1:5001`, not `0.0.0.0:5001`. From the Azure portal, open the VM's **Networking** page, inspect its effective inbound security rules and attached NSG, and verify there is no inbound TCP rule for port `5001` (or `5000`). Keep only the intended SSH rule restricted to the administrator's IP and HTTP/HTTPS rules as required. From a separate machine, `Test-NetConnection <public-ip> -Port 5001` should report `TcpTestSucceeded: False`.

The `/health` route reports whether the existing slot model is loaded; it does not prove the optional risk model is loaded. To check a deliberately enabled approved risk model, POST only a synthetic, non-identifying feature object to `http://127.0.0.1:5001/noshow/predict` from the VM and verify a valid response. With the risk model disabled or stopped, expect a generic `503`; then run Laravel's alert command and verify it creates no assessment or alert. Do not include real user fields in health probes or shell history.

Only exercise the stopped-service failure path with an isolated test database and synthetic test account. Do not turn on the feature in a live deployment just to run this check. If the approved model is not available, keep the production flags off; the Python and Laravel automated suites already exercise the unavailable-model case with synthetic test doubles.

## Sprint 8 deployment verification record

The repository changes and CI checks do not prove the live VM or NSG configuration. Complete this table after applying the merged commit on Azure; do not claim deployment verification until each item has been checked.

| Item | Result to record |
|---|---|
| Deployed Git commit (`git rev-parse --short HEAD`) | Pending deployment |
| Recommender service status and local `/health` | Pending deployment |
| Bound address (`ss -lntp`) | Pending; expected `127.0.0.1:5001` |
| NSG/effective inbound rule for TCP 5001 from Internet | Pending portal verification; expected absent |
| Scheduler timer and `php artisan schedule:list` | Pending deployment |
| Risk endpoint with model disabled or service stopped | Pending deployment; expected unavailable, with no alert created |
| Ordinary schedule create/edit while risk feature is disabled | Pending browser verification |

Keep real IP addresses, user details, tokens, and raw model data out of this record.

## Current deployment status

The Azure VM has served the app over its public IP, and the recommender health endpoint has returned `model_loaded: true`. Domain DNS and HTTPS, production SMTP delivery, and Google OAuth with the final HTTPS callback remain separate setup tasks. Until HTTPS and mail are configured and verified, treat the IP-hosted site as a visual demo only.

The commitment-risk model is not currently deployed or enabled. `WASAA_RISK_MODEL_ENABLED` remains false in the tracked systemd template; Laravel's `COMMITMENT_RISK_ENABLED` is false by default and its alert threshold is unset. The live VM's NSG and scheduler timer have not been independently verified from this repository workspace.
