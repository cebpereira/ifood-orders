import { formatCountdown } from '@/lib/orders';
import { cn } from '@/lib/utils';

const WINDOW_MS = 8 * 60_000;

export function deadlineTone(remainingMs: number): 'calm' | 'warn' | 'late' {
    if (remainingMs <= 2 * 60_000) return 'late';
    if (remainingMs <= 4 * 60_000) return 'warn';
    return 'calm';
}

/**
 * iFood cancels an order that is not confirmed within 8 minutes. The bar
 * drains over that window and changes tone as the deadline approaches.
 */
export function DeadlineBar({ deadline, now }: { deadline: string; now: number }) {
    const remaining = new Date(deadline).getTime() - now;
    const expired = remaining <= 0;
    const tone = deadlineTone(remaining);
    const fraction = Math.min(1, Math.max(0, remaining / WINDOW_MS));

    return (
        <div className="space-y-1.5">
            <div
                className="h-1.5 overflow-hidden rounded-full bg-ink/8"
                role="progressbar"
                aria-label="Tempo para confirmar"
                aria-valuemin={0}
                aria-valuemax={480}
                aria-valuenow={Math.round(Math.max(0, remaining) / 1000)}
            >
                <div
                    className={cn(
                        'h-full rounded-full transition-[width] duration-1000 ease-linear',
                        tone === 'calm' && 'bg-basil',
                        tone === 'warn' && 'bg-saffron',
                        tone === 'late' && 'bg-chili',
                    )}
                    style={{ width: `${fraction * 100}%` }}
                />
            </div>
            <p
                className={cn(
                    'tabular text-xs font-semibold',
                    tone === 'calm' && 'text-basil',
                    tone === 'warn' && 'text-saffron',
                    tone === 'late' && 'text-chili',
                )}
            >
                {expired ? 'Prazo de confirmação esgotado' : `Confirme em ${formatCountdown(remaining)}`}
            </p>
        </div>
    );
}
