<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

/**
 * §25.5 PRD — La donnée financière est immuable une fois validée.
 */
class InvoicePolicy
{
    public function delete(User $user, Invoice $invoice): bool
    {
        if (in_array($invoice->status, ['paid', 'sent', 'partial', 'overdue'], true)) {
            return false;
        }

        return $user->hasRole('super_admin');
    }
}
