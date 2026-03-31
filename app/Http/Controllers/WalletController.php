<?php

namespace App\Http\Controllers;

use App\Models\ClientWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $wallets = ClientWallet::query()->where('user_id', $user->id)->with('transactions')->get();

        $usersForDeposit = [];
        if ($user->can('manage_finances')) {
            $uq = User::query()->orderBy('name')->limit(500);
            if (! $user->canAccessAllAgencies()) {
                $uq->where('agency_id', $user->agency_id);
            }
            $usersForDeposit = $uq->get(['id', 'name', 'email']);
        }

        return response()->json([
            'wallets' => $wallets,
            'usersForDeposit' => $usersForDeposit,
        ]);
    }

    public function deposit(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage_finances'), 403);

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'currency' => ['required', 'string', 'max:8'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data) {
            $wallet = ClientWallet::query()->firstOrCreate(
                ['user_id' => $data['user_id'], 'currency' => $data['currency']],
                ['balance' => 0]
            );

            $wallet->increment('balance', $data['amount']);

            WalletTransaction::query()->create([
                'client_wallet_id' => $wallet->id,
                'reference' => $data['reference'] ?? 'manual-deposit',
                'amount' => $data['amount'],
                'type' => 'credit',
                'meta' => ['source' => 'admin'],
            ]);
        });

        return response()->json(['message' => 'Portefeuille crédité.']);
    }
}
