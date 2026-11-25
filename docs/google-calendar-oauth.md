# Google Calendar OAuth setup

Wasaa connects to Google Calendar and Google Tasks through the server-side OAuth authorization-code flow. It requests `https://www.googleapis.com/auth/calendar.events.readonly` and `https://www.googleapis.com/auth/tasks.readonly` for read-only imports, plus `https://www.googleapis.com/auth/userinfo.email` to display which Google account is connected. Access and refresh tokens are encrypted with Laravel's `APP_KEY` before they are stored per user.

## Google Cloud setup

1. Select or create a Google Cloud project and enable both the Google Calendar API and Google Tasks API.
2. Configure the OAuth consent screen and add the Calendar events read-only, Tasks read-only, and user email scopes.
3. Create an OAuth client ID with application type **Web application**.
4. Add the exact callback URI to **Authorized redirect URIs**. For the default local setup with `php artisan serve`, use `http://localhost:8000/settings/google-calendar/callback`. If the app uses another host or port, set `APP_URL` and the registered redirect URI to match it exactly.
5. Copy the client ID and secret into the local `web/.env` file. Do not commit real credentials.

```dotenv
APP_URL=http://localhost:8000
GOOGLE_CALENDAR_CLIENT_ID=your-client-id
GOOGLE_CALENDAR_CLIENT_SECRET=your-client-secret
GOOGLE_CALENDAR_REDIRECT_URI=http://localhost:8000/settings/google-calendar/callback
```

The callback value in `.env` and in Google Cloud must match exactly.

Then apply the connection migration and reload Laravel's configuration:

```powershell
cd C:\path\to\wasaalifestyle\web
php artisan migrate
php artisan config:clear
```

Sign in, open **Settings**, and select **Connect Google Calendar**. Google returns to the callback route, where Wasaa validates the one-time OAuth state before exchanging the code. Wasaa then requests the verified account email and stores it with the connection; if the email cannot be verified, the new credentials are not saved. The refresh token allows continued access after short-lived access tokens expire. If Google revokes or expires the refresh grant, the stored connection is removed and the user must connect again.

`APP_KEY` must remain stable while encrypted credentials are stored. If it is changed, existing tokens cannot be decrypted and affected users need to reconnect.

## Importing events and tasks

Open **Schedule** and select **Import calendar**. Choose an inclusive date range of up to 31 days and review the fetched Calendar events and dated Google Tasks. Choose a category for each new item on the review screen, then confirm the import. Tasks without a due date are outside the selected date range. Untimed Google Tasks and all-day events are stored and shown as all-day schedule entries on their due/event dates; timed events that cross dates are shown on each affected day. Imported entries use Medium priority. Task IDs are namespaced by task-list ID, and database uniqueness prevents duplicate imports for the same user.

Start and end timestamps are converted to the app time zone for schedule storage; the original Google time zone is kept on timed imported events. Task due dates and all-day event dates use the user's local date. If the Wasaa schedule changes between review and confirmation, the updated conflict summary must be reviewed again. The temporary review payload is encrypted in the session.

Existing connections created before Tasks and account-email support must be reauthorized from **Settings** to grant the Tasks read-only and user email scopes. Google must have the Tasks API enabled in the same Cloud project. If the preview reports a Tasks API access error, confirm it is enabled and reconnect the account.

## Official references

- [OAuth 2.0 for Web Server Applications](https://developers.google.com/identity/protocols/oauth2/web-server) — authorization state, offline access, code exchange, and refresh requests.
- [Choose Google Calendar API scopes](https://developers.google.com/workspace/calendar/api/auth) — Calendar scopes and their access levels.
- [Google Tasks API](https://developers.google.com/tasks) — enablement and API reference.
