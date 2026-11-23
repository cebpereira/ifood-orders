# iFood Orders

Integração em Laravel com a API de pedidos do iFood (módulo Order), com um painel em quadro e lista para acompanhar os pedidos. O foco é mostrar o tratamento de falhas, eventos duplicados e eventos fora de ordem.

> 🚧 Em construção. A documentação completa (decisões, fluxo e prints) entra na etapa final.

## Como rodar

Pré-requisitos: Docker e `make`.

```bash
make setup     # cria o .env, sobe os containers, instala dependências e roda as migrations
```

O painel fica em http://localhost:8000. Para usar a API real, preencha `IFOOD_CLIENT_ID`, `IFOOD_CLIENT_SECRET` e `IFOOD_MERCHANT_ID` no `.env`.

```bash
make help      # lista todos os comandos
make dev       # sobe o Vite com hot reload
make ci        # Pint + PHPStan + Pest, igual ao CI
```

## Stack

Laravel 13 · PHP 8.5 · PostgreSQL · Redis + Horizon · Inertia + React + Tailwind + shadcn/ui · Pest · PHPStan · Pint · GitHub Actions
