import type { OrderStatus } from '@/types/orders';

export const ACTIVE_STATUSES: OrderStatus[] = ['PLACED', 'CONFIRMED', 'PREPARATION_STARTED', 'READY_TO_PICKUP', 'DISPATCHED'];
export const CLOSED_STATUSES: OrderStatus[] = ['CONCLUDED', 'CANCELLED'];
export const ALL_STATUSES: OrderStatus[] = [...ACTIVE_STATUSES, ...CLOSED_STATUSES];

export const STATUS_LABEL: Record<OrderStatus, string> = {
    PLACED: 'Novos',
    CONFIRMED: 'Confirmados',
    PREPARATION_STARTED: 'Em preparo',
    READY_TO_PICKUP: 'Prontos',
    DISPATCHED: 'Despachados',
    CONCLUDED: 'Concluídos',
    CANCELLED: 'Cancelados',
};

/** Singular, for a single order ("Pedido confirmado"). */
export const STATUS_SINGULAR: Record<OrderStatus, string> = {
    PLACED: 'Novo',
    CONFIRMED: 'Confirmado',
    PREPARATION_STARTED: 'Em preparo',
    READY_TO_PICKUP: 'Pronto',
    DISPATCHED: 'Despachado',
    CONCLUDED: 'Concluído',
    CANCELLED: 'Cancelado',
};

export const ORDER_TYPE_LABEL: Record<string, string> = {
    DELIVERY: 'Entrega',
    TAKEOUT: 'Retirada',
    DINE_IN: 'Na loja',
    INDOOR: 'Na loja',
};

export const EVENT_LABEL: Record<string, string> = {
    PLC: 'Pedido recebido',
    CFM: 'Confirmado',
    PRS: 'Preparo iniciado',
    RTP: 'Pronto para retirada',
    DSP: 'Despachado',
    CON: 'Concluído',
    CAN: 'Cancelado',
    CAR: 'Cancelamento solicitado',
    CARF: 'Cancelamento recusado',
    DDCR: 'Código de entrega solicitado',
};

/** Why an event did not change the order, in the operator's words. */
export const IGNORE_REASON_LABEL: Record<string, string> = {
    order_terminal: 'Chegou depois do pedido ser encerrado e não reabre o pedido',
    not_forward: 'Chegou fora de ordem: o pedido já estava em uma etapa posterior',
    unknown_code: 'Evento que o sistema não trata, registrado e confirmado ao iFood',
};

/** Completes "Cancelado …". */
export const CANCEL_ORIGIN_LABEL: Record<string, string> = {
    SCHEDULER: 'automaticamente pelo iFood',
    RESTAURANT: 'pela loja',
    CUSTOMER: 'pelo cliente',
    IFOOD: 'pelo iFood',
};

/** Who triggered an event, from its metadata (ORIGIN / CANCEL_ORIGIN). */
export const EVENT_ORIGIN_LABEL: Record<string, string> = {
    ORDER_API: 'este sistema',
    SYSTEM: 'iFood, automaticamente',
    SCHEDULER: 'iFood, prazo de confirmação',
    RESTAURANT: 'loja',
    CUSTOMER: 'cliente',
};

export const PAYMENT_METHOD_LABEL: Record<string, string> = {
    CREDIT: 'Crédito',
    DEBIT: 'Débito',
    PIX: 'Pix',
    CASH: 'Dinheiro',
    MEAL_VOUCHER: 'Vale-refeição',
    FOOD_VOUCHER: 'Vale-alimentação',
    DIGITAL_WALLET: 'Carteira digital',
};

const currency = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
const time = new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' });
const timeWithSeconds = new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
const dateTime = new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });

export const formatMoney = (value: number | null) => (value === null ? '—' : currency.format(value));
export const formatTime = (iso: string | null) => (iso ? time.format(new Date(iso)) : '—');
export const formatTimeWithSeconds = (iso: string) => {
    const date = new Date(iso);
    return `${timeWithSeconds.format(date)}.${String(date.getMilliseconds()).padStart(3, '0')}`;
};
export const formatDateTime = (iso: string | null) => (iso ? dateTime.format(new Date(iso)) : '—');

/** "4 min", "1 h 12 min" — elapsed time on a ticket. */
export function formatElapsed(fromIso: string, now: number): string {
    const minutes = Math.max(0, Math.floor((now - new Date(fromIso).getTime()) / 60_000));
    if (minutes < 60) return `${minutes} min`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours} h ${minutes % 60} min`;
    return `${Math.floor(hours / 24)} d`;
}

/** "3:07" — countdown to the confirmation deadline. */
export function formatCountdown(ms: number): string {
    const total = Math.max(0, Math.ceil(ms / 1000));
    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}

export const orderLabel = (order: { display_id: string | null; ifood_order_id: string }) =>
    order.display_id ? `#${order.display_id}` : order.ifood_order_id.slice(0, 8);
