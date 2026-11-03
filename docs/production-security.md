# Production security checklist

Use this checklist before exposing Wasaa Lifestyle to public users. The repository does not select a hosting provider; configure host-specific TLS and proxy settings for the deployment target.

## Environment

Set secrets in the hosting environment or secret manager. Do not commit a real `.env` file.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
```

Keep `APP_KEY` private and stable. Configure a production mail transport so account verification, password setup, and password recovery messages are delivered. Set `SESSION_SECURE_COOKIE=true` explicitly; the application also defaults it to secure when `APP_ENV=production`.

For local development, `MAIL_MAILER=log` records messages in `storage/logs/laravel.log` and does not send them to an inbox. To test inbox delivery, configure a real SMTP transport in the local `.env`; keep mail credentials out of Git. For Gmail SMTP, use Google's current account security requirements and an app password when eligible; do not use the normal account password.

## HTTPS, proxies, and headers

- Configure the hosting proxy or web server to redirect HTTP to HTTPS and trust only the actual proxy addresses. Do not trust arbitrary forwarded headers.
- The application sends HSTS only when it is running in production and Laravel recognizes the request as HTTPS. Confirm TLS termination and trusted-proxy settings make `$request->isSecure()` accurate; otherwise configure HSTS at the TLS-terminating proxy.
- The application adds `nosniff`, frame denial, a strict referrer policy, a limited Permissions Policy, and a production Content Security Policy. The CSP permits inline styles because the current Blade views use dynamic style attributes and allows the Bunny Fonts stylesheet/font host used on the public welcome page; it does not permit inline scripts. Delete confirmations are handled by the bundled JavaScript.
- Local development does not receive production CSP/HSTS, so Vite HMR continues to work.

## Accounts and operations

- Public registration is limited to five attempts per IP per minute.
- New registrations must verify their email before accessing web or Sanctum-protected app functionality. Verification links are signed and time-limited by Laravel.
- Run `composer audit`, `npm audit`, Laravel tests, ML tests, and `npm run build` as release checks.
- Run migrations and cache configuration only after production environment values are set. Never expose debug pages or publish logs containing credentials or verification/reset tokens.
