<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\OrderDetailResource;
use App\Http\Resources\OrderResource;
use App\Ifood\Enums\IfoodEventStatus;
use App\Ifood\Enums\OrderStatus;
use App\Ifood\Events\PollingHealth;
use App\Models\IfoodEvent;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class OrderController extends Controller
{
    /** Closed orders stay on the board for this long. */
    private const int BOARD_CLOSED_WINDOW_HOURS = 24;

    private const int BOARD_LIMIT = 300;

    public function index(Request $request, PollingHealth $health): Response
    {
        $view = $request->query('view') === 'list' ? 'list' : 'board';
        $search = trim($request->string('q')->toString());
        $status = OrderStatus::tryFrom($request->string('status')->toString());
        $selectedId = $request->integer('order');

        return Inertia::render('orders/index', [
            'view' => $view,
            'filters' => ['q' => $search, 'status' => $status?->value],
            'orders' => fn () => $view === 'board'
                ? $this->board($search)
                : $this->list($search, $status),
            'counts' => fn () => $this->counts(),
            'health' => fn () => [
                'polling' => $health->last(),
                'events_waiting' => IfoodEvent::query()->where('status', IfoodEventStatus::Received)->count(),
                'events_failed' => IfoodEvent::query()->where('status', IfoodEventStatus::Failed)->count(),
            ],
            'selected' => fn () => $selectedId > 0 ? $this->detail($selectedId) : null,
            'server_time' => now()->toIso8601ZuluString('millisecond'),
        ]);
    }

    /** @return array{data: array<mixed>} */
    private function board(string $search): array
    {
        $orders = Order::query()
            ->where(fn (Builder $q) => $q
                ->whereNotIn('status', [OrderStatus::Concluded, OrderStatus::Cancelled])
                ->orWhere('status_changed_at', '>=', now()->subHours(self::BOARD_CLOSED_WINDOW_HOURS)))
            ->tap(fn (Builder $q) => $this->search($q, $search))
            ->orderByRaw('coalesce(placed_at, first_event_at)')
            ->with('openAction')
            ->limit(self::BOARD_LIMIT)
            ->get();

        return ['data' => OrderResource::collection($orders)->resolve()];
    }

    /** @return array<string, mixed> */
    private function list(string $search, ?OrderStatus $status): array
    {
        $page = Order::query()
            ->when($status, fn (Builder $q, OrderStatus $s) => $q->where('status', $s))
            ->tap(fn (Builder $q) => $this->search($q, $search))
            ->orderByRaw('coalesce(placed_at, first_event_at) desc')
            ->with('openAction')
            ->paginate(25)
            ->withQueryString();

        return [
            'data' => OrderResource::collection($page->getCollection())->resolve(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ];
    }

    /** @param  Builder<Order>  $query */
    private function search(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $like = '%'.addcslashes($search, '%_\\').'%';

        $query->where(fn (Builder $q) => $q
            ->where('display_id', 'ilike', $like)
            ->orWhere('customer_name', 'ilike', $like)
            ->orWhereRaw('ifood_order_id::text ilike ?', [$like]));
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        /** @var array<string, int> */
        return Order::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $n): int => is_numeric($n) ? (int) $n : 0)
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function detail(int $id): ?array
    {
        $order = Order::query()->with(['transitions', 'events', 'actions', 'openAction'])->find($id);

        return $order ? (new OrderDetailResource($order))->resolve() : null;
    }
}
