# CleanCheck API

API do projeto CleanCheck, uma aplicação de apoio à gestão de serviços de limpeza e acompanhamento de prestadores em alojamentos.

Este repositório contém o backend em Laravel, que será utilizado pela aplicação web e pela aplicação mobile.

## Estado atual

Projeto em fase inicial de configuração e desenvolvimento.

## Tecnologias

- PHP
- Laravel
- MySQLMySQL / MariaDB (via XAMPP)
- Composer

As dependências e os requisitos de versão encontram-se no ficheiro `composer.json`. O ficheiro `composer.lock` regista as versões utilizadas pelo projeto.

## Requisitos

Antes de começar, instala:

- Git - version 2.55.0
- PHP - version PHP 8.4.26
- Composer - version 2.9.8
- MySQL / MariaDB (via XAMPP)

O serviço MySQL deve estar em execução.

## Instalação local

### 1. Clonar o repositório

Substitui `PROPRIETARIO` pelo utilizador ou organização do GitHub:

```bash
git clone https://github.com/CodebyCS/cleancheck-api.git
cd cleancheck-api
```

### 2. Instalar as dependências

```bash
composer install
```

### 3. Criar o ficheiro de configuração

No Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

No Linux ou macOS:

```bash
cp .env.example .env
```

### 4. Configurar a base de dados

Cria uma base de dados no MySQL:

```sql
CREATE DATABASE cleancheck
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;
```

Edita o ficheiro `.env` com os dados da tua instalação:

```dotenv
APP_NAME=CleanCheck
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cleancheck
DB_USERNAME=teu_utilizador
DB_PASSWORD=tua_password
```

Cada elemento da equipa utiliza a sua própria base de dados local.

### 5. Gerar a chave da aplicação

```bash
php artisan key:generate
```

### 6. Executar as migrações

```bash
php artisan migrate
```

### 7. Iniciar o servidor local

```bash
php artisan serve
```

A aplicação fica disponível em:

```text
http://localhost:8000
```

Para parar o servidor, utiliza `Ctrl+C`.

## Estrutura principal

```text
app/               Código da aplicação
bootstrap/         Inicialização da aplicação
config/            Ficheiros de configuração
database/          Migrações, factories e seeders
public/            Ponto de entrada público
resources/         Recursos da aplicação
routes/            Definição de rotas
storage/           Logs, cache e ficheiros gerados
tests/             Testes automatizados
```

## Comandos úteis

Listar as rotas disponíveis:

```bash
php artisan route:list
```

Consultar o estado das migrações:

```bash
php artisan migrate:status
```

Limpar a cache de configuração após alterações ao `.env`:

```bash
php artisan config:clear
```

Executar os testes existentes:

```bash
php artisan test
```

## Configuração e ficheiros locais

O ficheiro `.env` contém a configuração de cada instalação e não deve ser enviado para o GitHub.

O `.env.example` deve conter apenas configurações de exemplo, sem passwords, chaves privadas ou outros dados sensíveis.

As pastas `vendor/` e `node_modules/` não são incluídas no repositório. As dependências PHP são instaladas através de `composer install`.

O ficheiro `composer.lock` deve permanecer no repositório para manter as versões das dependências consistentes entre os elementos da equipa.

## Contribuição

1. Atualiza a tua cópia da branch `main`.
2. Cria uma branch para a alteração.
3. Implementa e verifica o funcionamento.
4. Envia a branch para o GitHub.
5. Abre um Pull Request para revisão antes de integrar na `Develop`.

Sempre que uma alteração modificar os passos de instalação ou configuração, este README será atualizado.
