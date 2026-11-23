import type { ReactNode } from 'react';

import { DeadlineBar } from '@/components/orders/deadline-bar';
import { OrderActions } from '@/components/orders/order-actions';
import { Separator } from '@/components/ui/separator';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import {
    CANCEL_ORIGIN_LABEL,
    EVENT_LABEL,
    EVENT_ORIGIN_LABEL,
    formatDateTime,
    formatMoney,
    formatTimeWithSeconds,
    IGNORE_REASON_LABEL,
    ORDER_TYPE_LABEL,
    orderLabel,
    PAYMENT_METHOD_LABEL,
    STATUS_SINGULAR,
} from '@/lib/orders';
import { cn } from '@/lib/utils';
import type { OrderDetail, TimelineEntry } from '@/types/orders';

interface Props {
    order: OrderDetail | null;
    open: boolean;
    now: number;
    onClose: () => void;
    actions?: ReactNode;
}

export function OrderSheet({ order, open, now, onClose, actions }: Props) {
    return (
        <Sheet open={open} onOpenChange={(value) => !value && onClose()}>
            <SheetContent className="w-full overflow-y-auto data-[side=right]:w-full sm:data-[side=right]:max-w-lg">
                {order ? <OrderDetailBody order={order} now={now} actions={actions} /> : <SheetTitle className="p-6">Carregando pedido…</SheetTitle>}
            </SheetContent>
        </Sheet>
    );
}

function OrderDetailBody({ order, now, actions }: { order: OrderDetail; now: number; actions?: ReactNode }) {
    const type = order.order_type ? (ORDER_TYPE_LABEL[order.order_type] ?? order.order_type) : null;

    return (
        <>
            <SheetHeader className="gap-1 border-b border-border">
                <SheetTitle className="flex items-baseline gap-3 text-2xl">
                    <span className="tabular">{orderLabel(order)}</span>
                    <span className={cn('text-base font-semibold', order.status === 'CANCELLED' ? 'text-chili' : 'text-basil')}>
                        {STATUS_SINGULAR[order.status]}
                    </span>
                </SheetTitle>
                <SheetDescription>
                    {[order.customer_name, type, order.placed_at && `recebido ${formatDateTime(order.placed_at)}`].filter(Boolean).join(', ')}
                </SheetDescription>
                {order.is_test && <p className="text-xs text-muted-foreground">Pedido de teste gerado no portal do desenvolvedor</p>}
            </SheetHeader>

            <div className="space-y-6 px-4 pb-8">
                {order.status === 'PLACED' && order.confirm_deadline_at && <DeadlineBar deadline={order.confirm_deadline_at} now={now} />}

                {order.status === 'CANCELLED' && (
                    <p className="rounded-md bg-chili/8 p-3 text-sm text-chili">
                        Cancelado{order.cancellation_origin ? ` ${CANCEL_ORIGIN_LABEL[order.cancellation_origin] ?? order.cancellation_origin}` : ''}.
                        {order.cancellation_reason ? ` Motivo: ${order.cancellation_reason}.` : ''}
                    </p>
                )}

                {actions}
                <OrderActions order={order} />

                <section aria-labelledby="items-title">
                    <h3 id="items-title" className="mb-2 font-semibold">
                        Itens
                    </h3>
                    {order.details_state === 'pending' && <p className="text-sm text-muted-foreground">Buscando os detalhes no iFood…</p>}
                    {order.details_state === 'failed' && (
                        <p className="text-sm text-saffron">O iFood não devolveu os detalhes deste pedido ({order.details_error}).</p>
                    )}
                    {order.items.length > 0 && (
                        <ul className="divide-y divide-border">
                            {order.items.map((item, index) => (
                                <li key={index} className="flex justify-between gap-4 py-2 text-sm">
                                    <div>
                                        <p>
                                            <span className="tabular font-semibold">{item.quantity ?? 1}×</span> {item.name}
                                        </p>
                                        {item.options.map((option, i) => (
                                            <p key={i} className="text-muted-foreground">
                                                {option.quantity && option.quantity > 1 ? `${option.quantity}× ` : ''}
                                                {option.name}
                                            </p>
                                        ))}
                                        {item.observations && <p className="text-saffron">Obs.: {item.observations}</p>}
                                    </div>
                                    <span className="tabular shrink-0">{formatMoney(item.total_price)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {order.details_state === 'ready' && (
                        <dl className="mt-2 space-y-1 border-t border-border pt-2 text-sm">
                            <Row label="Subtotal" value={formatMoney(order.totals.sub_total)} />
                            {!!order.totals.delivery_fee && <Row label="Entrega" value={formatMoney(order.totals.delivery_fee)} />}
                            {!!order.totals.benefits && <Row label="Descontos" value={`−${formatMoney(order.totals.benefits)}`} />}
                            {!!order.totals.additional_fees && <Row label="Taxas" value={formatMoney(order.totals.additional_fees)} />}
                            <Row label="Total" value={formatMoney(order.totals.order_amount)} strong />
                            {order.payments.map((p, i) => (
                                <Row
                                    key={i}
                                    label={`Pagamento ${p.prepaid ? 'online' : 'na entrega'}`}
                                    value={`${p.method ? (PAYMENT_METHOD_LABEL[p.method] ?? p.method) : ''} ${formatMoney(p.value)}`.trim()}
                                    muted
                                />
                            ))}
                        </dl>
                    )}
                </section>

                <Separator />

                <section aria-labelledby="timeline-title">
                    <h3 id="timeline-title" className="font-semibold">
                        Histórico de eventos
                    </h3>
                    <p className="mb-3 text-sm text-muted-foreground">
                        Na ordem em que aconteceram no iFood. Eventos que chegaram atrasados ficam registrados, mas não mudam o pedido.
                    </p>
                    <ol className="relative space-y-4 border-l border-border pl-4">
                        {order.timeline.map((entry) => (
                            <TimelineItem key={entry.id} entry={entry} />
                        ))}
                    </ol>
                </section>

                <p className="text-xs break-all text-muted-foreground">ID no iFood: {order.ifood_order_id}</p>
            </div>
        </>
    );
}

function TimelineItem({ entry }: { entry: TimelineEntry }) {
    const ignored = entry.status === 'ignored';
    const failed = entry.status === 'failed';
    const pending = entry.status === 'received';
    const delayMs = new Date(entry.received_at).getTime() - new Date(entry.occurred_at).getTime();

    return (
        <li className="relative">
            <span
                aria-hidden
                className={cn(
                    'absolute top-1.5 -left-[21px] size-2.5 rounded-full border-2 border-card',
                    entry.applied ? 'bg-basil' : failed ? 'bg-chili' : pending ? 'bg-saffron' : 'bg-muted-foreground/40',
                )}
            />
            <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                <p className={cn('font-semibold', ignored && 'text-muted-foreground')}>
                    {EVENT_LABEL[entry.code] ?? entry.full_code}
                    <span className="ml-2 text-xs font-normal text-muted-foreground">{entry.code}</span>
                </p>
                <time className="tabular text-xs text-muted-foreground" dateTime={entry.occurred_at}>
                    {formatTimeWithSeconds(entry.occurred_at)}
                </time>
            </div>
            {ignored && entry.ignore_reason && (
                <p className="text-sm text-muted-foreground">{IGNORE_REASON_LABEL[entry.ignore_reason] ?? entry.ignore_reason}</p>
            )}
            {entry.applied === false && !ignored && entry.code.startsWith('CAR') && (
                <p className="text-sm text-muted-foreground">Registrado sem mudar a etapa do pedido</p>
            )}
            {failed && <p className="text-sm text-chili">Falhou após {entry.attempts} tentativas. O sistema tenta de novo automaticamente.</p>}
            {pending && <p className="text-sm text-saffron">Na fila para processar</p>}
            {entry.origin && <p className="text-xs text-muted-foreground">Origem: {EVENT_ORIGIN_LABEL[entry.origin] ?? entry.origin}</p>}
            {delayMs > 60_000 && (
                <p className="text-xs text-muted-foreground">Recebido {Math.round(delayMs / 60_000)} min depois de acontecer</p>
            )}
        </li>
    );
}

function Row({ label, value, strong, muted }: { label: string; value: string; strong?: boolean; muted?: boolean }) {
    return (
        <div className={cn('flex justify-between', strong && 'font-semibold', muted && 'text-muted-foreground')}>
            <dt>{label}</dt>
            <dd className="tabular">{value}</dd>
        </div>
    );
}
