# Contrato de Tasks

## 1. Objetivo

As Tasks representam tarefas operacionais associadas a uma reserva e a uma propriedade: a limpeza após o checkout (criada automaticamente a partir da reserva) e a inspeção antes do check-in (criada manualmente pelo adm/supervisor).

O módulo é independente do módulo de reservas: recebe apenas o `booking_id` e vai buscar sozinho os dados de que precisa (ver secção 8).

## 2. Campos da Task

| Campo | Descrição |
|---|---|
| `id` | Identificador único da tarefa |
| `booking_id` | Identificador da reserva associada |
| `property_id` | Identificador da propriedade associada |
| `service_provider_id` | Prestador de serviços atribuído. Pode ser nulo inicialmente |
| `type` | Tipo de tarefa: `checkout_cleaning` ou `checkin_inspection` |
| `status` | Estado da tarefa: `pending`, `in_progress`, `completed` ou `cancelled` |
| `scheduled_start_at` | Início da janela operacional |
| `scheduled_end_at` | Fim da janela operacional. Pode ser nulo |
| `started_at` | Início real da tarefa. Pode ser nulo |
| `completed_at` | Conclusão real da tarefa. Pode ser nulo |
| `agreed_amount` | Valor acordado. Pode ser nulo |
| `notes` | Notas adicionais. Pode ser nulo |

A tabela inclui ainda `created_at`, `updated_at` e `deleted_at` (eliminação lógica).

Nota: o estado `cancelled` existe na tabela, mas **o módulo não o usa**. Reservas canceladas ou removidas resultam em eliminação lógica da task (secção 5).

## 3. Tipos de tarefa e janelas

### 3.1 Limpeza após checkout (`checkout_cleaning`)

- Criada automaticamente, a partir de uma reserva.
- O estado inicial é `pending`.
- `scheduled_start_at`: 11:30 do dia do check-out.
- `scheduled_end_at`: 14:30 do dia do próximo check-in da mesma propriedade. Se não existir próximo check-in conhecido, fica `null`.
- O prestador de serviços não é atribuído na criação.
- O check-in da própria reserva não é obrigatório para criar a limpeza.

### 3.2 Inspeção antes do check-in (`checkin_inspection`)

- Criada manualmente pelo adm/supervisor, sempre associada a uma reserva (normalmente a seguinte).
- O estado inicial é `pending`.
- `scheduled_start_at`: o momento em que a task é criada.
- `scheduled_end_at`: 14:30 do dia do check-in da reserva (prazo máximo).
- Só pode ser criada se esse prazo ainda não passou.
- Se a janela terminar sem a task estar concluída, a task fica em atraso até o operador a marcar como concluída.

### 3.3 Regras comuns

- Não pode existir mais do que uma task do mesmo tipo para a mesma reserva (índice único `booking_id` + `type`, que inclui as tasks apagadas).
- Os horários são fixos (11:30 e 14:30) e calculados em UTC, o fuso da aplicação.
- A propriedade da task é a propriedade da fonte da reserva.

## 4. Validações

Aplicadas sobre os dados da reserva antes de criar, atualizar ou restaurar:

| Regra | Checkout | Inspeção |
|---|---|---|
| A reserva tem de pertencer a uma propriedade | Sim | Sim |
| Data de check-out válida | Sim | Não |
| Data de check-in válida | Não | Sim |
| Check-in anterior ao check-out (quando ambos existem) | Sim | Não |
| O prazo (14:30 do check-in) não pode ter passado | Não | Sim |

## 5. Ciclo de vida

Todas as eliminações são lógicas (soft delete).

| Operação | Quem | Regras |
|---|---|---|
| Criar limpeza | Booking (automático) | Idempotente. Se já existe, devolve a existente. Se existe apagada, não recria |
| Criar inspeção | Adm/supervisor | Erro se já existe (a mensagem da apagada orienta para o restore) |
| Atualizar por reserva | Booking (automático) | Recalcula as datas. Na inspeção só muda o fim. Não mexe em tasks `in_progress` ou `completed` (fica em log). Dados inválidos ficam em log, sem falhar |
| Editar | Adm/supervisor | Só `service_provider_id`, `agreed_amount` e `notes`. Tasks `completed` não se editam. Estado e datas não se alteram por aqui |
| Apagar por reserva | Booking (automático) | Apaga todas as tasks da reserva (checkout e inspeção). Não apaga `in_progress` nem `completed` (fica em log). Repetir a chamada é seguro |
| Apagar | Adm/supervisor | Erro se a task estiver `in_progress` ou `completed` |
| Restaurar | Adm/supervisor | Só tasks apagadas. A reserva tem de existir, os dados são revalidados, as datas recalculadas, e não se restaura se a reserva (checkout) ou o prazo (inspeção) já passou |

## 6. API

Todas as rotas exigem utilizador autenticado e ativo. Os erros de validação respondem `422`; uma task inexistente responde `404`. Os pedidos devem enviar `Accept: application/json`.

### `GET /api/tasks`

Lista de tarefas ordenada por `scheduled_start_at`. Cada elemento inclui:

- `id`
- `property`: nome da propriedade, ou `null`
- `type`
- `scheduled_start_at`
- `status`

### `POST /api/tasks`

Cria uma inspeção (`checkin_inspection`) para uma reserva.

Corpo:

```json
{ "booking_id": 12 }
```

Resposta `201` com a task criada. Erros `422`: reserva não encontrada, reserva sem propriedade ou sem check-in, prazo já passado, ou já existe inspeção para a reserva.

### `PATCH /api/tasks/{task}`

Edita os campos permitidos. Todos opcionais:

| Campo | Regra |
|---|---|
| `service_provider_id` | Inteiro, nulo ou existente em `service_providers` |
| `agreed_amount` | Numérico, nulo ou maior ou igual a 0 |
| `notes` | Texto, nulo ou até 2000 caracteres |

Qualquer outro campo enviado (por exemplo `status` ou datas) é ignorado. Resposta `200` com a task. Erro `422` se a task estiver concluída.

### `DELETE /api/tasks/{task}`

Apaga (soft delete). Resposta `204`. Erro `422` se a task estiver em andamento ou concluída.

### `POST /api/tasks/{task}/restore`

Restaura uma task apagada. Resposta `200` com a task. Erro `404` se a task não estiver apagada. Erro `422` se a reserva já não existe ou se o prazo já passou.

### Permissões

**A definir.** O objetivo é que `POST`, `PATCH`, `DELETE` e `restore` sejam exclusivos de administrator e supervisor, e que `GET` fique disponível a todos os utilizadores ativos. Até estar implementado, as rotas de escrita aceitam qualquer utilizador autenticado e ativo.

## 7. Dependências e limitações

- O funcionamento depende de reservas e propriedades válidas na base de dados.
- Os horários estão em UTC. O horário local da propriedade (`properties.timezone`) fica para uma fase futura.
- A limpeza da reserva anterior da mesma propriedade **não é recalculada** quando um check-in entra, muda ou sai. O seu `scheduled_end_at` pode ficar desatualizado.
- A criação do checkout não trata, por agora, dois pedidos simultâneos para a mesma reserva (o índice único protege contra duplicados, mas o segundo pedido falha).
- O módulo ainda não exclui reservas canceladas que continuem na tabela sem eliminação lógica. Depende de confirmar `bookings.status` e o uso de `SoftDeletes` no `Booking`.

## 8. Integração com Reservations (Booking)

### 8.1 O que o Booking chama

O Booking comunica com a Task **apenas enviando o `booking_id`**. Não envia datas nem propriedade.

| Evento no Booking | Chamada | Efeito |
|---|---|---|
| Reserva criada | `CreateCheckoutCleaningTask->execute($bookingId)` | Cria a limpeza de checkout |
| Datas da reserva alteradas | `UpdateTask->forBooking($bookingId)` | Recalcula as datas das tasks |
| Reserva cancelada ou apagada | `DeleteTask->forBooking($bookingId)` | Apaga as tasks da reserva |

Recomendação: chamar depois do commit e, de preferência, num job em fila, para não atrasar a sincronização do iCal.

Cada reserva deve gerar uma única task por tipo. Evitar reservas duplicadas no tratamento do iCal é responsabilidade do módulo Reservations.

### 8.2 Como a Task lê os dados da reserva

Um único ficheiro (`EloquentBookingDataProvider`) lê as tabelas `bookings` e `booking_sources` e devolve um `BookingSnapshot`. Colunas usadas:

- `bookings`: `id`, `booking_source_id`, `source_check_in_at`, `source_check_out_at`, `overridden_check_in_at`, `overridden_check_out_at`
- `booking_sources`: `property_id`

As datas efetivas são as `overridden_*` quando existem e, caso contrário, as `source_*`. **Renomear estas colunas quebra o módulo Tasks**, por isso fazem parte do contrato entre os dois módulos. O próximo check-in é a reserva mais próxima da mesma propriedade com check-in na data do check-out ou depois dela.

Quando o Booking passar a ser um serviço próprio, só este provider é substituído (por exemplo, por um que faça chamadas HTTP).

### 8.3 Adaptador iCal

O módulo Reservations disponibiliza um adaptador iCal que lê eventos do Airbnb e os normaliza em objetos `ReservationEvent`, agrupados num `ParseResult`. Os eventos incluem `externalUid`, `propertyId`, `channel`, as datas de check-in e check-out e o `status`. A persistência das reservas e a chamada às Actions de Task dependem das etapas do módulo Reservations (a confirmar com a equipa).

## 9. Exemplos

### 9.1 Criação da limpeza

O Booking chama:

```php
$action->execute(bookingId: 12);
```

A Task lê a reserva 12. Com check-out a 20/10/2026 e próximo check-in da mesma propriedade a 22/10/2026, resulta:

- `booking_id`: `12`
- `property_id`: o da reserva
- `type`: `checkout_cleaning`
- `status`: `pending`
- `scheduled_start_at`: 20/10/2026, 11:30
- `scheduled_end_at`: 22/10/2026, 14:30

Este exemplo é ilustrativo e não representa uma execução confirmada na base de dados.

### 9.2 Sem próximo check-in

Sem próxima reserva conhecida na propriedade, a task começa às 11:30 do dia do check-out e `scheduled_end_at` fica `null`.

### 9.3 Criação da inspeção

Pedido do adm no dia 10/10/2026 às 09:00, para uma reserva com check-in a 15/10/2026:

```http
POST /api/tasks
{ "booking_id": 12 }
```

Resultado: `type` `checkin_inspection`, `status` `pending`, `scheduled_start_at` 10/10/2026 09:00 e `scheduled_end_at` 15/10/2026 14:30.

### 9.4 Resposta de `GET /api/tasks`

```json
[
  {
    "id": 1,
    "property": "Apartamento Centro",
    "type": "checkout_cleaning",
    "scheduled_start_at": "2026-10-20T11:30:00.000000Z",
    "status": "pending"
  }
]
```

Valores fictícios. A resposta real ainda necessita de validação através de um pedido HTTP.

## 10. Trabalho futuro

- Aplicar as permissões de administrator e supervisor às rotas de escrita.
- Recalcular a limpeza da reserva anterior quando um check-in muda.
- Tratar pedidos simultâneos na criação do checkout.
- Horário de 11:30 e 14:30 em hora local da propriedade.
- Confirmar com Reservations o uso de `SoftDeletes`, os valores de `bookings.status` e onde são feitas as chamadas às Actions.
- Testes das regras de agendamento, das Actions e dos endpoints.
- Fluxos de iniciar e concluir task (alteração de `status`), fora do âmbito deste contrato.
- Integrar a listagem de tarefas na interface web.