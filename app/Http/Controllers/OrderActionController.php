<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ifood\Actions\ActionNotAllowedException;
use App\Ifood\Actions\OrderActionService;
use App\Ifood\Client\IfoodClient;
use App\Ifood\Enums\OrderActionType;
use App\Ifood\Exceptions\IfoodException;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class OrderActionController extends Controller
{
    public function store(Request $request, Order $order, OrderActionService $actions): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::enum(OrderActionType::class)],
            'cancellation_code' => ['nullable', 'string', 'max:16'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $type = OrderActionType::from(self::str($data['action']));

        try {
            $actions->request($order, $type, self::str($data['cancellation_code'] ?? null) ?: null, self::str($data['reason'] ?? null) ?: null);
        } catch (ActionNotAllowedException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$type->label()}: enviado ao iFood, aguardando confirmação.");
    }

    /** The list depends on the order type, so it always comes from iFood. */
    public function cancellationReasons(Order $order, IfoodClient $client): JsonResponse
    {
        try {
            return response()->json(['reasons' => $client->cancellationReasons($order->ifood_order_id)]);
        } catch (IfoodException $e) {
            return response()->json([
                'reasons' => [],
                'message' => 'O iFood não devolveu os motivos de cancelamento'.($e->errorMessage ? ": {$e->errorMessage}" : '.'),
            ], 422);
        }
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
