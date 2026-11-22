# Meu Money

Meu Money é uma aplicação web privada para acompanhar finanças pessoais, organizar carteiras e cartões de crédito e dividir despesas com contatos. A interface é em português e funciona em telas móveis e desktop.

## O que você pode fazer

| Área | Recursos |
| --- | --- |
| **Início** | Consultar lançamentos do mês, acompanhar saldos e pendências e registrar receitas e despesas. Os atalhos de lançamento rápido usam as categorias mais frequentes do seu histórico de lançamentos à vista. |
| **Carteiras e cartões** | Separar movimentações por conta, acompanhar faturas por competência e registrar pagamentos pela carteira vinculada. |
| **Lançamentos** | Criar receitas e despesas à vista, parceladas ou recorrentes, organizar lançamentos em categorias e grupos e adicionar anexos. |
| **Compartilhamento** | Dividir lançamentos com contatos, acompanhar valores a receber e delegar operações em carteiras e cartões por convite. |
| **Relatório e simulações** | Comparar receitas, despesas e evolução de saldos. Simular entradas e saídas a partir dos saldos atuais, sem gravar lançamentos. |
| **Preferências** | Alternar entre os modos simples e completo, personalizar a aparência e ocultar valores na tela. |

Em uma carteira comum, **Acumulado** acompanha o saldo da carteira até o mês selecionado. O **Saldo atual** considera as movimentações realizadas, enquanto o **Saldo estimado** também incorpora os valores a receber e a pagar. **Resultado do mês** mostra a diferença entre receitas e despesas daquele período. Nos cartões, a visão principal é a fatura da competência selecionada.

## Tecnologias e requisitos

- **Backend** — PHP 8.5, Laravel 13, Fortify, Socialite e Inertia 3.
- **Frontend** — Vue 3, TypeScript, Tailwind CSS 4, Vite Plus e Wayfinder para rotas tipadas.
- **Ambiente local** — Composer 2, Node.js 22 a partir da versão 22.18 e npm. O banco local padrão é SQLite, com a extensão PHP `pdo_sqlite`. As dependências PHP são verificadas na instalação e podem ser conferidas com `composer check-platform-reqs --lock`.

## Rodar localmente

Na raiz do repositório, prepare o ambiente. Em um checkout novo, `database/database.sqlite` é o arquivo do banco local.

```bash
composer install
cp -n .env.example .env
touch database/database.sqlite
php artisan key:generate
```

Se o ambiente já estiver em uso, preserve a chave `APP_KEY` existente e execute `key:generate` somente quando ela estiver vazia.

### Primeiro acesso

O acesso é provisionado pela administração. Para criar o primeiro administrador local, preencha `SEED_ADMIN_NAME` e `SEED_ADMIN_EMAIL` no `.env`. Com o padrão `MAIL_MAILER=log`, o e-mail de definição de senha é gravado no log local, normalmente em `storage/logs/laravel.log`.

```bash
php artisan migrate --seed
npm ci
composer run dev
```

Abra no navegador o endereço informado pelo servidor local. Use o link do log para definir a senha e acessar a aplicação. O seeder carrega as categorias globais e cria o administrador quando as duas variáveis estão preenchidas e o e-mail ainda não está cadastrado.

Para provisionar um administrador depois da instalação, use:

```bash
php artisan usuario:admin "Nome do administrador" "admin@example.test"
```

### Serviços opcionais

- **E-mail real** — Configure `MAIL_MAILER` e as variáveis `MAIL_*` correspondentes para enviar convites e links de senha.
- **Login com Google** — Configure `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` e `GOOGLE_REDIRECT_URI`. O endereço de retorno deve coincidir com o cadastrado no provedor.
- **Rotinas agendadas** — O scheduler executa diariamente a extensão de séries recorrentes. Para exercitar o agendamento localmente, rode `php artisan schedule:work` em outro terminal.
- **Outro banco** — Ajuste as variáveis `DB_*` no `.env` antes de rodar as migrações.

O arquivo [`.env.example`](.env.example) lista as variáveis do projeto. Mantenha credenciais e chaves no ambiente correspondente.

## Organização do código

| Caminho | Responsabilidade |
| --- | --- |
| [`app/Actions`](app/Actions) | Regras e operações de domínio, como cálculo de saldos e faturas, rateio e recorrências. |
| [`app/Http`](app/Http) e [`app/Policies`](app/Policies) | Entrada HTTP, validação de formulários e autorização. |
| [`database`](database) | Migrações, factories e dados de inicialização. |
| [`resources/js/pages`](resources/js/pages) e [`resources/js/components`](resources/js/components) | Páginas Inertia e componentes Vue. |
| [`routes`](routes) | Rotas HTTP e agendamentos. |
| [`tests`](tests) | Testes de funcionalidade e de unidade com Pest. |

## Comandos de desenvolvimento

| Comando | Para que serve |
| --- | --- |
| `composer run dev` | Iniciar o ambiente de desenvolvimento com servidor Laravel e Vite. |
| `npm run build` | Compilar os assets de produção. |
| `npm run check` | Verificar o frontend com Vite Plus. |
| `npm run types:check` | Validar os tipos de Vue e TypeScript. |
| `composer run lint:check` | Conferir a formatação PHP com Pint. |
| `composer run types:check` | Analisar o PHP com Larastan. |
| `vendor/bin/pest --compact` | Rodar a suíte PHP, configurada para SQLite em memória. |
| `composer run ci:check` | Rodar as verificações de frontend e backend usadas pelo projeto. |

Para verificar apenas o fluxo do Início:

```bash
vendor/bin/pest --compact tests/Feature/Inicio/TelaPrincipalTest.php
```
