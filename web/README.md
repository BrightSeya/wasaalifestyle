# Wasaa Lifestyle web application

This directory contains the Laravel application for accounts, activities, schedules, recommendations, notifications, settings, and Google Calendar import.

## Requirements

- PHP `^8.2` and Composer
- Node.js `>=22.12.0` and npm
- MySQL or MariaDB for the configured local development database
- The Flask slot recommender for recommendation requests (see the repository's `ml/slot-recommender/` directory)

## Install and run (Windows PowerShell)

From this directory:

```powershell
composer install
Copy-Item .env.example .env  # only when .env does not already exist
php artisan key:generate     # only when creating a new local environment
```

Configure `.env` with the local database connection, then run:

```powershell
php artisan migrate
php artisan storage:link
npm.cmd ci
npm.cmd run build
php artisan serve
```

Visit `http://127.0.0.1:8000`. For frontend development, run `npm.cmd run dev` in another terminal. Start the ML service separately when using recommendations, and set `SLOT_RECOMMENDER_URL` if its address differs from `http://127.0.0.1:5001`.

Keep an existing `.env` and `APP_KEY`: replacing the key can make previously encrypted Google Calendar tokens unreadable. Run `php artisan migrate` to update an existing database. Avoid rerunning the default seed command on a populated database because its fixed test email may already exist.

For all service setup, configuration names, data flow, tests, and known limitations, see [`../docs/system-architecture.md`](../docs/system-architecture.md). The end-user guide is [`../docs/user-manual.md`](../docs/user-manual.md).
