import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatTime } from '@/lib/orders';
import { cn } from '@/lib/utils';
import type { ActionType, OrderDetail } from '@/types/orders';

const ACTION_STATUS_LABEL = {
    pending: 'enviando ao iFood',
    sent: 'aguardando confirmação do iFood',
    confirmed: 'confirmado pelo iFood',
    failed: 'falhou',
    expired: 'sem resposta do iFood',
} as const;

type Reason = { code: string; description: string };

/**
 * Merchant actions. iFood answers 202 and confirms later with an event, so
 * after a click the action shows as waiting until the board receives it.
 */
export function OrderActions({ order }: { order: OrderDetail }) {
    const [submitting, setSubmitting] = useState<ActionType | null>(null);
    const [cancelOpen, setCancelOpen] = useState(false);
    const open = order.actions.find((a) => a.status === 'pending' || a.status === 'sent');
    const lastClosed = order.actions.find((a) => a.status === 'failed' || a.status === 'expired');
    const forward = order.available_actions.filter((a) => a.action !== 'request_cancellation');
    const canCancel = order.available_actions.some((a) => a.action === 'request_cancellation');

    const send = (action: ActionType, extra: Record<string, string> = {}) => {
        setSubmitting(action);
        router.post(`/orders/${order.id}/actions`, { action, ...extra }, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setSubmitting(null),
        });
    };

    if (!open && forward.length === 0 && !canCancel && order.actions.length === 0) return null;

    return (
        <section aria-labelledby="actions-title" className="space-y-3">
            <h3 id="actions-title" className="sr-only">
                Ações
            </h3>

            {open ? (
                <div role="status" className="rounded-md border border-basil/30 bg-basil/8 p-3 text-sm">
                    <p className="font-semibold">
                        {open.label}: {ACTION_STATUS_LABEL[open.status]}
                    </p>
                    <p className="text-muted-foreground">
                        O iFood responde por evento, normalmente em menos de 30 segundos. Sem resposta em 2 minutos, a ação é marcada como não confirmada.
                    </p>
                </div>
            ) : (
                <div className="flex flex-wrap gap-2">
                    {forward.map((a, i) => (
                        <Button
                            key={a.action}
                            variant={i === 0 ? 'default' : 'outline'}
                            disabled={submitting !== null}
                            onClick={() => send(a.action)}
                        >
                            {submitting === a.action ? 'Enviando…' : a.label}
                        </Button>
                    ))}
                    {canCancel && (
                        <Button variant="ghost" className="text-chili hover:bg-chili/10 hover:text-chili" disabled={submitting !== null} onClick={() => setCancelOpen(true)}>
                            Cancelar pedido
                        </Button>
                    )}
                </div>
            )}

            {!open && lastClosed && lastClosed === order.actions[0] && (
                <p className={cn('rounded-md p-3 text-sm', lastClosed.status === 'failed' ? 'bg-chili/8 text-chili' : 'bg-saffron/10 text-saffron')}>
                    {lastClosed.label} {ACTION_STATUS_LABEL[lastClosed.status]} às {formatTime(lastClosed.resolved_at)}
                    {lastClosed.error ? `: ${lastClosed.error.replace(/\.+$/, '')}` : ''}. Você pode tentar de novo.
                </p>
            )}

            {canCancel && (
                <CancelDialog
                    orderId={order.id}
                    open={cancelOpen}
                    onOpenChange={setCancelOpen}
                    onConfirm={(reason) => {
                        setCancelOpen(false);
                        send('request_cancellation', { cancellation_code: reason.code, reason: reason.description });
                    }}
                />
            )}
        </section>
    );
}

function CancelDialog({
    orderId,
    open,
    onOpenChange,
    onConfirm,
}: {
    orderId: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirm: (reason: Reason) => void;
}) {
    const [reasons, setReasons] = useState<Reason[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [code, setCode] = useState('');

    const load = async () => {
        setReasons(null);
        setError(null);
        setCode('');
        const response = await fetch(`/orders/${orderId}/cancellation-reasons`, { headers: { Accept: 'application/json' } });
        const body = (await response.json()) as { reasons: Reason[]; message?: string };
        setReasons(body.reasons);
        if (!response.ok) setError(body.message ?? 'Não foi possível carregar os motivos.');
    };

    const selected = reasons?.find((r) => r.code === code);

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
        >
            <DialogContent onOpenAutoFocus={() => void load()}>
                <DialogHeader>
                    <DialogTitle>Cancelar pedido</DialogTitle>
                    <DialogDescription>O cliente é avisado pelo iFood. Os motivos disponíveis dependem do tipo de pedido.</DialogDescription>
                </DialogHeader>

                {error ? (
                    <p className="text-sm text-chili">{error}</p>
                ) : (
                    <div className="space-y-2">
                        <Label htmlFor="cancel-reason">Motivo</Label>
                        <Select value={code} onValueChange={setCode} disabled={!reasons}>
                            <SelectTrigger id="cancel-reason" className="w-full">
                                <SelectValue placeholder={reasons ? 'Escolha um motivo' : 'Carregando motivos…'} />
                            </SelectTrigger>
                            <SelectContent>
                                {reasons?.map((r) => (
                                    <SelectItem key={r.code} value={r.code}>
                                        {r.description}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                )}

                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>
                        Manter pedido
                    </Button>
                    <Button variant="destructive" disabled={!selected} onClick={() => selected && onConfirm(selected)}>
                        Cancelar pedido
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
