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
    "correction_of_execution_id": null,
    "responses": [{"key": "example_item", "value": true}],
    "observations": "Dados fictícios para validação.",
    "evidence_ids": [42],
    "send_state": "confirmed",
    "confirmed_at": "2026-11-26T12:00:00Z",
    "rejection": null
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

## Rejeição e correção (#121)

`send_state: "confirmed"` continua a significar apenas que a execução e as relações às evidências foram persistidas; não significa aprovação. A rejeição é um registo separado, associado à execução, com motivo, autor e data. A resposta original e as provas não são substituídas nem apagadas. Rejeitar altera o estado da tarefa para `rejected`; não muda a atribuição, horários, notas nem dados da execução. O campo automático `updated_at` também é atualizado.

### Rejeitar uma execução (Admin/Supervisor)

`POST /api/tasks/{task}/executions/{execution}/reject` exige sessão válida, conta ativa e perfil `admin` ou `supervisor`. O motivo é obrigatório, texto livre, com máximo de 500 caracteres. Uma execução só pode ser rejeitada uma vez; repetir a rejeição responde `409`.

```json
{ "reason": "Corrigir a resposta de limpeza da cozinha e anexar uma fotografia atualizada." }
```

Resposta `201`: a execução em `data`, mantendo respostas e evidências, acrescida de:

```json
{
  "rejection": {
    "reason": "Corrigir a resposta de limpeza da cozinha e anexar uma fotografia atualizada.",
    "rejected_by_user_id": 3,
    "rejected_at": "2026-11-27T12:00:00Z"
  }
}
```

O motivo inclui a explicação e os passos que a equipa pede ao prestador. Admin/Supervisor podem solicitar a correção, mas não editam as respostas nem enviam provas em nome do prestador. A tarefa passa para `rejected` na mesma transação do registo da rejeição. Enquanto estiver rejeitada, a tarefa continua atribuída ao mesmo prestador; novas evidências podem ser carregadas por esse prestador para preparar a correção. A tarefa rejeitada não pode ser cancelada, apagada nem ter o horário alterado por sincronização da reserva.

### Submeter uma correção (prestador atualmente atribuído)

O prestador corrige através do endpoint de execução existente, `POST /api/my/tasks/{task}/executions`, enviando novas respostas e, opcionalmente, novas evidências previamente carregadas. Para ligar a nova tentativa à execução rejeitada, acrescenta `correction_of_execution_id`:

```json
{
  "idempotency_key": "2c5b54b0-a57b-49b7-9e6c-0f337e9ec4ad",
  "correction_of_execution_id": 18,
  "responses": [{"key": "kitchen_clean", "value": true}],
  "observations": "Correção concluída com dados fictícios.",
  "evidence_ids": [43]
}
```

A correção cria uma nova execução ligada à mesma tarefa; não cria outra tarefa nem altera a execução rejeitada. Respostas, evidências e autoria anteriores mantêm-se. A nova execução fica com o autor autenticado e as evidências têm de pertencer à mesma tarefa e a esse autor. A submissão da correção exige que a tarefa esteja `rejected`; depois da persistência, a tarefa volta a `completed`, e `completed_at` e `completed_by_user_id` são atualizados para a nova conclusão (momento e prestador que enviou a correção). A data e autoria da tentativa original continuam preservadas no histórico da execução anterior. Cada execução rejeitada aceita uma única correção. O `correction_of_execution_id` é incluído no conteúdo protegido pela chave de idempotência.

Após reatribuição, o prestador atualmente atribuído pode consultar o histórico e corrigir uma execução rejeitada da tarefa. O prestador anterior deixa de poder consultar ou escrever e recebe `404`. A autoria de cada tentativa continua visível no histórico.

`GET /api/tasks/{task}/executions` e `GET /api/my/tasks/{task}/executions` incluem `correction_of_execution_id` e `rejection` (objeto ou `null`) por execução.

### Erros

| Código | Quando |
|---|---|
| `401` | Sessão ausente/expirada ou conta inativa. |
| `403` | Perfil sem permissão para rejeitar ou prestador sem papel/acesso de escrita. |
| `404` | Tarefa/execução inexistente, execução não pertencente à tarefa ou acesso do prestador removido. |
| `409` | Execução já rejeitada ou já corrigida. |
| `422` | Motivo ausente/vazio, `correction_of_execution_id` inválido ou tarefa que não está em `rejected` ao submeter uma correção. |

Os alertas operacionais em [`alerts.md`](alerts.md) continuam distintos deste fluxo.

Não houve demonstração deste fluxo num aparelho real. A validação no dispositivo depende da integração mobile e permanece por executar nesta issue da API.

## Validação local

1. Autenticar um prestador ativo atribuído à tarefa.
2. Opcionalmente carregar uma evidência pela rota existente e obter o respetivo `id`.
3. Enviar o exemplo acima com UUID e dados fictícios; confirmar `201` e `send_state: confirmed`.
4. Repetir sem alterar chave ou conteúdo; confirmar `200` e o mesmo ID. Alterar conteúdo mantendo a chave; confirmar `409`.
5. Consultar execuções e confirmar autoria/evidências. Reatribuir a tarefa; o prestador anterior recebe `404`, e a equipa ainda consulta o histórico.
6. Como Admin/Supervisor, enviar um motivo fictício para `POST /api/tasks/{task}/executions/{execution}/reject`; confirmar `201` e o objeto `rejection`.
7. Como prestador atualmente atribuído, repetir `POST /api/my/tasks/{task}/executions` com novo UUID, `correction_of_execution_id`, respostas corrigidas e novas evidências; confirmar que a nova tentativa fica na mesma tarefa e a rejeitada/provas anteriores continuam no histórico.

Testes automatizados relacionados: `php artisan test tests/Feature/Tasks/TaskExecutionSubmissionTest.php` e `php artisan test tests/Feature/Tasks/TaskExecutionRejectionTest.php`.
