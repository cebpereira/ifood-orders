import { useEffect, useState } from 'react';

/**
 * Current time, ticking every `interval` ms, corrected by the offset between
 * the server clock and the browser clock (deadlines come from the server).
 */
export function useNow(serverTime: string, interval = 1000): number {
    const [offset] = useState(() => new Date(serverTime).getTime() - Date.now());
    const [now, setNow] = useState(() => Date.now() + offset);

    useEffect(() => {
        const id = window.setInterval(() => setNow(Date.now() + offset), interval);
        return () => window.clearInterval(id);
    }, [interval, offset]);

    return now;
}
