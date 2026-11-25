# Aceitação da Fase 1 e proposta v0.1

Este documento regista as evidências disponíveis para a [API #49](https://github.com/CodebyCS/cleancheck-api/issues/49), no âmbito do [Sprint 4 — API #48](https://github.com/CodebyCS/cleancheck-api/issues/48).

## Versões e evidências

- **API publicada:** commit `799d292`, promovido pelo [PR #141](https://github.com/CodebyCS/cleancheck-api/pull/141). O pedido `GET /api/health` devolveu `200 OK` por HTTPS.
- **Frontend publicado:** build do commit `fd26dca`, gerada com `VITE_API_URL=/`. A build e o lint passaram e a aplicação foi publicada no Plesk. O login web funcionou no domínio de demonstração.
- **Suite de referência da API:** 407 testes e 1.471 asserções aprovados na branch `chore/api-demo-deployment`, no commit `e81b596`. Este resultado documenta a preparação da API. A validação numa instalação limpa da versão candidata continua pendente.

## Matriz de aceitação

| Critério | Evidência disponível | Estado |
|---|---|---|
| Instalação reproduzível da API | O [guia do Plesk](../deployment/plesk.md) descreve a publicação. Na demonstração, o dump foi importado e `migrate:status` indicou que todas as migrações listadas estavam aplicadas. | Publicação confirmada. Falta repetir a instalação a partir de um clone limpo e de uma base vazia para a #49. |
| Importar, alterar e cancelar reservas iCal | `IcalImportTest`, `IcalBookingChangesTest` e `IcalCancellationTest` cobrem o percurso na suite de referência. | Cobertura automatizada registada. Falta evidência do percurso completo na demonstração. |
| Preservar reservas quando a origem falha | `SyncRecoveryTest` cobre a recuperação na suite de referência. | Falta registar a execução deste cenário no ambiente de demonstração. |
| Consultar tarefas e calendário | A listagem de tarefas foi observada no frontend publicado. | Falta registar a validação do calendário completo durante uma alteração e um cancelamento. |
| Atribuir e reatribuir prestadores | `TaskAssignmentTest` cobre o comportamento da API, incluindo admin e supervisor. No Plesk, o ModSecurity bloqueou a atribuição com `403`. Uma alteração ao frontend foi ensaiada localmente. | Falha conhecida na versão publicada. A correção ainda não foi validada no Plesk. |
| Aplicar permissões por perfil | `PermissionMatrixTest` cobre a matriz de permissões na suite de referência. | Cobertura automatizada registada. |
| Sessão web e publicação reproduzível | Frontend e API acessíveis por HTTPS. Login web confirmado no domínio principal. | Falta consolidar as evidências de publicação e reprodução previstas na [Web #11](https://github.com/CodebyCS/cleancheck-web/issues/11) e na [Web #17](https://github.com/CodebyCS/cleancheck-web/issues/17). |
| Consultar trabalho e enviar prova no mobile | `EvidenceTrialTest` cobre o endpoint de ensaio. A build, a instalação num telemóvel e a consulta de trabalho fazem parte da demonstração. | Falta evidência do percurso num telemóvel real e da sua reprodução independente. |

## Instalação, atualização e recuperação

Para repetir a instalação, usar um clone limpo e uma base local exclusiva. Na pasta `api`, executar `composer install`, criar o `.env` a partir de `.env.example`, configurar a base de validação e executar `php artisan key:generate`, `php artisan migrate`, `php artisan db:seed` e `php artisan migrate:status`. Iniciar a API e confirmar que `GET /api/health` devolve `200`. Registar o commit avaliado e o resultado de `php artisan test --compact`.

Para atualizar o ambiente publicado, seguir o [guia do Plesk](../deployment/plesk.md). Registar o commit em execução e guardar uma cópia da base e dos ficheiros privados antes de aplicar migrações. Para recuperar, voltar à versão anterior e restaurar a cópia correspondente quando as alterações de estrutura ou dados o exigirem. Preservar a chave da aplicação e validar a saúde da API e o login antes de retomar o serviço. Este procedimento está documentado. A execução de um restauro nesta Fase 1 ainda não foi registada.

## Armazenamento experimental de provas

O [contrato do ensaio](../contracts/evidence-trial.md) limita `POST /api/evidence-trials` a prestadores autenticados. O ficheiro vai para o disco privado `local`. O endpoint de ensaio não disponibiliza ligação pública de leitura nem altera o estado da tarefa. A demonstração deve usar fotografias e vídeos fictícios e registar formato, tamanho e resultado do envio, sem expor credenciais ou ficheiros privados.

## Proposta de versão v0.1

A versão candidata reúne os fluxos com evidência na matriz. Antes da aceitação, falta registar a instalação limpa, concluir a demonstração web e mobile, confirmar a decisão acordada sobre o âmbito do iCal e da bidirecionalidade e obter uma reprodução independente. A falha de atribuição no Plesk deve constar das lacunas da versão enquanto a correção não estiver validada no alojamento.

**Estado da v0.1:** proposta para avaliação. A publicação do ambiente de demonstração está confirmada. A aceitação final da Fase 1 continua pendente.
