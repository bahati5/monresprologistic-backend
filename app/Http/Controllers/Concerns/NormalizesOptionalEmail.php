<?php

namespace App\Http\Controllers\Concerns;

trait NormalizesOptionalEmail
{
    /**
     * Email CRM optionnel : chaîne vide → null (notifications possibles par téléphone / SMS).
     */
    protected function normalizeOptionalEmail(mixed $email): ?string
    {
        if ($email === null || $email === '') {
            return null;
        }

        if (! is_string($email)) {
            return null;
        }

        $trimmed = trim($email);

        return $trimmed === '' ? null : $trimmed;
    }
}
