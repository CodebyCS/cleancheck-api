# Contrato — Ensaio de envio de fotografia e vídeo

Endpoint usado para testar a câmara e o envio a partir de um telemóvel real (Sprint 4). **É um ensaio:** não está ligado a tarefas, não altera o estado de nenhuma tarefa e não substitui o fluxo completo de evidências previsto nos sprints seguintes.

## `POST /api/evidence-trials`

- Autenticação: sessão válida (`auth:sanctum` e `access.valid`).
- Permissão: apenas `service_provider`.
- Limite: 10 pedidos por minuto por utilizador.
- Corpo: `multipart/form-data`.

| Campo | Obrigatório | Regra |
|---|---|---|
| `file` | Sim | Fotografia ou vídeo nos formatos abaixo |
| `device` | Não | Texto até 120 caracteres para registar o aparelho e a rede (ex.: `Pixel 7, Android 15, 4G`) |

### Formatos e limites

| Tipo | Formatos (MIME) | Tamanho máximo |
|---|---|---|
| Fotografia | `image/jpeg`, `image/png`, `image/webp`, `image/heic`, `image/heif` | 10 MB |
| Vídeo | `video/mp4`, `video/quicktime` (MOV) | 50 MB |

O tipo é detetado pelo conteúdo do ficheiro, não pela extensão.

### Resposta `201`

```json
{
  "data": {
    "kind": "video",
    "mime_type": "video/mp4",
    "size_bytes": 18350080,
    "original_name": "VID_20261109_101500.mp4",
    "device": "Pixel 7, Android 15, 4G",
    "received_at": "2026-11-09T10:15:42+00:00"
  }
}
```

### Erros

| Código | Quando | Mensagem |
|---|---|---|
| `401` | Sem sessão ou sessão expirada | — |
| `403` | Utilizador que não é prestador | — |
| `422` | Sem ficheiro | "Escolha uma fotografia ou um vídeo." |
| `422` | Formato não aceite | "Formato não aceite. Use JPG, PNG, WEBP ou HEIC para fotografias e MP4 ou MOV para vídeos." |
| `422` | Fotografia acima de 10 MB | "A fotografia não pode ter mais de 10 MB." |
| `422` | Ficheiro acima de 50 MB | "O ficheiro não pode ter mais de 50 MB." |
| `422` | Ficheiro cortado pelo PHP (ver abaixo) | "O ficheiro não foi recebido. Pode ser maior do que o servidor aceita." |
| `429` | Mais de 10 envios por minuto | — |

## Armazenamento

- Disco privado `local`, em `storage/app/private/evidence-trials/{user_id}/`, com nome gerado pelo Laravel.
- Os ficheiros não têm link público nem endpoint de leitura.
- Cada envio fica registado no log da aplicação (`Evidence trial received`), com utilizador, caminho, tipo, tamanho e aparelho.
- Os ficheiros de ensaio podem ser apagados no fim do ensaio; não são dados de produção.

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
| Ligado a uma tarefa | Não | Sim (vídeo inicial e final por tarefa) |
| Muda o estado da tarefa | Não | Sim |
| Guarda registo na base de dados | Não (só log) | Sim |
| Supervisor pode ver o ficheiro | Não | Sim |

Os resultados do ensaio em aparelho real (formatos gerados, tamanhos, tempos de envio, rede e problemas) são registados no repositório mobile.
