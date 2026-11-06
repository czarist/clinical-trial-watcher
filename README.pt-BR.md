<h1 align="center">Clinical Trial Watcher</h1>

<h3 align="center">Coleta estudos clínicos do ClinicalTrials.gov, guarda em um banco pesquisável e entrega por uma API autenticada.</h3>

<p align="center">
  <a href="README.md">English</a> · <a href="README.pt-BR.md">Português (Brasil)</a>
</p>

<p align="center">
  <b>Um back end em Laravel para acompanhar pesquisas médicas: importar estudos, mantê-los atualizados e, nas próximas etapas, avisar os usuários quando surgirem estudos do seu interesse.</b>
</p>

<p align="center">
  <img alt="Status: early development" src="https://img.shields.io/badge/status-early_development-orange?style=flat-square">
  <img alt="PHP 8.3" src="https://img.shields.io/badge/PHP-8.3-777BB4?style=flat-square&logo=php&logoColor=white">
  <img alt="Laravel 11" src="https://img.shields.io/badge/Laravel-11-FF2D20?style=flat-square&logo=laravel&logoColor=white">
  <img alt="MySQL 8.0" src="https://img.shields.io/badge/MySQL-8.0-4479A1?style=flat-square&logo=mysql&logoColor=white">
  <img alt="Docker Compose" src="https://img.shields.io/badge/runs%20with-Docker%20Compose-2496ED?style=flat-square&logo=docker&logoColor=white">
  <img alt="Auth: JWT" src="https://img.shields.io/badge/auth-JWT-000000?style=flat-square&logo=jsonwebtokens&logoColor=white">
</p>

<p align="center">
  <a href="#recursos">Recursos</a> ·
  <a href="#início-rápido">Início rápido</a> ·
  <a href="#como-funciona">Como funciona</a> ·
  <a href="#desenvolvimento">Desenvolvimento</a> ·
  <a href="ROADMAP.md">Roadmap</a> ·
  <a href="CHANGELOG.md">Changelog</a>
</p>

## Recursos

O projeto está no começo. O que existe hoje é a base de dados: a importação, o modelo do banco e a autenticação. Busca, alertas e cobrança estão planejados no [roadmap](ROADMAP.md).

### Importar estudos do ClinicalTrials.gov

- **Um comando importa os estudos.** `php artisan clinicaltrials:fetch` lê estudos da API v2 do ClinicalTrials.gov e salva no banco. `--pageSize` define quantos estudos são lidos (padrão 100).
- **Rodar de novo atualiza em vez de duplicar.** Cada estudo é identificado pelo número NCT (`nct_id`), então uma nova importação atualiza os registros existentes. As listas relacionadas (condições, locais, desfechos etc.) são substituídas pela versão atual da API.
- **As datas são normalizadas.** A API devolve datas parciais como `2024` ou `2024-06`; elas são gravadas como datas completas (`2024-01-01`, `2024-06-01`).
- **O registro original é mantido.** A resposta completa da API de cada estudo fica em `raw_json`, para que campos ainda não mapeados possam ser aproveitados depois sem buscar de novo.

### O que é guardado de cada estudo

| Dados | Tabela |
|---|---|
| Identificação, títulos, sigla, status, datas, fase, tipo de estudo, participantes, patrocinador, elegibilidade (critérios, idades, sexo), supervisão da FDA, acesso expandido, compartilhamento de dados individuais (IPD) | `clinical_trials` |
| Condições estudadas | `clinical_trial_conditions` |
| Palavras-chave | `clinical_trial_keywords` |
| Termos MeSH, principais e ancestrais | `clinical_trial_mesh_terms` |
| Centros: instituição, cidade, estado, país, CEP, coordenadas | `clinical_trial_locations` |
| Intervenções: tipo, nome, descrição | `clinical_trial_interventions` |
| Braços do estudo | `clinical_trial_arm_groups` |
| Desfechos primários, secundários e outros | `clinical_trial_outcomes` |
| Identificadores secundários | `clinical_trial_secondary_ids` |
| Colaboradores | `clinical_trial_collaborators` |
| Publicações (PMID, citação, link) | `clinical_trial_references` |

### Contas e acesso

- **Autenticação JWT** na API: login com e-mail e senha, recebimento de um token bearer, consulta do usuário atual e logout.
- **Papéis e permissões.** `Admin` tem todas as permissões; `User` pode ver estudos clínicos e criar alertas.
- **Planos e assinaturas (só o modelo de dados).** Três planos criados pelo seeder, Basic (grátis, 5 alertas), Pro (29,99/mês, 50 alertas) e Enterprise (99,99/mês, ilimitado), e uma tabela `subscriptions` pronta para um provedor de pagamento. Ainda não há fluxo de pagamento.

## Início rápido

### Requisitos

- Docker e Docker Compose
- Portas `8000` (web), `3306` (MySQL) e `5173` (Vite) livres na máquina

### Subir o ambiente

```sh
cp .env.example .env
```

Ajuste o banco no `.env` para bater com o `docker-compose.yml`:

```dotenv
DB_DATABASE=clinical_saas
DB_USERNAME=clinical_user
DB_PASSWORD=clinical_pass
```

Depois suba os containers e conclua a configuração:

```sh
docker-compose up -d --build
docker-compose exec app php artisan jwt:secret
docker-compose exec app php artisan db:seed
docker-compose exec app php artisan clinicaltrials:fetch
```

Ao iniciar, o container `app` espera o MySQL, roda `composer install` se `vendor/` não existir, gera o `APP_KEY` se não estiver definido e roda as migrations. A API fica em `http://localhost:8000`.

O seeder cria uma conta de administrador: `admin@admin.com` com a senha `password`. Troque fora do ambiente local.

### Testar a API

```sh
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@admin.com","password":"password"}'

curl http://localhost:8000/api/auth/me \
  -H "Authorization: Bearer <access_token>"
```

| Método | Rota | Autenticação | Retorna |
|---|---|---|---|
| `POST` | `/api/auth/login` | nenhuma | `access_token`, `token_type`, `expires_in` |
| `POST` | `/api/auth/logout` | token bearer | Mensagem de confirmação |
| `GET` | `/api/auth/me` | token bearer | O usuário atual |
| `GET` | `/up` | nenhuma | Verificação de saúde |

## Como funciona

```text
Importação: artisan clinicaltrials:fetch -> API v2 do ClinicalTrials.gov -> FetchClinicalTrials -> clinical_trials + tabelas relacionadas
API:        cliente -> nginx -> PHP-FPM (Laravel) -> routes/api.php -> AuthController -> AuthService -> guard JWT
```

- `app/Console/Commands/FetchClinicalTrials.php` lê os módulos `protocolSection` e `derivedSection` da API, mapeia para colunas e salva o estudo e suas linhas relacionadas.
- `app/Models/ClinicalTrials/` tem `ClinicalTrial` e um model para cada lista relacionada; `ClinicalTrial` define todas as relações `hasMany`.
- `app/Models/` tem `User`, `Role`, `Permission`, `Plan` e `Subscription`. `User` implementa `JWTSubject` para o `tymon/jwt-auth`.
- `app/Http/Controllers/Api/AuthController.php` valida a entrada com `LoginRequest` e delega para `app/Services/AuthService.php`.
- `config/auth.php` define o guard `api` com o driver `jwt`; `config/jwt.php` configura os tokens.

O ambiente Docker tem quatro serviços: `app` (PHP 8.3 FPM com Composer e Node.js), `mysql` (MySQL 8.0), `nginx` (serve `public/` e repassa o PHP para `app:9000`) e `node` (roda o servidor de desenvolvimento do Vite).

## Desenvolvimento

```sh
docker-compose exec app php artisan test
docker-compose exec app ./vendor/bin/pint
docker-compose exec app php artisan migrate:fresh --seed
```

- `php artisan test` roda o PHPUnit. Por enquanto a suíte tem só os testes de exemplo do framework.
- `./vendor/bin/pint` formata o código com o Laravel Pint.
- `php artisan migrate:fresh --seed` recria o banco com planos, papéis, permissões e a conta de administrador.

O esquema do banco é definido apenas pelas migrations em `database/migrations/`. Crie uma migration nova para cada mudança; não edite migrations que já rodaram.

## Estrutura do projeto

```text
app/
  Console/Commands/     FetchClinicalTrials.php
  Http/Controllers/Api/ AuthController.php
  Http/Requests/        LoginRequest.php
  Models/               User, Role, Permission, Plan, Subscription
  Models/ClinicalTrials/ ClinicalTrial e seus models relacionados
  Services/             AuthService.php
config/                 auth.php (guard api), jwt.php
database/
  migrations/           users, tabelas do SaaS, tabelas de estudos clínicos
  seeders/              InitialSaasSeeder (planos, papéis, permissões), UserSeeder (admin)
nginx/default.conf
routes/                 api.php, web.php, console.php
tests/                  Feature/, Unit/
docker-compose.yml
Dockerfile
entrypoint.sh
```

## Fonte dos dados

Os dados dos estudos vêm do [ClinicalTrials.gov](https://clinicaltrials.gov/), mantido pela National Library of Medicine dos EUA. Os registros descrevem estudos cadastrados e não são orientação médica. Confira o registro oficial antes de confiar em qualquer detalhe de um estudo.

## Licença

Ainda não foi escolhida uma licença para este projeto.
