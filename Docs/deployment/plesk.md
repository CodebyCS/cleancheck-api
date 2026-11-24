# Publicação da API de demonstração no Plesk

Este guia descreve como publicar e validar a API de demonstração do CleanCheck. Os detalhes do alojamento, as credenciais e as evidências de validação ficam no registo interno da equipa.

## Preparar a publicação

1. Na pasta `api`, executa `php artisan test --compact` e regista o commit validado.
2. Confirma que o PHP usado pelo servidor web tem as atualizações de segurança aplicáveis.
3. Prepara uma base exclusiva para a demonstração, com um utilizador dedicado e dados fictícios. Guarda a exportação SQL fora do repositório.

## Publicar a API

1. Carrega a versão validada para o Plesk e aponta a raiz pública do subdomínio para `api/public`. Mantém o `.env` e os ficheiros privados fora dessa pasta.
2. No Laravel Toolkit, usa **Scan** para registar a aplicação. Na pasta `api`, instala as dependências com `composer install --no-dev --prefer-dist --optimize-autoloader`.
3. Configura HTTPS e as variáveis de ambiente no servidor. Define `APP_DEBUG=false` e autoriza apenas a origem prevista para o frontend. Na primeira instalação, gera a `APP_KEY` se estiver vazia. Preserva essa chave nas atualizações.
4. Importa o SQL para a base vazia da demonstração. Confirma o estado com `php artisan migrate:status` e aplica as migrações pendentes, se existirem.
5. Configura o agendador para invocar `schedule:run` a cada minuto e ativa um worker para a fila `database`. Usa uma única configuração para o agendador.

## Validar

- Confirmar que `/api/health` responde por HTTPS com `200` e `status: ok`.
- Testar no telemóvel o login, o acesso a uma rota protegida, a renovação e o logout. Confirmar que o token revogado recebe `401`.
- Confirmar que um prestador recebe `403` ao tentar uma operação reservada a perfis de gestão.
- Confirmar que `reservations:sync` aparece em `schedule:list` e que o worker processa um trabalho de demonstração.
- Verificar os cabeçalhos numa resposta real da API e registar a data, o commit e os resultados, sem passwords nem tokens.

## Recuperar o ambiente

Antes de atualizar, regista a versão em execução e guarda uma cópia da base de dados e dos ficheiros privados. Se for necessário voltar atrás, coloca a API em manutenção, pausa o agendador e o worker e reinstala a versão anterior. Restaura a cópia correspondente se tiverem ocorrido alterações incompatíveis na estrutura ou nos dados. Valida a API e o login antes de retomar o serviço.