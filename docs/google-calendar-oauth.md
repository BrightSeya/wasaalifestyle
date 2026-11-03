# Google Calendar OAuth setup

Wasaa connects to Google Calendar through the server-side OAuth authorization-code flow. It requests only `https://www.googleapis.com/auth/calendar.events.readonly`, so the connection can read events but cannot change them. Access and refresh tokens are encrypted with Laravel's `APP_KEY` before they are stored per user.

## Google Cloud setup

1. Select or create a Google Cloud project and enable the Google Calendar API.
2. Configure the OAuth consent screen and add the Calendar events read-only scope.
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

Sign in, open **Settings**, and select **Connect Google Calendar**. Google returns to the callback route, where Wasaa validates the one-time OAuth state before exchanging the code. The refresh token allows continued access after short-lived access tokens expire. If Google revokes or expires the refresh grant, the stored connection is removed and the user must connect again.

`APP_KEY` must remain stable while encrypted credentials are stored. If it is changed, existing tokens cannot be decrypted and affected users need to reconnect.

## Official references

- [OAuth 2.0 for Web Server Applications](https://developers.google.com/identity/protocols/oauth2/web-server) — authorization state, offline access, code exchange, and refresh requests.
- [Choose Google Calendar API scopes](https://developers.google.com/workspace/calendar/api/auth) — Calendar scopes and their access levels.
