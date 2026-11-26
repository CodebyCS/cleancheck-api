# Contrato — Checklists versionadas

Define a checklist genérica de cada tipo de tarefa e o seu preenchimento pelo prestador, item a item. Cada tarefa fica com a versão da checklist em vigor quando foi criada; versões posteriores não a alteram.

Todas as rotas exigem sessão válida (`auth:sanctum` e `access.valid`). Erros de validação devolvem `422` com os campos em `errors`.

## Versões da checklist (admin e supervisor)

| Método | Rota | Descrição |
|---|---|---|
| `GET` | `/api/checklist-templates` | Todas as versões, por tipo de tarefa e da mais recente para a mais antiga |
| `GET` | `/api/checklist-templates/{id}` | Uma versão com os seus itens |
| `POST` | `/api/checklist-templates` | Publica a versão seguinte para o tipo de tarefa. Resposta `201` |

Filtros do `GET`: `?task_type=` e `?current=1` (só a versão em vigor de cada tipo). Na listagem cada versão traz `items_count`; os itens só vêm no detalhe e na criação. O prestador recebe `403`.

| Campo | Obrigatório | Regra |
|---|---|---|
| `task_type` | Sim | `checkout_cleaning` ou `checkin_inspection` |
| `name` | Sim | Texto até 120 caracteres |
| `items` | Sim | Lista de 1 a 100 itens. A ordem da lista é a ordem mostrada ao prestador |
| `items.*.label` | Sim | Texto até 200 caracteres. Não pode repetir outro item da mesma versão (sem distinguir maiúsculas) |
| `items.*.required` | Não | `true` ou `false`. Por defeito `true` |

```json
{
  "data": {
    "id": 4,
    "task_type": "checkout_cleaning",
    "version": 2,
    "name": "Limpeza após checkout",
    "is_current": true,
    "items": [
      { "id": 11, "position": 1, "label": "Fazer as camas", "required": true },
      { "id": 12, "position": 2, "label": "Repor amenities", "required": false }
    ],
    "created_by_user_id": 1,
    "created_at": "2026-11-25T18:00:00+00:00"
  }
}
```

- As versões não se editam nem se apagam. Para mudar a checklist publica-se uma versão nova; a numeração é independente por tipo de tarefa.
- `is_current` indica a versão mais recente do tipo, que é a usada pelas tarefas novas.

## Versão de cada tarefa

- Quando uma tarefa é criada fica ligada à versão em vigor do seu tipo. Implementação: observador do modelo `Task` no módulo Checklists (`AttachChecklistOnTaskCreated`). O módulo Tasks não foi alterado.
- Se ainda não existir checklist para o tipo, a tarefa é criada normalmente, sem checklist.
- Uma tarefa pendente ou em curso criada antes de existir checklist recebe a versão em vigor na primeira consulta da checklist. Tarefas concluídas ou canceladas nunca recebem checklist depois de criadas.

## Consultar a checklist de uma tarefa

| Método | Rota | Perfil |
|---|---|---|
| `GET` | `/api/tasks/{id}/checklist` | admin e supervisor; inclui tarefas eliminadas |
| `GET` | `/api/my/tasks/{id}/checklist` | service_provider; só as suas tarefas, as restantes devolvem `404` |

Sem checklist para a tarefa a resposta é `200` com `{ "data": null }`.

```json
{
  "data": {
    "task_id": 31,
    "checklist_template_id": 3,
    "name": "Limpeza após checkout",
    "version": 1,
    "required_total": 2,
    "required_done": 1,
    "completed": false,
    "items": [
      {
        "id": 8,
        "position": 1,
        "label": "Fazer as camas",
        "required": true,
        "done": true,
        "done_at": "2026-11-26T10:15:00+00:00",
        "done_by": { "id": 7, "name": "Ana Prestadora" }
      },
      {
        "id": 9,
        "position": 2,
        "label": "Lavar a casa de banho",
        "required": true,
        "done": false,
        "done_at": null,
        "done_by": null
      }
    ]
  }
}
```

- `required_total` e `required_done` contam só os itens obrigatórios. `completed` é `true` quando todos os obrigatórios estão feitos.

## Marcar um item (service_provider)

`PATCH /api/my/tasks/{id}/checklist/items/{itemId}`

```json
{ "done": true }
```

| Código | Quando |
|---|---|
| `200` | Item atualizado. Devolve a checklist completa, no formato da consulta |
| `403` | Perfil sem permissão |
| `404` | Tarefa inexistente ou de outro prestador, tarefa sem checklist, ou item que não pertence à versão da tarefa |
| `422` | `done` em falta ou não booleano, ou tarefa que não está em curso ("A checklist só pode ser preenchida com a tarefa em curso.") |

- Os itens marcam-se um a um; não existe "marcar todos".
- `done: true` guarda a data e o utilizador; `done: false` limpa os dois.
- A checklist não bloqueia a conclusão da tarefa: `POST /api/my/tasks/{id}/complete` e `POST /api/tasks/{id}/complete` funcionam com a checklist incompleta.
