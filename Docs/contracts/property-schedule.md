# Contrato — Horários de referência e fuso da propriedade

## Campos da propriedade

| Campo | Tipo | Regra |
|---|---|---|
| `default_check_out_time` | `HH:MM` ou `null` | Hora de saída de referência |
| `default_check_in_time` | `HH:MM` ou `null` | Hora de entrada de referência |
| `timezone` | Identificador IANA | Opcional. Por defeito `Europe/Lisbon` |

- Os três campos são enviados em `POST /api/properties` e `PUT /api/properties/{id}`, e devolvidos em `data`.
- `timezone` tem de ser um identificador válido (`Europe/Lisbon`, `Atlantic/Azores`, `Atlantic/Madeira`...). Offsets como `+01:00` são rejeitados.
- No `PUT`, se `timezone` não for enviado, mantém-se o atual.
- Erro de validação → `422` com o campo em `errors`.

## Precedência

Para calcular as janelas das tasks (ver [`Docs/contratos/tasks.md`](../contratos/tasks.md)):

| O quê | 1.º | 2.º | 3.º |
|---|---|---|---|
| **Dia** | Ajuste local da reserva (`overridden_*`) | Valor de origem (`source_*`, iCal) | — |
| **Hora** | Horário de referência da propriedade | Valor por defeito do sistema (11:30 início, 14:30 fim) | — |
| **Fuso** | `timezone` da propriedade | `Europe/Lisbon` | — |

- O dia é lido **no fuso da propriedade**: uma data às 23:30 UTC pode já ser o dia seguinte em Lisboa no horário de verão.
- A hora das datas da reserva não é usada, porque o iCal só traz datas de dia inteiro.
- As datas calculadas são guardadas em UTC.
- O ajuste local da reserva continua a mudar apenas a reserva interna. Não altera o fornecedor iCal.

| Janela | Início | Fim |
|---|---|---|
| Limpeza (`checkout_cleaning`) | Dia do check-out, hora de saída | Dia do próximo check-in, hora de entrada (ou `null`) |
| Inspeção (`checkin_inspection`) | Momento da criação | Dia do check-in, hora de entrada |

## Exemplos

Propriedade com saída 11:00, entrada 16:00 e fuso `Europe/Lisbon`:

| Reserva | Limpeza (UTC) | Equivale em Lisboa |
|---|---|---|
| Check-out 20/10/2026, próximo check-in 21/10/2026 | 20/10 10:00 → 21/10 15:00 | 11:00 → 16:00 (verão, UTC+1) |
| Check-out 20/12/2026, sem próximo check-in | 20/12 11:00 → `null` | 11:00 (inverno, UTC+0) |
| Check-out de origem 05/12/2026, ajustado para 06/12/2026 | começa 06/12 11:00 | O ajuste local ganha à origem |

Propriedade sem horas de referência: 11:30 e 14:30 no fuso da propriedade.

Valores fictícios. As regras estão cobertas pelos testes `PropertyTimezoneTest`, `CleaningSchedulePropertyHoursTest` e `PropertyReferenceHoursTest`.
