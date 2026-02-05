# Deploy to Railway — Minimal steps

1) Choose deployment method

- Railway can build using the included `Dockerfile` (recommended) or use PHP buildpacks.

2) Required environment variables (set in Railway project settings)

- `APP_ENV` = `prod`
- `APP_SECRET` = (generate secure value)
- `DATABASE_URL` = (pdo connection, e.g. `mysql://user:pass@host:3306/dbname`)
- `MAILER_DSN` = (if used)
- `RAILS_ENV` not required for Symfony

3) Railway: build & run

- From Railway dashboard, create a new project -> Deploy from GitHub, choose this repo.
- In "Settings" set the build command to use the provided `Dockerfile` (Railway detects automatically).

4) After container starts: run migrations

- Open Railway shell or use Railway CLI and run:

```bash
# Enter container (or run via CLI/railway run)
php bin/console doctrine:migrations:migrate --no-interaction --env=prod
php bin/console cache:clear --env=prod
php bin/console doctrine:fixtures:load --no-interaction --env=prod # optional
```

5) Assets

- If you rely on `public/build`, ensure `npm run build` completes during Docker build. If you prefer to build assets inside Railway, set a `build` script in `package.json`.

6) Notes

- Ensure `public/` is the document root (Dockerfile config takes care of this).
- For zero-downtime deploys consider using Railway releases and run migrations in a dedicated job.
