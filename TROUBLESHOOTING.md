# Troubleshooting - Application Failed to Respond

Si tu app en Railway dice "Application failed to respond", sigue estos pasos.

## Paso 1: Verificar que la app está corriendo

```bash
# Accede a la terminal de Railway
railway shell

# Intenta estos comandos:
curl http://localhost:80/api/health
curl http://localhost:80/api/diagnostics
```

**Esperado:** Deberías ver respuestas JSON

Si no funciona, continúa con el Paso 2.

## Paso 2: Revisar Logs de Apache y PHP

```bash
railway shell

# Ver errores de Apache
tail -100 /var/log/apache2/error.log

# Ver request log
tail -100 /var/log/apache2/access.log

# Ver logs del servidor
journalctl -n 100
```

**Busca errores tipo:**
- `connection refused` → Database problem
- `PHP Fatal error` → Código error
- `No such file` → Missing file
- `Permission denied` → Permisos incorrectos

## Paso 3: Verificar Base de Datos

```bash
railway shell

# Ver variables de entorno
env | grep DATABASE_URL

# Probar conexión con PHP
php -r "
\$dsn = getenv('DATABASE_URL');
echo 'DSN: ' . \$dsn . PHP_EOL;

try {
    \$pdo = new PDO(\$dsn);
    echo 'Database connection: OK' . PHP_EOL;
} catch (Exception \$e) {
    echo 'Database connection ERROR: ' . \$e->getMessage() . PHP_EOL;
}
"
```

### Si DATABASE_URL no está visible o es incorrecto:

1. Ve a tu proyecto Railway dashboard
2. Haz clic en tu app 
3. Ve a **Variables** tab
4. Modifica `DATABASE_URL` y asegúrate que está correcto

**Formato MySQL:**
```
mysql://user:password@mysql.railway.internal:3306/railway?serverVersion=8&charset=utf8mb4
```

**Formato PostgreSQL:**
```
postgresql://user:password@postgres.railway.internal:5432/railway?serverVersion=16
```

## Paso 4: Revisar Configuración de Symfony

```bash
railway shell

# Ver variables de entorno críticas
echo "APP_ENV: $APP_ENV"
echo "APP_SECRET está set: $([ ! -z \"$APP_SECRET\" ] && echo 'YES' || echo 'NO')"
echo "DATABASE_URL está set: $([ ! -z \"$DATABASE_URL\" ] && echo 'YES' || echo 'NO')"
```

**Todos deben ser YES. Si no:**

1. Railway dashboard → Variables
2. Asegúrate que tienes:
   ```
   APP_ENV=prod
   APP_SECRET=<valor-aleatorio>
   DATABASE_URL=<connection-string>
   ```

## Paso 5: Ejecutar Migraciones Manualmente

```bash
railway shell

# Ver estado de migraciones
php bin/console doctrine:migrations:list

# Ejecutar migraciones
php bin/console doctrine:migrations:migrate --no-interaction --env=prod

# Ver estado de la base de datos
php bin/console doctrine:database:create --if-not-exists --env=prod
```

## Paso 6: Verificar Permisos

```bash
railway shell

# Verificar que www-data puede escribir en var/
ls -la var/
ls -la var/log/
ls -la var/cache/

# Si hay problemas de permisos:
chown -R www-data:www-data var/ public/
chmod -R 755 var/ public/
```

## Paso 7: Reiniciar Apache manualmente

```bash
railway shell

# Reiniciar Apache
apache2ctl restart

# O fuerza:
pkill apache2
sleep 2
apache2-foreground
```

## Paso 8: Forzar Redeploy Completo

En Railway dashboard:
1. Ve a **Deployments**
2. Haz clic en el último deployment
3. Haz clic en **Redeploy**
4. Espera 2-3 minutos

## Problemas Comunes y Soluciones

### ❌ "SQLSTATE[HY000]: General error"
**Causa:** Database connection failed
- Verifica `DATABASE_URL` está correcto
- Verifica database service está running en Railway
- Prueba la conexión con el test en Paso 3

### ❌ "No such file or directory"
**Causa:** Archivo faltante o permisos
- ejecuta: `ls -R src/ config/ public/ | head -20`
- Verifica que la repo está completa

### ❌ "Class not found: App\..."
**Causa:** Composer no instaló las dependencias
```bash
railway shell
composer install --no-dev --optimize-autoloader
```

### ❌ "404 Not Found" en /api/docs
**Causa:** Template missing o ruta mal configurada
```bash
railway shell
ls -la templates/docs/api_documentation.html.twig
php bin/console debug:router | grep api_docs
```

### ❌ "Timeout"
**Causa:** Query lenta o infinito loop
- Ejecuta: `tail -50 /var/log/apache2/error.log`
- Reduce query count si hay N+1 queries
- Aumenta timeout en Railway settings

## Debug Endpoints

Una vez la app esté parcialmente funcionando, usa estos:

- `GET /api/health` ← Endpoint más simple, sin database
- `GET /api/diagnostics` ← Ver estado de extensiones y DB
- `GET /api/docs` ← Documentation page (requiere templates)

## Último Recurso: Logs Completos

```bash
railway shell

# Exportar TODOS los logs
tail -500 /var/log/apache2/error.log > /tmp/apache_errors.txt
tail -500 /var/log/apache2/access.log > /tmp/apache_access.txt
php bin/console doctrine:database:create --if-not-exists --env=prod 2>&1 > /tmp/doctrine_output.txt

# Ver que se crearon
ls -lah /tmp/*.txt

# Mostrar cada uno
cat /tmp/apache_errors.txt
cat /tmp/apache_access.txt
cat /tmp/doctrine_output.txt
```

---

**¿Aún no funciona?** Copia el OUTPUT de estos comandos:
```bash
railway shell
echo "=== ENVIRONMENT ===" && env | grep -E "APP_|DATABASE_" 
echo "=== PHP ===" && php -v
echo "=== EXTENSIONS ===" && php -m | grep -E "pdo|mysql"
echo "=== APACHE ===" && apache2ctl -V 2>&1 | head -5
echo "=== RECENT ERRORS ===" && tail -20 /var/log/apache2/error.log
```

Y comparte el output!
