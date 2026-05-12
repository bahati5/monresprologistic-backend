<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ProspectResolverService
{
    /**
     * Resolve a user from a public form submission.
     * If the email matches an existing user, link the purchase to them.
     * If not, create a prospect record and send a confirmation email.
     *
     * @return array{user_id: int|null, agency_id: int, is_prospect: bool}
     */
    public function resolve(string $email, string $fullName, ?string $phone = null): array
    {
        $existingUser = User::where('email', strtolower(trim($email)))->first();

        if ($existingUser) {
            return [
                'user_id' => $existingUser->id,
                'agency_id' => $existingUser->agency_id ?? Agency::first()?->id ?? 1,
                'is_prospect' => false,
            ];
        }

        $defaultAgency = Agency::where('is_active', true)->first();
        $agencyId = $defaultAgency?->id ?? 1;

        $this->sendProspectConfirmation($email, $fullName, $agencyId);

        Log::info('Prospect submission recorded', [
            'email' => $email,
            'full_name' => $fullName,
            'agency_id' => $agencyId,
        ]);

        return [
            'user_id' => null,
            'agency_id' => $agencyId,
            'is_prospect' => true,
        ];
    }

    private function sendProspectConfirmation(string $email, string $fullName, int $agencyId): void
    {
        $appName = config('app.name', 'Monrespro');
        $firstName = trim(explode(' ', $fullName)[0] ?? '') ?: 'cher client';

        $subject = "{$appName} — Votre demande d'achat assisté a été reçue";
        $body = "Bonjour {$firstName},\n\n"
            . "Nous avons bien reçu votre demande d'achat assisté. "
            . "Notre équipe la traitera dans les plus brefs délais.\n\n"
            . "Vous recevrez un devis par email dès que le chiffrage sera terminé.\n\n"
            . "Si vous avez déjà un compte {$appName}, connectez-vous pour suivre l'avancement.\n"
            . "Sinon, un compte sera créé automatiquement lors de l'envoi du devis.\n\n"
            . "Cordialement,\nL'équipe {$appName}";

        try {
            Mail::raw($body, function ($message) use ($email, $fullName, $subject) {
                $message->to($email, $fullName)->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::warning("Failed to send prospect confirmation to {$email}: {$e->getMessage()}");
        }
    }
}
