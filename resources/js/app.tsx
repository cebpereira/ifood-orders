import { createInertiaApp } from '@inertiajs/react';
import type { ComponentType } from 'react';

import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';

const pages = import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx', { eager: true });

createInertiaApp({
    title: (title) => (title ? `${title} · iFood Orders` : 'iFood Orders'),
    resolve: (name) => pages[`./pages/${name}.tsx`],
    withApp: (app) => (
        <TooltipProvider>
            {app}
            <Toaster />
        </TooltipProvider>
    ),
    progress: { color: '#ea1d2c' },
});
