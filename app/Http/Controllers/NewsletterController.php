<?php

namespace App\Http\Controllers;

use App\Models\NewsletterSubscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NewsletterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('manage_newsletter');

        $subscribers = NewsletterSubscriber::orderByDesc('created_at')
            ->paginate(50);

        $stats = [
            'total' => NewsletterSubscriber::count(),
            'active' => NewsletterSubscriber::active()->count(),
            'by_locale' => [
                'fr' => NewsletterSubscriber::active()->byLocale('fr')->count(),
                'en' => NewsletterSubscriber::active()->byLocale('en')->count(),
                'es' => NewsletterSubscriber::active()->byLocale('es')->count(),
                'ar' => NewsletterSubscriber::active()->byLocale('ar')->count(),
            ],
        ];

        return response()->json([
            'subscribers' => $subscribers,
            'stats' => $stats,
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'name' => 'nullable|string|max:255',
            'locale' => 'nullable|string|in:fr,en,es,ar|max:5',
        ]);

        $existing = NewsletterSubscriber::where('email', $validated['email'])->first();

        if ($existing) {
            if ($existing->is_active) {
                return response()->json(['message' => 'Déjà abonné'], 409);
            }
            // Reactivate
            $existing->update([
                'is_active' => true,
                'unsubscribed_at' => null,
            ]);
            return response()->json(['message' => 'Abonnement réactivé']);
        }

        NewsletterSubscriber::subscribe(
            $validated['email'],
            $validated['name'] ?? null,
            'website',
            $validated['locale'] ?? 'fr'
        );

        return response()->json(['message' => 'Abonnement confirmé'], 201);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $subscriber = NewsletterSubscriber::where('email', $validated['email'])->first();

        if ($subscriber) {
            $subscriber->unsubscribe();
        }

        return response()->json(['message' => 'Désabonnement effectué']);
    }

    public function destroy(Request $request, NewsletterSubscriber $subscriber): JsonResponse
    {
        $this->authorize('manage_newsletter');
        
        $subscriber->delete();
        
        return response()->json(['success' => true]);
    }
}
