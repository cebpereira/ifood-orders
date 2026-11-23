import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type { Health } from '@/types/orders';

/** Polling runs every 30s; past this the integration is considered stalled. */
const STALE_AFTER_MS = 90_000;

export function HealthIndicator({ health, now }: { health: Health; now: number }) {
    const polling = health.polling;
    const age = polling ? now - new Date(polling.at).getTime() : null;
    const stale = age === null || age > STALE_AFTER_MS;

    let tone: 'ok' | 'warn' | 'down' = 'ok';
    let text = 'Conectado ao iFood';

    if (!polling) {
        tone = 'down';
        text = 'Aguardando o primeiro polling';
    } else if (stale) {
        tone = 'down';
        text = 'Polling parado: verifique o scheduler';
    } else if (polling.state === 'error') {
        tone = 'down';
        text = 'Falha ao consultar o iFood';
    } else if (polling.state === 'rate_limited' || polling.state === 'ack_failed') {
        tone = 'warn';
        text = polling.state === 'rate_limited' ? 'Limite de requisições atingido' : 'Confirmação de eventos pendente';
    }

    const seconds = age === null ? null : Math.max(0, Math.round(age / 1000));

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <div
                    tabIndex={0}
                    className="flex items-center gap-2 rounded-md border border-border bg-card px-2.5 py-1.5 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <span
                        aria-hidden
                        className={cn('size-2 rounded-full', tone === 'ok' && 'bg-basil', tone === 'warn' && 'bg-saffron', tone === 'down' && 'bg-chili')}
                    />
                    <span>{text}</span>
                    {seconds !== null && <span className="tabular text-muted-foreground">último polling há {seconds}s</span>}
                </div>
            </TooltipTrigger>
            <TooltipContent className="max-w-xs">
                <p>O sistema consulta o iFood a cada 30 segundos. A loja só aparece online no iFood enquanto isso acontece.</p>
                {polling?.message && <p className="mt-1">{polling.message}</p>}
                <p className="mt-1">
                    Eventos na fila: {health.events_waiting}. Com falha: {health.events_failed}.
                </p>
            </TooltipContent>
        </Tooltip>
    );
}
