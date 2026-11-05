# Agenda e impedimentos

## Agenda

A agenda reutiliza `GET /api/tasks`, sem alterar os campos atuais nem adicionar filtros nesta entrega. O mobile agrupa as tarefas em “Hoje” e “Próximas” a partir de `scheduled_start_at`. A API ordena por `scheduled_start_at`, limita os resultados do perfil `service_provider` às tarefas atualmente atribuídas ao utilizador e devolve `[]` quando não há tarefas. Após reatribuição, o prestador anterior deixa de ver a tarefa e o novo passa a vê-la.

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
