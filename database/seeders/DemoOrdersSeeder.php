<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Jobs\FetchOrderDetailsJob;
use App\Jobs\ProcessIfoodEventJob;
use App\Models\IfoodEvent;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

/**
 * Synthetic orders for trying the panel without iFood credentials
 * (`make demo`). Events go through the real processing job, so statuses,
 * history and ignored events are produced by the same code as real orders.
 */
final class DemoOrdersSeeder extends Seeder
{
    public const string DEMO_MERCHANT_ID = '00000000-0000-4000-8000-00000000d3e0';

    private const array MENU = [
        ['Marmita de frango grelhado', 32.9],
        ['Feijoada individual', 38.5],
        ['Salada caesar', 27.0],
        ['Suco de laranja 500 ml', 11.0],
        ['Pudim de leite', 12.5],
        ['Hambúrguer artesanal', 34.0],
        ['Batata frita média', 16.0],
        ['Refrigerante lata', 7.0],
    ];

    public function run(): void
    {
        // Details are written below; never call iFood from the seeder.
        Bus::fake([FetchOrderDetailsJob::class]);

        $now = CarbonImmutable::now();

        // Waiting for confirmation: one fresh, one close to iFood's 8-minute limit.
        $this->order('Ana Souza', 'DELIVERY', [['PLC', $now->subSeconds(50)]]);
        $this->order('Bruno Lima', 'DELIVERY', [['PLC', $now->subMinutes(6)->subSeconds(20)]]);

        $this->order('Carla Mendes', 'TAKEOUT', [['PLC', $now->subMinutes(9)], ['CFM', $now->subMinutes(8)]]);
        $this->order('Diego Rocha', 'DELIVERY', [['PLC', $now->subMinutes(18)], ['CFM', $now->subMinutes(17)], ['PRS', $now->subMinutes(12)]]);
        $this->order('Elisa Martins', 'DINE_IN', [
            ['PLC', $now->subMinutes(25)], ['CFM', $now->subMinutes(25)->addSeconds(1)], ['PRS', $now->subMinutes(20)], ['RTP', $now->subMinutes(4)],
        ]);

        // Out of order: DSP and CFM arrive before the PLC; the PLC is recorded but ignored.
        $this->order('Fábio Nunes', 'DELIVERY', [
            ['DSP', $now->subMinutes(3)], ['CFM', $now->subMinutes(30)], ['PLC', $now->subMinutes(31)],
        ]);

        $this->order('Gabriela Alves', 'DELIVERY', [
            ['PLC', $now->subMinutes(80)], ['CFM', $now->subMinutes(79)], ['PRS', $now->subMinutes(75)],
            ['RTP', $now->subMinutes(60)], ['DSP', $now->subMinutes(58)], ['CON', $now->subMinutes(30)],
        ]);

        // Cancelled by iFood after 8 minutes without confirmation; a late CFM does not reopen it.
        $placed = $now->subMinutes(50);
        $this->order('Heitor Campos', 'DELIVERY', [
            ['PLC', $placed],
            ['CAR', $placed->addMinutes(8)->subMilliseconds(150), ['ORIGIN' => 'SYSTEM', 'reason_code' => '902', 'details' => 'O pedido não foi confirmado pela loja']],
            ['CAN', $placed->addMinutes(8), ['CANCEL_CODE' => '902', 'CANCEL_CODE_DESCRIPTION' => 'O pedido não foi confirmado pela loja', 'CANCEL_ORIGIN' => 'SCHEDULER']],
            ['CFM', $placed->addMinutes(8)->addSeconds(2)],
        ]);

        // Cancelled by the store.
        $this->order('Isabela Prado', 'DINE_IN', [
            ['PLC', $now->subMinutes(40)],
            ['CAR', $now->subMinutes(38), ['ORIGIN' => 'ORDER_API', 'reason_code' => '503', 'details' => 'Item indisponível']],
            ['CAN', $now->subMinutes(38)->addMilliseconds(156), ['CANCEL_CODE' => '503', 'CANCEL_CODE_DESCRIPTION' => 'Item indisponível/desatualizado', 'CANCEL_ORIGIN' => 'RESTAURANT']],
        ]);
    }

    /**
     * Events are processed in the order given (arrival order), not by time.
     *
     * @param  list<array{0: string, 1: CarbonImmutable, 2?: array<string, mixed>}>  $events
     */
    private function order(string $customer, string $type, array $events): void
    {
        $orderId = (string) Str::uuid();
        $arrival = null;

        foreach ($events as $event) {
            // Arrives ~2s after it happened, and never before the previous event
            // arrived: an event listed after a later one shows up as delayed.
            $arrival = $event[1]->addSeconds(2)->max($arrival?->addSecond() ?? $event[1]);

            $stored = IfoodEvent::query()->create([
                'event_id' => (string) Str::uuid(),
                'code' => $event[0],
                'full_code' => $event[0],
                'order_id' => $orderId,
                'merchant_id' => self::DEMO_MERCHANT_ID,
                'sales_channel' => 'IFOOD',
                'event_created_at' => $event[1],
                'metadata' => $event[2] ?? null,
                'payload' => ['demo' => true],
                'acknowledged_at' => $arrival,
                'created_at' => $arrival,
                'updated_at' => $arrival,
            ]);

            ProcessIfoodEventJob::dispatchSync($stored->id);
        }

        $items = collect(self::MENU)->shuffle()->take(random_int(1, 3))->values()->map(fn (array $item, int $i): array => [
            'index' => $i + 1,
            'name' => $item[0],
            'quantity' => $quantity = random_int(1, 2),
            'unitPrice' => $item[1],
            'totalPrice' => round($item[1] * $quantity, 2),
            'observations' => $i === 0 && random_int(0, 2) === 0 ? 'Sem cebola' : '',
            'options' => [],
        ]);
        $subTotal = round($items->sum(fn (array $item): float => $item['totalPrice']), 2);
        $fee = $type === 'DELIVERY' ? 6.0 : 0.0;

        Order::query()->where('ifood_order_id', $orderId)->update([
            'display_id' => (string) random_int(1000, 9999),
            'order_type' => $type,
            'category' => 'FOOD',
            'customer_name' => $customer,
            'total_amount' => $subTotal + $fee,
            'is_test' => false,
            'details_fetched_at' => now(),
            'details' => json_encode([
                'items' => $items->all(),
                'total' => ['subTotal' => $subTotal, 'deliveryFee' => $fee, 'benefits' => 0, 'additionalFees' => 0, 'orderAmount' => $subTotal + $fee],
                'payments' => ['methods' => [['method' => 'CREDIT', 'type' => 'ONLINE', 'value' => $subTotal + $fee, 'prepaid' => true]]],
                'delivery' => $type === 'DELIVERY' ? ['deliveredBy' => 'MERCHANT'] : null,
                'demo' => true,
            ]),
        ]);
    }
}
