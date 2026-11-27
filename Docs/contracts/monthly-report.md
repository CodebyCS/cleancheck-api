# Contrato — Relatório mensal por prestador

Apuramento mensal das limpezas e valores por prestador, calculado a partir dos lançamentos de serviço (ver `rates-service-entries.md`). O valor de cada serviço é o valor final do lançamento: valor original mais ajustes.

Todas as rotas exigem sessão válida (`auth:sanctum` e `access.valid`) e o perfil `admin` ou `supervisor`. O prestador recebe `403`; o seu próprio resumo está em `GET /api/my/service-entries/summary`.

## Apuramento e pagamento

- `status` é sempre `"apurado"`: o valor foi calculado a partir dos serviços concluídos e dos ajustes.
- O sistema não regista pagamentos, por isso o relatório não tem estado de pagamento. "Apurado" não significa pago nem por pagar.

## Consultar

`GET /api/reports/monthly?month=AAAA-MM&service_provider_id=`

| Parâmetro | Obrigatório | Regra |
|---|---|---|
| `month` | Não | Formato `AAAA-MM`. Por defeito, o mês atual |
| `service_provider_id` | Não | Prestador existente. Sem ele, inclui todos os prestadores com serviços no mês |

```json
{
  "data": {
    "month": "2026-12",
    "period": { "from": "2026-12-01", "to": "2026-12-31" },
    "status": "apurado",
    "totals": [
      { "currency": "EUR", "services": 3, "original_total": "120.50", "adjustments_total": "-5.25", "total": "115.25" }
    ],
    "providers": [
      {
        "service_provider_id": 4,
        "name": "Ana Costa",
        "totals": [
          { "currency": "EUR", "services": 2, "original_total": "75.50", "adjustments_total": "-5.25", "total": "70.25" }
        ],
        "entries": [
          {
            "id": 12,
            "task_id": 40,
            "service_date": "2026-12-10",
            "property_id": 1,
            "property_name": "Apartamento da Foz",
            "task_type": "checkout_cleaning",
            "amount_source": "rate",
            "amount": "45.50",
            "adjustments_total": "-5.25",
            "final_amount": "40.25",
            "currency": "EUR"
          }
        ]
      }
    ]
  }
}
```

- O mês é decidido pela `service_date` do lançamento, que é a data local da hora prevista no fuso do imóvel.
- `providers` vem ordenado por nome; `entries` por data do serviço.
- `totals` existe por moeda, no geral e em cada prestador. Moedas diferentes nunca são somadas entre si.
- A soma de `final_amount` das linhas é igual ao `total` do prestador, e a soma dos prestadores é igual ao `total` geral. As contas são feitas em cêntimos.
- O valor de cada serviço fica fixado no lançamento: alterar uma tarifa depois não muda o relatório. As correções são feitas com ajustes.
- Mês sem serviços: `totals` e `providers` vazios.

| Código | Quando |
|---|---|
| `200` | Relatório devolvido |
| `403` | Perfil sem permissão |
| `422` | `month` fora do formato `AAAA-MM` ou prestador inexistente |

## Exportar em CSV

`GET /api/reports/monthly/export?month=AAAA-MM&service_provider_id=` aceita os mesmos filtros e devolve o ficheiro `relatorio-mensal-AAAA-MM.csv`.

- Preparado para o Excel em português: separador `;`, decimais com vírgula, datas `dd/mm/aaaa` e UTF-8 com BOM.
- Colunas: Prestador, Data, Imóvel, Tipo de tarefa, Origem do valor, Valor original, Ajustes, Valor final, Moeda, Estado.
- Uma linha por serviço; depois uma linha "Total <prestador>" por moeda e, no fim, "Total geral" por moeda.
- Mês sem serviços: só a linha de cabeçalho.

```
Prestador;Data;Imóvel;"Tipo de tarefa";"Origem do valor";"Valor original";Ajustes;"Valor final";Moeda;Estado
"Ana Costa";10/12/2026;"Apartamento da Foz";"Limpeza de checkout";Tarifa;45,50;-5,25;40,25;EUR;apurado
"Total Ana Costa";;;"2 serviços";;75,50;-5,25;70,25;EUR;apurado
"Total geral";;;"3 serviços";;120,50;-5,25;115,25;EUR;apurado
```
