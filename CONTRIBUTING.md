# Guia de contribuição

Estas convenções aplicam-se aos três repositórios do CleanCheck:

- `cleancheck-api`
- `cleancheck-web`
- `cleancheck-mobile`

## Idioma

Os nomes das branches, as mensagens dos commits e os títulos dos Pull Requests devem ser escritos em inglês.

As descrições dos Pull Requests, a documentação e os comentários de revisão podem ser escritos em português.

## Branches

A branch `main` contém o código integrado da equipa.

Cria uma branch para cada tarefa, utilizando o formato:

```text
<type>/<short-description>
```

Usa letras minúsculas e palavras separadas por hífen. Não uses espaços ou acentos.

### Tipos permitidos

| Tipo | Utilização | Exemplo de branch |
|---|---|---|
| `feat` | Nova funcionalidade | `feat/user-login` |
| `fix` | Correção de erros | `fix/invalid-login-response` |
| `docs` | Documentação | `docs/setup-instructions` |
| `refactor` | Reorganização do código sem alterar o comportamento | `refactor/reservation-validation` |
| `test` | Criação ou atualização de testes | `test/login-validation` |
| `chore` | Configuração e manutenção | `chore/initial-setup` |

### Criar uma branch

Com o trabalho anterior guardado, atualiza a `main` antes de iniciar uma tarefa:

```bash
git switch main
git pull --ff-only
git switch -c feat/user-login
```

Não cries branches permanentes por pessoa ou sprint. Cada branch deve corresponder a uma tarefa com um objetivo definido.

## Commits

Utiliza o formato:

```text
<type>: <short description>
```

Os tipos permitidos são os mesmos utilizados nas branches.

Escreve a descrição em inglês, começando por um verbo de ação, sem ponto final.

### Exemplos

```text
feat: add user login endpoint
fix: reject invalid login credentials
docs: update local setup instructions
refactor: extract reservation validation
test: add login validation tests
chore: configure environment example
ci: add API test workflow
```

Cada commit deve representar uma alteração coerente. Evita mensagens vagas como:

```text
update
changes
fix stuff
```

Antes de criar um commit, confirma os ficheiros e as alterações preparados:

```bash
git status
git diff --cached
```

Exemplo de criação de um commit:

```bash
git commit -m "feat: add user login endpoint"
```

## Pull Requests

Todas as alterações à `develop` devem ser integradas através de um Pull Request.

Um Pull Request pode incluir vários commits, desde que estejam relacionados com o mesmo objetivo.

### Título

Utiliza o mesmo formato das mensagens de commit:

```text
<type>: <short description>
```

Exemplos:

```text
feat: add user authentication
fix: handle expired sessions
docs: update installation guide
```

### Descrição

Preenche o modelo de Pull Request disponível no repositório, indicando:

- O que foi alterado e porquê.
- A issue relacionada, quando existir.
- Os passos para testar.
- O resultado esperado e as verificações realizadas.
- Capturas de ecrã, quando existirem alterações visuais.
- Limitações ou decisões que o revisor deva conhecer.

Para associar uma issue do mesmo repositório e fechá-la após a integração, utiliza:

```text
Closes #12
```

Substitui `12` pelo número real da issue. Se não existir uma issue relacionada, remove essa linha.

### Pedido de revisão

Antes de pedir revisão:

- Verifica as alterações localmente.
- Adiciona ou atualiza testes quando necessário.
- Atualiza a documentação afetada.
- Confirma que não incluíste segredos ou dados pessoais.
- Seleciona `main` como branch de destino.
- Atribui o Pull Request a ti e solicita revisão a outro elemento da equipa.

Se o trabalho ainda estiver incompleto, abre o Pull Request como `Draft`.

## Revisão e integração

O revisor deve ler as alterações e repetir os passos de verificação relevantes.

A integração só deve acontecer quando:

- Outro elemento da equipa tiver aprovado o Pull Request.
- Os comentários de revisão estiverem resolvidos.
- As verificações automáticas configuradas tiverem passado.
- Os conflitos estiverem resolvidos.

Utiliza `Squash and merge` para integrar o Pull Request num único commit. Confirma que a mensagem final respeita a convenção de commits.

Após a integração:

1. Elimina a branch remota da tarefa.
2. Atualiza a tua `main` local.
3. Verifica o funcionamento da versão integrada.
4. Atualiza o estado da tarefa no quadro do projeto.

## Configuração e dados sensíveis

Não incluas no repositório:

- Ficheiros `.env` com configuração local.
- Passwords, tokens ou chaves privadas.
- Bases de dados pessoais.
- Dados ou fotografias reais de clientes.
- Pastas de dependências como `vendor/` e `node_modules/`.

Mantém o `.env.example` atualizado, utilizando apenas valores de exemplo sem segredos.

Guarda os ficheiros de dependências e respetivos lockfiles, como `composer.lock` e `package-lock.json`, para manter as versões consistentes na equipa.

## Documentação

Atualiza o README sempre que uma alteração modificar os requisitos, a instalação, a configuração ou o arranque do projeto.
