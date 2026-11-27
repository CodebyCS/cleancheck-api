# Contrato — Tarifas e lançamentos de serviço

Fixa o valor de cada serviço elegível e garante um único lançamento por tarefa. Serve de base ao apuramento mensal; não representa pagamentos realizados.

Todas as rotas exigem sessão válida (`auth:sanctum` e `access.valid`) e o perfil `admin` ou `supervisor`, exceto o resumo mensal do prestador. O prestador recebe `403` nas restantes. Erros de validação devolvem `422` com os campos em `errors`.

## Tarifas

| Método | Rota | Descrição |
|---|---|---|
| `GET` | `/api/rates` | Histórico de tarifas, da mais recente para a mais antiga |
| `POST` | `/api/rates` | Cria uma tarifa. Resposta `201` |

Filtros do `GET`: `?task_type=` e `?property_id=` (id de um imóvel, ou `general` para ver só as tarifas gerais).

| Campo | Obrigatório | Regra |
|---|---|---|
| `property_id` | Não | Imóvel existente. Vazio significa tarifa geral, usada quando o imóvel não tem tarifa própria |
| `task_type` | Sim | `checkout_cleaning` ou `checkin_inspection` |
| `amount` | Sim | Número entre 0 e 99999999.99, com até 2 casas decimais |
| `currency` | Não | Código de 3 letras (ISO 4217). É convertido para maiúsculas. Por defeito `EUR` |
| `valid_from` | Sim | Data `AAAA-MM-DD`: primeiro dia do serviço (data local do imóvel) em que a tarifa se aplica |

```json
{
  "data": {
    "id": 3,
    "property_id": null,
    "task_type": "checkout_cleaning",
    "amount": "45.00",
    "currency": "EUR",
    "valid_from": "2026-12-01",
    "created_by_user_id": 1,
    "created_at": "2026-11-21T20:30:00+00:00"
  }
}
```

- As tarifas não se editam nem se apagam. Para mudar o valor cria-se uma tarifa nova com outra `valid_from`; as anteriores ficam no histórico.
- Uma tarifa nova nunca altera lançamentos já criados.

## Valor aplicado a um serviço

Quando um lançamento é criado, o valor é escolhido por esta ordem:

| Ordem | Origem (`amount_source`) | Moeda |
|---|---|---|
| 1 | `task_agreed_amount`: valor acordado na tarefa (`agreed_amount`) | A da tarifa em vigor, ou `EUR` |
| 2 | `rate`: tarifa em vigor na data do serviço; a do imóvel antes da geral, e entre várias a de `valid_from` mais recente | A da tarifa |
| 3 | `property_base`: valor base de limpeza do imóvel (`base_cleaning_amount`) | `EUR` |

Se nenhum existir, não é criado lançamento (fica registado no log e a tarefa aparece em `tasks_without_entry`). Nunca é gravado um valor estimado.

## Lançamento único

Um serviço é elegível quando a tarefa está concluída (`completed`), não foi eliminada e tem prestador.

- O lançamento é criado quando a tarefa passa a `completed`, pela conclusão do prestador (`POST /api/my/tasks/{id}/complete`) ou da gestão (`POST /api/tasks/{id}/complete`). Corre depois de a transação ser gravada.
- `service_entries.task_id` é único: repetir a conclusão, o pedido ou o processamento nunca cria um segundo lançamento.
- O lançamento guarda uma cópia do prestador, do imóvel, do tipo, da data do serviço, do valor, da moeda e da origem. Alterações posteriores à tarifa, ao valor base do imóvel ou ao valor acordado da tarefa não o alteram.
- A data do serviço (`service_date`) é o dia do início agendado no fuso do imóvel.
- Implementação: observador do modelo `Task` no módulo Billing (`TaskCompletionObserver`). O módulo Tasks não foi alterado.

### Repetir a criação

`POST /api/tasks/{id}/service-entry` tenta criar o lançamento de uma tarefa concluída, por exemplo depois de criar a tarifa que faltava.

| Código | Quando |
|---|---|
| `201` | O lançamento foi criado |
| `200` | O lançamento já existia; é devolvido sem alterações |
| `404` | Tarefa inexistente ou eliminada |
| `422` | Tarefa não elegível ou sem valor definido ("A tarefa não é elegível ou não tem valor definido (valor combinado, tarifa ou valor base).") |

## Conferência

`GET /api/service-entries`

| Filtro | Regra |
|---|---|
| `month` | `AAAA-MM`; filtra pela data do serviço |
| `service_provider_id` | Id do prestador |
| `property_id` | Id do imóvel |
| `per_page` | 1 a 100, por defeito 50 |

```json
{
  "data": [
    {
      "id": 12,
      "task_id": 40,
      "service_provider_id": 4,
      "service_provider_name": "Prestador Um",
      "property_id": 2,
      "property_name": "Apartamento da Foz",
      "task_type": "checkout_cleaning",
      "service_date": "2026-12-10",
      "amount": "45.00",
      "adjustments_total": "-5.00",
      "final_amount": "40.00",
      "currency": "EUR",
      "amount_source": "rate",
      "rate_id": 3,
      "created_at": "2026-12-10T15:02:11+00:00"
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": null },
  "meta": {
    "current_page": 1,
    "per_page": 50,
    "total": 1,
    "totals": [
      {
        "currency": "EUR",
        "entries": 1,
        "original_total": "45.00",
        "adjustments_total": "-5.00",
        "total": "40.00"
      }
    ],
    "tasks_without_entry": [41]
  }
}
```

- `amount` é o valor original do lançamento e nunca muda. `final_amount` é o valor original mais a soma dos ajustes.
- `meta.totals` soma os lançamentos filtrados, por moeda. `total` usa o valor final; `original_total` e `adjustments_total` mostram as parcelas. Moedas diferentes nunca são somadas entre si.
- `meta.tasks_without_entry` lista até 100 tarefas concluídas, com prestador e sem lançamento, com os mesmos filtros. O mês usa o início agendado no fuso do imóvel, tal como a `service_date` dos lançamentos.
- Os lançamentos são ordenados por data do serviço, do mais recente para o mais antigo.

### Detalhe de conferência

`GET /api/service-entries/{id}` devolve o lançamento no mesmo formato da lista, com o histórico de ajustes do mais antigo para o mais recente. Lançamento inexistente devolve `404`.

```json
{
  "data": {
    "id": 12,
    "amount": "45.00",
    "adjustments_total": "-5.00",
    "final_amount": "40.00",
    "currency": "EUR",
    "adjustments": [
      {
        "id": 3,
        "service_entry_id": 12,
        "amount": "-5.00",
        "reason": "Serviço incompleto",
        "created_by": { "id": 1, "name": "Admin" },
        "created_at": "2026-12-11T09:30:00+00:00"
      }
    ]
  }
}
```

(Campos restantes iguais aos da lista.)

## Ajustes

`POST /api/service-entries/{id}/adjustments` regista uma correção ao lançamento sem o alterar nem apagar. Resposta `201`.

| Campo | Obrigatório | Regra |
|---|---|---|
| `amount` | Sim | Diferença com sinal, na moeda do lançamento: negativo reduz, positivo aumenta. Até 2 casas decimais, diferente de 0 |
| `reason` | Sim | Texto até 500 caracteres |

```json
{
  "data": {
    "id": 3,
    "service_entry_id": 12,
    "amount": "-5.00",
    "reason": "Serviço incompleto",
    "created_by": { "id": 1, "name": "Admin" },
    "created_at": "2026-12-11T09:30:00+00:00"
  },
  "meta": {
    "currency": "EUR",
    "original_amount": "45.00",
    "adjustments_total": "-5.00",
    "final_amount": "40.00"
  }
}
```

| Código | Quando |
|---|---|
| `201` | Ajuste registado |
| `404` | Lançamento inexistente |
| `422` | Campos inválidos, ou ajuste que deixaria o valor final negativo ("O ajuste deixaria o valor final do lançamento negativo.") |

- Os ajustes não se editam nem se apagam. Um ajuste errado corrige-se com outro ajuste.
- O valor final pode chegar a `0.00`, mas nunca fica negativo.

## Resumo mensal do prestador

`GET /api/my/service-entries/summary?month=AAAA-MM`, só para o perfil `service_provider`. Sem `month` usa o mês atual. Mês inválido devolve `422`; admin e supervisor recebem `403`.

```json
{
  "data": {
    "month": "2026-12",
    "totals": [
      {
        "currency": "EUR",
        "entries": 2,
        "original_total": "75.00",
        "adjustments_total": "-5.00",
        "total": "70.00"
      }
    ],
    "entries": [
      {
        "id": 12,
        "task_id": 40,
        "property_name": "Apartamento da Foz",
        "task_type": "checkout_cleaning",
        "service_date": "2026-12-10",
        "amount": "45.00",
        "adjustments_total": "-5.00",
        "final_amount": "40.00",
        "currency": "EUR",
        "adjustments": [
          { "amount": "-5.00", "reason": "Serviço incompleto", "created_at": "2026-12-11T09:30:00+00:00" }
        ]
      }
    ]
  }
}
```

- Só inclui os lançamentos do prestador com sessão iniciada.
- Os lançamentos são ordenados por data do serviço, do mais antigo para o mais recente.
- Nos ajustes o prestador vê o valor, o motivo e a data, mas não quem os registou.
