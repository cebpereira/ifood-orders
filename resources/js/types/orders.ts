export type OrderStatus =
    | 'PLACED'
    | 'CONFIRMED'
    | 'PREPARATION_STARTED'
    | 'READY_TO_PICKUP'
    | 'DISPATCHED'
    | 'CONCLUDED'
    | 'CANCELLED';

export interface OrderSummary {
    id: number;
    ifood_order_id: string;
    display_id: string | null;
    status: OrderStatus;
    order_type: string | null;
    sales_channel: string | null;
    customer_name: string | null;
    total_amount: number | null;
    is_test: boolean | null;
    placed_at: string | null;
    first_event_at: string;
    status_changed_at: string;
    confirm_deadline_at: string | null;
    cancellation_requested_at: string | null;
    cancellation_reason: string | null;
    cancellation_origin: string | null;
    details_state: 'ready' | 'pending' | 'failed';
    open_action?: { action: ActionType; label: string; status: 'pending' | 'sent' } | null;
}

export type ActionType = 'confirm' | 'start_preparation' | 'ready_to_pickup' | 'dispatch' | 'request_cancellation';

export interface OrderActionEntry {
    id: number;
    action: ActionType;
    label: string;
    status: 'pending' | 'sent' | 'confirmed' | 'failed' | 'expired';
    error: string | null;
    cancellation_code: string | null;
    requested_at: string;
    resolved_at: string | null;
}

export interface TimelineEntry {
    id: number;
    code: string;
    full_code: string;
    occurred_at: string;
    received_at: string;
    status: 'received' | 'processed' | 'ignored' | 'failed';
    ignore_reason: string | null;
    attempts: number;
    last_error: string | null;
    from_status: OrderStatus | null;
    to_status: OrderStatus | null;
    applied: boolean | null;
    origin: string | null;
}

export interface OrderDetail extends OrderSummary {
    category: string | null;
    details_error: string | null;
    items: {
        name: string;
        quantity: number | null;
        total_price: number | null;
        observations: string | null;
        options: { name: string; quantity: number | null }[];
    }[];
    totals: {
        sub_total: number | null;
        delivery_fee: number | null;
        benefits: number | null;
        additional_fees: number | null;
        order_amount: number | null;
    };
    payments: { method: string | null; type: string | null; value: number | null; prepaid: boolean }[];
    delivered_by: string | null;
    available_actions: { action: ActionType; label: string }[];
    actions: OrderActionEntry[];
    timeline: TimelineEntry[];
}

export interface Health {
    polling: { at: string; state: 'ok' | 'ack_failed' | 'rate_limited' | 'error'; received: number; message: string | null } | null;
    events_waiting: number;
    events_failed: number;
}

export interface OrdersPageProps {
    view: 'board' | 'list';
    filters: { q: string; status: OrderStatus | null };
    orders: {
        data: OrderSummary[];
        meta?: { current_page: number; last_page: number; total: number };
    };
    counts: Partial<Record<OrderStatus, number>>;
    health: Health;
    selected: OrderDetail | null;
    server_time: string;
    flash: { success: string | null; error: string | null };
    [key: string]: unknown;
}
