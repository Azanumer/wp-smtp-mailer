# SMTP Mailer

Routes all WordPress mail through your own SMTP server — password resets, comment notifications, contact-form mail — so it actually arrives instead of vanishing into spam (or never leaving the server at all). No dependencies, no third-party service.

## What it does

- Hooks `phpmailer_init` to point `wp_mail()` at your SMTP host, port, encryption (TLS/SSL/none) and credentials.
- Optional From name / From email override.
- One-click **Send Test Email** button on the settings page — no need to trigger a password reset to check your config.
- Records the outcome of the last send (time, recipient, subject, success/failure) so a silent failure is visible.
- Blank password field on save keeps the stored password — you never have to re-type it to change the host.

## Installation

1. Upload the plugin folder to `wp-content/plugins/` and activate it.
2. Go to Settings → SMTP Mailer and enter your SMTP host, port, encryption, username and password.
3. Send a test email to yourself before relying on it.

Common ports: **587** with TLS (most hosts), **465** with SSL (Gmail, many shared hosts), **25** plain.

## License

MIT
