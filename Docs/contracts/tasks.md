# Contrato de Tasks

## 1. Objetivo

As Tasks representam tarefas operacionais associadas a uma reserva e a uma propriedade: a limpeza após o checkout (criada automaticamente a partir da reserva) e a inspeção antes do check-in (criada manualmente pelo adm/supervisor).

O módulo é independente do módulo de reservas: recebe apenas o `booking_id` e vai buscar sozinho os dados de que precisa (ver secção 8).

Perfis (nomes dos roles): `admin`, `supervisor` e `service_provider`. Nas secções seguintes, "staff" significa `admin` ou `supervisor`.

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
| `completed_by_user_id` | ID do utilizador que concluiu a tarefa; nulo antes da conclusão |
| `agreed_amount` | Valor acordado. Pode ser nulo |
| `notes` | Notas adicionais. Pode ser nulo |

A tabela inclui ainda `created_at`, `updated_at` e `deleted_at` (eliminação lógica).

### 2.1 Estados

| Estado | Significado |
|---|---|
| `pending` | Criada, ainda não iniciada |
| `in_progress` | Iniciada pelo prestador |
| `completed` | Concluída |
| `cancelled` | Cancelada. Uma task cancelada é sempre eliminada logicamente (`deleted_at` preenchido) |

### 2.2 Task em atraso (`is_overdue`)

As respostas da API incluem `is_overdue` (booleano). É `true` quando a task está `pending` ou `in_progress` e o `scheduled_end_at` já passou. É `false` nos restantes casos, incluindo quando `scheduled_end_at` é nulo. A regra está num só sítio (`Task::isOverdue()`) e é usada nas respostas de staff e de prestador. Uma task em atraso deixa de o estar quando é concluída.

## 3. Tipos de tarefa e janelas

### 3.1 Limpeza após checkout (`checkout_cleaning`)

- Criada automaticamente, a partir de uma reserva.
- O estado inicial é `pending`.
- `scheduled_start_at`: 11:30 do dia do check-out.
- `scheduled_end_at`: 14:30 do dia do próximo check-in da mesma propriedade. Se não existir próximo check-in conhecido, fica `null`.
- O prestador de serviços e o valor acordado não são atribuídos na criação.
- O check-in da própria reserva não é obrigatório para criar a limpeza.

### 3.2 Inspeção antes do check-in (`checkin_inspection`)

- Criada manualmente pelo adm/supervisor, sempre associada a uma reserva (normalmente a seguinte).
- O estado inicial é `pending`.
- `scheduled_start_at`: o momento em que a task é criada.
- `scheduled_end_at`: 14:30 do dia do check-in da reserva (prazo máximo).
- Só pode ser criada se esse prazo ainda não passou.
- Se a janela terminar sem a task estar concluída, a task fica em atraso (`is_overdue`) até ser concluída.

### 3.3 Regras comuns

- Não pode existir mais do que uma task do mesmo tipo para a mesma reserva (índice único `booking_id` + `type`, que inclui as tasks apagadas).
- Os horários são fixos (11:30 e 14:30) e calculados em UTC, o fuso da aplicação.
- A propriedade da task é a propriedade da fonte da reserva.

## 4. Validações

Aplicadas sobre os dados da reserva antes de criar, atualizar ou restaurar:

| Regra | Checkout | Inspeção |
|---|---|---|
| A reserva tem de existir e estar ativa (`status` `reserved`) | Sim | Sim |
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
| Iniciar | Prestador | Só as suas tasks `pending`. Idempotente: se já está `in_progress`, devolve-a sem alterar `started_at` |
| Concluir | Prestador | Só as suas tasks `in_progress`. Idempotente: se já está `completed`, devolve-a sem alterar `completed_at`. Não exige fotos nem vídeo |
| Cancelar por reserva | Booking (automático) | Reserva cancelada: as tasks `pending` ficam `cancelled` e são apagadas logicamente. Não toca em `in_progress` nem `completed` (fica em log). Repetir a chamada é seguro |
| Apagar por reserva | Booking (automático) | Reserva removida: apaga logicamente as tasks da reserva (sem mudar o estado). Não apaga `in_progress` nem `completed` (fica em log). Repetir a chamada é seguro |
| Cancelar | Adm/supervisor | Task `pending` fica `cancelled` e é apagada logicamente. Repetir o pedido é seguro (sucesso sem alterações). Erro se estiver `in_progress` ou `completed`, ou se já tiver sido apagada por outra operação |
| Apagar | Adm/supervisor | Soft delete sem mudar o estado (para remoções administrativas, por exemplo uma task criada por engano). Erro se a task estiver `in_progress` ou `completed` |
| Restaurar | Adm/supervisor | Só tasks apagadas (canceladas ou apagadas). A reserva tem de existir e estar ativa, os dados são revalidados, e não se restaura se a reserva (checkout) ou o prazo (inspeção) já passou. A task volta a `pending`, com `started_at` e `completed_at` limpos e as datas recalculadas (na inspeção, o início passa a ser o momento do restauro) |

## 6. API

Todas as rotas exigem utilizador autenticado e ativo. Os erros de validação respondem `422`; uma task inexistente responde `404`; sem autenticação responde `401`. Os pedidos devem enviar `Accept: application/json`.

Respostas de lista (`GET /api/tasks` e `GET /api/my/tasks`) são paginadas: `data` (elementos), `links` e `meta`.

### 6.1 Staff

#### `GET /api/tasks` (admin e supervisor)

Lista paginada, ordenada por `scheduled_start_at` e depois por `id`. Filtros opcionais:

| Filtro | Regra |
|---|---|
| `from`, `to` | Data do início da task (`to` não pode ser anterior a `from`) |
| `property_id` | Inteiro |
| `status` | `pending`, `in_progress`, `completed` ou `cancelled` |
| `type` | `checkout_cleaning` ou `checkin_inspection` |
| `deleted` | `only`: devolve só as tasks apagadas (incluindo as canceladas). Por defeito, as apagadas não aparecem |
| `per_page` | 1 a 100, por defeito 15 |

Cada elemento: `id`, `booking_id`, `property`, `property_address`, `type`, `scheduled_start_at`, `scheduled_end_at`, `is_overdue`, `service_provider_id`, `service_provider` (nome do prestador), `agreed_amount`, `status`, `completed_at`, `completed_by` (nome de quem concluiu), `deleted_at`, `notes` e `provider_notes`.

#### `GET /api/tasks/{id}` (admin e supervisor)

Detalhe de uma task, mesmo apagada ou cancelada, dentro de `data`. Mesmos campos da lista.

#### `POST /api/tasks` (admin e supervisor)

Cria uma inspeção (`checkin_inspection`) para uma reserva.

Corpo:

```json
{ "booking_id": 12 }
```

Resposta `201` com a task criada. Erros `422`: reserva não encontrada, reserva sem propriedade ou sem check-in, prazo já passado, ou já existe inspeção para a reserva.

#### `PATCH /api/tasks/{id}` (admin e supervisor)

Edita os campos permitidos. Todos opcionais:

| Campo | Regra |
|---|---|
| `service_provider_id` | Inteiro de prestador existente com utilizador ativo; `null` é rejeitado |
| `agreed_amount` | Numérico, nulo ou maior ou igual a 0 |
| `notes` | Texto, nulo ou até 2000 caracteres |

Qualquer outro campo enviado (por exemplo `status` ou datas) é ignorado. Resposta `200` com a task. Erro `422` se a task estiver concluída.

Apenas administradores e supervisores podem enviar `service_provider_id`; outros perfis recebem `403`. A primeira atribuição só é permitida numa tarefa `pending`. A reatribuição é permitida em `pending`, `in_progress` e `cancelled`, mas não em `completed` (regra provisória). Um prestador inexistente ou inativo é rejeitado com `422`. O envio de `null` é rejeitado; enviar o mesmo prestador não altera a atribuição nem cria histórico.

Exemplo de atribuição ou reatribuição:

```http
PATCH /api/tasks/42
Accept: application/json
Authorization: Bearer <token>
Content-Type: application/json

{ "service_provider_id": 7 }
```

A resposta `200` devolve a task atualizada, incluindo `id` e `service_provider_id`. As respostas `401`, `403`, `404` e `422` indicam, respetivamente, autenticação inválida, falta de permissão, tarefa inexistente ou dados/regra inválidos.

Cada alteração efetiva cria uma entrada em `task_assignment_history` com `task_id`, `previous_service_provider_id` (`null` na primeira atribuição), `new_service_provider_id`, `user_id` do autor e `created_at`. A atualização da task e a criação da entrada ocorrem na mesma transação. As atribuições existentes antes desta funcionalidade não têm histórico retroativo. A eliminação física de tarefas ou prestadores referenciados pelo histórico é restringida; a eliminação normal de tasks continua lógica.

#### `POST /api/tasks/{id}/cancel` (admin e supervisor)

Cancela a task: `status` passa a `cancelled` e a task é apagada logicamente. Resposta `204`, sem corpo.

- Idempotente: repetir o pedido numa task já cancelada responde `204`, sem alterar nada.
- `422` se a task estiver `in_progress` ou `completed`.
- `422` se a task já foi apagada por outra operação (`DELETE`): uma remoção administrativa não se transforma em cancelamento.
- `404` se a task não existir.

#### `DELETE /api/tasks/{id}` (admin e supervisor)

Apaga logicamente a task, sem mudar o estado. Resposta `204`. Erro `422` se a task estiver em andamento ou concluída.

#### `POST /api/tasks/{id}/complete` (admin e supervisor)

Conclui uma task `in_progress`, mesmo que não esteja atribuída ao utilizador staff autenticado. A conclusão é idempotente e não exige foto nem vídeo. Uma task `pending` não pode ser concluída diretamente.

Resposta `200` com o resource dentro de `data`. O sistema guarda o ID do autor em `completed_by_user_id` e devolve o nome em `completed_by`.

A autorização por perfil nesta rota ficará a cargo do middleware de roles pendente.

### 6.2 Prestador

Um utilizador sem registo de prestador recebe `403` em todas as rotas `/api/my/...`. O prestador só vê e age sobre as tasks atualmente atribuídas ao seu registo, e nunca vê o valor acordado nem o prestador atribuído. Uma reatribuição muda imediatamente o acesso na próxima consulta. Tasks apagadas ou canceladas não aparecem ao prestador.

#### `GET /api/my/tasks` (service_provider)

Lista paginada das tasks atribuídas ao prestador autenticado. Mesmos filtros da lista de staff, exceto `deleted` (enviá-lo responde `422`).

Cada elemento: `id`, `property`, `property_address`, `access_instructions`, `type`, `scheduled_start_at`, `scheduled_end_at`, `is_overdue`, `status`, `started_at`, `completed_at` e `provider_notes`.

#### `GET /api/my/tasks/{id}` (service_provider)

Detalhe de uma task atribuída ao prestador, dentro de `data`. Mesmos campos da lista. Responde `404` se a task não existir, estiver apagada ou não for dele.

#### `POST /api/my/tasks/{id}/start` (service_provider)

Inicia a task: `status` passa a `in_progress` e `started_at` fica com o momento atual. Resposta `200` com a task (mesmos campos do detalhe).

- Idempotente: se a task já está `in_progress`, devolve-a sem alterar nada (`started_at` mantém-se).
- Só tasks `pending` podem ser iniciadas; uma task `completed` responde `422`. As canceladas, por estarem apagadas, respondem `404`.
- Por agora não há restrição de horário: o início não é bloqueado por `scheduled_start_at`. Uma task iniciada depois do fim da janela fica em atraso (`is_overdue`) até ser concluída.
- Responde `404` se a task não existir, estiver apagada ou não for do prestador.

#### `POST /api/my/tasks/{id}/complete` (service_provider)

Conclui a task: `status` passa a `completed` e `completed_at` fica com o momento atual. Resposta `200` com a task.

- Idempotente: se a task já está `completed`, devolve-a sem alterar nada (`completed_at` mantém-se).
- Só tasks `in_progress` podem ser concluídas; as restantes respondem `422`.
- Não exige fotos nem vídeo. Esses requisitos serão acrescentados quando existirem.
- Responde `404` se a task não existir, estiver apagada ou não for do prestador.

#### `PATCH /api/my/tasks/{id}/notes` (service_provider)

Atualiza `provider_notes`, um campo separado das notas administrativas (`notes`). O prestador envia o texto completo; cada atualização substitui o valor anterior. Enviar `null` limpa o campo.

{
  "provider_notes": "Apartamento muito sujo; um copo quebrado na cozinha."
}

### 6.3 Permissões

| Rotas | Perfil |
|---|---|
| `GET /api/tasks`, `GET /api/tasks/{id}`, `POST /api/tasks`, `PATCH`, `DELETE`, `cancel`, `restore` | `admin` e `supervisor` |
| `GET /api/my/tasks`, `GET /api/my/tasks/{id}`, `start`, `complete` | `service_provider` |

A verificação de perfil por rota será feita por um middleware de roles (a cargo de outro elemento da equipa). Até lá, algumas rotas ainda não verificam o perfil (ver secção 7).

## 7. Dependências e limitações

- O funcionamento depende de reservas e propriedades válidas na base de dados.
- **Autorização por perfil:** a integração do middleware de roles ainda está pendente. O teste de acesso à lista staff falha para prestadores, e o teste de cancelamento por prestador está skipped até o middleware ser aplicado. Não disponibilizar essas rotas no frontend antes dessa integração.
- **Formato das respostas:** listagem, detalhe e endpoints de escrita que devolvem uma tarefa usam Resources com envelope `data`. Cancelamento e remoção respondem `204`, sem corpo.
- Os horários por defeito, `responsible_service_provider_id` e `base_cleaning_amount` ainda não são usados pelas Tasks. `access_instructions` já é devolvido no resource do prestador.
- A desativação de uma propriedade já cancela e apaga logicamente as tasks `pending` elegíveis. O bloqueio da desativação de um prestador com tarefas abertas ainda aguarda a integração de `ProviderOpenTasks` pelo módulo Properties.
- A limpeza da reserva anterior da mesma propriedade **não é recalculada** quando um check-in entra, muda ou sai. O seu `scheduled_end_at` pode ficar desatualizado.
- Pedidos simultâneos para criar a limpeza da mesma reserva: o índice único protege contra duplicados e o segundo pedido devolve a task já criada.
- Reservas que não estejam `reserved` (por exemplo, canceladas) são tratadas como "reserva não encontrada" ao criar, atualizar ou restaurar.
- A criação da limpeza corre numa fila (`QUEUE_CONNECTION=database`): é preciso um worker (`php artisan queue:work`) a correr.
- Os campos da propriedade `default_check_in_time`, `default_check_out_time`, `responsible_service_provider_id`, `base_cleaning_amount` e `access_instructions` ainda não são usados pelas Tasks (os horários são fixos, a limpeza não é atribuída nem leva valor, e o prestador não vê as instruções de acesso).
- Na inspeção restaurada, o início passa a ser o momento do restauro.

## 8. Integração com Reservations (Booking)

### 8.1 Integração com Reservations

Reservations comunica-se com Tasks pelo `booking_id`; não envia datas nem propriedade.

| Evento em Reservations | Action de Tasks | Efeito | Estado |
|---|---|---|---|
| Reserva criada ou reimportada | `CreateCheckoutCleaningTask->execute($bookingId)` | Cria a limpeza de checkout | Ligado pelo evento `BookingImported` |
| Datas da reserva alteradas | `UpdateTask->forBooking($bookingId)` | Recalcula as datas das tasks elegíveis | Action existente; aguarda evento e listener |
| Reserva cancelada | `CancelTask->forBooking($bookingId)` | Cancela e apaga logicamente tasks `pending`; preserva `in_progress` e `completed` | Action existente; aguarda evento e listener |
| Reserva removida | `DeleteTask->forBooking($bookingId)` | Apaga logicamente tasks não protegidas, sem alterar o status | Action existente; aguarda evento e listener |

A integração de criação já está ligada. Para atualização, cancelamento e remoção, Reservations precisa definir e emitir os eventos correspondentes depois do commit. Depois, Tasks precisa criar e registar os listeners que chamam as Actions acima. Avisar as pessoas responsáveis por ambos os módulos quando forem alinhar essa integração.

Cada reserva deve gerar uma única task por tipo. Evitar reservas duplicadas no iCal continua sendo responsabilidade de Reservations.

### 8.2 Como a Task lê os dados da reserva

Um único ficheiro (`EloquentBookingDataProvider`) lê as tabelas `bookings` e `booking_sources` e devolve um `BookingSnapshot`. Só considera reservas com `status` `reserved`. Colunas usadas:

- `bookings`: `id`, `status`, `booking_source_id`, `source_check_in_at`, `source_check_out_at`, `overridden_check_in_at`, `overridden_check_out_at`
- `booking_sources`: `property_id`

As datas efetivas são as `overridden_*` quando existem e, caso contrário, as `source_*`. **Renomear estas colunas ou mudar o valor de `status` quebra o módulo Tasks**, por isso fazem parte do contrato entre os dois módulos. O próximo check-in é a reserva ativa mais próxima da mesma propriedade com check-in na data do check-out ou depois dela.

Quando o Booking passar a ser um serviço próprio, só este provider é substituído (por exemplo, por um que faça chamadas HTTP).

### 8.3 Adaptador iCal

O módulo Reservations disponibiliza um adaptador iCal que lê eventos do Airbnb e os normaliza em objetos `ReservationEvent`, agrupados num `ParseResult`. Os eventos incluem `externalUid`, `propertyId`, `channel`, as datas de check-in e check-out e o `status`. Depois de guardar as reservas, o import emite `BookingImported` para cada reserva (nova ou já existente), o que permite recuperar tasks que tenham falhado antes.

## 9. Exemplos

### 9.1 Criação da limpeza

O Booking emite `BookingImported` e o listener chama:

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

Sem próxima reserva conhecida na propriedade, a task começa às 11:30 do dia do check-out e `scheduled_end_at` fica `null` (e `is_overdue` fica sempre `false`).

### 9.3 Criação da inspeção

Pedido do adm no dia 10/10/2026 às 09:00, para uma reserva com check-in a 15/10/2026:

```http
POST /api/tasks
{ "booking_id": 12 }
```

Resultado: `type` `checkin_inspection`, `status` `pending`, `scheduled_start_at` 10/10/2026 09:00 e `scheduled_end_at` 15/10/2026 14:30.

### 9.4 Resposta de `GET /api/tasks` (staff)

```json
{
  "data": [
    {
      "id": 1,
      "booking_id": 12,
      "property": "Apartamento Centro",
      "property_address": "Rua de Exemplo, 1",
      "type": "checkout_cleaning",
      "scheduled_start_at": "2026-10-20T11:30:00.000000Z",
      "scheduled_end_at": "2026-10-22T14:30:00.000000Z",
      "is_overdue": false,
      "service_provider_id": 7,
      "service_provider": "Maria Exemplo",
      "agreed_amount": "45.00",
      "status": "pending",
      "deleted_at": null
    }
  ],
  "links": {},
  "meta": {}
}
```

Valores fictícios; `links` e `meta` são os da paginação do Laravel.

### 9.5 Resposta de `GET /api/my/tasks/{id}` (prestador)

```json
{
  "data": {
    "id": 1,
    "property": "Apartamento Centro",
    "property_address": "Rua de Exemplo, 1",
    "type": "checkout_cleaning",
    "scheduled_start_at": "2026-10-20T11:30:00.000000Z",
    "scheduled_end_at": "2026-10-22T14:30:00.000000Z",
    "is_overdue": false,
    "status": "in_progress",
    "started_at": "2026-10-20T11:45:00.000000Z",
    "completed_at": null
  }
}
```

O prestador não recebe `booking_id`, `service_provider`, `service_provider_id` nem `agreed_amount`. Valores fictícios.

## 10. Lembretes para a equipa

| Responsável | Pendência |
|---|---|
| Identity/Auth | Aplicar o middleware de roles nas rotas Tasks: `admin`/`supervisor` em `/api/tasks` e `service_provider` em `/api/my/tasks`. Depois, corrigir o teste de acesso à lista staff e reativar o teste de cancelamento que está skipped. |
| Reservations | Emitir eventos após o commit para reservas atualizadas, canceladas e removidas, e ligar esses eventos às Actions de Tasks já existentes. |
| Properties | Usar `ProviderOpenTasks` no fluxo de desativação de prestadores para verificar se existem tarefas abertas. O contrato e a implementação Eloquent já existem; falta integrá-los no fluxo de Properties. |

Estes lembretes correspondem aos comentários `TODO` nas rotas e no `AppServiceProvider`. Avisar os responsáveis quando essas integrações forem tratadas.

### Melhorias futuras

- Notas do prestador com autoria/histórico, checklist e anexos de evidência. A tarefa continua podendo ser concluída sem foto ou vídeo; media é opcional.
- Indicador de falta de media, se o frontend vier a precisar dele.
- Recalcular a limpeza da reserva anterior quando o check-in seguinte mudar.
- Considerar o fuso horário da propriedade e os horários configuráveis do imóvel.
- Integrar listagem, detalhe e execução das tarefas na interface web/mobile.