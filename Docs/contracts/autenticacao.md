# Contrato de autenticação

## Identidade e perfis

Web e mobile utilizam e-mail e palavra-passe.

O perfil é guardado através de `users.role_id`, associado à tabela `roles`. Nas respostas, o campo `role` contém um dos identificadores:

- `admin`
- `supervisor`
- `service_provider`

A identidade devolvida inclui `id`, `name`, `email` e `role`. A autenticação identifica o utilizador; as permissões de cada operação são verificadas pela API no módulo responsável.

## Política de duração

| Cliente | Sem “Lembrar-me” | Com “Lembrar-me” |
|---|---|---|
| Web | Sessão com limite absoluto de 2 horas | Sessão com limite absoluto de 14 dias |
| Mobile | Token de acesso de 2 horas, sem refresh token | Token de acesso de até 2 horas e renovação limitada a 14 dias desde o login |

O campo `remember` é opcional e booleano. Quando omitido, equivale a `false`.

Consultar a identidade não prolonga a sessão. A renovação mobile também não prolonga o limite original de 14 dias. Perto desse limite, o novo token de acesso dura apenas o tempo restante.

Os instantes são devolvidos em formato ISO 8601 com indicação do fuso horário.

## Rotas

Os caminhos abaixo são relativos à origem da API, por exemplo `http://localhost:8000` no desenvolvimento local.

| Método | Caminho | Autenticação ou credencial | Sucesso |
|---|---|---|---|
| GET | `/sanctum/csrf-cookie` | Não exige login | 204 |
| POST | `/login` | E-mail, palavra-passe e CSRF | 200 |
| GET | `/api/user` | Sessão web ou Bearer token | 200 |
| POST | `/logout` | Sessão web e CSRF | 204 |
| POST | `/api/mobile/login` | E-mail e palavra-passe | 200 |
| POST | `/api/mobile/refresh` | Refresh token no corpo JSON | 200 |
| POST | `/api/mobile/logout` | Bearer token válido | 204 |
| POST | `/api/mobile/logout-with-refresh` | Refresh token no corpo JSON | 204 |

Os pedidos devem enviar `Accept: application/json`. Pedidos com corpo JSON devem também enviar `Content-Type: application/json`.

## Fluxo web

A autenticação web utiliza cookies de sessão e proteção CSRF. Não utiliza os refresh tokens do fluxo mobile.

1. Pedir `GET /sanctum/csrf-cookie`.
2. Enviar `POST /login` com as credenciais e o cabeçalho CSRF correspondente.
3. Consultar `GET /api/user` para recuperar a identidade.
4. Enviar `POST /logout` para terminar a sessão.

O cliente deve enviar os cookies nos pedidos. No desenvolvimento com origens diferentes, a configuração de CORS e de domínios stateful do Sanctum deve corresponder aos endereços utilizados.

### Login web

Pedido:

```json
{
  "email": "admin@example.test",
  "password": "PASSWORD_DE_TESTE",
  "remember": true
}
```

Resposta HTTP 200, com valores ilustrativos:

```json
{
  "data": {
    "id": 1,
    "name": "Administrador",
    "email": "admin@example.test",
    "role": "admin"
  },
  "meta": {
    "expires_at": "2026-11-12T12:00:00+00:00"
  }
}
```

O prazo absoluto é guardado na sessão em `access_expires_at`. A configuração prevista é `SESSION_LIFETIME=20160` e `SESSION_EXPIRE_ON_CLOSE=false`; o middleware aplica o prazo de 2 horas ou 14 dias escolhido no login.

### Logout web

`POST /logout` termina a autenticação, invalida a sessão e regenera o token CSRF. Devolve HTTP 204, sem corpo.

## Fluxo mobile

### Login

Enviar `POST /api/mobile/login`.

| Campo | Obrigatório | Regra |
|---|---|---|
| `email` | Sim | E-mail válido, até 255 caracteres |
| `password` | Sim | String, até 255 caracteres |
| `device_name` | Não | String, até 100 caracteres; por defeito `mobile` |
| `remember` | Não | Booleano; por defeito `false` |

Pedido:

```json
{
  "email": "prestador1@example.test",
  "password": "PASSWORD_DE_TESTE",
  "device_name": "CleanCheck Mobile",
  "remember": true
}
```

Resposta HTTP 200, com credenciais ilustrativas:

```json
{
  "token": "ACCESS_TOKEN",
  "expires_at": "2026-10-29T14:00:00+00:00",
  "refresh_token": "REFRESH_TOKEN",
  "refresh_expires_at": "2026-11-12T12:00:00+00:00",
  "user": {
    "id": 3,
    "name": "Prestador Um",
    "email": "prestador1@example.test",
    "role": "service_provider"
  }
}
```

Sem “Lembrar-me”, `refresh_token` e `refresh_expires_at` são `null`.

`device_name` é uma descrição do dispositivo, não uma credencial nem um identificador único. Cada login cria uma sessão independente.

### Pedidos autenticados

Enviar o token de acesso no cabeçalho:

```http
Authorization: Bearer ACCESS_TOKEN
```

`GET /api/user` devolve a identidade em `data` e a expiração do acesso em `meta.expires_at`, no mesmo formato da resposta web.

O token de acesso e o refresh token têm finalidades diferentes. O refresh token não deve ser utilizado como Bearer para consultar os recursos.

### Renovação

Enviar `POST /api/mobile/refresh`, sem exigir um Bearer válido:

```json
{
  "refresh_token": "REFRESH_TOKEN_ATUAL"
}
```

O refresh token emitido pela API tem 64 caracteres hexadecimais minúsculos.

Resposta HTTP 200:

```json
{
  "token": "NOVO_ACCESS_TOKEN",
  "expires_at": "2026-10-29T16:00:00+00:00",
  "refresh_token": "NOVO_REFRESH_TOKEN",
  "refresh_expires_at": "2026-11-12T12:00:00+00:00"
}
```

A resposta de renovação não inclui a identidade. O cliente pode recuperá-la através de `GET /api/user`.

Cada renovação:

- Consome o refresh token apresentado.
- Remove os tokens de acesso anteriores associados à sessão.
- Emite um novo token de acesso e um novo refresh token.
- Preserva o prazo absoluto original da sessão.

A reutilização de um refresh token consumido é recusada com HTTP 401 e revoga as credenciais da sessão desse dispositivo. As restantes sessões não são revogadas.

O cliente deve coordenar a renovação para evitar pedidos simultâneos e guardar o novo par de credenciais antes de voltar a utilizá-lo. Não deve repetir automaticamente um refresh antigo após uma resposta perdida: o servidor pode já o ter consumido.

### Logout com acesso válido

Enviar `POST /api/mobile/logout` com Bearer token.

Devolve HTTP 204 e revoga a sessão associada ao token, incluindo os seus tokens de acesso e refresh tokens. Outros dispositivos mantêm as suas sessões.

Para tokens antigos sem associação a uma sessão mobile, apenas o token apresentado é eliminado.

### Logout após expirar o acesso

Enviar `POST /api/mobile/logout-with-refresh`:

```json
{
  "refresh_token": "REFRESH_TOKEN"
}
```

Esta operação não exige um Bearer válido. Um refresh token conhecido permite revogar a própria sessão mesmo que esteja expirado, consumido ou revogado. Não permite emitir novas credenciais.

Devolve HTTP 204 quando a sessão é localizada e revogada. Uma credencial desconhecida devolve HTTP 401.

### Armazenamento no cliente

As credenciais mobile devem ser guardadas no Expo SecureStore.

A integração do armazenamento, rotação e utilização dos refresh tokens no cliente mobile continua pendente. A disponibilidade das rotas na API não significa que a aplicação já execute esse fluxo.

No logout sem ligação, apagar as credenciais locais não comprova a revogação no servidor.

## Contas inativas e revogação

Contas inativas são recusadas no login.

Nos pedidos protegidos, o middleware verifica o estado atual da conta. A renovação também verifica esse estado e revoga a sessão quando encontra a conta inativa.

Reativar uma conta não recupera credenciais já eliminadas ou revogadas. Nesse caso, é necessário iniciar uma nova sessão.

As rotas de negócio protegidas devem utilizar `auth:sanctum` e `access.valid`, além das verificações de autorização do módulo.

## Respostas de erro

| Situação | HTTP |
|---|---|
| Campos obrigatórios ausentes ou formato inválido | 422 |
| Credenciais de login incorretas ou conta inativa no login | 422 |
| Pedido protegido sem autenticação válida | 401 |
| Acesso expirado ou conta inativa num pedido protegido | 401 |
| Refresh desconhecido, expirado, consumido ou sessão revogada na renovação | 401 |
| Operação sem permissão | 403 |
| Falha de CSRF web | 419 |
| Limite de tentativas excedido | 429 |

Os erros de validação incluem `message` e `errors`, com mensagens por campo. O cliente deve usar o código HTTP e os campos estruturados, sem depender do texto exato da mensagem.

Uma falha de rede não é uma resposta HTTP 401 e não comprova expiração ou revogação.

## Limites atuais de autenticação mobile

| Operação | Limite configurado |
|---|---|
| Login | `throttle:5,1` |
| Refresh | `throttle:10,1` |
| Logout com refresh | `throttle:10,1` |

Os valores representam o máximo de pedidos e o intervalo em minutos, aplicados à chave resolvida pelo limitador. Não representam uma quota independente por dispositivo.

O requisito referido de 2.000 pedidos por 10 minutos ainda precisa de ser enquadrado no limite geral da API; não está implementado por esta tabela.

## Contas fictícias

O `DemoUserSeeder` disponibiliza contas locais de demonstração:

| E-mail | Perfil |
|---|---|
| `admin@example.test` | `admin` |
| `supervisor@example.test` | `supervisor` |
| `prestador1@example.test` | `service_provider` |
| `prestador2@example.test` | `service_provider` |

Na pasta `api`, executar:

```bash
php artisan db:seed --class=DemoUserSeeder
```

As passwords de demonstração são definidas no seeder. Não utilizar credenciais reais nos exemplos ou evidências.

## Validação

Testes automatizados associados:

- `MobileSessionIssuanceTest`: emissão, duração e armazenamento do hash.
- `MobileSessionRefreshTest`: rotação, prazo absoluto, reutilização, isolamento entre dispositivos e conta inativa.
- `MobileAuthHttpTest`: contrato HTTP de login, renovação e logout.
- `WebSessionLifetimeTest`: prazos web, expiração e conta inativa.

Nas execuções registadas, os três ficheiros mobile passaram com 17 testes e 90 assertions; o ficheiro web passou com 5 testes e 21 assertions. A execução conjunta deve ser registada na PR.

No Postman, foram confirmados login, renovação, utilização do novo token de acesso e preservação de `refresh_expires_at`.

Estes resultados não representam aprovação da suite completa dos módulos nem validação concluída dos fluxos novos nos clientes.

## Pendências de integração

- Integrar refresh tokens e logout por refresh no cliente mobile.
- Validar “Lembrar-me” e tratamento de expiração no navegador.
- Adaptar os testes dos módulos à preparação comum de credenciais válidas.
- Confirmar a matriz de permissões e a sua aplicação por cada responsável.
- Rever os limites de pedidos conforme o requisito acordado com o professor.

Credenciais utilizáveis, cookies e tokens reais não devem ser incluídos neste documento, em logs de aplicação ou nas evidências publicadas.