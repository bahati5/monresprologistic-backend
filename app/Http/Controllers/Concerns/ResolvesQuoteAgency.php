<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Agency;
use Illuminate\Http\Request;

trait ResolvesQuoteAgency
{
    /**
     * Agence utilisée pour les paramètres devis (lignes, templates, e-mails, audit).
     * Priorité : utilisateur → profil → première agence active.
     */
    protected function quoteAgencyId(Request $request): int
    {
        $user = $request->user();
        if ($user->agency_id) {
            return (int) $user->agency_id;
        }

        $user->loadMissing('profile');
        if ($user->profile?->agency_id) {
            return (int) $user->profile->agency_id;
        }

        $fallback = Agency::query()->where('is_active', true)->orderBy('id')->value('id');
        abort_if(! $fallback, 503, 'Aucune agence active : impossible de charger les paramètres devis.');

        return (int) $fallback;
    }
}
