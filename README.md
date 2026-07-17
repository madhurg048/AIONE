# Exotel Voice API — Localhost Test

A tiny, framework-free PHP project to test Exotel's **Connect two numbers**
(click-to-call) Voice API from your local machine (XAMPP/WAMP/Laragon).

## Structure

```
exotel-test/
├── config.php      # All credentials + shared log helper (edit this)
├── call.php        # Builds & sends the Exotel API request (cURL + Basic Auth)
├── webhook.php     # Receives Exotel callbacks, logs them, returns 200 OK
├── index.php       # Simple HTML form -> POSTs to call.php, shows response
├── logs/
│   └── exotel.log  # Every request, response and webhook is appended here
├── .gitignore
└── README.md
```

## Setup

1. Put this folder in your web root, e.g. `C:\xampp\htdocs\exotel-test`.
2. Open **`config.php`** and fill in your real values:
   - `EXOTEL_ACCOUNT_SID`
   - `EXOTEL_API_KEY`
   - `EXOTEL_API_TOKEN`
   - `EXOTEL_API_BASE_URL` — `https://api.exotel.com` or `https://api.in.exotel.com`
   - `EXOTEL_CALLER_ID` — your ExoPhone (virtual number)
   - `EXOTEL_AGENT_NUMBER` — the number dialed first (e.g. your mobile)
3. Make sure Apache is running in XAMPP.

## Run

Open: **http://localhost/exotel-test**

Enter a **Customer Number**, click **Make Call**.

- Exotel dials the **agent number** (`EXOTEL_AGENT_NUMBER`) first.
- When the agent answers, Exotel connects the **customer number** you typed.
- The full API response (Sid, Status, etc.) is shown on the page **and** written
  to `logs/exotel.log`.

## How the call works

`call.php` sends a form-encoded POST to:

```
POST {EXOTEL_API_BASE_URL}/v1/Accounts/{EXOTEL_ACCOUNT_SID}/Calls/connect.json
```

with `From` (agent), `To` (customer), `CallerId` (ExoPhone), authenticated via
HTTP Basic Auth (`API Key` : `API Token`).

## Webhooks

`webhook.php` logs whatever Exotel posts to it and returns `200 OK`.

> Exotel can't reach `localhost` directly. To test callbacks, expose the folder
> with a tunnel (e.g. `ngrok http 80`) and set the resulting HTTPS URL as your
> `StatusCallback` (uncomment it in `call.php`) or in the Exotel dashboard.

## Debugging errors

If you get an error (401 / 404 / 400 / etc.), **don't guess** — collect:

1. The **error message** shown on the page.
2. The relevant block from **`logs/exotel.log`** (REQUEST + RESPONSE).

Common causes:
- **401 Unauthorized** → wrong API Key/Token, or wrong region base URL.
- **404 Not Found** → wrong Account SID in the URL.
- **400 Bad Request** → `From`/`To`/`CallerId` format, or CallerId not owned.

## Security notes

- Credentials live **only** in `config.php`; nothing is hardcoded elsewhere.
- `config.php` and `logs/*.log` are git-ignored so secrets aren't committed.
- TLS verification is **on** in `call.php` (production-safe). If XAMPP's cURL
  can't verify certificates, fix `cacert.pem` rather than disabling verification.
