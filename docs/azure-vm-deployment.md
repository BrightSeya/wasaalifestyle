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

The app has no no-show model, activity-risk rules, or activity-risk reminders. The slot recommender's Random Forest is the separate ML service.

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

## Automatic deployment from GitHub

The `CI` workflow deploys a successful push to `main` after the frontend build, sanity checks, Laravel tests, and ML tests pass. It uses a self-hosted GitHub Actions runner on this VM, so no GitHub-hosted runner needs inbound SSH access. The VM runner connects outbound to GitHub.

### One-time runner setup

1. Complete the manual VM setup above. `/var/www/wasaa` must be a clean Git checkout of this repository on `main`; the production `.env`, database, and ignored recommender model artifacts must already be in place.
2. In the GitHub repository, open **Settings → Actions → Runners → New self-hosted runner**, choose **Linux x64**, and follow GitHub's generated download and configuration commands in a VM terminal as `azureuser`. Add the custom label `wasaa-prod`. Never share or commit the temporary registration token.
3. Install and start the runner as a service so it persists after SSH sessions end and VM restarts:

   ```bash
   sudo ./svc.sh install azureuser
   sudo ./svc.sh start
   sudo ./svc.sh status
   ```

   GitHub should show the runner online with labels `self-hosted`, `Linux`, `X64`, and `wasaa-prod`.
4. The deployment job needs `azureuser` to run only the required service reloads without an interactive password. Check the `systemctl` path with `command -v systemctl`, then use `sudo visudo` to add this narrowly scoped rule, adjusting the path if needed:

   ```sudoers
   azureuser ALL=(root) NOPASSWD: /usr/bin/systemctl restart wasaa-recommender, /usr/bin/systemctl reload php8.3-fpm, /usr/bin/systemctl reload nginx
   ```

   Validate the file with `sudo visudo -c`. Do not grant the runner unrestricted sudo access.
5. Protect `main` so only reviewed changes can trigger production deployment.

Each successful push to `main` fetches the latest code into `/var/www/wasaa`, installs production Composer dependencies, copies the browser assets built and uploaded by the GitHub-hosted frontend job, applies Laravel migrations, refreshes the config cache, and reloads the recommender, PHP-FPM, and Nginx. Node.js and npm are needed by the GitHub-hosted build job, not on the VM. The workflow never overwrites `.env` or transfers model artifacts. Back up the database before releases that include migrations; keep VM and database backups current.

If a deployment fails, inspect the failing job in GitHub **Actions** and the Laravel/Nginx/service logs on the VM. Do not run `key:generate` during an update or recovery.

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

## Automatic GitHub deployment

The `CI` workflow deploys each successful push to `main`. It waits for the frontend build, Laravel checks, and ML checks, then runs the deployment on a self-hosted GitHub Actions runner on the Azure VM. The runner label must include `wasaa-prod`. This keeps SSH closed to GitHub-hosted runner address ranges; the runner makes an outbound connection to GitHub.

### One-time setup

1. Confirm the VM is running Ubuntu 24.04 and the manual deployment above is complete. In particular, `/var/www/wasaa` must be a Git checkout of this repository on branch `main`, and the production `.env`, database, and model artifacts must already be in place. Keep `.env` and generated model files outside Git.
2. On the VM, install the runtime tools used by the workflow: PHP 8.3 and Composer, Node.js 22.12 or newer, Git, and the Laravel PHP extensions. Verify `php -v`, `composer --version`, and `node --version` as the account that will run the runner.
3. In the GitHub repository, open **Settings → Actions → Runners → New self-hosted runner**, choose Linux x64, and follow GitHub's current download and configuration commands on the VM. Add the custom label `wasaa-prod` when configuring the runner. Registration tokens expire; copy a fresh token from GitHub when configuring and never commit it.
4. Run the runner as the same VM account that owns `/var/www/wasaa` (typically `azureuser`), and install/start it as a system service using the commands GitHub displays. Do not run it as root. Ensure that account can fetch the private/public repository and write the Laravel `storage/` and `bootstrap/cache/` directories.
5. Allow that account to run only the service operations in the workflow without a password. Edit sudoers with `sudo visudo` and add a narrowly scoped rule, adjusting the account if needed:

   ```sudoers
   azureuser ALL=(root) NOPASSWD: /usr/bin/systemctl restart wasaa-recommender, /usr/bin/systemctl reload php8.3-fpm, /usr/bin/systemctl reload nginx
   ```

   Check command paths with `command -v systemctl`, and ensure sudoers accepts the rule with `sudo visudo -c`.
6. In GitHub, verify the runner shows **Idle** with labels `self-hosted`, `linux`, `x64`, and `wasaa-prod`. Keep the `main` branch protected so only reviewed changes can reach the production deployment path.
7. Push a small reviewed change to `main`. In **Actions**, wait for all CI jobs and then **Deploy to Azure VM**. On the VM, check `git -C /var/www/wasaa rev-parse --short HEAD`, `systemctl status wasaa-recommender --no-pager`, and the site in a browser.

Each deployment fetches the latest `main` commit, installs production Composer and npm dependencies, builds browser assets, runs Laravel migrations, refreshes the Laravel config cache, and reloads Nginx/PHP-FPM and the recommender. It does not overwrite the VM's `.env` or transfer model artifacts. Back up the database before a release with schema changes, and keep Azure disk/VM backups current; a failed migration may require restoring a backup. If your VM uses a different PHP-FPM version, update its service name in `.github/workflows/ci.yml` and the sudoers rule to match.

### Recovery

If a deployment fails, inspect the failed job in GitHub Actions and the local services on the VM. Fix forward with a new commit to `main`; for an application regression, revert the offending commit and push the revert. For a failed schema migration, restore the database backup only after assessing whether any writes happened after the backup. Do not run `key:generate` during recovery.

## Manual updates and recovery

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

The `/health` route reports whether the slot recommender is loaded. The app has no no-show or activity-risk reminder feature.

The Laravel test suite covers rule-based assessments using test data. The slot recommender remains an independent Flask service.

## Sprint 8 deployment verification record

The repository changes and CI checks do not prove the live VM or NSG configuration. Complete this table after applying the merged commit on Azure; do not claim deployment verification until each item has been checked.

| Item | Result to record |
|---|---|
| Deployed Git commit (`git rev-parse --short HEAD`) | Pending deployment |
| Recommender service status and local `/health` | Pending deployment |
| Bound address (`ss -lntp`) | Pending; expected `127.0.0.1:5001` |
| NSG/effective inbound rule for TCP 5001 from Internet | Pending portal verification; expected absent |
| Ordinary schedule create/edit and conflict checks | Pending browser verification |

Keep real IP addresses, user details, tokens, and raw model data out of this record.

## Current deployment status

The Azure VM has served the app over its public IP, and the recommender health endpoint has returned `model_loaded: true`. Domain DNS and HTTPS, production SMTP delivery, and Google OAuth with the final HTTPS callback remain separate setup tasks. Until HTTPS and mail are configured and verified, treat the IP-hosted site as a visual demo only.

The running app has no activity-risk reminder or no-show model. This repository workspace does not verify the live VM's environment or NSG; verify those separately before relying on deployment behavior.
