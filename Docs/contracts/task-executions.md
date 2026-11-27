# Execuções de tarefas

## Estado atual

Uma execução é um registo imutável associado a uma tarefa e, através dela, à reserva e ao imóvel. O autor é sempre o utilizador autenticado. A API não fornece um catálogo de checklist: o cliente envia respostas como pares `key`/`value`. A chave aceita até 120 caracteres; o valor pode ser texto (até 2000 caracteres), número, booleano ou `null`. Observações são opcionais e limitadas a 5000 caracteres.

`send_state: "confirmed"` significa que o registo e as relações às evidências já armazenadas foram persistidos. Não altera o estado da tarefa, não representa aprovação e não executa conclusão ou faturação.

## Gravar execução

`POST /api/my/tasks/{task}/executions` exige autenticação, conta ativa, papel `service_provider` e atribuição atual da tarefa. Aceita evidências previamente carregadas para a mesma tarefa pelo mesmo autor.

```json
{
  "idempotency_key": "5a2b0d29-7ea9-4d27-9bc0-4236e26b9712",
  "responses": [{"key": "example_item", "value": true}],
  "observations": "Dados fictícios para validação.",
  "evidence_ids": [42]
}
```

`user_id`, `task_id` e `send_state` são determinados pelo servidor e não podem ser enviados pelo cliente. A chave UUID é única por tarefa e autor. Nova chave cria registo novo (`201`); a mesma chave com o mesmo conteúdo devolve o registo existente (`200`); a mesma chave com conteúdo diferente responde `409`. Cada nova submissão preserva os registos anteriores.

Exemplo de resposta:

```json
{
  "data": {
    "id": 18,
    "task_id": 7,
    "user_id": 12,
    "idempotency_key": "5a2b0d29-7ea9-4d27-9bc0-4236e26b9712",
    "responses": [{"key": "example_item", "value": true}],
    "observations": "Dados fictícios para validação.",
    "evidence_ids": [42],
    "send_state": "confirmed",
    "confirmed_at": "2026-11-26T12:00:00Z"
  }
}
```

## Evidências

O ficheiro usa `POST /api/my/tasks/{task}/evidence`, que valida o formato e guarda no armazenamento privado existente. A gravação posterior da execução só aceita IDs de evidências da mesma tarefa, carregadas pelo mesmo autor e ainda disponíveis no disco privado. Se a gravação dos metadados do upload falhar, o ficheiro recém-carregado é removido. O endpoint existente de upload binário não tem idempotency key; a idempotência descrita acima aplica-se à associação e persistência da execução.

## Consultar execução

- Prestador: `GET /api/my/tasks/{task}/executions`, apenas enquanto tiver acesso atual à tarefa; após reatribuição responde `404`.
- Admin/Supervisor: `GET /api/tasks/{task}/executions`, incluindo tarefa arquivada.

Ambos devolvem paginação Laravel de 20 entradas, mais recentes primeiro. A autoria das submissões anteriores é preservada após reatribuição.

## Vistoria de saída

A vistoria de saída é um registo separado associado à tarefa `checkout_cleaning`, à reserva e ao imóvel dessa tarefa. Não cria outro tipo de tarefa nem uma entrada faturável. A tarefa tem de estar atualmente atribuída ao prestador autenticado. O registo só é aceite a partir da hora prevista de check-out da reserva (`overridden_check_out_at` ou `source_check_out_at`), interpretada no fuso horário do imóvel e usando `default_check_out_time`; quando não está configurada, usa-se 11:00. A regra acordada usa a hora prevista como condição, sem declarar que um evento físico de check-out foi confirmado.

`POST /api/my/tasks/{task}/checkout-inspection` aceita `idempotency_key`, `responses`, `observations` e `evidence_ids`. A tarefa tem uma vistoria de saída: repetição idêntica devolve `200`, chave igual com conteúdo diferente devolve `409`, e outra vistoria para a mesma tarefa com nova chave também devolve `409`. Este registo não substitui a execução nem a vistoria de entrada. Consulta com `GET /api/my/tasks/{task}/checkout-inspection` (prestador autorizado) ou `GET /api/tasks/{task}/checkout-inspection` (Admin/Supervisor); `data` é o registo ou `null` quando ainda não existe. Evidências devem pertencer à mesma tarefa e autor.

A vistoria de entrada continua a ser a tarefa `checkin_inspection`, com o contrato e regras existentes.

## Validação local

1. Autenticar um prestador ativo atribuído à tarefa.
2. Opcionalmente carregar uma evidência pela rota existente e obter o respetivo `id`.
3. Enviar o exemplo acima com UUID e dados fictícios; confirmar `201` e `send_state: confirmed`.
4. Repetir sem alterar chave ou conteúdo; confirmar `200` e o mesmo ID. Alterar conteúdo mantendo a chave; confirmar `409`.
5. Consultar execuções e confirmar autoria/evidências. Reatribuir a tarefa; o prestador anterior recebe `404`, e a equipa ainda consulta o histórico.

Teste automatizado: `php artisan test tests/Feature/Tasks/TaskExecutionSubmissionTest.php`. A tentativa local ainda precisa de execução válida: PHPUnit não conseguiu abrir `api/vendor/autoload.php` neste ambiente.
