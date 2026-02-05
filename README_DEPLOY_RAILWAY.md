# Deploy to Railway — Complete Setup Guide

## 1) Choose deployment method

- Railway can build using the included `Dockerfile` (recommended) or use PHP buildpacks.
- The Dockerfile now includes automatic database migrations on startup.

## 2) Required environment variables (MUST be set in Railway project settings)

**CRITICAL**: These must be configured in your Railway project for the app to work:

- `APP_ENV` = `prod` (required for production)
- `APP_SECRET` = Generate a secure random value (e.g., use `openssl rand -hex 32`)
- `DATABASE_URL` = Your database connection URL
- Optional: `MAILER_DSN` = Email configuration (if used)

### Database URL Examples:

**For Railway MySQL service:**
```
mysql://username:password@mysql.railway.internal:3306/railway?serverVersion=8&charset=utf8mb4
```

**For Railway PostgreSQL service:**
```
postgresql://username:password@postgres.railway.internal:5432/railway?serverVersion=16&charset=utf8
```

To find your Railway database credentials:
1. Go to your Railway dashboard
2. Click on your database service (MySQL or PostgreSQL)
3. Go to "Connect" tab
4. Copy the **connection string** and use it as `DATABASE_URL`

## 3) Railway: build & run

- From Railway dashboard, create a new project → Deploy from GitHub, select this repo
- In "Settings" → "Build" → Ensure `Dockerfile` is selected
- In "Variables" tab, add all the environment variables listed above
- Deploy!

## 4) After container starts

The Dockerfile now automatically runs migrations on startup. However, you can also manually run:

```bash
# Via Railway shell:
railway shell
php bin/console doctrine:migrations:migrate --no-interaction --env=prod
php bin/console cache:clear --env=prod
```

Or via Railway CLI:
```bash
railway run php bin/console doctrine:migrations:migrate --no-interaction --env=prod
```

## 5) Troubleshooting

### If app says "Application failed to respond":
1. Check Railway logs for database connection errors
2. Verify `DATABASE_URL` environment variable is correct
3. Make sure database service is running (check Railway dashboard)
4. Check that `APP_ENV=prod` is set (not `dev`)

### If you see database connection errors:
1. Use `railway shell` and run: `php bin/console doctrine:migrations:migrate`
2. Check Railway database service status
3. Verify credentials in `DATABASE_URL`

### If migrations fail:
1. Manually connect to the database and check tables exist
2. Run: `php bin/console doctrine:database:create --if-not-exists --env=prod`
3. Then run: `php bin/console doctrine:migrations:migrate --no-interaction --env=prod`

## 6) Assets

- Static assets are built during Docker build via `npm run build`
- Assets are served from `public/build` directory
- Clear cache if assets don't update: `php bin/console cache:clear --env=prod`

## 7) Important Notes

- **Never commit `.env.local`** with production secrets
- `public/` is correctly configured as the document root in the Dockerfile
- For zero-downtime deploys, consider using Railway's release feature
- If using PostgreSQL, the app will automatically use `pdo_pgsql` driver
- If using MySQL, the app will automatically use `pdo_mysql` driver

## 8) First time setup checklist

- [ ] Repository connected to Railway
- [ ] Database service created (MySQL or PostgreSQL)
- [ ] `APP_ENV=prod` set in Variables
- [ ] `APP_SECRET` set in Variables (generate with: `openssl rand -hex 32` or similar)
- [ ] `DATABASE_URL` set correctly pointing to Railway database
- [ ] Deploy triggered and completed
- [ ] Check Railway logs for errors
- [ ] Navigate to `/api/docs` to verify the app is running

