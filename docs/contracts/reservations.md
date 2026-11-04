# Contrato — Reservas iCal

## Pedido

Igual nas duas operações. Conta ativa com perfil `admin` ou `supervisor` · `multipart/form-data`

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
- Eventos recorrentes (`RRULE`) não são expandidos: só é lida a primeira ocorrência.

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

Sem autenticação ou conta inativa → `401`. Perfil sem permissão (`service_provider`) → `403`.

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

- A fonte (tipo `file`) é identificada pelo imóvel + canal e é reutilizada entre importações.
- Cada importação fica registada em `sync_runs` (ver "Registo de tentativas").
- As reservas são gravadas numa só transação: se a gravação falhar, nada desta importação fica gravado e os dados anteriores mantêm-se.

---

## Evento `BookingImported`

`App\Modules\Reservations\Events\BookingImported`

| Propriedade | Tipo |
|---|---|
| `bookingId` | `int` |

- Disparado uma vez por reserva `created` e `existing`, depois de a importação terminar com sucesso.
- Não é disparado se a importação falhar.
- As reservas `existing` são anunciadas em cada importação: reimportar o ficheiro permite recuperar uma tarefa que não foi criada.
- A criação de tarefas é feita por quem ouve o evento (módulo Tasks) e não altera a resposta da importação.

---

## Fontes URL

Conta ativa com perfil `admin` ou `supervisor` · JSON. Perfil sem permissão (`service_provider`) → `403`. Sem autenticação → `401`.

### Listar

`GET /api/properties/{property}/booking-sources`

```json
{
  "data": [
    {
      "id": 3,
      "propertyId": 1,
      "channel": "airbnb",
      "type": "url",
      "url": "https://www.airbnb.com/calendar/ical/123456.ics?s=fict",
      "isActive": true
    }
  ]
}
```

- Inclui as fontes criadas por importação de ficheiro (`type: "file"`, `url: null`).
- Imóvel inexistente → `404`.

### Registar

`POST /api/properties/{property}/booking-sources`

| Campo | Regra |
|---|---|
| `url` | Obrigatório · `https://` · até 2048 caracteres · único no imóvel |

- Resposta `201` com a fonte em `data`.
- `channel` (`airbnb`) e `type` (`url`) são definidos pelo servidor.
- URL inválido ou repetido → `422` no formato de validação do Laravel (`{ "message", "errors": { "url": [...] } }`).
- O URL de exportação do Airbnb contém um token privado: não deve ser partilhado nem guardado em ficheiros do repositório.

### Sincronizar

`POST /api/booking-sources/{bookingSource}/sync`

Descarrega o calendário agora e importa as reservas.

- Resposta `200` no mesmo formato da Importação (`created`, `existing`, `ambiguous`, `skipped`, `warnings`).
- Aplicam-se as mesmas regras: "Destino de cada evento" e evento `BookingImported`.
- Sincronizar o mesmo calendário outra vez não cria duplicados: as reservas aparecem em `existing`.

| Estado | Código | Quando |
|---|---|---|
| `422` | `URL_NOT_ALLOWED` | Não é `https` na porta 443, tem credenciais no URL, aponta para uma rede privada ou local (também depois de um redirecionamento) ou tem mais de 3 redirecionamentos |
| `422` | `FETCH_FAILED` | Servidor não encontrado, ligação recusada, tempo esgotado ou estado HTTP diferente de 2xx |
| `422` | `FEED_TOO_LARGE` | Calendário com mais de 2 MB |
| `422` | `EMPTY_FILE` / `INVALID_ICAL` | O conteúdo descarregado não é um calendário válido |
| `422` | `SOURCE_NOT_SYNCABLE` | Fonte do tipo `file` ou inativa |
| `409` | `SYNC_IN_PROGRESS` | A fonte já está a ser sincronizada |
| `404` | — | Fonte inexistente |

As mensagens de erro indicam o servidor, nunca o URL completo.

### Limites do download

| Regra | Valor |
|---|---|
| Protocolo | Só `https`, porta 443 |
| Destino | Só IPs públicos, verificados em cada redirecionamento; a ligação é feita ao IP verificado |
| Redirecionamentos | Máximo 3 |
| Tempo | 5 s para ligar, 15 s no total |
| Tamanho | Máximo 2 MB |

### Registo de tentativas (`sync_runs`)

- Cada sincronização (manual ou automática) cria uma linha **antes** do download: `running` → `success` ou `failed`.
- `success` grava `created_count`. `failed` grava `error_message`.
- Uma sincronização falhada não altera as reservas já gravadas e não dispara eventos.
- A mesma fonte nunca é sincronizada duas vezes ao mesmo tempo. Uma tentativa recusada por isso (`409`) não cria linha.

### Sincronização automática

- O comando `php artisan reservations:sync` põe na fila um job por cada fonte URL ativa.
- Está agendado a cada 30 minutos, sem sobreposição.
- `php artisan reservations:sync --now` sincroniza no próprio terminal, sem fila.
- Falhas ficam em `sync_runs` e no log (`iCal sync failed`, com o id da fonte e o código, sem o URL). O ciclo seguinte tenta outra vez.

Para correr:

| Ambiente | Agendador | Fila |
|---|---|---|
| Desenvolvimento | `php artisan schedule:work` | `php artisan queue:work` |
| Servidor | cron `* * * * * php artisan schedule:run` | `php artisan queue:work` (supervisionado) |

### Fora de âmbito (próximas issues)

- Reservas que desaparecem do calendário não são canceladas (#35).
- Reservas já importadas não são atualizadas se as datas mudarem no Airbnb.

### Reproduzir localmente

1. MySQL ligado, `php artisan migrate`, API com `php artisan serve`, web com `npm run dev`. Entrar como supervisor (contas no `DemoUserSeeder`).
2. No Airbnb: Calendário → Disponibilidade → Sincronizar calendários → Exportar calendário. Copiar o link `.ics`.
3. `GET /api/properties` para obter o id do imóvel.
4. `POST /api/properties/{id}/booking-sources` com `{ "url": "<link>" }` → `201`.
5. `POST /api/booking-sources/{id}/sync` → `200` com reservas em `created`. Repetir → as mesmas em `existing`.
6. `php artisan reservations:sync --now` → sincroniza todas as fontes ativas. Ver as linhas em `sync_runs`.
7. Sem link real: `php artisan test --filter=Sync` e `php artisan test --filter=IcalFetcher` cobrem o fluxo com respostas simuladas.