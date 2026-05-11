# Railway Deployment Guide

## 1. Use Railway environment variables
This app already reads settings from environment variables and `.env`.

Copy `.env.example` to `.env` for local development:

```bash
cp .env.example .env
```

Then update the variables from Railway:

- `APP_ENV=production`
- `DB_HOST` → Railway MySQL host
- `DB_PORT` → Railway MySQL port
- `DB_NAME` → Railway MySQL database name
- `DB_USER` → Railway MySQL username
- `DB_PASS` → Railway MySQL password
- `BASE_PATH` → `/`

Railway automatically ignores `.env` if the repo uses `.gitignore`.

## 2. Deploy the database schema
Railway gives you MySQL connection details. Use them to run the schema file:

```bash
mysql -h $DB_HOST -P $DB_PORT -u $DB_USER -p$DB_PASS $DB_NAME < sql/schema.sql
```

Or use Railway's SQL editor to import `sql/schema.sql` directly.

## 3. Deploy the app on Railway
This project now includes a `Dockerfile`, so Railway can build and run it as a PHP/Apache service.

### Recommended workflow
1. Create a new Railway project.
2. Add a MySQL plugin.
3. Connect your GitHub repo or upload this directory.
4. Set Railway environment variables to match the database values.
5. Deploy the service.

## 4. Important notes
- `BASE_PATH` is now configurable via environment variable.
- If you serve the app from root, use `BASE_PATH=/`.
- Keep `.env` local and do not commit it.
