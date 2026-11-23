import { Head, router, usePage, usePoll } from '@inertiajs/react';
import { LayoutGrid, List, Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { HealthIndicator } from '@/components/orders/health-indicator';
import { OrderBoard } from '@/components/orders/order-board';
import { OrderList } from '@/components/orders/order-list';
import { OrderSheet } from '@/components/orders/order-sheet';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useNow } from '@/hooks/use-now';
import { formatCountdown, orderLabel } from '@/lib/orders';
import type { OrderStatus, OrdersPageProps, OrderSummary } from '@/types/orders';

type Query = { view?: string; q?: string; status?: string; order?: number; page?: number };

export default function OrdersIndex() {
    const { props } = usePage<OrdersPageProps>();
    const { view, filters, orders, counts, health, selected, server_time, flash } = props;
    const now = useNow(server_time);

    const params = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search);
    const selectedId = Number(params.get('order')) || null;
    const [search, setSearch] = useState(filters.q);

    // Live board: refresh data (and the open order) without touching the URL.
    usePoll(5000, { only: ['orders', 'counts', 'health', 'selected', 'server_time'] });

    const visit = (changes: Query, only?: string[]) => {
        const query: Query = {
            view: view === 'list' ? 'list' : undefined,
            q: filters.q || undefined,
            status: filters.status ?? undefined,
            order: selectedId ?? undefined,
            ...changes,
        };
        const clean = Object.fromEntries(Object.entries(query).filter(([, v]) => v !== undefined && v !== '' && v !== null));
        router.get('/', clean, { preserveState: true, preserveScroll: true, replace: true, only });
    };

    useEffect(() => {
        if (flash.success) toast.success(flash.success);
        if (flash.error) toast.error(flash.error);
    }, [flash]);

    // Debounced search.
    useEffect(() => {
        if (search === filters.q) return;
        const id = window.setTimeout(() => visit({ q: search || undefined, page: undefined }, ['orders', 'filters']), 300);
        return () => window.clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const open = (order: OrderSummary) => visit({ order: order.id }, ['selected']);
    const close = () => visit({ order: undefined }, ['selected']);

    const waiting = useMemo(
        () =>
            view === 'board'
                ? orders.data
                      .filter((o) => o.status === 'PLACED' && o.confirm_deadline_at)
                      .sort((a, b) => a.confirm_deadline_at!.localeCompare(b.confirm_deadline_at!))
                : [],
        [orders.data, view],
    );

    return (
        <>
            <Head title="Pedidos" />

            <div className="min-h-screen">
                <header className="border-b border-border bg-card">
                    <div className="mx-auto flex max-w-[1600px] flex-wrap items-center gap-x-6 gap-y-3 px-4 py-4 sm:px-6">
                        <h1 className="text-2xl font-bold">Pedidos</h1>

                        <HealthIndicator health={health} now={now} />

                        <div className="ml-auto flex w-full flex-wrap items-center gap-3 sm:w-auto">
                            <label className="relative w-full sm:w-64">
                                <span className="sr-only">Buscar pedido</span>
                                <Search aria-hidden className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    type="search"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Número do pedido ou cliente"
                                    className="bg-paper pl-8"
                                />
                            </label>

                            <ToggleGroup
                                type="single"
                                variant="outline"
                                value={view}
                                onValueChange={(value) => value && visit({ view: value === 'list' ? 'list' : undefined, status: undefined, page: undefined })}
                                aria-label="Visualização"
                            >
                                <ToggleGroupItem value="board" aria-label="Quadro">
                                    <LayoutGrid /> Quadro
                                </ToggleGroupItem>
                                <ToggleGroupItem value="list" aria-label="Lista">
                                    <List /> Lista
                                </ToggleGroupItem>
                            </ToggleGroup>
                        </div>
                    </div>
                </header>

                <main className="mx-auto max-w-[1600px] px-4 py-6 sm:px-6">
                    {waiting.length > 0 && <AttentionStrip waiting={waiting} now={now} onOpen={open} />}

                    {view === 'board' ? (
                        orders.data.length === 0 && !filters.q ? (
                            <EmptyBoard />
                        ) : (
                            <OrderBoard orders={orders.data} now={now} selectedId={selectedId} onOpen={open} />
                        )
                    ) : (
                        <OrderList
                            orders={orders}
                            counts={counts}
                            status={filters.status}
                            selectedId={selectedId}
                            onOpen={open}
                            onStatus={(status: OrderStatus | null) => visit({ status: status ?? undefined, page: undefined })}
                            pageUrl={(page) => {
                                const next = new URLSearchParams(window.location.search);
                                next.set('page', String(page));
                                return `/?${next.toString()}`;
                            }}
                        />
                    )}
                </main>
            </div>

            <OrderSheet order={selectedId ? selected : null} open={selectedId !== null} now={now} onClose={close} />
        </>
    );
}

/** Only shown when something needs a decision before iFood cancels it. */
function AttentionStrip({ waiting, now, onOpen }: { waiting: OrderSummary[]; now: number; onOpen: (o: OrderSummary) => void }) {
    const first = waiting[0];
    const remaining = new Date(first.confirm_deadline_at!).getTime() - now;
    const expired = remaining <= 0;

    return (
        <div role="status" className="mb-6 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-md border border-saffron/40 bg-saffron/10 px-4 py-3">
            <p className="font-semibold">
                {waiting.length === 1 ? '1 pedido aguardando confirmação' : `${waiting.length} pedidos aguardando confirmação`}
            </p>
            <p className="tabular text-sm">
                {expired
                    ? `O prazo de ${orderLabel(first)} acabou: o iFood vai cancelá-lo`
                    : `${orderLabel(first)} é cancelado pelo iFood em ${formatCountdown(remaining)} se não for confirmado`}
            </p>
            <button
                type="button"
                onClick={() => onOpen(first)}
                className="ml-auto rounded-md bg-ink px-3 py-1.5 text-sm font-semibold text-white focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                Abrir {orderLabel(first)}
            </button>
        </div>
    );
}

function EmptyBoard() {
    return (
        <div className="mx-auto max-w-xl rounded-md border border-dashed border-border bg-card p-8">
            <h2 className="text-lg font-semibold">Nenhum pedido ainda</h2>
            <p className="mt-2 text-muted-foreground">
                Gere pedidos de teste no portal do desenvolvedor iFood. Eles aparecem aqui em até 30 segundos. Sem credenciais, rode{' '}
                <code className="rounded bg-muted px-1">make demo</code> para criar pedidos de exemplo.
            </p>
        </div>
    );
}
