#!/usr/bin/env bash
set -e
cd /var/www/html

# Empreinte du lockfile : si elle change, on réinstalle dans le volume Linux
LOCK_HASH=""
if [ -f package-lock.json ]; then
    LOCK_HASH=$(sha256sum package-lock.json | cut -d' ' -f1)
fi

ROLLUP_LINUX="node_modules/@rollup/rollup-linux-x64-gnu"
NEED_INSTALL=false

if [ ! -d node_modules ]; then
    NEED_INSTALL=true
elif [ ! -d "$ROLLUP_LINUX" ]; then
    echo "[entrypoint] Binaires Rollup Linux manquants (souvent après un npm sur Windows). Réinstallation…"
    NEED_INSTALL=true
elif [ -n "$LOCK_HASH" ]; then
    if [ ! -f node_modules/.vite-lock-hash ] || [ "$(cat node_modules/.vite-lock-hash)" != "$LOCK_HASH" ]; then
        echo "[entrypoint] package-lock.json modifié, réinstallation npm…"
        NEED_INSTALL=true
    fi
fi

if [ "$NEED_INSTALL" = true ]; then
    echo "[entrypoint] npm install (Linux, optional deps inclus)…"
    # node_modules est un volume Docker : impossible de « rm -rf node_modules » (EBUSY).
    # On vide uniquement le contenu du point de montage.
    if [ -d node_modules ]; then
        find node_modules -mindepth 1 -delete 2>/dev/null || true
    fi
    npm install --no-audit --no-fund
    if [ -n "$LOCK_HASH" ]; then
        echo "$LOCK_HASH" > node_modules/.vite-lock-hash
    fi
fi

exec npm run dev -- --host 0.0.0.0 --port 5173
