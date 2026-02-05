# ChatPro - Railway Deployment Quick Start

## Problem: "Application failed to respond"

This error usually means the database connection failed or migrations weren't run.

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
1. Check Railway logs for errors (should see "Application ready")
2. Visit your domain: `https://your-railway-domain.railway.app/api/docs`
3. If it works, you'll see the API documentation page

## Still Getting Errors?

### Check Logs
```bash
railway shell
tail -100 /var/log/apache2/error.log
tail -100 /var/log/apache2/access.log
```

### Check Database Connection
```bash
railway shell
php bin/console doctrine:database:create --if-not-exists --env=prod
php bin/console doctrine:migrations:migrate --env=prod
```

### Database-specific URLs

**MySQL (Railway):**
```
mysql://root:PASSWORD@mysql.railway.internal:3306/railway?serverVersion=8&charset=utf8mb4
```

**PostgreSQL (Railway):**
```
postgresql://postgres:PASSWORD@postgres.railway.internal:5432/railway?serverVersion=16
```

## Advanced Troubleshooting

### If you get "SQLSTATE[HY000]"
- The database host/port/credentials are wrong
- Check DATABASE_URL format

### If you get "no such table"
- Run migrations: `railway run php bin/console doctrine:migrations:migrate --no-interaction`

### If app starts but certain features don't work
- Clear cache: `railway run php bin/console cache:clear --env=prod`
- Rebuild assets: `railway redeploy`  (forces full rebuild)

---

**Need more details?** See [README_DEPLOY_RAILWAY.md](README_DEPLOY_RAILWAY.md)
