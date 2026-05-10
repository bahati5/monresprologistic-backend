<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// §5.6 — Expiration automatique des devis non répondus
Schedule::command('quotes:expire')->hourly();
Schedule::command('quotes:remind-expiring')->hourly();

// §8.5 — Rapport de tournée chauffeur automatique en fin de journée
Schedule::command('pickups:daily-report')->dailyAt('20:00');

// §15.2 — Relance Freshsales clients inactifs (run chaque lundi matin)
Schedule::command('freshsales:reengage-inactive')->weeklyOn(1, '09:00');

// §18.4 — Retry automatique des erreurs de synchronisation Odoo
Schedule::command('sync:retry-errors')->everyFiveMinutes();

// §7.2 — Expiration des pré-alertes non déposées
Schedule::command('pre-alerts:expire')->daily();

// Nettoyage des brouillons d'expédition expirés (legacy, à retirer après migration)
Schedule::command('shipments:cleanup-drafts')->daily();

// Notification J-3 + suppression des brouillons de formulaire expirés
Schedule::command('drafts:prune')->daily();

// §21.12 — Rapport mensuel automatique le 1er de chaque mois à 8h
Schedule::command('report:monthly')->monthlyOn(1, '08:00');
