# Contrato — Zonas, disponibilidade e candidatos

Critério de proximidade entre imóveis e prestadores (zonas), horário semanal e indisponibilidades dos prestadores, e lista de candidatos para uma tarefa.

Todas as rotas exigem sessão válida (`auth:sanctum` e `access.valid`). Erros de validação devolvem `422` com os campos em `errors`.

## Zonas

Apenas `admin` e `supervisor`.

| Método | Rota | Descrição |
|---|---|---|
| `GET` | `/api/zones` | Lista por nome. `?active=1` ou `?active=0` filtra pelo estado |
| `POST` | `/api/zones` | Cria uma zona. Resposta `201` |
| `PUT` | `/api/zones/{id}` | Edita nome e descrição |
| `PATCH` | `/api/zones/{id}/activate` | Ativa |
| `PATCH` | `/api/zones/{id}/deactivate` | Desativa |

| Campo | Obrigatório | Regra |
|---|---|---|
| `name` | Sim | Até 80 caracteres, único (ao editar, a própria zona pode manter o nome) |
| `description` | Não | Até 1000 caracteres |

```json
{
  "data": {
    "id": 1,
    "name": "Porto Centro",
    "description": "Baixa e Ribeira",
    "is_active": true,
    "properties_count": 3,
    "service_providers_count": 2,
    "created_at": "2026-11-19T10:00:00+00:00"
  }
}
```

- Desativar uma zona mantém as ligações a imóveis e prestadores, mas a zona deixa de poder ser escolhida em ligações novas.
- Não há eliminação.

## Zona e localização do imóvel

Campos novos em `POST /api/properties` e `PUT /api/properties/{id}`, devolvidos em `data`:

| Campo | Tipo | Regra |
|---|---|---|
| `zone_id` | inteiro ou `null` | Tem de ser uma zona ativa |
| `latitude` | número ou `null` | Entre -90 e 90. Obrigatória se for enviada `longitude` |
| `longitude` | número ou `null` | Entre -180 e 180. Obrigatória se for enviada `latitude` |

- As coordenadas são devolvidas como texto com 6 casas decimais (ex.: `"41.140600"`).
- São uma referência fixa do imóvel; não há GPS contínuo.

## Zonas do prestador

| Método | Rota | Quem | Descrição |
|---|---|---|---|
| `GET` | `/api/service-providers/{id}/zones` | Admin, supervisor ou o próprio prestador | Zonas do prestador |
| `PUT` | `/api/service-providers/{id}/zones` | Admin e supervisor | Substitui a lista de zonas |

```json
{ "zone_ids": [1, 3] }
```

- `zone_ids` é obrigatório e pode ser vazio (remove todas as zonas).
- Cada zona tem de existir, estar ativa e aparecer uma só vez. Máximo de 50.

## Disponibilidade do prestador

Admin, supervisor ou o próprio prestador. Outro prestador recebe `403`.

| Método | Rota | Descrição |
|---|---|---|
| `GET` | `/api/service-providers/{id}/availability` | Horário semanal e indisponibilidades que ainda não terminaram |
| `PUT` | `/api/service-providers/{id}/availability` | Substitui todo o horário semanal |
| `POST` | `/api/service-providers/{id}/unavailabilities` | Cria uma indisponibilidade. Resposta `201` |
| `DELETE` | `/api/service-providers/{id}/unavailabilities/{unavailabilityId}` | Apaga. Resposta `204`; `404` se for de outro prestador |

### Horário semanal

```json
{
  "intervals": [
    { "weekday": 1, "start_time": "09:00", "end_time": "13:00" },
    { "weekday": 1, "start_time": "14:00", "end_time": "18:00" }
  ]
}
```

- `weekday`: 1 (segunda) a 7 (domingo), segundo a ISO 8601.
- Horas no formato `HH:MM`, lidas no fuso de cada imóvel. O fim tem de ser depois do início, no mesmo dia.
- Intervalos do mesmo dia não se podem sobrepor; intervalos encostados (12:00 e 12:00) são aceites.
- A lista substitui a anterior numa transação. Uma lista vazia limpa o horário. Máximo de 50 intervalos.

### Indisponibilidades

| Campo | Obrigatório | Regra |
|---|---|---|
| `starts_at` | Sim | Data e hora ISO 8601 com fuso (ex.: `2026-12-24T00:00:00+00:00`) |
| `ends_at` | Sim | Depois de `starts_at` |
| `reason` | Não | Até 120 caracteres |

- São guardadas e devolvidas em UTC.
- Não se podem sobrepor a outra indisponibilidade do mesmo prestador.

### Resposta do `GET` e do `PUT`

```json
{
  "data": {
    "service_provider_id": 4,
    "weekly": [
      { "id": 10, "weekday": 1, "start_time": "09:00", "end_time": "13:00" }
    ],
    "unavailabilities": [
      { "id": 3, "starts_at": "2026-12-24T00:00:00+00:00", "ends_at": "2026-12-27T00:00:00+00:00", "reason": "Férias" }
    ]
  }
}
```

## Candidatos de uma tarefa

`GET /api/tasks/{id}/candidates`, apenas `admin` e `supervisor`.

Lista os prestadores ativos e indica quem pode fazer a tarefa. Os prestadores desativados não aparecem. A ordem é: elegíveis primeiro, depois por nome.

Um prestador é elegível quando não tem nenhum destes motivos:

| Código | Significado |
|---|---|
| `outside_zone` | O imóvel tem zona e o prestador não trabalha nela |
| `no_schedule` | O prestador não tem horário semanal definido |
| `outside_hours` | Nenhum intervalo desse dia cobre toda a janela da tarefa, no fuso do imóvel |
| `unavailable` | Tem uma indisponibilidade que se sobrepõe à janela |
| `busy` | Tem outra tarefa pendente ou em curso que se sobrepõe à janela |

```json
{
  "data": {
    "task_id": 15,
    "property_id": 2,
    "zone_id": 1,
    "window": {
      "starts_at": "2026-12-07T11:30:00+00:00",
      "ends_at": "2026-12-07T14:30:00+00:00",
      "timezone": "Europe/Lisbon",
      "local_date": "2026-12-07",
      "weekday": 1,
      "local_start": "11:30",
      "local_end": "14:30"
    },
    "candidates": [
      {
        "service_provider_id": 4,
        "name": "Prestador Um",
        "eligible": true,
        "is_assigned": false,
        "in_zone": true,
        "reasons": [],
        "unavailability": null,
        "conflicting_task_ids": []
      }
    ]
  }
}
```

- `in_zone` é `null` quando o imóvel não tem zona; nesse caso a zona não é verificada.
- Uma janela que passa da meia-noite (no fuso do imóvel) nunca é coberta pelo horário semanal.
- O endpoint só informa: a atribuição de tarefas não foi alterada e continua a aceitar qualquer prestador ativo.

| Código | Quando |
|---|---|
| `404` | Tarefa inexistente ou eliminada |
| `422` | Tarefa concluída ou cancelada ("Só é possível procurar candidatos para tarefas pendentes ou em curso.") |
| `422` | Tarefa sem horário ("A tarefa não tem horário definido.") |

## Dados de demonstração

`php artisan db:seed` (apenas em `local` e `testing`) cria, com datas relativas ao dia em que corre:

| Cenário | Dados |
|---|---|
| Zona com equipa | Porto Centro, com o Apartamento da Foz, `prestador1` e `prestador2` |
| Zona sem equipa | Gaia, com a Casa de Gaia e nenhum prestador |
| Prestador indisponível | `prestador2`, de férias desde o dia do seed até 10 dias depois |
| Prestador desativado | `prestador3@example.test`, na zona Porto Centro, nunca aparece como candidato |
| Tarefas para testar | Uma tarefa sem prestador em cada imóvel, 6 dias depois do seed, das 11:30 às 14:30 |

O seed pode ser corrido várias vezes sem duplicar dados.
