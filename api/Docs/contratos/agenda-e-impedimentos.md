# Agenda e impedimentos

## Agenda

O mobile consulta a agenda do prestador autenticado através de `GET /api/my/tasks`. `GET /api/tasks` é a rota de gestão para Administradores e Supervisores; não é a rota do prestador. Ambos os endpoints exigem autenticação e conta ativa.

`GET /api/my/tasks` devolve uma coleção paginada Laravel: os itens estão em `data`, com informação de paginação em `links` e `meta`. Quando o prestador não tem tarefas, `data` é um array vazio (`[]`), mantendo o invólucro paginado. Cada item segue o recurso do prestador e inclui `id`, `property`, `property_address`, `access_instructions`, `type`, `scheduled_start_at`, `scheduled_end_at`, `is_overdue`, `status`, `started_at`, `completed_at` e `provider_notes`.

A lista do prestador inclui apenas tarefas atualmente atribuídas ao seu perfil. O detalhe `GET /api/my/tasks/{task}` aplica a mesma autorização e responde `404` se a tarefa não existir ou já não estiver atribuída ao prestador. Após reatribuição, o prestador anterior deixa de a receber e de lhe aceder; o novo prestador passa a recebê-la e a aceder-lhe.

A ordenação é ascendente por `scheduled_start_at`, com `id` como desempate. A lista aceita os filtros opcionais comuns `from`, `to`, `property_id`, `status`, `type` e `per_page`; `from` e `to` filtram a data de início agendada. `per_page` aceita de 1 a 100 itens e o padrão é 15. O filtro `deleted` não é permitido para prestadores. O mobile agrupa os itens em “Hoje” e “Próximas” usando `scheduled_start_at`, sem alterar o formato da resposta da API.

## Registar impedimento

`POST /api/tasks/{task}/impediments` exige autenticação, conta ativa e perfil `service_provider`. Só o prestador atualmente atribuído pode criar o registo.

Pedido:

```json
{
  "reason": "equipment_failure",
  "description": "Equipamento avariado.",
  "idempotency_key": "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"
}
```

`reason` é obrigatório e tem limite de 120 caracteres. `description` é opcional, aceita `null` e tem limite de 2000 caracteres. `idempotency_key` é um UUID obrigatório, que o cliente deve reutilizar nos retries do mesmo envio e renovar para um impedimento posterior.

Uma criação nova responde `201 Created`:

```json
{
  "id": 12,
  "task_id": 7,
  "user_id": 3,
  "reason": "equipment_failure",
  "description": "Equipamento avariado.",
  "created_at": "2026-11-04T12:00:00.000000Z"
}
```

Repetir a mesma chave, tarefa, autor e conteúdo responde `200 OK` com o registo original. Reutilizar a chave com conteúdo diferente responde `409 Conflict`. A chave é única por tarefa e autor; uma chave nova pode registar outro impedimento.

| HTTP | Comportamento |
| --- | --- |
| `201` | Impedimento criado. |
| `200` | Retry idêntico; devolve o registo já criado. |
| `401` | Sessão ausente/inválida ou conta inativa. |
| `403` | Utilizador sem perfil de prestador ou que já não está atribuído à tarefa. |
| `404` | Tarefa inexistente. |
| `409` | Chave de idempotência reutilizada com conteúdo diferente. |
| `422` | Campos inválidos; a resposta Laravel contém `message` e `errors` por campo. |

O impedimento guarda a tarefa, o autor, o motivo, a descrição, a chave de idempotência e timestamps. Não altera o estado da tarefa, a atribuição atual ou o histórico de atribuições. Os vínculos impedem a eliminação física da tarefa ou do autor enquanto houver impedimentos. Não existe endpoint de consulta nesta entrega.

## Reatribuição e estados

Os impedimentos anteriores mantêm o autor original depois de uma reatribuição. O prestador anterior não pode criar novos impedimentos; o atual pode fazê-lo. A API ainda não bloqueia `completed` nem `cancelled`: estes estados são atualmente aceites quando a atribuição continua válida. A política definitiva precisa de ser confirmada pela equipa. `reason` é texto livre; uma lista controlada depende de acordo com Carlos e Guilherme.
