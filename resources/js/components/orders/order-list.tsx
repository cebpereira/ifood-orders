import { Link } from '@inertiajs/react';

import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ALL_STATUSES, formatDateTime, formatMoney, ORDER_TYPE_LABEL, orderLabel, STATUS_LABEL, STATUS_SINGULAR } from '@/lib/orders';
import { cn } from '@/lib/utils';
import type { OrderStatus, OrderSummary, OrdersPageProps } from '@/types/orders';

interface Props {
    orders: OrdersPageProps['orders'];
    counts: OrdersPageProps['counts'];
    status: OrderStatus | null;
    selectedId: number | null;
    onOpen: (order: OrderSummary) => void;
    onStatus: (status: OrderStatus | null) => void;
    pageUrl: (page: number) => string;
}

export function OrderList({ orders, counts, status, selectedId, onOpen, onStatus, pageUrl }: Props) {
    const total = Object.values(counts).reduce((sum, n) => sum + (n ?? 0), 0);
    const meta = orders.meta;

    return (
        <div className="space-y-4">
            <nav aria-label="Filtrar por etapa" className="flex flex-wrap gap-1.5">
                {[null, ...ALL_STATUSES].map((s) => {
                    const active = s === status;
                    return (
                        <button
                            key={s ?? 'all'}
                            type="button"
                            onClick={() => onStatus(s)}
                            aria-pressed={active}
                            className={cn(
                                'rounded-md border px-2.5 py-1 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                active ? 'border-basil bg-basil text-white' : 'border-border bg-card hover:border-ink/30',
                            )}
                        >
                            {s ? STATUS_LABEL[s] : 'Todos'}
                            <span className={cn('tabular ml-1.5', active ? 'text-white/80' : 'text-muted-foreground')}>
                                {s ? (counts[s] ?? 0) : total}
                            </span>
                        </button>
                    );
                })}
            </nav>

            <div className="overflow-x-auto rounded-md border border-border bg-card">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Pedido</TableHead>
                            <TableHead>Cliente</TableHead>
                            <TableHead>Tipo</TableHead>
                            <TableHead>Etapa</TableHead>
                            <TableHead>Recebido</TableHead>
                            <TableHead className="text-right">Total</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {orders.data.map((order) => (
                            <TableRow
                                key={order.id}
                                data-state={order.id === selectedId ? 'selected' : undefined}
                                className="cursor-pointer"
                                onClick={() => onOpen(order)}
                            >
                                <TableCell className="tabular font-bold">
                                    <button
                                        type="button"
                                        className="rounded focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            onOpen(order);
                                        }}
                                    >
                                        {orderLabel(order)}
                                    </button>
                                </TableCell>
                                <TableCell className="max-w-48 truncate">{order.customer_name ?? '—'}</TableCell>
                                <TableCell>{order.order_type ? (ORDER_TYPE_LABEL[order.order_type] ?? order.order_type) : '—'}</TableCell>
                                <TableCell>
                                    <span className={cn(order.status === 'CANCELLED' && 'text-chili', order.status === 'PLACED' && 'font-semibold')}>
                                        {STATUS_SINGULAR[order.status]}
                                    </span>
                                    {order.cancellation_requested_at && order.status !== 'CANCELLED' && (
                                        <span className="ml-2 text-xs text-chili">cancelamento solicitado</span>
                                    )}
                                </TableCell>
                                <TableCell className="tabular">{formatDateTime(order.placed_at ?? order.first_event_at)}</TableCell>
                                <TableCell className="tabular text-right">{formatMoney(order.total_amount)}</TableCell>
                            </TableRow>
                        ))}
                        {orders.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={6} className="py-10 text-center text-muted-foreground">
                                    Nenhum pedido nesta etapa. Troque o filtro ou limpe a busca.
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </div>

            {meta && meta.last_page > 1 && (
                <nav aria-label="Páginas" className="flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">
                        Página {meta.current_page} de {meta.last_page}, {meta.total} pedidos
                    </span>
                    <div className="flex gap-2">
                        {meta.current_page > 1 && (
                            <Link href={pageUrl(meta.current_page - 1)} preserveState className="rounded-md border border-border bg-card px-3 py-1 hover:border-ink/30">
                                Anterior
                            </Link>
                        )}
                        {meta.current_page < meta.last_page && (
                            <Link href={pageUrl(meta.current_page + 1)} preserveState className="rounded-md border border-border bg-card px-3 py-1 hover:border-ink/30">
                                Próxima
                            </Link>
                        )}
                    </div>
                </nav>
            )}
        </div>
    );
}
