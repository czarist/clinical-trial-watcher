<p align="center"><img src="docs/logo.svg" alt="Clinical Trial Watcher" width="128"></p>

<h1 align="center">Clinical Trial Watcher</h1>

<h3 align="center">Importa estudos clínicos do ClinicalTrials.gov, permite pesquisá-los e avisa por e-mail quando estudos que você acompanha abrem, mudam ou começam a recrutar.</h3>

<p align="center">
  <a href="README.md">English</a> · <a href="README.pt-BR.md">Português (Brasil)</a>
</p>

<p align="center">
  <img alt="Status: desenvolvimento inicial" src="https://img.shields.io/badge/status-desenvolvimento_inicial-orange?style=flat-square">
  <img alt="PHP 8.6" src="https://img.shields.io/badge/PHP-8.6-777BB4?style=flat-square&logo=php&logoColor=white">
  <img alt="Laravel 13" src="https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white">
  <img alt="Vue 3" src="https://img.shields.io/badge/Vue-3-4FC08D?style=flat-square&logo=vuedotjs&logoColor=white">
  <img alt="MySQL 8.4" src="https://img.shields.io/badge/MySQL-8.4-4479A1?style=flat-square&logo=mysql&logoColor=white">
  <img alt="Docker Compose" src="https://img.shields.io/badge/roda%20com-Docker%20Compose-2496ED?style=flat-square&logo=docker&logoColor=white">
  <img alt="Licença: MIT" src="https://img.shields.io/badge/licen%C3%A7a-MIT-blue?style=flat-square">
</p>

<p align="center">
  <a href="#funcionalidades">Funcionalidades</a> ·
  <a href="#início-rápido">Início rápido</a> ·
  <a href="#api">API</a> ·
  <a href="#como-funciona">Como funciona</a> ·
  <a href="#desenvolvimento">Desenvolvimento</a> ·
  <a href="ROADMAP.md">Roadmap</a> ·
  <a href="CHANGELOG.md">Changelog</a>
</p>

## Funcionalidades

### Importação do ClinicalTrials.gov

- **O registro inteiro, depois só o que mudou.** `php artisan clinicaltrials:fetch` lê a API v2 de produção (`https://clinicaltrials.gov/api/v2/studies`, com TLS verificado), segue o `nextPageToken` por todas as páginas e registra cada execução. As execuções seguintes pedem só os estudos atualizados desde a última execução bem-sucedida.
- **Roda todo dia.** O agendador coloca a importação na fila às 03:00 (`CLINICAL_TRIALS_SCHEDULE_TIME`); um worker da fila a executa, uma importação por vez.
- **Pode ser repetida com segurança.** Os estudos são identificados pelo número NCT. Cada estudo e suas listas relacionadas são gravados em uma transação, e toda lista é substituída pela atual, inclusive listas vazias. Um estudo que falha é desfeito e contado; a execução continua.
- **Falhas são explícitas.** Limites de requisição (HTTP 429), erros do servidor e falhas de conexão são repetidos com espera exponencial, um número limitado de vezes. Uma execução que não consegue ler o registro fica marcada como falha e o comando termina com erro.
- **Mudanças são registradas.** Estudos novos, atualizações e mudanças de status de recrutamento ficam registrados para os alertas e aparecem no histórico de cada estudo.
- **Datas parciais são normalizadas** (`2024` → `2024-01-01`, `2024-06` → `2024-06-01`), e o payload completo do estudo fica em `raw_json`.

### Pesquisa

- Pesquisa paginada com filtros por palavras no título e no resumo (texto completo do MySQL), condição, palavra-chave, termo MeSH, status de recrutamento, fase, tipo de estudo, patrocinador, país, cidade, datas de início, de publicação e de última atualização, resultados e distância de um ponto.
- Uma página de estudo com todas as listas relacionadas: centros, intervenções, braços, desfechos, elegibilidade, identificadores, colaboradores, termos MeSH, publicações com links para o PubMed e as mudanças de status registradas pela importação.

### Alertas

- Um alerta é uma pesquisa salva. Depois de cada importação, os estudos novos e atualizados que correspondem a ele são registrados.
- Os e-mails saem assim que um estudo corresponde, ou em um resumo diário ou semanal. Mudanças de status, como um estudo que começa a recrutar, podem ser incluídas ou não.
- A quantidade de alertas é limitada pelo plano (`alerts_limit`).

### Contas, planos e cobrança

- Cadastro com confirmação de e-mail, login com JWT, renovação de token, logout, redefinição de senha e alteração de perfil e senha.
- Papéis e permissões aplicados em todas as rotas protegidas: `view_clinical_trials`, `create_alerts`, `manage_users`, `manage_plans`, `manage_roles` e `manage_imports`.
- Contas novas ganham 14 dias de teste com os limites do plano Pro (`TRIAL_DAYS`).
- Planos pagos pelo Stripe com Laravel Cashier: Stripe Checkout na primeira assinatura, upgrade e downgrade com cobrança proporcional, cancelamento no fim do período, retomada e o portal do cliente do Stripe. Os webhooks do Stripe mantêm o plano da conta sincronizado.

### Aplicação web

Uma aplicação Vue 3 de página única servida pelo Laravel: páginas de pesquisa e de estudo, alertas com suas correspondências, conta e plano, e administração de usuários, planos, papéis e importações.

> Os dados dos estudos vêm de um registro público. Não são orientação médica, recomendação de tratamento nem decisão sobre quem pode participar de um estudo.

## Início rápido

### Requisitos

- Docker e Docker Compose
- Portas `8000` (web), `3306` (MySQL) e `5173` (Vite) livres no host, ou outras portas definidas no `.env` (`APP_PORT`, `FORWARD_DB_PORT`, `VITE_PORT`)

### Subir o ambiente

```sh
cp .env.example .env
docker-compose up -d --build
docker-compose exec app php artisan db:seed
docker-compose exec app php artisan clinicaltrials:fetch --max-pages=1
```

O `.env.example` já traz os valores de banco usados pelo `docker-compose.yml`. Ao iniciar, o contêiner `app` espera o MySQL, roda `composer install` se `vendor/` não existir, gera `APP_KEY` e `JWT_SECRET` quando estão vazios e roda as migrations. Os contêineres `queue` e `scheduler` executam os jobs da fila e o agendamento diário; o contêiner `node` roda o servidor de desenvolvimento do Vite.

Abra `http://localhost:8000` e entre com o administrador criado pelo seeder, `admin@admin.com` / `password`. Essas credenciais servem só para desenvolvimento local.

`--max-pages=1` importa uma primeira página de 100 estudos. Sem essa opção, a primeira execução lê o registro inteiro, o que leva horas.

Para gerar o build da aplicação web em vez de usar o servidor de desenvolvimento: `docker-compose run --rm node npm run build`.

### Configurar a cobrança

Defina `STRIPE_KEY`, `STRIPE_SECRET` e `STRIPE_WEBHOOK_SECRET` no `.env`, crie no Stripe um preço recorrente para cada plano pago e salve o ID no plano (`PATCH /api/admin/plans/{plan}` com `stripe_price_id`, ou a página Planos na Administração). Aponte um webhook do Stripe para `https://<seu-host>/stripe/webhook` com os eventos `customer.subscription.*` e `invoice.*`.

## API

Todas as rotas ficam em `/api` e respondem em JSON. Rotas protegidas exigem `Authorization: Bearer <access_token>`.

| Método | Rota | Acesso | Descrição |
|---|---|---|---|
| `POST` | `/auth/register` | público | Cria uma conta; devolve um token (201) |
| `POST` | `/auth/login` | público, 5 tentativas por minuto | Token, ou HTTP 401 para credenciais inválidas |
| `POST` | `/auth/refresh` | token | Novo token |
| `POST` | `/auth/logout` | token | Invalida o token (204) |
| `GET` | `/auth/me` | token | Usuário atual, papéis, permissões e resumo da cobrança |
| `POST` | `/auth/forgot-password` | público | Envia um link de redefinição por e-mail |
| `POST` | `/auth/reset-password` | público | Define uma nova senha com o token do e-mail |
| `GET` | `/auth/verify-email/{user}/{hash}` | link assinado | Confirma o e-mail e redireciona para a aplicação web |
| `POST` | `/auth/email/verification-notification` | token | Reenvia o link de confirmação |
| `PUT` | `/account/profile`, `/account/password` | token | Altera nome e e-mail, ou a senha |
| `GET` | `/trials` | `view_clinical_trials` | Pesquisa; filtros abaixo, `sort`, `page`, `per_page` (até 100) |
| `GET` | `/trials/{nct_id}` | `view_clinical_trials` | Estudo com todas as listas relacionadas |
| `GET` `POST` | `/alerts` | `create_alerts`, e-mail confirmado | Lista ou cria alertas |
| `GET` `PATCH` `DELETE` | `/alerts/{id}` | dono | Lê, altera ou exclui um alerta |
| `GET` | `/alerts/{id}/matches` | dono | Estudos que corresponderam |
| `GET` | `/plans` | público | Planos com preços e limites |
| `GET` | `/billing` | e-mail confirmado | Plano em vigor e assinatura |
| `POST` | `/billing/plan` | e-mail confirmado | Escolhe um plano; devolve `checkout_url` quando há pagamento |
| `POST` | `/billing/cancel`, `/billing/resume` | e-mail confirmado | Cancela no fim do período, ou mantém a assinatura |
| `GET` | `/billing/portal` | e-mail confirmado | URL do portal do cliente do Stripe |
| `GET` `PATCH` | `/admin/users`, `/admin/users/{id}` | `manage_users` | Lista usuários; altera papéis e plano |
| `POST` `PATCH` `DELETE` | `/admin/plans`, `/admin/plans/{id}` | `manage_plans` | Gerencia planos |
| `GET` `PUT` | `/admin/roles`, `/admin/permissions`, `/admin/roles/{id}/permissions` | `manage_roles` | Gerencia as permissões dos papéis |
| `GET` `POST` | `/admin/imports` | `manage_imports` | Execuções da importação; coloca uma importação na fila (202) |

Filtros da pesquisa: `q`, `condition`, `keyword`, `mesh_term`, `sponsor`, `country`, `city`, `status[]`, `phase[]`, `study_type[]`, `has_results`, `start_date_from`/`_to`, `first_posted_from`/`_to`, `last_update_from`/`_to`, e `latitude` + `longitude` + `radius_km`. `sort` aceita `-last_update_post_date` (padrão), `last_update_post_date`, `±start_date`, `±study_first_post_date`, `±brief_title` e `relevance`.

Token ausente ou inválido devolve 401, permissão ausente 403, entrada inválida 422 com os erros por campo, e o limite de alertas do plano 403.

```sh
TOKEN=$(curl -s -X POST http://localhost:8000/api/auth/login -H "Content-Type: application/json" \
  -d '{"email":"admin@admin.com","password":"password"}' | jq -r .access_token)

curl "http://localhost:8000/api/trials?condition=asthma&status[]=RECRUITING&country=Brazil" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

## Como funciona

```text
Importação: scheduler → ImportClinicalTrialsJob → ClinicalTrialImporter
              → ClinicalTrialsGovClient (páginas, novas tentativas) → StudyMapper → ClinicalTrialSynchronizer (uma transação por estudo)
              → evento ClinicalTrialsImported → MatchAlertsJob → AlertMatcher → AlertNotifier (e-mail)
API:        nginx → PHP-FPM (Laravel) → routes/api.php → Form Request → controller → serviço
Web:        o Laravel serve resources/views/app.blade.php → Vue 3 + Vue Router + Pinia → /api
```

- `app/Services/ClinicalTrials/Import/` contém a importação. O cliente do registro fica atrás da interface `StudyRegistryClient`, o mapeador é uma função pura do payload e o sincronizador cuida da gravação.
- `app/Services/ClinicalTrials/Search/` monta a pesquisa com pequenas classes `TrialFilter` registradas no `AppServiceProvider`. Os alertas reaproveitam os mesmos critérios e filtros.
- `app/Services/Alerts/` aplica o limite do plano, cruza as importações e envia os e-mails. `app/Services/Billing/` contém as regras dos planos; o Stripe é acessado pela interface `SubscriptionGateway`.
- `app/Http/` mantém os controllers enxutos: Form Requests validam, serviços decidem, API Resources formatam o JSON.
- O ambiente Docker tem seis serviços: `app` (PHP 8.6 FPM), `queue`, `scheduler`, `mysql` (MySQL 8.4), `nginx` e `node` (Node.js 26 e Vite).

## Desenvolvimento

```sh
docker-compose exec app php artisan test
docker-compose exec app ./vendor/bin/pint --test
docker-compose run --rm node npm run typecheck
docker-compose run --rm node npm test
```

- Os testes PHP rodam em um banco MySQL dedicado, `clinical_saas_test`, criado por `docker/mysql/init/` quando o volume do MySQL é criado pela primeira vez. Para um volume que já existe, crie-o uma vez: `docker-compose exec mysql mysql -uroot -proot -e "CREATE DATABASE clinical_saas_test; GRANT ALL ON clinical_saas_test.* TO 'clinical_user'@'%';"`.
- Os testes da importação usam `Http::fake()` com uma resposta gravada da API em `tests/Fixtures/`; os testes nunca chamam o ClinicalTrials.gov nem o Stripe.
- O GitHub Actions (`.github/workflows/ci.yml`) roda o Pint, os testes PHP, a checagem de tipos, os testes do Vue e o build em pushes e pull requests para a `main`, em tags e manualmente pela aba Actions. As branches seguem de `dev` para `hom` e para `main`.

O esquema do banco é definido apenas pelas migrations em `database/migrations/`. Crie uma migration nova para cada mudança; não edite migrations que já rodaram.

## Estrutura do projeto

```text
app/
  Console/Commands/      clinicaltrials:fetch, alerts:send-digests
  Enums/                 status, fases, permissões, frequência dos alertas
  Http/                  Controllers/Api (e Admin/), Requests/, Resources/, Middleware/
  Jobs/, Events/, Listeners/, Notifications/, Policies/
  Models/                User, Role, Permission, Plan, Subscription, Alert, AlertMatch, ImportRun
  Models/ClinicalTrials/ ClinicalTrial, suas listas relacionadas e ClinicalTrialChange
  Services/              Auth/, ClinicalTrials/Import, ClinicalTrials/Search, Alerts/, Billing/, Admin/
config/                  clinicaltrials.php, billing.php, auth.php, jwt.php
database/                migrations/, factories/, seeders/
docs/                    logo, avaliação de registros
resources/js/            aplicação Vue: pages/, components/, stores/, api/
routes/                  api.php, web.php (aplicação web), console.php (agendamento)
tests/                   Feature/, Unit/, Fixtures/
```

## Fonte dos dados

Os dados dos estudos vêm do [ClinicalTrials.gov](https://clinicaltrials.gov/), mantido pela National Library of Medicine dos EUA. Os registros descrevem estudos cadastrados e não são orientação médica. Confira o registro oficial antes de confiar em qualquer detalhe de um estudo. [docs/registries-evaluation.md](docs/registries-evaluation.md) avalia outros registros (WHO ICTRP, EU CTR/CTIS e ReBEC).

## Licença

[MIT](LICENSE-MIT).
