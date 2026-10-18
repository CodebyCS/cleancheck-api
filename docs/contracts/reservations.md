# Contrato — Reservas iCal

## Pedido

Igual nas duas operações. Autenticado (`auth:sanctum`) · `multipart/form-data`

| Campo | Tipo | Obrigatório |
|---|---|---|
| `file` | Ficheiro `.ics` | Sim |
| `property_id` | Inteiro positivo (imóvel já registado) | Sim |

O `.ics` não identifica o imóvel: a associação vem sempre do `property_id`.

## Mapeamento

| iCal | Campo | Regra |
|---|---|---|
| `UID` | `externalUid` | Obrigatório |
| `DTSTART` | `checkIn` | `YYYY-MM-DD` |
| `DTEND` | `checkOut` | `YYYY-MM-DD`, dia de saída (exclusivo) |
| `SUMMARY` | `status` | Ver classificação |
| `SEQUENCE` | `sequence` | Opcional, `null` se ausente |
| `DESCRIPTION` | `reservationUrl` | Só o URL após `Reservation URL:` |

- Só datas de dia inteiro (`VALUE=DATE`).
- `nights` = `checkOut − checkIn`.
- Outras propriedades (`DTSTAMP`, `X-*`) são ignoradas.
- A `DESCRIPTION` original e o telefone não são devolvidos.

## Classificação

| `SUMMARY` | `status` |
|---|---|
| `Reserved` | `reserved` |
| `Airbnb (Not available)` | `blocked` |
| Outro ou ausente | `unknown` + aviso `UNKNOWN_SUMMARY` |

## Erros `422` — ficheiro rejeitado

```json
{ "error": { "code": "INVALID_ICAL", "message": "O ficheiro não é um VCALENDAR válido." } }
```

| Código | Quando |
|---|---|
| `NO_FILE` | Pedido sem ficheiro |
| `INVALID_FILE_TYPE` | Extensão diferente de `.ics` |
| `PROPERTY_NOT_FOUND` | `property_id` em falta ou inválido |
| `EMPTY_FILE` | Ficheiro vazio |
| `INVALID_ICAL` | Não é um `VCALENDAR` válido |

Sem autenticação → `401`.

## Avisos — evento ignorado ou degradado

| Código | Quando | Efeito |
|---|---|---|
| `MISSING_UID` | Sem `UID` | Ignorado (`uid: null`) |
| `DUPLICATE_UID` | `UID` repetido | Mantém o primeiro |
| `MISSING_DATES` | Falta `DTSTART` ou `DTEND` | Ignorado |
| `UNSUPPORTED_DATETIME` | Data com hora | Ignorado |
| `INVALID_DATE_RANGE` | `DTEND` ≤ `DTSTART` | Ignorado |
| `UNKNOWN_SUMMARY` | `SUMMARY` não reconhecido | Mantido como `unknown` |

---

## Leitura

`POST /api/reservations/ical/read`

Lê o ficheiro e devolve os eventos, sem gravar nada.

### Resposta `200`

```json
{
  "propertyId": 1,
  "channel": "airbnb",
  "events": [
    {
      "externalUid": "fict-0001@cleancheck.test",
      "checkIn": "2026-10-10",
      "checkOut": "2026-10-14",
      "nights": 4,
      "status": "reserved",
      "sequence": null,
      "reservationUrl": "https://www.airbnb.com/hosting/reservations/details/HMFICT0001",
      "rawSummary": "Reserved"
    }
  ],
  "warnings": [
    {
      "uid": "fict-inv-0006@cleancheck.test",
      "code": "UNKNOWN_SUMMARY",
      "message": "SUMMARY não reconhecido: 'Manutencao'. Evento classificado como unknown."
    }
  ]
}
```

Ficheiro válido sem eventos → `events: []`.

---

## Importação

`POST /api/reservations/ical/import`

Grava as reservas do ficheiro. Importar o mesmo ficheiro outra vez não cria duplicados.

### Resposta `200`

```json
{
  "propertyId": 1,
  "channel": "airbnb",
  "created": [
    {
      "bookingId": 1,
      "externalUid": "fict-0001@cleancheck.test",
      "checkIn": "2026-10-10",
      "checkOut": "2026-10-14",
      "nights": 4,
      "status": "reserved"
    }
  ],
  "existing": [],
  "ambiguous": [
    {
      "externalUid": "fict-inv-0006@cleancheck.test",
      "checkIn": "2026-12-20",
      "checkOut": "2026-12-22",
      "status": "unknown",
      "rawSummary": "Manutencao"
    }
  ],
  "skipped": [],
  "warnings": []
}
```

### Destino de cada evento

| Evento | Gravado? | Lista |
|---|---|---|
| `reserved`, novo | Sim | `created` |
| `reserved`, já importado (mesma fonte + `externalUid`) | Não é alterado | `existing` |
| `unknown` | Não | `ambiguous` |
| `blocked` | Não | `skipped` |
| Inválido (ver avisos) | Não | `warnings` |

- A fonte é identificada pelo imóvel + canal e é reutilizada entre importações.
- Ficheiro rejeitado (`422`): nada é gravado.