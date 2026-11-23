# Descoberta: API do iFood com credenciais reais

Validação feita em 23/11/2026, das 18:09 às 18:21 UTC, com o app de teste (fluxo **centralizado**) e a loja de teste da conta de desenvolvedor. Este arquivo prevalece sobre a documentação pública quando as duas divergem.

IDs, tokens e credenciais foram omitidos. Os pedidos de teste usam dados fictícios do próprio iFood, mas o payload de detalhes traz o e-mail do desenvolvedor em `additionalInfo.metadata`, então **nenhum payload real vira fixture**: as fixtures dos testes são sintéticas.

## Resumo das divergências

| Item | Documentação / premissa | Comportamento real |
|---|---|---|
| Polling | `GET /order/v1.0/orders:polling` | **404**. O path correto é `GET /order/v1.0/events:polling` (`/events/v1.0/events:polling` também responde) |
| Ack | `POST /order/v1.0/orders:acknowledgment` com `{"acknowledgedEventIds": [...]}` | Path `POST /order/v1.0/events/acknowledgment`, body **lista** `[{"id": "..."}]`. O formato com objeto responde **400** |
| Polling vazio | 200 com `[]` | **204** sem corpo |
| `readyToPickup` / `dispatch` | A homologação cita PUT | **POST**, como as demais ações. PUT dá `no Route matched` no gateway |
| Token | Form OAuth padrão | Campos em **camelCase**: `grantType`, `clientId`, `clientSecret` |
| 404 logo após PLC | Esperado, com retry de até 10 min | Não ocorreu: os 4 pedidos retornaram 200 já na primeira tentativa. O retry com backoff continua, por segurança |
| Header de rate limit | `X-RateLimit-*` | Ausentes nas respostas 2xx. Só não foi possível validar o 429, pois não houve estouro |
| Loja online | – | A loja fica `ERROR`/indisponível até existir polling ativo. Depois do primeiro polling, passa para `OK` |
| Body do cancelamento | `{"reason": "503"}` | **400** `Field 'cancellationCode' is required`. O body correto é `{"reason": "<texto>", "cancellationCode": "503"}` |
| Ordem das ações | `readyToPickup` antes de `dispatch` | Não é exigida: `dispatch` direto de CONFIRMED foi aceito e gerou DSP |
| Ação inválida | Resposta CARF | Respondeu **202** e depois **nenhum evento** (nem CARF) |
| CAR antes de CAN | Sempre | Num cancelamento pedido pela loja, chegou **CAN sem CAR** |
| Códigos de evento | PLC, CFM, PRS, RTP, DSP, CON, CAN, CAR, CARF | Também chega **DDCR** (`DELIVERY_DROP_CODE_REQUESTED`) |

## Autenticação

`POST /authentication/v1.0/oauth/token` com `application/x-www-form-urlencoded`:

```
grantType=client_credentials&clientId=…&clientSecret=…
```

A resposta é `{"accessToken", "type": "bearer", "expiresIn": 21599}`: o token dura 6 h e **não há refresh token** no fluxo centralizado. A decisão foi cachear o token até `expiresIn - 5 min` e, ao receber um 401, invalidar o cache e tentar de novo uma única vez.

## Polling

- `GET /order/v1.0/events:polling` com o header `x-polling-merchants: <merchantId>`.
- **204** sem corpo quando não há eventos e **200** com uma lista quando há.
- **Reentrega confirmada:** sem ack, o mesmo evento (mesmo `id`) volta em todos os pollings seguintes. Num dos ciclos, o polling trouxe 5 eventos, 3 deles já recebidos antes.
- Campos do evento: `id`, `code`, `fullCode`, `orderId`, `merchantId`, `createdAt` (ISO-8601 com milissegundos, UTC), `salesChannel` e `metadata`, que **só aparece em alguns eventos** (no PLC o campo nem vem).

Exemplo do `metadata` de um CFM:

```json
{"triggerEvent": {"id": "…", "previousStatus": "PLACED", "value": "CONFIRM"}}
```

## Acknowledgment

- `POST /order/v1.0/events/acknowledgment` com o body `[{"id": "<eventId>"}, …]`. A resposta é **202** sem corpo.
- Um ID inexistente também recebe 202, ou seja, o ack é idempotente e não valida os IDs.
- **O ack pode ser lento:** o primeiro ack real, com 15 IDs, levou **5,5 s** do lado do iFood (o polling leva de 300 a 500 ms). Por isso o timeout HTTP é de 10 s, e uma falha no ack nunca derruba o ciclo: o evento volta no próximo polling e o ack é refeito.
- Depois do ack, o polling seguinte voltou 204: o ack foi efetivo.
- Durante a descoberta **nenhum evento real foi confirmado com ack**: só IDs fictícios foram usados.

## Detalhes do pedido

`GET /order/v1.0/orders/{id}`. Os campos relevantes para o projeto são:

- `id`, `displayId` (código curto exibido para a loja), `createdAt`, `isTest`
- `category` (`FOOD`, `FOOD_SELF_SERVICE`), `orderType` (`DELIVERY`, `DINE_IN`, …) e `orderTiming` (`IMMEDIATE`)
- `salesChannel` (`IFOOD`, `TOTEM`, …)
- `delivery.deliveredBy` (`MERCHANT` nos pedidos de delivery de teste)
- `customer` (`name`, `phone`, `documentNumber`), `items[]`, `total.orderAmount` e `payments`

Um pedido inexistente devolve `404 {"error": {"code": "OrderNotFound", …}}`.

### Tipos de pedido observados

Dos 4 pedidos gerados pelo portal:

| Tipo | Canal | Observação |
|---|---|---|
| `DELIVERY` / `FOOD` | — | Fluxo completo: PLC → CFM → … → DSP → CON |
| `DINE_IN` / `FOOD_SELF_SERVICE` | `TOTEM` | **Já chega confirmado**: o CFM vem cerca de 350 ms depois do PLC, sem ação da loja. Não há dispatch |

Consequência: as **ações oferecidas no painel** dependem do `orderType`. Um pedido `DINE_IN` não tem `dispatch`, e o alerta do prazo de 8 minutos só vale para pedidos ainda em PLACED. A aplicação de eventos não precisa disso, porque trabalha por rank (veja Ações).

## Ações

### Método

Testado com um order ID **fictício**:

| Endpoint | POST | PUT |
|---|---|---|
| `/orders/{id}/confirm` | 404 `OrderNotFound` (a rota existe) | 404 `no Route matched` |
| `/orders/{id}/startPreparation` | 404 `OrderNotFound` | 404 `no Route matched` |
| `/orders/{id}/readyToPickup` | 404 `OrderNotFound` | 404 `no Route matched` |
| `/orders/{id}/dispatch` | 404 `OrderNotFound` | 404 `no Route matched` |
| `/orders/{id}/requestCancellation` | 403 com o body vazio | 404 `no Route matched` |

Dá para distinguir as duas causas de 404 pelo corpo: `{"error": {"code": "OrderNotFound"}}` vem da API, e `{"message": "no Route matched…"}` vem do gateway. O 403 do cancelamento **não era falta de permissão**: com o body correto e um pedido real, a resposta foi 202.

### Execução em pedidos de teste

Todas as chamadas responderam **202 sem corpo**. A tabela mostra o que chegou depois pelo polling:

| Ação | Estado do pedido | Evento resultante | Latência |
|---|---|---|---|
| `confirm` | PLACED (delivery) | CFM, seguido de **DDCR** | ~0,5 s |
| `startPreparation` | CONFIRMED (dine-in) | PRS | <1 s |
| `readyToPickup` | PREPARATION_STARTED (dine-in) | RTP | <1 s |
| `dispatch` | **CONFIRMED** (sem PRS nem RTP) | DSP, **aceito** | <1 s |
| `requestCancellation` `503` | PLACED | CAR, seguido de CAN | ~150 ms entre os dois |
| `requestCancellation` `501` | **DISPATCHED** | CAN **sem CAR**; `CANCEL_STAGE: [DISPATCHED]` | — |
| `startPreparation` | **PLACED** (não confirmado) | **nenhum** | — |
| `confirm` | **CANCELLED** | **nenhum** | — |

Conclusões para o projeto:

1. **O 202 não garante nada.** Uma ação inválida pode ser ignorada em silêncio, sem CARF. Por isso a ação pendente precisa de um **timeout**: se nenhum evento a resolver em N segundos, ela vira `expired`. O CARF é tratado quando aparece, mas não dá para depender dele.
2. **A máquina de estados trabalha por rank, não por adjacência.** DSP a partir de CONFIRMED é válido.
3. **CAN não depende de CAR.** O cancelamento é aplicado mesmo sem um CAR anterior, e um CAR que chegue depois de um CAN é só registrado.
4. **Códigos desconhecidos (como o DDCR) são gravados e recebem ack**, mas não mudam o estado do pedido. Um código novo nunca pode travar a fila.

### Metadata dos eventos de ação

Eventos gerados por ação da loja trazem `metadata.ORIGIN = "ORDER_API"`, além de `CLIENT_ID` e `appName`. Já o CFM automático do totem traz `metadata.triggerEvent`.

## Cancelamento

- **CAR** (pedido pela loja): `metadata` com `ORIGIN: ORDER_API`, `reason_code` e `details`.
- **CAR automático pelo prazo:** `ORIGIN: SYSTEM`, `reason_code: "902"`, `details: "O pedido não foi confirmado pela loja"`.
- **CAN:** `metadata` detalhado com `CANCEL_CODE`, `CANCEL_CODE_DESCRIPTION`, `CANCEL_ORIGIN` (`RESTAURANT` ou `SCHEDULER`), `CANCEL_REASON`, `CANCEL_STAGE`, `CANCELLATION_REQUESTED_EVENT_ID` (aponta para o CAR, que pode não chegar) e dados financeiros (`CANCELLATION_OCCURRENCE`, `CANCELLATION_DISPUTE`).

### Prazo de confirmação

Um pedido de delivery não confirmado foi cancelado automaticamente **7min59s** depois do PLC (PLC às 18:12:01, CAR às 18:20:00, CAN às 18:20:01). O alerta do painel deve disparar antes disso, por volta de 5 minutos.

## Motivos de cancelamento

`GET /orders/{id}/cancellationReasons` → `[{"cancelCodeId": "501", "description": "…"}]`.

- O código vem como **string**.
- **A lista varia por tipo de pedido.** Um pedido `DINE_IN` não traz, por exemplo, o `504` (sem entregadores).
- Por isso o painel deve sempre buscar os motivos do próprio pedido, em vez de usar uma lista fixa.

- Num pedido **já cancelado**, o endpoint responde `400 {"code": "400 BAD_REQUEST", "message": "Order … is already cancelled"}`.

## Formatos de erro

A API usa **dois formatos** de corpo de erro, e o cliente aceita ambos:

- `{"error": {"code": "OrderNotFound", "message": "…"}}`: 404, 403, 400 de validação
- `{"code": "400 BAD_REQUEST", "message": "…"}`: por exemplo, os motivos de cancelamento de um pedido cancelado
- `{"message": "no Route matched with those values"}`: vem do gateway (Kong), quando a rota ou o método não existem

## Cabeçalhos úteis

Toda resposta traz `x-request-id` (igual a `X-iFood-Request-Id`). Ele entra nos logs ao lado do `correlation_id` local, para cruzar com o suporte do iFood.

## Ações pelo sistema (validação de ponta a ponta)

Em 23/11/2026, às 19:13 UTC, três pedidos novos foram movidos **pelo painel**, passando pelo `SendOrderActionJob` e pelo polling real:

| Pedido | Ação | 202 recebido | Fechada pelo evento | Espera |
|---|---|---|---|---|
| #2216 | confirm | 19:13:56.787 | CFM, 19:14:02.101 | 5 s |
| #2213 | requestCancellation `503` | 19:14:16.917 | CAN, 19:14:32.152 | 15 s |
| #2216 | startPreparation | 19:14:32.231 | PRS, 19:15:02.172 | 30 s |
| #2216 | readyToPickup | 19:15:14.630 | RTP, 19:15:32.209 | 18 s |
| #2216 | dispatch | 19:15:44.618 | DSP, 19:16:02.263 | 18 s |
| #4870 | nenhuma | — | CAR `902` + CAN pelo `SCHEDULER` | 8 min após o PLC |

- O iFood gera o evento em menos de 1 s depois do 202. A espera vista no painel vem do intervalo de 30 s do polling. Com webhook, ela cairia para perto de zero.
- O DDCR apareceu de novo logo depois do confirm de um pedido de delivery. É um comportamento consistente da API de teste.
- Nenhuma ação expirou nem gerou CARF.

## Pendências

- [ ] Observar um CARF real: não ocorreu em nenhum dos cenários testados
- [ ] Observar CON (CONCLUDED): os pedidos de teste não concluíram durante a janela observada
- [ ] Provocar ou observar um 429 para validar os headers `X-RateLimit-*`
- [x] Eventos sem ack ficam disponíveis por pelo menos **23 minutos**: os 15 eventos desta descoberta foram ingeridos pelo `ifood:poll` às 18:35 UTC, e o mais antigo tinha sido criado às 18:12 UTC. O limite máximo não foi medido
