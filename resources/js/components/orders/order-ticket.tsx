import { DeadlineBar } from '@/components/orders/deadline-bar';
import { formatElapsed, formatMoney, ORDER_TYPE_LABEL, orderLabel, STATUS_SINGULAR } from '@/lib/orders';
import { cn } from '@/lib/utils';
import type { OrderSummary } from '@/types/orders';

interface Props {
    order: OrderSummary;
    now: number;
    selected: boolean;
    onOpen: (order: OrderSummary) => void;
    showStatus?: boolean;
}

/** A kitchen ticket: the order number is what people look for first. */
export function OrderTicket({ order, now, selected, onOpen, showStatus = false }: Props) {
    const closed = order.status === 'CONCLUDED' || order.status === 'CANCELLED';
    const since = order.placed_at ?? order.first_event_at;
    const waitingConfirmation = order.status === 'PLACED' && order.confirm_deadline_at;

    return (
        <button
            type="button"
            onClick={() => onOpen(order)}
            aria-pressed={selected}
            className={cn(
                'group w-full rounded-md border bg-card p-3 text-left transition-colors',
                'focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                'hover:border-ink/30',
                selected ? 'border-basil ring-1 ring-basil' : 'border-border',
                closed && 'bg-card/60',
            )}
        >
            <div className="flex items-baseline justify-between gap-2">
                <span className={cn('tabular text-xl leading-none font-bold', closed && 'text-muted-foreground')}>
                    {orderLabel(order)}
                </span>
                <span className="tabular text-xs text-muted-foreground" title="Tempo desde o pedido">
                    {formatElapsed(since, now)}
                </span>
            </div>

            <p className="mt-2 truncate text-sm">
                {order.customer_name ?? (order.details_state === 'pending' ? 'Carregando detalhes…' : 'Cliente não informado')}
            </p>

            <div className="mt-1 flex items-center justify-between gap-2 text-sm text-muted-foreground">
                <span>{order.order_type ? (ORDER_TYPE_LABEL[order.order_type] ?? order.order_type) : '—'}</span>
                <span className="tabular font-semibold text-foreground">{formatMoney(order.total_amount)}</span>
            </div>

            {waitingConfirmation && (
                <div className="mt-3">
                    <DeadlineBar deadline={order.confirm_deadline_at!} now={now} />
                </div>
            )}

            {(showStatus || order.open_action || order.cancellation_requested_at || order.is_test || order.details_state === 'failed') && (
                <div className="mt-3 flex flex-wrap gap-1.5 text-xs">
                    {showStatus && (
                        <span
                            className={cn(
                                'rounded px-1.5 py-0.5 font-semibold',
                                order.status === 'CANCELLED' ? 'bg-chili/10 text-chili' : 'bg-basil/10 text-basil',
                            )}
                        >
                            {STATUS_SINGULAR[order.status]}
                        </span>
                    )}
                    {order.open_action && (
                        <span className="rounded bg-basil/10 px-1.5 py-0.5 font-semibold text-basil">{order.open_action.label}: aguardando iFood</span>
                    )}
                    {order.cancellation_requested_at && order.status !== 'CANCELLED' && (
                        <span className="rounded bg-chili/10 px-1.5 py-0.5 font-semibold text-chili">Cancelamento solicitado</span>
                    )}
                    {order.details_state === 'failed' && (
                        <span className="rounded bg-saffron/15 px-1.5 py-0.5 text-saffron">Detalhes indisponíveis</span>
                    )}
                    {order.is_test && <span className="rounded bg-muted px-1.5 py-0.5 text-muted-foreground">Teste</span>}
                </div>
            )}
        </button>
    );
}
