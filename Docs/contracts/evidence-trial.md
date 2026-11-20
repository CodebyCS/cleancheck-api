# Contrato — Ensaio de envio de fotografia e vídeo

Endpoint usado para testar a câmara e o envio a partir de um telemóvel real (Sprint 4). **É um ensaio:** o novo envio associado a tarefa regista o ficheiro e o prestador autenticado, mas não altera o estado da tarefa nem substitui o fluxo completo de evidências previsto nos sprints seguintes.

## `POST /api/my/tasks/{task}/evidence-trials`

- Autenticação: sessão válida (`auth:sanctum` e `access.valid`).
- Permissão: apenas `service_provider`.
- Limite: 10 pedidos por minuto por utilizador.
- Corpo: `multipart/form-data`.
- O `{task}` da rota tem de existir e estar atualmente atribuído ao prestador autenticado.

| Campo | Obrigatório | Regra |
|---|---|---|
| `file` | Sim | Fotografia ou vídeo nos formatos abaixo |
| `device` | Não | Texto até 120 caracteres para registar o aparelho e a rede (ex.: `Pixel 7, Android 15, 4G`) |

O prestador autenticado é guardado como autor (`user_id`). A tarefa é carregada pela rota e a atribuição é verificada novamente na transação que regista o ficheiro. Se a atribuição mudar durante o upload, o ficheiro temporário é apagado e o envio é recusado com `403`. Tarefa inexistente ou apagada devolve `404`.

### Formatos e limites

| Tipo | Formatos (MIME) | Tamanho máximo |
|---|---|---|
| Fotografia | `image/jpeg`, `image/png`, `image/webp`, `image/heic`, `image/heif` | 10 MB |
| Vídeo | `video/mp4`, `video/quicktime` (MOV) | 50 MB e 60 segundos |

O tipo é detetado pelo conteúdo do ficheiro, não pela extensão. Para vídeos, a API lê a duração do cabeçalho MP4/MOV e rejeita ficheiros cuja duração exceda 60 segundos ou não possa ser determinada. A app também verifica a duração devolvida pelo seletor antes de enviar. O timeout de 2 minutos é o limite de tempo do pedido mobile, não uma duração máxima do vídeo.

### Resposta `201`

```json
{
  "data": {
    "is_trial": true,
    "id": 74,
    "task_id": 123,
    "kind": "video",
    "mime_type": "video/mp4",
    "size_bytes": 18350080,
    "original_name": "VID_20261109_101500.mp4",
    "device": "Pixel 7, Android 15, 4G",
    "received_at": "2026-11-09T10:15:42+00:00"
  }
}
```

`data.is_trial: true` identifica explicitamente esta resposta como confirmação de ensaio. Não representa uma prova de produção nem altera a tarefa.

### Erros

| Código | Quando | Mensagem |
|---|---|---|
| `401` | Sem sessão ou sessão expirada | — |
| `403` | Utilizador que não é prestador | — |
| `403` | Tarefa não está atualmente atribuída ao prestador autenticado | "Só podes enviar ficheiros para uma tarefa atualmente atribuída a ti." |
| `404` | Tarefa inexistente ou apagada | — |
| `422` | Sem ficheiro | "Escolha uma fotografia ou um vídeo." |
| `422` | Formato não aceite | "Formato não aceite. Use JPG, PNG, WEBP ou HEIC para fotografias e MP4 ou MOV para vídeos." |
| `422` | Fotografia acima de 10 MB | "A fotografia não pode ter mais de 10 MB." |
| `422` | Ficheiro acima de 50 MB | "O ficheiro não pode ter mais de 50 MB." |
| `422` | Vídeo acima de 60 segundos | "O vídeo não pode ter mais de 60 segundos." |
| `422` | Duração não determinável em MP4/MOV inválido | "Não foi possível determinar a duração do vídeo. Envia um ficheiro MP4 ou MOV válido." |
| `422` | Ficheiro cortado pelo PHP (ver abaixo) | "O ficheiro não foi recebido. Pode ser maior do que o servidor aceita." |
| `429` | Mais de 10 envios por minuto | — |

## Armazenamento

- Disco privado `local`, em `storage/app/private/evidence-trials/tasks/{task_id}/providers/{user_id}/`, com nome gerado pelo Laravel.
- Os ficheiros não têm link público nem endpoint de leitura.
- Cada envio cria uma linha experimental em `evidence_trials` com tarefa, autor, caminho privado, tipo, tamanho, nome original, aparelho e hora de receção. Também é registado no log da aplicação (`Evidence trial received`).
- Os ficheiros de ensaio podem ser apagados no fim do ensaio; não são dados de produção.

## Compatibilidade

`POST /api/evidence-trials` continua disponível para builds de ensaio anteriores. Essa rota não associa o ficheiro a uma tarefa e não cria uma linha em `evidence_trials`, mas usa as mesmas validações de formato, tamanho e duração. A build mobile atual usa apenas a rota associada acima.

## Requisitos do servidor

O PHP corta uploads antes de chegarem ao Laravel. Os valores por defeito (`upload_max_filesize = 2M`, `post_max_size = 8M`) não chegam para vídeo. Para o ensaio, no `php.ini`:

```
upload_max_filesize = 50M
post_max_size = 55M
```

Se o servidor estiver atrás de nginx, também é preciso `client_max_body_size 55M;`.

## Ensaio vs. fluxo completo

| | Ensaio (este endpoint) | Fluxo completo (sprints seguintes) |
|---|---|---|
| Ligado a uma tarefa | Sim, como registo experimental | Sim, como prova associada ao passo e tipo (vídeo inicial/final ou fotografia) |
| Muda o estado da tarefa | Não | Sim |
| Guarda registo na base de dados | Sim, em `evidence_trials`; não é consultável como prova de produção | Sim, no modelo final de evidências |
| Supervisor pode ver o ficheiro | Não | Sim |

Os resultados do ensaio em aparelho real (formatos gerados, tamanhos, tempos de envio, rede e problemas) são registados no repositório mobile.
