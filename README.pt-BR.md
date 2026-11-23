<p align="center"><img src="docs/logo.svg" alt="Clinical Trial Watcher" width="128"></p>

<h1 align="center">Clinical Trial Watcher</h1>

<h3 align="center">Importa estudos clínicos do ClinicalTrials.gov, permite pesquisá-los e avisa por e-mail quando estudos que você acompanha abrem, mudam ou começam a recrutar.</h3>

<p align="center">
  <a href="README.md">English</a> · <a href="README.pt-BR.md">Português (Brasil)</a> · <a href="README.es.md">Español</a> · <a href="README.zh-CN.md">简体中文</a> · <a href="README.zh-TW.md">繁體中文</a> · <a href="README.ja.md">日本語</a> · <a href="README.ko.md">한국어</a>
</p>

<p align="center">
  <img alt="Status: desenvolvimento inicial" src="https://img.shields.io/badge/status-desenvolvimento_inicial-orange?style=flat-square">
  <a href="https://github.com/czarist/clinical-trial-watcher/actions/workflows/ci.yml"><img alt="Testes" src="https://img.shields.io/github/actions/workflow/status/czarist/clinical-trial-watcher/ci.yml?branch=main&label=tests&logo=githubactions&logoColor=white&style=flat-square"></a>
  <a href="https://github.com/czarist/clinical-trial-watcher/actions/workflows/packages.yml"><img alt="Auditoria dos pacotes" src="https://img.shields.io/github/actions/workflow/status/czarist/clinical-trial-watcher/packages.yml?branch=main&label=packages&logo=dependabot&logoColor=white&style=flat-square"></a>
  <img alt="PHP 8.6" src="https://img.shields.io/badge/PHP-8.6-777BB4?style=flat-square&logo=php&logoColor=white">
  <img alt="Laravel 13" src="https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white">
  <img alt="Vue 3" src="https://img.shields.io/badge/Vue-3-4FC08D?style=flat-square&logo=vuedotjs&logoColor=white">
  <img alt="TypeScript 7" src="https://img.shields.io/badge/TypeScript-7-3178C6?style=flat-square&logo=typescript&logoColor=white">
  <img alt="MySQL 8.4" src="https://img.shields.io/badge/MySQL-8.4-4479A1?style=flat-square&logo=mysql&logoColor=white">
  <img alt="Redis 8.10" src="https://img.shields.io/badge/Redis-8.10-FF4438?style=flat-square&logo=redis&logoColor=white">
  <img alt="Stripe" src="https://img.shields.io/badge/cobran%C3%A7a-Stripe-635BFF?style=flat-square&logo=stripe&logoColor=white">
  <img alt="Auth: JWT" src="https://img.shields.io/badge/auth-JWT-000000?style=flat-square&logo=jsonwebtokens&logoColor=white">
  <img alt="Docker Compose" src="https://img.shields.io/badge/roda%20com-Docker%20Compose-2496ED?style=flat-square&logo=docker&logoColor=white">
  <img alt="Licença: MIT" src="https://img.shields.io/badge/licen%C3%A7a-MIT-blue?style=flat-square">
</p>

<p align="center">
  <a href="#funcionalidades">Funcionalidades</a> ·
  <a href="#stack">Stack</a> ·
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
- **Mudanças são registradas com o valor anterior e o novo.** Um estudo novo é registrado como novo e vira a base de comparação. Nas importações seguintes são comparados o status geral de recrutamento, os centros (adicionados, removidos ou com status do centro alterado), as datas de início e de conclusão (com a precisão original: uma data só com mês continua sendo um mês) e a publicação de resultados, e as diferenças ficam gravadas de forma estruturada. As listas de centros são comparadas por instituição e local, então uma mudança na ordem em que o registro os lista não é uma mudança. Cada mudança tem uma impressão digital estável, então uma importação repetida não consegue registrá-la nem notificá-la duas vezes.
- **Datas parciais são normalizadas** (`2024` → `2024-01-01`, `2024-06` → `2024-06-01`), e o payload completo do estudo fica em `raw_json`.

### Outros registros

Outros doze registros podem ser importados com `php artisan clinicaltrials:fetch --source=<chave>`. Só o ClinicalTrials.gov roda no agendamento diário.

| Chave | Registro | Lido por | Incremental | Uso comercial |
|---|---|---|---|---|
| `ctis` | CTIS (UE/EEE) | API JSON do portal | Sim (última atualização) | Sim, com crédito à EMA |
| `isrctn` | ISRCTN | API XML | Sim (última edição) | Sim (CC BY / CC0) |
| `euctr` | EU Clinical Trials Register | Exportação em texto simples | Só estudos novos | Sim, com crédito ao registro |
| `drks` | DRKS (Alemanha) | Dump JSON; só registros CC BY 4.0 (registrados ou atualizados a partir de 2025) | Sim (última atualização) | Sim (CC BY 4.0) |
| `reec` | REEC (Espanha) | API REST | Inscrições dos últimos 90 dias | Sim, citando a AEMPS |
| `rebec` | ReBEC (Brasil) | Páginas web | Sim (última aprovação) | Não confirmado |
| `renis` | RENIS (Argentina) | Arquivo XLSX | Não | Sim (CC BY 4.0) |
| `health_canada` | Health Canada | API JSON | Não | Sim (OGL Canada) |
| `tfda` | TFDA (Taiwan) | ZIP de CSV | Não | Sim (Open Government Data License) |
| `ppb_kenya` | Pharmacy and Poisons Board (Quênia) | Páginas web | Sim (data de aprovação) | Não confirmado |
| `eda_egypt` | Egyptian Drug Authority | PDF | Não | Não confirmado |
| `moz_ukraine` | Portarias do Ministério da Saúde da Ucrânia | Anexos em PDF | Portarias mais recentes | Não confirmado |

Outros oito importadores estão prontos e testados, mas ficam desligados até que o operador forneça o que o registro exige:

| Chave | Registro | Lido por | Requer | Uso comercial |
|---|---|---|---|---|
| `nci_cts` | NCI Clinical Trials Search API (EUA, câncer) | API JSON; os estudos apontam para os seus registros no ClinicalTrials.gov | `REGISTRY_NCI_CTS_API_KEY` | Sim, com crédito ao NCI |
| `mfds` | Planos de estudo aprovados pelo MFDS (Coreia do Sul) | API do data.go.kr | `REGISTRY_MFDS_SERVICE_KEY` | Sim |
| `cris` | CRIS (Coreia do Sul) | API do data.go.kr | `REGISTRY_CRIS_SERVICE_KEY` | Não (KOGL Tipo 2): aparece com atribuição, nunca nos digests com IA |
| `anzctr` | ANZCTR (Austrália e Nova Zelândia) | Páginas web | Permissão por escrito, `REGISTRY_PERMISSION_ANZCTR` | Não |
| `umin` | UMIN-CTR (Japão) | Dump CSV diário | Permissão por escrito, `REGISTRY_PERMISSION_UMIN` | Não |
| `hra` | Resumos de pesquisa da HRA (Reino Unido) | Páginas web | Permissão por escrito, `REGISTRY_PERMISSION_HRA` | Não |
| `sukl` | SÚKL (Tchéquia) | API de busca do site | Permissão por escrito, `REGISTRY_PERMISSION_SUKL` | Não |
| `orphanet` | Estudos clínicos da Orphanet | Dump XML do Orphadata | Um contrato com a Orphanet-AISBL, `REGISTRY_PERMISSION_ORPHANET` e `REGISTRY_ORPHANET_DUMP_URL` | Não |

Os mapeamentos das APIs protegidas por chave e do UMIN foram feitos a partir da documentação, com fixtures sintéticas, porque não havia chave nem amostra disponível; confira-os na primeira execução real.

- **Cada importador passou por uma revisão jurídica**, registrada na sua classe de origem e em [docs/registries-evaluation.md](docs/registries-evaluation.md) (seção 7), que também lista os registros que ainda não podem ser importados e o que cada um exige (WHO ICTRP, UMIN-CTR, CRIS, ANZCTR, HRA e outros).
- **Registros de origens cujos termos não confirmam o uso comercial aparecem de graça com atribuição, mas ficam fora dos digests com IA**, até que o registro confirme a reutilização por escrito.
- **Acesso educado.** As requisições levam um user agent com endereço de contato (`REGISTRY_CONTACT`, obrigatório para downloads e páginas web), seguem o robots.txt e o seu Crawl-delay em downloads e páginas web, vão uma por vez por registro com pelo menos `REGISTRY_MIN_INTERVAL_MS` de intervalo e são repetidas um número limitado de vezes. Um login, captcha ou HTTP 401/403 interrompe a execução: peça permissão ou uma exportação ao registro. Nada é contornado.
- **Páginas guardadas.** Todo corpo de resposta é guardado (`storage/app/registry-raw/`) com o seu `ETag`/`Last-Modified`, então páginas sem mudança não são baixadas de novo, e `clinicaltrials:fetch --source=<chave> --from-cache` lê de novo as páginas guardadas depois de uma correção no parser (passe o mesmo `--since` ou `--full` da execução repetida).
- **O registro como publicado** (XML, linha de CSV, página web, texto do PDF) fica guardado com cada estudo, e um estudo cujo conteúdo normalizado não mudou não é regravado.
- **Mudanças de layout.** Uma execução completa de um registro lido por download ou página web que extrai muito menos registros que a anterior, ou qualquer execução cuja parcela de campos preenchidos cai bruscamente, fica marcada como `partial` com o motivo. As importações nunca apagam estudos.

### Registros duplicados

Cada registro guarda a sua origem (`source`) e o seu identificador nela (`source_id`), único por origem, então importar o mesmo registro de novo o atualiza. Os registros estão listados em `config/registries.php`.

- **Estudos registrados mais de uma vez aparecem uma vez só.** Os identificadores de registro de cada estudo (o próprio e os que ele cita, como um número ISRCTN ou EudraCT entre os IDs secundários do ClinicalTrials.gov) são normalizados (`ISRCTN 12345678` e `isrctn12345678` são o mesmo) e comparados. Registros que compartilham um deles são ligados e agrupados: aparece o registro da origem listada primeiro em `config/registries.php`, e os outros ficam como inscrições adicionais do estudo. Números de protocolo do patrocinador, números de financiamento e títulos nunca são usados para juntar estudos.
- **Correspondências duvidosas esperam uma pessoa.** Dois registros da mesma origem que compartilham um identificador, ou um grupo que ficaria com dois registros da mesma origem, não são juntados: os vínculos ficam como `conflict`. Os operadores listam os vínculos em `GET /api/admin/trial-links` e os confirmam ou rejeitam; a decisão é mantida pelas importações seguintes. Um vínculo cujo identificador compartilhado desaparece é removido e o grupo é recalculado.
- **Um estudo, um alerta.** A pesquisa lista cada estudo uma vez, uma mudança em qualquer inscrição é comparada com o estudo, e a mesma notícia publicada por um segundo registro (o estudo aparecendo nele, ou o mesmo status) não gera nova correspondência nem novo e-mail. Um digest tem um item por estudo.
- **Dados existentes.** Rode `php artisan trials:reconcile-duplicates` uma vez depois de atualizar, para indexar os identificadores dos estudos já gravados; as importações fazem isso para cada estudo que salvam.

### Pesquisa

- Pesquisa paginada com filtros por palavras no título e no resumo (texto completo do MySQL), condição, palavra-chave, termo MeSH, status de recrutamento, fase, tipo de estudo, patrocinador, país, cidade, datas de início, de publicação e de última atualização, resultados e distância de um ponto.
- Uma página de estudo com todas as listas relacionadas: centros, intervenções, braços, desfechos, elegibilidade, identificadores, colaboradores, termos MeSH, publicações com links para o PubMed e as mudanças de status registradas pela importação.

### Alertas

- Um alerta é uma pesquisa salva. Depois de cada importação, os estudos novos e atualizados que correspondem a ele são registrados.
- Os e-mails saem assim que um estudo corresponde, ou em um resumo diário ou semanal. Mudanças de status, como um estudo que começa a recrutar, podem ser incluídas ou não.
- Os alertas básicos são gratuitos. Todos os planos têm o mesmo limite de buscas salvas (`SAVED_SEARCH_LIMIT`, 100 por padrão); buscas criadas acima de um limite menor são mantidas.

### Digests com IA (Pro AI e Team AI)

- Uma busca salva pode ter um digest com IA, diário ou semanal, em qualquer um dos sete idiomas abaixo. Desativá-lo mantém o alerta básico funcionando.
- O backend escolhe as mudanças que corresponderam à busca no período, conta e ordena por relevância a partir dos critérios da busca (por exemplo, um centro que começou a recrutar num país monitorado fica acima de uma mudança de data). O modelo só redige um resumo factual curto de cada mudança a partir dos fatos registrados.
- Cada digest mostra a busca salva, o período, o número de atualizações correspondentes, as mudanças mais relevantes com o motivo de corresponderem e, para cada estudo, o número NCT, a data de atualização no registro e o link do ClinicalTrials.gov (montado pelo servidor a partir do número NCT validado). Fatos ausentes aparecem como ausentes, e o digest avisa quando lista só as atualizações mais relevantes.
- Só fatos públicos do registro vão para o modelo: nenhum dado da conta, e-mail ou anotação do usuário. A resposta precisa ser um JSON ligado às mudanças pedidas; resumos com links, outros números NCT ou frases sobre elegibilidade, tratamento ou eficácia são rejeitados.
- Quando a IA está desligada, falha depois de um número limitado de tentativas ou devolve uma saída inválida, o digest usa um resumo factual montado a partir das mudanças registradas, e a edição grava o motivo (`ai_disabled`, `provider_error`, `invalid_output`).
- Cada resumo fica em cache por versão da mudança, idioma e versão do prompt e é reaproveitado por todos os assinantes; a relevância é calculada por busca salva.
- Cada edição tem uma identidade estável (destinatário ou equipe, busca salva e período) e uma linha de entrega por destinatário, então novas execuções do agendador e novas tentativas de job não enviam duas vezes. O acesso pago e a participação na equipe são verificados de novo antes do envio.
- Duas edições de exemplo com dados sintéticos ficam em `/digests/samples`, abertas sem conta.

### Equipes (Team AI)

- Um assinante do Team AI é dono de uma equipe, com uma única assinatura. O dono convida usuários cadastrados por e-mail; eles só entram aceitando o convite.
- Os membros veem as buscas compartilhadas e os relatórios de digest da equipe e escolhem se recebem os digests da equipe por e-mail. Só o dono altera a equipe, os membros e as buscas compartilhadas. Toda rota de equipe verifica a participação.
- Quando a assinatura Team AI do dono termina, as buscas compartilhadas e os relatórios ficam pausados; os digests pessoais de um membro dependem do plano dele.

### Contas, planos e cobrança

- Cadastro com confirmação de e-mail, login com JWT, renovação de token, logout, redefinição de senha e alteração de perfil e senha.
- Papéis e permissões aplicados em todas as rotas protegidas: `view_clinical_trials`, `create_alerts`, `manage_users`, `manage_plans`, `manage_roles` e `manage_imports`.
- Planos, em dólares americanos:

  | Plano | Preço | Inclui |
  |---|---|---|
  | Free | US$ 0 | Pesquisa, filtros, detalhes dos estudos, histórico de mudanças, buscas salvas e alertas básicos por e-mail |
  | Pro AI | US$ 19 por mês | Digests com IA das suas buscas salvas |
  | Team AI | US$ 99 por mês por equipe | Buscas salvas compartilhadas, digests com IA compartilhados e uma página de relatório para cada digest da equipe |

- Contas novas começam no Free, sem período de teste.
- Planos pagos pelo Stripe com Laravel Cashier: Stripe Checkout na primeira assinatura, troca entre Pro AI e Team AI com cobrança proporcional, cancelamento no fim do período, retomada e o portal do cliente do Stripe. Os webhooks do Stripe mantêm o plano da conta sincronizado. Depois de um cancelamento, os recursos pagos continuam até o fim do período pago.
- Basic, Pro e Enterprise são planos legados. Não são mais vendidos e não dão acesso a IA; as assinaturas existentes neles, os preços e os clientes do Stripe ficam como estão, e os assinantes ainda podem cancelar, retomar, usar o portal do cliente ou mudar para um plano atual.

### Aplicação web

Uma aplicação Vue 3 de página única servida pelo Laravel: páginas de pesquisa e de estudo, alertas com suas correspondências e configurações de digest com IA, relatórios de digest, digests de exemplo, equipes (membros, convites e buscas compartilhadas), conta e plano, e administração de usuários, planos, papéis e importações.

### Idiomas

A aplicação web, as mensagens da API, os e-mails e os digests com IA estão disponíveis em inglês (o padrão), português do Brasil, espanhol, chinês simplificado, chinês tradicional, japonês e coreano.

- A aplicação web abre no idioma do navegador quando ele é oferecido (senão em inglês) e tem um menu de idioma no cabeçalho, nas páginas de login e na página da conta. Datas, números e preços seguem o idioma escolhido.
- Cada conta guarda o seu idioma (`locale`): ele é definido no cadastro e alterado pelo menu ou por `PUT /api/account/locale`. Os e-mails (confirmação, redefinição de senha, alertas, convites de equipe) usam o idioma do destinatário; um digest com IA usa o idioma escolhido na sua busca salva.
- A API responde no idioma do cabeçalho `Accept-Language`, que a aplicação web sempre envia, e usa inglês quando o idioma não é oferecido. A resposta traz `Content-Language`.
- Os dados do registro (títulos, resumos, condições, centros) continuam no idioma do ClinicalTrials.gov; só a interface em volta deles é traduzida.

As traduções ficam em `lang/<locale>/` e `lang/<locale>.json` no backend e em `resources/js/i18n/locales/` na aplicação web. O inglês é a fonte: os testes falham quando outro idioma não tem uma chave ou um placeholder.

> Os dados dos estudos vêm de um registro público. Não são orientação médica, recomendação de tratamento nem decisão sobre quem pode participar de um estudo.

## Stack

Versões instaladas em 23/11/2026. As restrições de versão estão em `composer.json` e `package.json`.

| Camada | Tecnologia |
|---|---|
| Linguagem e framework | PHP 8.6, Laravel 13.43 |
| Banco de dados | MySQL 8.4 LTS (dados, busca de texto completo, fila e sessões) |
| Cache | Redis 8.10 pelo Predis 3.7 (cache, limite de requisições, lista de tokens JWT invalidados) |
| Autenticação | JWT com `tymon/jwt-auth` 2.3 |
| Cobrança | Stripe pelo Laravel Cashier 16.11 |
| Cliente HTTP | Guzzle 8.6 (cliente HTTP do Laravel) para a API do ClinicalTrials.gov |
| E-mail | Laravel Mail e notificações (`MAIL_MAILER`; `log` no desenvolvimento local) |
| Aplicação web | Vue 3.5, Vue Router 5.5, Pinia 4.1, TypeScript 7.0, Public Sans (Google Fonts) |
| Build do front-end | Vite 8.3, `laravel-vite-plugin` 3.2, `@vitejs/plugin-vue` 6.0 |
| Testes e qualidade do PHP | PHPUnit 13.4, Mockery 1.6, Faker 1.24, Collision 8.9, Laravel Pint 1.32, Ignition 2.13 (páginas de erro) |
| Testes e qualidade do front-end | Vitest 5.1, Vue Test Utils 2.6, jsdom 31, `vue-tsc` 3.3 (com `@typescript/typescript6` 6.0) |
| Ferramentas | Composer 2.11, Node.js 26, npm, Laravel Tinker 3.2 |
| Contêineres | Docker Compose: PHP-FPM, worker da fila, agendador, MySQL, Redis, nginx 1.30, Node.js (Vite) |
| CI | GitHub Actions |
| IA | Qualquer API de chat completions compatível com a OpenAI, pelo cliente HTTP do Laravel (opcional) |
| Serviços externos | API v2 do ClinicalTrials.gov, Stripe, um provedor de IA compatível com a OpenAI, PubMed (links pelo PMID) |

O Laravel Breeze 2.4 e o Laravel Sail 1.71 estão instalados como dependências de desenvolvimento vindas do esqueleto do Laravel, mas não são usados: a aplicação tem seu próprio front-end em Vue e roda com seu próprio Docker Compose. O TypeScript 7 checa os arquivos `.ts`. O compilador nativo dele não tem mais a API JavaScript que o `vue-tsc` usa, então `npm run typecheck:vue` roda o `vue-tsc` sobre o pacote oficial do TypeScript 6, `@typescript/typescript6` (`scripts/vue-tsc.cjs`), para checar os arquivos `.vue`; `npm run typecheck` roda os dois. O Redis usa o Predis porque a extensão phpredis ainda não compila no PHP 8.6. O `package.json` força versões corrigidas de `glob` e `minimatch` (`overrides`), porque o `@vue/test-utils` ainda depende de versões vulneráveis.

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

O `.env.example` já traz os valores de banco usados pelo `docker-compose.yml`. Ao iniciar, o contêiner `app` espera o MySQL, roda `composer install` se `vendor/` não existir, gera `APP_KEY` e `JWT_SECRET` quando estão vazios e roda as migrations. Os contêineres `queue` e `scheduler` executam os jobs da fila e o agendamento diário, o `redis` guarda o cache, e o contêiner `node` roda o servidor de desenvolvimento do Vite.

Abra `http://localhost:8000` e entre com o administrador criado pelo seeder, `admin@admin.com` / `password`. Essas credenciais servem só para desenvolvimento local.

`--max-pages=1` importa uma primeira página de 100 estudos. Sem essa opção, a primeira execução lê o registro inteiro, o que leva horas.

Para gerar o build da aplicação web em vez de usar o servidor de desenvolvimento: `docker-compose run --rm node npm run build`.

### Configurar outros registros

Defina `REGISTRY_CONTACT` no `.env` com uma URL ou endereço de e-mail pelo qual os operadores dos registros possam falar com você; importações por download ou página web não rodam sem ele. Depois de puxar mudanças no `Dockerfile` (ele instala o `poppler-utils` para os registros em PDF), reconstrua a imagem com `docker-compose up -d --build`. Em seguida, importe um registro de cada vez, por exemplo `docker-compose exec app php artisan clinicaltrials:fetch --source=isrctn --max-pages=1`.

Registros que exigem uma chave do operador a leem de `REGISTRY_<KEY>_SERVICE_KEY` ou `REGISTRY_<KEY>_API_KEY` (veja o `.env.example`). Registros que exigem permissão por escrito ficam desligados até que ela seja registrada com `REGISTRY_PERMISSION_<KEY>=true`; defina isso só depois que o registro a concedeu por escrito.

### Configurar a cobrança

A pesquisa e os alertas básicos funcionam sem o Stripe. Para vender o Pro AI e o Team AI:

1. Defina `STRIPE_KEY`, `STRIPE_SECRET` e `STRIPE_WEBHOOK_SECRET` no `.env`.
2. No Stripe, crie dois preços recorrentes mensais em USD: US$ 19,00 para o Pro AI e US$ 99,00 para o Team AI. Coloque os IDs em `STRIPE_PRICE_PRO_AI` e `STRIPE_PRICE_TEAM_AI`.
3. Rode `docker-compose exec app php artisan billing:check-prices`. Ele copia os IDs para os planos e confere se cada preço está ativo e cobra o valor do plano em USD todo mês; se não, falha e diz qual é o problema. O checkout faz a mesma conferência e recusa um preço divergente.
4. Aponte um webhook do Stripe para `https://<seu-host>/stripe/webhook` com os eventos `customer.subscription.*` e `invoice.*`.

Assinaturas existentes: a migration `2026_11_24_000001_add_free_and_ai_plans` marca Basic, Pro e Enterprise como legados sem alterar preços, IDs de preço do Stripe, assinaturas ou clientes. Quem assina um plano legado continua nele, cobrado como antes, sem recursos de IA. Mantenha o `stripe_price_id` dos planos legados preenchido (página Planos na Administração) para que os webhooks continuem reconhecendo essas assinaturas; não é preciso mapear nada para os planos novos. Contas ainda dentro do antigo teste de 14 dias passam a usar o Free.

### Configurar a IA

Os digests com IA funcionam sem provedor: nesse caso usam o resumo factual. Para que os resumos sejam escritos por um modelo, defina no `.env`:

```sh
AI_DIGESTS_ENABLED=true
AI_BASE_URL=https://api.openai.com/v1   # qualquer API compatível com a OpenAI
AI_API_KEY=...                          # só no servidor
AI_MODEL=...                            # um nome de modelo aceito pelo provedor
```

Limites: `AI_TIMEOUT` (segundos, 30), `AI_RETRIES` (2) e `AI_RETRY_SLEEP_MS` (2000), `AI_MAX_INPUT_CHARS` (12000 caracteres por requisição), `AI_MAX_OUTPUT_TOKENS` (1200) e `AI_DIGEST_MAX_ITEMS` (10 estudos por digest). O log registra o status, a duração, os acertos de cache e os tokens de cada digest, nunca a chave da API nem a entrada e a saída do modelo. Os operadores veem como cada edição foi escrita (`ai`, `partial` ou `fallback`) e o motivo da alternativa em `GET /api/admin/digests`.

O agendador coloca na fila os digests diários às 07:30 e os semanais às segundas às 07:30 (`php artisan digests:dispatch daily|weekly`); o contêiner `queue` gera e envia.

### Verificar localmente

1. Entre com o administrador do seeder e importe uma página de estudos (acima).
2. Crie um alerta na página Alertas: funciona no Free. A seção de digest com IA mostra os digests de exemplo e a ação de upgrade.
3. Abra `http://localhost:8000/digests/samples` para ver as duas edições de exemplo.
4. Sem o Stripe, dê um plano a uma conta pelo Tinker para testar as telas pagas, por exemplo uma linha local em `subscriptions` com `stripe_status` `active` e o `plan_id` do Pro AI ou do Team AI. Depois ative um digest com IA, rode outra importação e `php artisan digests:dispatch daily --end=tomorrow` (assim o período inclui hoje); com `MAIL_MAILER=log` o e-mail do digest vai para `storage/logs/laravel.log` e o relatório aparece na página Digests com IA.

## API

Todas as rotas ficam em `/api` e respondem em JSON. Rotas protegidas exigem `Authorization: Bearer <access_token>`.

| Método | Rota | Acesso | Descrição |
|---|---|---|---|
| `POST` | `/auth/register` | público | Cria uma conta (`locale` opcional, senão o idioma da requisição); devolve um token (201) |
| `POST` | `/auth/login` | público, 5 tentativas por minuto | Token, ou HTTP 401 para credenciais inválidas |
| `POST` | `/auth/refresh` | token | Novo token |
| `POST` | `/auth/logout` | token | Invalida o token (204) |
| `GET` | `/auth/me` | token | Usuário atual, papéis, permissões e resumo da cobrança |
| `POST` | `/auth/forgot-password` | público | Envia um link de redefinição por e-mail |
| `POST` | `/auth/reset-password` | público | Define uma nova senha com o token do e-mail |
| `GET` | `/auth/verify-email/{user}/{hash}` | link assinado | Confirma o e-mail e redireciona para a aplicação web |
| `POST` | `/auth/email/verification-notification` | token | Reenvia o link de confirmação |
| `PUT` | `/account/profile`, `/account/password` | token | Altera nome e e-mail, ou a senha |
| `PUT` | `/account/locale` | token | Altera o idioma da conta (`locale`: `en`, `pt_BR`, `es`, `zh_CN`, `zh_TW`, `ja`, `ko`) |
| `GET` | `/trials` | `view_clinical_trials` | Pesquisa, um resultado por estudo, cada um com o seu `registry`, `registry_url` e a `attribution` que os termos exigem; filtros abaixo (inclusive `source[]`, a chave de um registro), `sort`, `page`, `per_page` (até 100) |
| `GET` | `/trials/{nct_id}` | `view_clinical_trials` | Estudo com todas as listas relacionadas, seus `identifiers` normalizados e suas outras inscrições (`also_registered_as`, ou `duplicate_of` para um registro mostrado sob outro) |
| `GET` `POST` | `/alerts` | `create_alerts`, e-mail confirmado | Lista ou cria buscas salvas; `team_id` para as compartilhadas de uma equipe (o dono cria, os membros listam) |
| `GET` `PATCH` `DELETE` | `/alerts/{id}` | dono; membros da equipe leem as compartilhadas | Lê, altera ou exclui uma busca salva, inclusive `ai_digest_enabled`, `ai_digest_frequency` (`daily`, `weekly`) e `ai_digest_language` (`en`, `pt_BR`, `es`, `zh_CN`, `zh_TW`, `ja`, `ko`) |
| `GET` | `/alerts/{id}/matches` | dono, membros da equipe | Estudos que corresponderam |
| `GET` | `/plans` | público | Free, Pro AI e Team AI com preços em USD e recursos |
| `GET` | `/digests/samples` | público | Duas edições de digest de exemplo com dados sintéticos |
| `GET` | `/digests`, `/digests/{id}` | e-mail confirmado; destinatário ou membro da equipe | Edições de digest (`?team_id=` para uma equipe) e um relatório com o conteúdo |
| `GET` `POST` | `/teams` | e-mail confirmado | Suas equipes; cria uma (Team AI) |
| `GET` `PATCH` `DELETE` | `/teams/{id}` | membro (leitura), dono (alteração, exclusão) | Uma equipe com seus membros |
| `PATCH` | `/teams/{id}/membership` | membro | Receber ou não os digests da equipe por e-mail |
| `POST` `DELETE` | `/teams/{id}/invitations`, `/teams/{id}/invitations/{invitation}` | dono | Convida um usuário cadastrado por e-mail; retira um convite |
| `DELETE` | `/teams/{id}/members/{user}` | dono, ou o próprio membro para sair | Remove um membro |
| `GET` `POST` | `/team-invitations`, `/team-invitations/{id}/accept`, `/team-invitations/{id}/decline` | o usuário convidado | Convites para o seu e-mail; aceitar ou recusar |
| `GET` | `/billing` | e-mail confirmado | Plano em vigor e assinatura |
| `POST` | `/billing/plan` | e-mail confirmado | Escolhe um plano; devolve `checkout_url` quando há pagamento |
| `POST` | `/billing/cancel`, `/billing/resume` | e-mail confirmado | Cancela no fim do período, ou mantém a assinatura |
| `GET` | `/billing/portal` | e-mail confirmado | URL do portal do cliente do Stripe |
| `GET` `PATCH` | `/admin/users`, `/admin/users/{id}` | `manage_users` | Lista usuários; altera papéis e plano |
| `GET` `POST` `PATCH` `DELETE` | `/admin/plans`, `/admin/plans/{id}` | `manage_plans` | Todos os planos, inclusive os legados; gerencia planos |
| `GET` `PUT` | `/admin/roles`, `/admin/permissions`, `/admin/roles/{id}/permissions` | `manage_roles` | Gerencia as permissões dos papéis |
| `GET` `POST` | `/admin/imports` | `manage_imports` | Execuções da importação; coloca uma importação na fila (202) |
| `GET` | `/admin/digests` | `manage_imports` | Edições de digest com status, forma de geração, motivo da alternativa e métricas (sem conteúdo) |
| `GET` | `/admin/trial-links` | `manage_imports` | Vínculos entre registros que compartilham um identificador; `status` = `conflict` (padrão), `auto`, `confirmed` ou `rejected` |
| `PATCH` | `/admin/trial-links/{id}` | `manage_imports` | Confirma ou rejeita um vínculo (`status`: `confirmed` ou `rejected`); os registros são reagrupados |

Filtros da pesquisa: `q`, `condition`, `keyword`, `mesh_term`, `sponsor`, `country`, `city`, `status[]`, `phase[]`, `study_type[]`, `has_results`, `start_date_from`/`_to`, `first_posted_from`/`_to`, `last_update_from`/`_to`, e `latitude` + `longitude` + `radius_km`. `sort` aceita `-last_update_post_date` (padrão), `last_update_post_date`, `±start_date`, `±study_first_post_date`, `±brief_title` e `relevance`.

Token ausente ou inválido devolve 401, permissão ausente ou registros de outro usuário ou equipe 403, recurso pago sem o plano (digests com IA, equipe) 402, o limite de buscas salvas 403, e entrada inválida 422 com os erros por campo.

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
Digests:    scheduler → digests:dispatch → GenerateDigestJob → DigestGenerator (identidade, trava, direito de acesso)
              → DigestBuilder (DigestRanker, ChangeSummarizer → AiProvider ou texto factual) → DigestDeliverer (e-mail)
API:        nginx → PHP-FPM (Laravel) → routes/api.php → Form Request → controller → serviço
Web:        o Laravel serve resources/views/app.blade.php → Vue 3 + Vue Router + Pinia → /api
```

- `app/Services/ClinicalTrials/Import/` contém a importação. Cada registro é uma `RegistrySource` (`app/Services/Registries/`) com um `StudyRegistryClient` e um `StudyPayloadMapper`; `clinicaltrials:fetch --source=<chave>` escolhe um (padrão `clinicaltrials_gov`), e cada registro tem o seu próprio ponto de importação incremental. O mapeador é uma função pura do payload e o sincronizador cuida da gravação.
- `app/Services/Registries/` contém a lista de registros (`SourceRegistry`), a normalização de identificadores (`IdentifierParser`) e a deduplicação (`DuplicateLinker`, executada na transação de cada estudo). `Fetching/RegistryFetcher` é o único caminho dos importadores até um registro; `Parsing/` contém os leitores de HTML, CSV, XLSX, texto de PDF e tabelas de largura fixa; `Sources/<Registro>/` contém cada importador (origem com a sua revisão jurídica, cliente, mapeador).
- `app/Services/ClinicalTrials/Search/` monta a pesquisa com pequenas classes `TrialFilter` registradas no `AppServiceProvider`. Os alertas reaproveitam os mesmos critérios e filtros.
- `app/Services/ClinicalTrials/Changes/` contém os fatos acompanhados de um estudo e o `ChangeDetector`, uma função pura que os compara.
- `app/Services/Alerts/` aplica o limite de buscas salvas, cruza as importações e envia os e-mails. `app/Services/Billing/` contém as regras dos planos e o `Entitlements`; o Stripe é acessado pela interface `SubscriptionGateway`. `app/Services/Teams/` gerencia equipes e convites.
- `app/Services/Digests/` monta os digests; o modelo é acessado pela interface `AiProvider` (`OpenAiCompatibleProvider`).
- `app/Http/` mantém os controllers enxutos: Form Requests validam, serviços decidem, API Resources formatam o JSON.
- O ambiente Docker tem sete serviços: `app` (PHP 8.6 FPM), `queue`, `scheduler`, `mysql` (MySQL 8.4), `redis` (Redis 8.10, o armazenamento de cache, usado também pelo limite de requisições e pela lista de tokens JWT invalidados), `nginx` e `node` (Node.js 26 e Vite). O Laravel acessa o Redis pelo Predis (`REDIS_CLIENT=predis`), porque a extensão phpredis ainda não compila no PHP 8.6.

## Desenvolvimento

```sh
docker-compose exec app php artisan test
docker-compose exec app ./vendor/bin/pint --test
docker-compose run --rm node npm run typecheck
docker-compose run --rm node npm test
```

- Os testes PHP rodam em um banco MySQL dedicado, `clinical_saas_test`, criado por `docker/mysql/init/` quando o volume do MySQL é criado pela primeira vez. Para um volume que já existe, crie-o uma vez: `docker-compose exec mysql mysql -uroot -proot -e "CREATE DATABASE clinical_saas_test; GRANT ALL ON clinical_saas_test.* TO 'clinical_user'@'%';"`.
- Os testes da importação usam `Http::fake()` com uma resposta gravada da API em `tests/Fixtures/`; os de cobrança usam `Tests\Fakes\FakeSubscriptionGateway` e os de digest `Tests\Fakes\FakeAiProvider`, com o e-mail simulado. Os testes nunca chamam o ClinicalTrials.gov, o Stripe nem um provedor de IA.
- GitHub Actions: `.github/workflows/packages.yml` audita os lock files do Composer e do npm (o selo *packages*), e `.github/workflows/ci.yml` roda o Pint, os testes PHP, a checagem de tipos, os testes do Vue e o build em pushes e pull requests para a `main`, em tags e manualmente pela aba Actions. As branches seguem de `dev` para `hom` e para `main`.

O esquema do banco é definido apenas pelas migrations em `database/migrations/`. Crie uma migration nova para cada mudança; não edite migrations que já rodaram.

## Estrutura do projeto

```text
app/
  Console/Commands/      clinicaltrials:fetch, trials:reconcile-duplicates, alerts:send-digests, digests:dispatch, billing:check-prices
  Enums/                 status, fases, permissões, frequência dos alertas e dos digests, papel na equipe
  Http/                  Controllers/Api (e Admin/), Requests/, Resources/, Middleware/
  Jobs/, Events/, Listeners/, Notifications/, Policies/
  Models/                User, Role, Permission, Plan, Subscription, Alert, AlertMatch, ImportRun,
                         Team, TeamMember, TeamInvitation, Digest, DigestItem, DigestDelivery, ChangeSummary
  Models/ClinicalTrials/ ClinicalTrial, suas listas relacionadas e ClinicalTrialChange
  Services/              Auth/, ClinicalTrials/{Import,Search,Changes}, Registries/{Fetching,Parsing,Sources,...},
                         Alerts/, Billing/, Teams/, Digests/, Ai/, Admin/
config/                  clinicaltrials.php, registries.php, billing.php, ai.php, auth.php, jwt.php
lang/                    textos da API, dos e-mails e dos digests por idioma (en, pt_BR, es, zh_CN, zh_TW, ja, ko)
database/                migrations/, factories/, seeders/
docs/                    logo, avaliação de registros
resources/js/            aplicação Vue: pages/, components/, stores/, api/, i18n/ (catálogos por idioma)
routes/                  api.php, web.php (aplicação web), console.php (agendamento)
tests/                   Feature/, Unit/, Fixtures/
```

## Fonte dos dados

Os dados dos estudos vêm do [ClinicalTrials.gov](https://clinicaltrials.gov/), mantido pela National Library of Medicine dos EUA. Os registros descrevem estudos cadastrados e não são orientação médica. Confira o registro oficial antes de confiar em qualquer detalhe de um estudo. Os outros registros estão listados em [Outros registros](#outros-registros); os dados de cada um aparecem com a sua atribuição. [docs/registries-evaluation.md](docs/registries-evaluation.md) registra a revisão jurídica de cada registro analisado.

## Licença

[MIT](LICENSE-MIT).
