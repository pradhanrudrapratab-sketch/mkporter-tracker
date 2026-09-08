# 🚚 Porter Live Tracker

A multi-user web application to monitor Porter ride-tracking URLs in real-time.

**Stack:** PHP 8.3 · PostgreSQL · Leaflet.js · Render (Web Service + Background Worker)

---

## Features

- ✅ Register/login with username + password (no email required)
- ✅ Add multiple Porter tracking URLs
- ✅ Background worker polls every **30 seconds** server-side
- ✅ Live map with Leaflet/OpenStreetMap
- ✅ GPS location history saved per ride
- ✅ Automatic ride completion detection
- ✅ Telegram report sent **10 minutes after completion** (message + CSV)
- ✅ Per-user Telegram Bot Token & Chat ID
- ✅ Token encrypted at rest (AES-256-GCM)
- ✅ Accounts inactive for 30 days auto-deleted
- ✅ Full CSRF protection, prepared statements, SSRF protection
- ✅ Multi-user data isolation
- ✅ Orange / Black / White premium UI

---

## 🚀 Deploy on Render (Recommended)

### 1. Push to GitHub

```bash
git init
git add .
git commit -m "Porter Tracker initial commit"
git remote add origin https://github.com/YOURNAME/porter-tracker.git
git push -u origin main
```

### 2. Create a New Blueprint on Render

1. Go to [render.com](https://render.com) → **New** → **Blueprint**
2. Connect your GitHub repository
3. Render will detect `render.yaml` and create:
   - **porter-tracker-web** (Web Service)
   - **porter-tracker-worker** (Background Worker)
   - **porter-db** (PostgreSQL)

### 3. Set Required Environment Variables

In the Render dashboard for `porter-tracker-web`, set:

| Variable | Value |
|----------|-------|
| `APP_URL` | Your Render URL, e.g. `https://porter-tracker.onrender.com` |
| `APP_KEY` | Run `openssl rand -hex 32` and paste the result |

> `DATABASE_URL` is automatically injected from the linked PostgreSQL database.

### 4. Run Migrations

After first deploy, open the Render Shell for `porter-tracker-web` and run:

```bash
php migrate.php
```

That's it — your app is live! 🎉

---

## 🛠 Local Development

### Requirements
- PHP 8.2+
- PostgreSQL
- Composer
- Extensions: `pdo_pgsql`, `curl`, `openssl`

### Setup

```bash
# Clone the repo
git clone ...
cd porter-tracker

# Install dependencies
composer install

# Copy and configure environment
cp .env.example .env
# Edit .env with your DATABASE_URL and APP_KEY

# Create the database
createdb porter_tracker

# Run migrations
php migrate.php

# Start the web server
php -S localhost:8080 -t public/

# In another terminal, start the worker
php worker.php
```

---

## 📁 Project Structure

```
porter-tracker/
├── public/
│   └── index.php          # Front controller / router
├── src/
│   ├── Auth/
│   │   └── AuthController.php
│   ├── Config/
│   │   ├── config.php
│   │   ├── Database.php
│   │   ├── Encryption.php
│   │   └── Logger.php
│   ├── Middleware/
│   │   ├── Auth.php
│   │   └── CSRF.php
│   ├── Ride/
│   │   ├── PorterParser.php
│   │   └── RideController.php
│   └── Telegram/
│       ├── ReportGenerator.php
│       ├── TelegramController.php
│       └── TelegramService.php
├── templates/
│   ├── layout.php
│   ├── login.php
│   ├── register.php
│   ├── dashboard.php
│   ├── ride_detail.php
│   ├── settings_telegram.php
│   ├── settings_password.php
│   └── 404.php
├── database/
│   └── migrations/
│       ├── 001_create_users.sql
│       ├── 002_create_rides.sql
│       ├── 003_create_waypoints.sql
│       ├── 004_create_location_history.sql
│       └── 005_create_telegram_settings.sql
├── worker.php             # Background polling worker
├── migrate.php            # Migration runner
├── composer.json
├── Dockerfile             # Web service
├── Dockerfile.worker      # Background worker
├── render.yaml            # Render blueprint
└── .env.example
```

---

## 🔐 Security

- Passwords hashed with `password_hash(PASSWORD_DEFAULT)`
- Telegram tokens encrypted with AES-256-GCM
- CSRF tokens on all state-changing requests
- Session ID regenerated on login
- Secure, HttpOnly, SameSite cookies
- All DB queries use PDO prepared statements
- Porter URL strictly validated (SSRF protection)
- User data fully isolated — no cross-user access possible

---

## 📋 Environment Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `DATABASE_URL` | ✅ | — | PostgreSQL connection string |
| `APP_KEY` | ✅ | — | 32+ char random secret for encryption |
| `APP_URL` | ✅ | — | Public URL of the web service |
| `APP_ENV` | | `production` | `development` or `production` |
| `TRACK_INTERVAL_SECONDS` | | `30` | Polling interval per ride |
| `REPORT_DELAY_SECONDS` | | `600` | Seconds after completion before Telegram report |
| `INACTIVE_DAYS` | | `30` | Days before inactive account is deleted |
| `APP_TIMEZONE` | | `Asia/Kolkata` | Display timezone |
| `LOG_LEVEL` | | `info` | `debug`, `info`, `warn`, `error` |
