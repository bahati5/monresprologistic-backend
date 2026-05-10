<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LedgerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage_finances'), 403);

        $q = $this->filteredQuery($request);

        return response()->json([
            'entries' => $q->paginate(40),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()->can('manage_finances'), 403);

        $filename = 'grand-livre-'.now()->format('Y-m-d-His').'.csv';

        $query = $this->filteredQuery($request);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'date', 'agency_id', 'user_id', 'invoice_id', 'type', 'amount', 'currency', 'description', 'reference_type', 'reference_id']);

            $query->orderByDesc('id')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row->id,
                        $row->created_at?->toIso8601String(),
                        $row->agency_id,
                        $row->user_id,
                        $row->invoice_id,
                        $row->type,
                        $row->amount,
                        $row->currency,
                        $row->description,
                        $row->reference_type,
                        $row->reference_id,
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function filteredQuery(Request $request)
    {
        $user = $request->user();
        $q = LedgerEntry::query()->with(['agency', 'user', 'invoice'])->latest();

        if (! $user->canAccessAllAgencies()) {
            $q->where('agency_id', $user->agency_id);
        }

        if ($request->filled('type')) {
            $q->where('type', $request->string('type'));
        }

        if ($request->filled('user_id')) {
            $q->where('user_id', (int) $request->input('user_id'));
        }

        if ($request->filled('date_from')) {
            $q->whereDate('created_at', '>=', $request->date('date_from')->format('Y-m-d'));
        }

        if ($request->filled('date_to')) {
            $q->whereDate('created_at', '<=', $request->date('date_to')->format('Y-m-d'));
        }

        return $q;
    }
}
