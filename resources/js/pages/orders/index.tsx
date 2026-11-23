import { Head } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

export default function OrdersIndex() {
    return (
        <>
            <Head title="Pedidos" />
            <main className="mx-auto max-w-5xl p-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Pedidos iFood</CardTitle>
                        <CardDescription>
                            Painel em construção. <Badge variant="secondary">etapa 1</Badge>
                        </CardDescription>
                    </CardHeader>
                </Card>
            </main>
        </>
    );
}
