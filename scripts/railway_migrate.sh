#!/bin/sh
set -e

echo "Running Doctrine migrations (prod)..."
php bin/console doctrine:migrations:migrate --no-interaction --env=prod

echo "Clearing cache (prod)..."
php bin/console cache:clear --env=prod

echo "Done."
