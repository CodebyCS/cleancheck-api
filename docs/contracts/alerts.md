# Contrato de Alertas de Tarefas

Alertas de atraso para supervisores e administradores. São detetados pelo comando `alerts:detect`, que corre a cada minuto.

## Tipos

| `type` | `priority` | Quando abre | `action` |
|---|---|---|---|
| `late_start` | `medium` | A tarefa continua `pending` 30 min depois de `scheduled_start_at`. | `contact_provider` |
| `deadline_at_risk` | `high` | Faltam 30 min ou menos para `scheduled_end_at` (60 min para o check-in) e a tarefa não está concluída. | `reassign_or_help` |

Os limiares estão em `config/alerts.php`. Tarefas com mais de 24 h são ignoradas.

## Regras

- No máximo um alerta ativo por tarefa e tipo.
- O alerta resolve-se sozinho quando a condição desaparece. `resolution` diz porquê: `task_started`, `task_completed`, `task_cancelled`, `rescheduled` ou `expired`.
- Um alerta resolvido à mão (`manual`) não volta a abrir para a mesma tarefa e tipo.
- Os alertas resolvidos nunca são apagados: ficam no histórico.

## `GET /api/task-alerts` (admin e supervisor)

| Parâmetro | Valores | Omissão |
|---|---|---|
| `status` | `active`, `resolved` | `active` |
| `priority` | `medium`, `high` | todas |
| `page` | número | 1 |

Ordem: os ativos aparecem por prioridade (`high` primeiro) e, dentro da mesma prioridade, do mais antigo para o mais recente. Os resolvidos aparecem do mais recente para o mais antigo. 20 por página.

```json
{
  "data": [
    {
      "id": 7,
      "type": "deadline_at_risk",
      "priority": "high",
      "action": "reassign_or_help",
      "reason": "Faltam 30 min ou menos para o fim do prazo e a tarefa não está concluída.",
      "active": true,
      "detectedAt": "2026-11-05T14:00:00+00:00",
      "resolvedAt": null,
      "resolution": null,
      "resolutionNote": null,
      "resolvedBy": null,
      "task": {
        "id": 12,
        "type": "checkout_cleaning",
        "status": "in_progress",
        "scheduledStart": "2026-11-05T11:30:00+00:00",
        "scheduledEnd": "2026-11-05T14:30:00+00:00",
        "available": true
      },
      "property": { "id": 3, "name": "Casa do Rio" },
      "provider": { "id": 5, "name": "Ana Costa" }
    }
  ],
  "links": {},
  "meta": { "current_page": 1, "activeCount": 3, "highCount": 1 }
}
```

`activeCount` e `highCount` contam todos os alertas ativos, independentemente dos filtros. `provider` é `null` quando a tarefa não tem prestador. `task.available` é `false` quando a tarefa foi cancelada.

## `PATCH /api/task-alerts/{id}/resolve` (admin e supervisor)

```json
{ "reason": "Prestador avisou que chega às 12:30." }
```

Resposta `200`: o alerta atualizado, em `data`, com `resolution: "manual"`, `resolutionNote` e `resolvedBy`.

## Erros

| Código | Quando |
|---|---|
| `401` | Sem sessão. |
| `403` | O perfil não é admin nem supervisor. |
| `404` | O alerta não existe. |
| `409` | O alerta já está resolvido. |
| `422` | `reason` está vazio ou tem menos de 3 ou mais de 255 caracteres, ou `status`/`priority` têm um valor inválido. |