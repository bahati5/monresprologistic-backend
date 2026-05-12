<?php

namespace App\Http\Controllers;

use App\Enums\SavTicketCategory;
use App\Enums\SavTicketPriority;
use App\Enums\SavTicketStatus;
use App\Models\SavQuickReply;
use App\Models\SavTicket;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SavTicketController extends Controller
{
    use Concerns\InteractsWithAgencyVisibility;

    protected function assertSavTicketVisibleToUser(Request $request, SavTicket $ticket): void
    {
        $user = $request->user();
        if ($user->hasRole('client')) {
            abort_unless((int) $ticket->client_id === (int) $user->id, 403);

            return;
        }
        if (! $user->canAccessAllAgencies()) {
            abort_unless((int) $ticket->agency_id === (int) $user->agency_id, 403);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $q = SavTicket::query()
            ->with(['client', 'assignee', 'creator']);

        if ($user->hasRole('client')) {
            $q->where('client_id', $user->id);
        } elseif (! $user->canAccessAllAgencies()) {
            $q->where('agency_id', $user->agency_id);
        }

        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }
        if ($request->filled('priority')) {
            $q->where('priority', $request->input('priority'));
        }
        if ($request->filled('category')) {
            $q->where('category', $request->input('category'));
        }
        if ($request->filled('assigned_to')) {
            $q->where('assigned_to', $request->input('assigned_to'));
        }
        if ($request->filled('search')) {
            $search = $request->input('search');
            $q->where(function ($sub) use ($search) {
                $sub->where('reference_code', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $sort = $request->input('sort', 'sla');
        $q->when($sort === 'sla', fn ($b) => $b->orderByRaw('CASE WHEN sla_deadline_at IS NULL THEN 1 ELSE 0 END, sla_deadline_at ASC'))
            ->when($sort === 'newest', fn ($b) => $b->orderByDesc('created_at'))
            ->when($sort === 'oldest', fn ($b) => $b->orderBy('created_at'));

        $tickets = $q->paginate($request->integer('per_page', 25));

        $countsByStatus = SavTicket::query()
            ->when($user->hasRole('client'), fn ($b) => $b->where('client_id', $user->id))
            ->when(! $user->hasRole('client') && ! $user->canAccessAllAgencies(), fn ($b) => $b->where('agency_id', $user->agency_id))
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'tickets' => $tickets,
            'counts_by_status' => $countsByStatus,
            'categories' => collect(SavTicketCategory::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()]),
            'priorities' => collect(SavTicketPriority::cases())->map(fn ($p) => ['value' => $p->value, 'label' => $p->label()]),
            'statuses' => collect(SavTicketStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    public function show(Request $request, SavTicket $savTicket): JsonResponse
    {
        $this->assertSavTicketVisibleToUser($request, $savTicket);
        $savTicket->load(['client', 'assignee', 'creator', 'messages.user', 'related']);
        if ($request->user()->hasRole('client')) {
            $visible = $savTicket->messages->filter(fn ($m) => ! $m->is_internal)->values();
            $savTicket->setRelation('messages', $visible);
        }

        return response()->json(['ticket' => $savTicket]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['nullable', 'exists:users,id'],
            'category' => ['required', Rule::enum(SavTicketCategory::class)],
            'priority' => ['nullable', Rule::enum(SavTicketPriority::class)],
            'channel' => ['nullable', 'string', 'max:24'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'related_type' => ['nullable', 'string'],
            'related_id' => ['nullable', 'integer'],
            'attachments' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $category = SavTicketCategory::from($data['category']);

        if ($user->hasRole('client') && ! empty($data['related_id'])) {
            $relType = (string) ($data['related_type'] ?? '');
            if ($relType === Shipment::class || str_ends_with($relType, 'Shipment')) {
                $shipment = Shipment::find((int) $data['related_id']);
                abort_unless($shipment && $user->can('view', $shipment), 403);
            } else {
                $data['related_type'] = null;
                $data['related_id'] = null;
            }
        }

        $ticket = new SavTicket;
        $ticket->reference_code = SavTicket::generateReferenceCode();
        $ticket->agency_id = $user->agency_id ?? 1;
        $ticket->client_id = $user->hasRole('client') ? $user->id : ($data['client_id'] ?? null);
        $ticket->created_by = $user->id;
        $ticket->category = $category;
        $ticket->priority = isset($data['priority']) ? SavTicketPriority::from($data['priority']) : SavTicketPriority::from($category->defaultPriority());
        $ticket->status = SavTicketStatus::Open;
        $ticket->channel = $data['channel'] ?? 'portal';
        $ticket->subject = $data['subject'];
        $ticket->description = $data['description'] ?? null;
        $ticket->related_type = $data['related_type'] ?? null;
        $ticket->related_id = $data['related_id'] ?? null;
        $ticket->attachments = $data['attachments'] ?? null;
        $ticket->computeSlaDeadline();
        $ticket->save();

        if ($data['description'] ?? null) {
            $ticket->messages()->create([
                'user_id' => $user->id,
                'body' => $data['description'],
                'is_internal' => false,
                'channel' => $data['channel'] ?? 'portal',
            ]);
        }

        return response()->json(['ticket' => $ticket->load(['client', 'assignee', 'creator'])], 201);
    }

    public function update(Request $request, SavTicket $savTicket): JsonResponse
    {
        $this->assertSavTicketVisibleToUser($request, $savTicket);

        $data = $request->validate([
            'assigned_to' => ['nullable', 'exists:users,id'],
            'priority' => ['nullable', Rule::enum(SavTicketPriority::class)],
            'category' => ['nullable', Rule::enum(SavTicketCategory::class)],
            'subject' => ['nullable', 'string', 'max:255'],
        ]);

        $savTicket->update(array_filter($data, fn ($v) => $v !== null));

        return response()->json(['ticket' => $savTicket->fresh(['client', 'assignee', 'creator'])]);
    }

    public function assign(Request $request, SavTicket $savTicket): JsonResponse
    {
        $this->assertSavTicketVisibleToUser($request, $savTicket);

        $user = $request->user();

        $savTicket->assigned_to = $request->input('user_id', $user->id);

        if ($savTicket->status === SavTicketStatus::Open) {
            $savTicket->status = SavTicketStatus::InProgress;
        }

        $savTicket->save();

        return response()->json(['ticket' => $savTicket->fresh(['client', 'assignee', 'creator'])]);
    }

    public function updateStatus(Request $request, SavTicket $savTicket): JsonResponse
    {
        $this->assertSavTicketVisibleToUser($request, $savTicket);

        $data = $request->validate([
            'status' => ['required', Rule::enum(SavTicketStatus::class)],
        ]);

        $newStatus = SavTicketStatus::from($data['status']);

        if (! $savTicket->status->canTransitionTo($newStatus)) {
            return response()->json([
                'message' => "Transition de {$savTicket->status->label()} vers {$newStatus->label()} non autorisée.",
            ], 422);
        }

        $savTicket->status = $newStatus;

        if ($newStatus === SavTicketStatus::Resolved) {
            $savTicket->resolved_at = now();
        } elseif ($newStatus === SavTicketStatus::Closed) {
            $savTicket->closed_at = now();
        } elseif ($newStatus === SavTicketStatus::Escalated) {
            $savTicket->escalated_at = now();
        }

        $savTicket->save();

        return response()->json(['ticket' => $savTicket->fresh(['client', 'assignee', 'creator'])]);
    }

    public function reply(Request $request, SavTicket $savTicket): JsonResponse
    {
        $this->assertSavTicketVisibleToUser($request, $savTicket);

        $data = $request->validate([
            'body' => ['required', 'string'],
            'is_internal' => ['nullable', 'boolean'],
            'attachments' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $isInternal = $user->hasRole('client') ? false : ($data['is_internal'] ?? false);

        $message = $savTicket->messages()->create([
            'user_id' => $user->id,
            'body' => $data['body'],
            'is_internal' => $isInternal,
            'channel' => 'app',
            'attachments' => $data['attachments'] ?? null,
        ]);

        if (! $isInternal && ! $savTicket->first_response_at) {
            $savTicket->first_response_at = now();
        }

        $isStaff = $user->hasAnyRole(['super_admin', 'agency_admin', 'operator']);
        if (! $isInternal && $isStaff && $savTicket->status === SavTicketStatus::InProgress) {
            $savTicket->status = SavTicketStatus::WaitingClient;
        }

        $savTicket->save();

        return response()->json(['message' => $message->load('user')]);
    }

    // ── Quick Replies ───────────────────

    public function quickReplies(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasRole('client')) {
            return response()->json(['quick_replies' => []]);
        }

        $replies = SavQuickReply::query()
            ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id))
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['quick_replies' => $replies]);
    }

    public function storeQuickReply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'category' => ['nullable', 'string', 'max:40'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $reply = SavQuickReply::create([
            'agency_id' => $request->user()->agency_id ?? 1,
            ...$data,
        ]);

        return response()->json(['quick_reply' => $reply], 201);
    }

    public function updateQuickReply(Request $request, SavQuickReply $savQuickReply): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:40'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $savQuickReply->update(array_filter($data, fn ($v) => $v !== null));

        return response()->json(['quick_reply' => $savQuickReply]);
    }

    public function destroyQuickReply(SavQuickReply $savQuickReply): JsonResponse
    {
        $savQuickReply->delete();

        return response()->json(null, 204);
    }
}
