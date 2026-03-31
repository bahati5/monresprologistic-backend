#!/usr/bin/env bash
set -e
cd /var/www/html

echo "[entrypoint] PHP $(php -r 'echo PHP_VERSION;')"

if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] Installation Composer..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

if [ ! -f .env ] && [ -f .env.example ]; then
    echo "[entrypoint] Copie .env.example -> .env"
    cp .env.example .env
fi

# Supprimer le fichier hot périmé : si Vite n'est pas (encore) démarré,
# ce fichier fait pointer @vite() vers un serveur injoignable → timeout navigateur.
# Vite le recréera automatiquement quand son conteneur démarrera.
if [ -f public/hot ]; then
    echo "[entrypoint] Suppression de public/hot (sera recréé par Vite)"
    rm -f public/hot
fi

# Droits d'ecriture Laravel (utile en bind-mount)
mkdir -p storage/framework/{sessions,views,cache} storage/logs bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache 2>/dev/null || true

if [ "${SKIP_DB_WAIT:-0}" != "1" ] && [ -f docker/wait-for-mysql.php ]; then
    php docker/wait-for-mysql.php
fi

# -- Caches Laravel -------------------------------------------------------
# Sur un bind-mount Windows, chaque requete re-lit des dizaines de fichiers
# (config, routes, vues) a travers une couche de virtualisation tres lente.
# Ces caches les consolident en fichiers uniques -> gain de 10-60 s/requete.
# Pour rafraichir : docker exec monrespro_app php artisan optimize:clear
echo "[entrypoint] Generation des caches Laravel (config, routes, vues, events)..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec "$@"
