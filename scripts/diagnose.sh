#!/bin/bash
# ChatPro Deployment Diagnostic Script
# Run this with: bash scripts/diagnose.sh (locally or in railway shell)

echo "╔════════════════════════════════════════════════════════════════╗"
echo "║         ChatPro Deploy - Diagnostic Report                    ║"
echo "║                    $(date)                ║"
echo "╚════════════════════════════════════════════════════════════════╝"
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

check() {
    if [ $1 -eq 0 ]; then
        echo -e "${GREEN}✓ $2${NC}"
        return 0
    else
        echo -e "${RED}✗ $2${NC}"
        return 1
    fi
}

warn() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

info() {
    echo -e "${BLUE}ℹ $1${NC}"
}

# ============= SYSTEM CHECK =============
echo -e "${BLUE}[SYSTEM INFORMATION]${NC}"
uname -s && check $? "Operating System detected"
which php > /dev/null && check $? "PHP installed" || check 1 "PHP NOT installed"

# ============= PHP CHECK =============
echo ""
echo -e "${BLUE}[PHP CONFIGURATION]${NC}"
PHP_VERSION=$(php -v | head -n1)
echo "Version: $PHP_VERSION"

# Check extensions
echo "Extensions:"
php -m | grep -q pdo && check 0 "  PDO" || check 1 "  PDO"
php -m | grep -q pdo_mysql && check 0 "  PDO MySQL" || check 1 "  PDO MySQL"
php -m | grep -q pdo_pgsql && check 0 "  PDO PostgreSQL" || check 1 "  PDO PostgreSQL"
php -m | grep -q json && check 0 "  JSON" || check 1 "  JSON"
php -m | grep -q xml && check 0 "  XML" || check 1 "  XML"
php -m | grep -q zip && check 0 "  ZIP" || check 1 "  ZIP"

# ============= PROJECT CHECK =============
echo ""
echo -e "${BLUE}[PROJECT STRUCTURE]${NC}"
[ -d "src" ] && check 0 "src/ directory" || check 1 "src/ directory"
[ -d "config" ] && check 0 "config/ directory" || check 1 "config/ directory"
[ -d "public" ] && check 0 "public/ directory" || check 1 "public/ directory"
[ -d "templates" ] && check 0 "templates/ directory" || check 1 "templates/ directory"
[ -d "vendor" ] && check 0 "vendor/ directory (dependencies installed)" || check 1 "vendor/ directory"
[ -f "composer.json" ] && check 0 "composer.json" || check 1 "composer.json"
[ -f "bin/console" ] && check 0 "bin/console (Symfony CLI)" || check 1 "bin/console"

# ============= ENVIRONMENT CHECK =============
echo ""
echo -e "${BLUE}[ENVIRONMENT VARIABLES]${NC}"
if [ -z "$APP_ENV" ]; then
    check 1 "APP_ENV is set (missing)"
else
    check 0 "APP_ENV = $APP_ENV"
fi

if [ -z "$APP_SECRET" ]; then
    check 1 "APP_SECRET is set (missing - CRITICAL)"
else
    check 0 "APP_SECRET is set (length: ${#APP_SECRET})"
fi

if [ -z "$DATABASE_URL" ]; then
    check 1 "DATABASE_URL is set (missing - CRITICAL)"
else
    # Hide password in output
    DISPLAY_URL=$(echo "$DATABASE_URL" | sed 's/:[^@]*@/:***@/')
    check 0 "DATABASE_URL is set: $DISPLAY_URL"
fi

# ============= SYMFONY CHECK =============
echo ""
echo -e "${BLUE}[SYMFONY CONFIGURATION]${NC}"
if [ -f "bin/console" ]; then
    # Check Symfony version
    SYMFONY_VER=$(php bin/console --version 2>/dev/null | awk '{print $3}')
    if [ ! -z "$SYMFONY_VER" ]; then
        echo "Symfony version: $SYMFONY_VER"
        check 0 "Symfony CLI working"
    else
        check 1 "Symfony CLI working"
    fi
    
    # Check routes
    echo ""
    echo "API Routes:"
    php bin/console debug:router 2>/dev/null | grep "/api/" | head -5
fi

# ============= DATABASE CHECK =============
echo ""
echo -e "${BLUE}[DATABASE CONNECTION]${NC}"
if [ ! -z "$DATABASE_URL" ]; then
    php << 'PHPEOF'
$dsn = getenv('DATABASE_URL');
echo "Testing connection to database...\n";
try {
    $dbType = parse_url($dsn, PHP_URL_SCHEME);
    echo "  Database type: $dbType\n";
    
    $pdo = new PDO($dsn);
    echo "✓ Connection successful\n";
    
    // Try a simple query
    $result = $pdo->query("SELECT 1");
    echo "✓ Query test successful\n";
    
    // Get database info
    $dbName = parse_url($dsn, PHP_URL_PATH);
    echo "  Database: $dbName\n";
} catch (Exception $e) {
    echo "✗ Connection failed: " . $e->getMessage() . "\n";
    exit(1);
}
PHPEOF
else
    check 1 "DATABASE_URL not set"
fi

# ============= MIGRATIONS CHECK =============
echo ""
echo -e "${BLUE}[DATABASE MIGRATIONS]${NC}"
if [ -f "bin/console" ]; then
    echo "Migration status:"
    php bin/console doctrine:migrations:list 2>/dev/null | tail -15 || echo "Could not read migrations"
fi

# ============= APACHE CHECK =============
echo ""
echo -e "${BLUE}[APACHE/WEB SERVER]${NC}"
which apache2ctl > /dev/null && {
    check 0 "Apache found"
    apache2ctl -V 2>/dev/null | grep "Apache/" | head -1
    apache2ctl -M 2>/dev/null | grep -q rewrite && check 0 "  rewrite module" || check 1 "  rewrite module"
    apache2ctl -M 2>/dev/null | grep -q php && check 0 "  PHP module" || warn "  PHP module (using PHP-FPM?)"
} || warn "Apache not found (might be running PHP-FPM)"

# ============= FILE PERMISSIONS =============
echo ""
echo -e "${BLUE}[FILE PERMISSIONS]${NC}"
if [ -d "var" ]; then
    VAR_PERMS=$(ls -ld var | awk '{print $1, $3, $4}')
    echo "  var/: $VAR_PERMS"
    [ -w "var" ] && check 0 "var/ is writable" || check 1 "var/ is NOT writable"
fi

if [ -d "public" ]; then
    PUB_PERMS=$(ls -ld public | awk '{print $1, $3, $4}')
    echo "  public/: $PUB_PERMS"
fi

# ============= RECENT LOGS =============
echo ""
echo -e "${BLUE}[RECENT APACHE ERRORS (last 10)]${NC}"
if [ -f "/var/log/apache2/error.log" ]; then
    tail -10 /var/log/apache2/error.log | sed 's/^/  /'
else
    echo "  error.log not found"
fi

echo ""
echo -e "${BLUE}[RECENT SYMFONY LOGS]${NC}"
if [ -d "var/log" ]; then
    ls -lt var/log/*.log 2>/dev/null | head -5 | awk '{print "  " $9 " (" $6 " " $7 " " $8 ")"}'
fi

# ============= RECOMMENDATIONS =============
echo ""
echo -e "${BLUE}[RECOMMENDATIONS]${NC}"

HAS_ERRORS=0

if [ -z "$APP_ENV" ] || [ -z "$APP_SECRET" ] || [ -z "$DATABASE_URL" ]; then
    warn "Missing critical environment variables!"
    warn "Set these in Railway Variables: APP_ENV, APP_SECRET, DATABASE_URL"
    HAS_ERRORS=1
fi

if [ ! -d "vendor" ]; then
    warn "Dependencies not installed. Run: composer install"
    HAS_ERRORS=1
fi

if [ "$APP_ENV" != "prod" ] && [ ! -z "$APP_ENV" ]; then
    warn "APP_ENV is not 'prod'. Should be 'prod' for deployment."
    HAS_ERRORS=1
fi

if [ $HAS_ERRORS -eq 0 ]; then
    echo -e "${GREEN}✓ All checks passed! Your deployment should be good to go.${NC}"
else
    echo -e "${YELLOW}⚠ Please address the issues above before deploying.${NC}"
fi

echo ""
echo "═════════════════════════════════════════════════════════════════"
echo "Generated: $(date '+%Y-%m-%d %H:%M:%S')"
echo "═════════════════════════════════════════════════════════════════"
