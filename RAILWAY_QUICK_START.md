# ChatPro - Railway Deployment Quick Start

## Problem: "Application failed to respond"

This error usually means the database connection failed or migrations weren't run.

## Quick Diagnosis

**First, test these endpoints:**

```bash
# Simple health check (no database needed)
curl https://your-domain.railway.app/api/health

# Full diagnostics (includes database check)
curl https://your-domain.railway.app/api/diagnostics

# API documentation
https://your-domain.railway.app/api/docs
```

**Expected responses:**
- `/api/health` → Should return `{"status": "ok", ...}`
- `/api/diagnostics` → Shows database connection status
- `/api/docs` → Shows HTML documentation page

If none of these work, continue below.

## Quick Fix Checklist

### 1️⃣ Set Environment Variables in Railway

Go to your Railway project → **Variables** tab and add these:

```
APP_ENV=prod
APP_SECRET=<generate-random-string>
DATABASE_URL=<your-database-connection-string>
```

**How to generate APP_SECRET:**
```bash
# Run in your terminal:
openssl rand -hex 32
# Or use: python -c "import secrets; print(secrets.token_hex(32))"
```

**How to get DATABASE_URL:**
1. Go to your Railway dashboard
2. Click on your MySQL/PostgreSQL service
3. Click "Connect" button
4. Copy the connection string (looks like: `mysql://user:pass@host:3306/dbname`)
5. Add `?serverVersion=8&charset=utf8mb4` to the end if it's MySQL
6. Paste it as `DATABASE_URL` variable

### 2️⃣ Redeploy

After setting variables:
1. Railway → **Deployments** tab
2. Click **Deploy** or wait for auto-deploy if enabled

### 3️⃣ Verify

Once deployed:
1. Check Railway logs for errors (should see "Application Ready")
2. Test: `curl https://your-domain.railway.app/api/health`
3. If it shows `{"status": "ok"}`, great!
4. Visit docs: `https://your-domain.railway.app/api/docs`

## Troubleshooting

### If `/api/health` returns 404 or timeout:

```bash
# Go to Railway shell
railway shell

# Run diagnostic script
bash scripts/diagnose.sh

# Or manually check:
curl http://localhost:80/api/health
tail -20 /var/log/apache2/error.log
```

### Database Connection Errors

**Copy the DATABASE_URL from Railway:**

**MySQL (Railway):**
```
mysql://root:PASSWORD@mysql.railway.internal:3306/railway?serverVersion=8&charset=utf8mb4
```

**PostgreSQL (Railway):**
```
postgresql://postgres:PASSWORD@postgres.railway.internal:5432/railway?serverVersion=16
```

Find your actual password in Railway's database service "Connect" tab.

### If database still fails:

```bash
railway shell

# Test connection
php -r "
\$dsn = getenv('DATABASE_URL');
echo 'Testing: ' . substr(\$dsn, 0, 50) . '...' . PHP_EOL;
try {
    \$pdo = new PDO(\$dsn);
    echo 'SUCCESS: Database connected' . PHP_EOL;
} catch (Exception \$e) {
    echo 'ERROR: ' . \$e->getMessage() . PHP_EOL;
}
"

# Run migrations manually
php bin/console doctrine:migrations:migrate --no-interaction --env=prod
```

### Missing `/api/docs` page:

```bash
railway shell
ls -la templates/docs/api_documentation.html.twig
php bin/console cache:clear --env=prod
```

## Advanced Debugging

See **[TROUBLESHOOTING.md](TROUBLESHOOTING.md)** for full debugging guide including:
- Apache error logs
- PHP errors
- Permission issues
- Manual migrations
- Cached issues
