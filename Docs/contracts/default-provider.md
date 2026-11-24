# Contrato — Prestador padrão do imóvel

O imóvel pode ter um prestador padrão. As tarefas novas desse imóvel ficam atribuídas a ele; a gestão vê as tarefas que precisam de atenção e troca o prestador manualmente quando for preciso.

## Prestador padrão no imóvel

Campo `responsible_service_provider_id` em `POST /api/properties` e `PUT /api/properties/{id}`, devolvido em `data`.

| Regra | Comportamento |
|---|---|
| Valor | Id de um prestador ou `null` (sem prestador padrão) |
| Integridade | Chave estrangeira para `service_providers` |
| Validação | O prestador tem de existir e ter a conta ativa; caso contrário `422` com "O prestador responsável não existe ou está inativo." |
| Quem altera | Apenas `admin` e `supervisor` |

## Atribuição das tarefas novas

Quando uma tarefa é criada sem prestador, a API consulta o prestador padrão do imóvel:

| Situação | Resultado |
|---|---|
| Prestador padrão ativo | A tarefa fica atribuída a ele |
| Prestador padrão inativo | A tarefa fica sem prestador. Nenhum outro é escolhido automaticamente |
| Imóvel sem prestador padrão | A tarefa fica sem prestador |
| A tarefa já vem com prestador | Mantém o prestador recebido |

- Aplica-se às limpezas criadas a partir das reservas (`checkout_cleaning`) e às inspeções criadas pela gestão (`checkin_inspection`).
- Só afeta tarefas novas. Mudar o prestador padrão do imóvel não altera as tarefas que já existem; essas mantêm o responsável até uma troca manual.
- A atribuição automática não cria entrada em `task_assignment_history`, porque não há utilizador a fazer a alteração. O histórico regista as trocas manuais.
- Implementação: observador do modelo `Task` no módulo Properties (`AssignDefaultProviderOnTaskCreating`). O módulo Tasks não foi alterado.

## Troca manual

Continua a ser feita em `PATCH /api/tasks/{id}` com `service_provider_id`, apenas por `admin` e `supervisor` (ver [`tasks.md`](tasks.md)). Cada troca fica em `task_assignment_history` com o prestador anterior, o novo e o utilizador que a fez. O prestador recebe `403`.

## Avisos para a gestão

`GET /api/tasks/assignment-warnings`, apenas `admin` e `supervisor`. Filtro opcional `?property_id=`.

Lista as tarefas abertas (`pending` ou `in_progress`, não eliminadas) que precisam de atenção, ordenadas pelo início agendado. Os avisos são calculados em cada pedido e desaparecem quando a causa é resolvida. O endpoint não atribui nem troca prestadores.

| Código | Quando |
|---|---|
| `no_default_provider` | Tarefa sem prestador e imóvel sem prestador padrão |
| `default_provider_inactive` | Tarefa sem prestador porque o prestador padrão está inativo |
| `unassigned` | Tarefa sem prestador, mas o imóvel já tem prestador padrão ativo (por exemplo, tarefa criada antes) |
| `provider_inactive` | O prestador atribuído foi desativado depois da atribuição |
| `schedule_conflict` | O prestador atribuído tem outra tarefa aberta com janela sobreposta; inclui `conflicting_task_ids` |

```json
{
  "data": [
    {
      "task_id": 21,
      "type": "checkout_cleaning",
      "status": "pending",
      "scheduled_start_at": "2026-12-07T11:30:00+00:00",
      "scheduled_end_at": "2026-12-07T14:30:00+00:00",
      "property_id": 3,
      "property_name": "Apartamento da Foz",
      "service_provider_id": 4,
      "default_service_provider_id": 4,
      "warnings": [
        { "code": "schedule_conflict", "conflicting_task_ids": [22] }
      ]
    }
  ]
}
```

- Uma tarefa sem fim agendado conta como um momento único no início, para efeitos de conflito.
- `property_id` não numérico responde `422`.
