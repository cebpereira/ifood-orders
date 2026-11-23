import { OrderTicket } from '@/components/orders/order-ticket';
import { ACTIVE_STATUSES, STATUS_LABEL } from '@/lib/orders';
import type { OrderStatus, OrderSummary } from '@/types/orders';

const EMPTY_HINT: Partial<Record<OrderStatus, string>> = {
    PLACED: 'Pedidos novos aparecem aqui em até 30 segundos.',
};

interface Props {
    orders: OrderSummary[];
    now: number;
    selectedId: number | null;
    onOpen: (order: OrderSummary) => void;
}

export function OrderBoard({ orders, now, selectedId, onOpen }: Props) {
    const byStatus = (status: OrderStatus) => orders.filter((o) => o.status === status);
    const closed = orders
        .filter((o) => o.status === 'CONCLUDED' || o.status === 'CANCELLED')
        .sort((a, b) => b.status_changed_at.localeCompare(a.status_changed_at));

    const columns: { key: string; title: string; orders: OrderSummary[]; hint?: string; showStatus?: boolean }[] = [
        ...ACTIVE_STATUSES.map((status) => ({
            key: status,
            title: STATUS_LABEL[status],
            orders: byStatus(status),
            hint: EMPTY_HINT[status],
        })),
        { key: 'closed', title: 'Encerrados nas últimas 24 h', orders: closed, showStatus: true },
    ];

    return (
        <div className="-mx-4 flex snap-x snap-mandatory scroll-px-4 gap-3 overflow-x-auto px-4 pb-4 sm:-mx-6 sm:scroll-px-6 sm:px-6 lg:mx-0 lg:grid lg:snap-none lg:grid-cols-6 lg:overflow-visible lg:px-0">
            {columns.map((column) => (
                <section
                    key={column.key}
                    aria-labelledby={`column-${column.key}`}
                    className="w-[78vw] max-w-72 shrink-0 snap-start sm:w-64 lg:w-auto lg:max-w-none"
                >
                    <header className="mb-2 flex items-baseline justify-between border-b border-border pb-2">
                        <h2 id={`column-${column.key}`} className="text-sm font-semibold">
                            {column.title}
                        </h2>
                        <span className="tabular text-sm text-muted-foreground">{column.orders.length}</span>
                    </header>

                    <div className="space-y-2">
                        {column.orders.map((order) => (
                            <OrderTicket
                                key={order.id}
                                order={order}
                                now={now}
                                selected={order.id === selectedId}
                                onOpen={onOpen}
                                showStatus={column.showStatus}
                            />
                        ))}
                        {column.orders.length === 0 && column.hint && (
                            <p className="rounded-md border border-dashed border-border p-3 text-sm text-muted-foreground">{column.hint}</p>
                        )}
                    </div>
                </section>
            ))}
        </div>
    );
}
