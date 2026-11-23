# Registry access requests

Draft messages to registries whose terms need a confirmation, a written
permission or an operator key before Clinical Trial Watcher may import or reuse
their data. The reason for each request is in
[registries-evaluation.md](registries-evaluation.md), section 7.

How to use them:

- Replace the placeholders: `[Name]`, `[Organization]`, `[Contact email]`,
  `[Website]`, `[Country]`.
- Contact channels marked **(unverified)** come from the research of
  2026-11-23; check them on the registry's site before sending.
- Keep every answer with the project's records. When a registry grants
  permission, set `REGISTRY_PERMISSION_<KEY>=true` (or the key variable) and
  note the date and scope of the permission in `registries-evaluation.md`.
- If a registry allows display but not paid use, leave the importer as it is:
  records of sources with `commercialUse: false` are shown for free with
  attribution and kept out of AI digests.

## Common description

Every message below refers to this paragraph. Paste it where a message says
*[Common description]*.

> Clinical Trial Watcher ([Website]) is a search service for public clinical
> trial registry data. Search, study pages, change history and basic email
> alerts are free for everyone. Each record shows its registry, its identifier
> there, the required attribution and a link to the official record. An
> optional paid plan sends AI-written digests that summarize changes to the
> studies a user follows; the summaries are generated from the public registry
> fields and link back to the registry. Registry data is never sold or
> redistributed as a dataset.
>
> Our importer identifies itself with the user agent
> `ClinicalTrialWatcher/1.0 (+[Contact email])`, makes one request at a time,
> waits at least [2] seconds between requests (longer if you prefer), uses
> conditional requests so unchanged pages are not downloaded again, and stops
> at the first refusal, login or captcha. We do not store personal data of
> contact persons or investigators.

---

## Confirmations for registries already imported

These importers run today under the published terms. A written confirmation
removes the remaining doubt.

### EMA: CTIS public portal and EU Clinical Trials Register

- **To:** EMA Service Desk / "Ask EMA" form, topic CTIS **(unverified)**
- **Subject:** Automated use of the CTIS public portal API and EU CTR export

> Dear CTIS team,
>
> [Common description]
>
> We read the EMA legal notice, which allows reuse of EMA information with
> acknowledgement. We would like to confirm three points before relying on it:
>
> 1. Automated, read-only calls to the JSON endpoints used by the CTIS public
>    portal (`/ctis-public-api/search` and `/ctis-public-api/retrieve/{EU CT}`),
>    at most one request every 3 seconds, are acceptable. Please tell us any
>    preferred rate, schedule or endpoint (or a bulk export) instead.
> 2. The same applies to the plain-text download of the EU Clinical Trials
>    Register (`/ctr-search/rest/download/full`).
> 3. The legal notice covers sponsor-supplied trial information shown in CTIS,
>    so it may be displayed with the attribution "Source: EMA, Clinical Trials
>    Information System (CTIS)" and summarized in our paid AI digests.
>
> Kind regards,
> [Name], [Organization], [Contact email]

### ISRCTN

- **To:** info@isrctn.com **(from research)**
- **Subject:** Use of the ISRCTN API in a registry search service

> Dear ISRCTN team,
>
> [Common description]
>
> We use your public API (`/api/query/format/default` and
> `/api/trial/{id}/format/default`) and the licences stated on each record
> (CC BY 4.0 and CC0). Your terms page also contains a general clause about
> building databases from the site. Could you confirm that importing records
> through the API, displaying them with attribution and including them in
> paid AI digests is acceptable, and whether you prefer a particular request
> rate?
>
> Kind regards,
> [Name], [Organization], [Contact email]

### DRKS (BfArM)

- **To:** drks@bfarm.de **(from research)**
- **Subject:** Reuse of DRKS records registered before 2025

> Dear DRKS team,
>
> [Common description]
>
> We import only the DRKS records published under CC BY 4.0 (registered or
> updated from 2025), from the JSON download. We would like to ask:
>
> 1. Whether records updated in 2026 are also covered by CC BY 4.0.
> 2. Whether BfArM can permit, or tell us how to obtain permission for, the
>    display of older records with attribution, and their inclusion in paid
>    AI digests.
> 3. Whether downloading the full JSON export once a day is acceptable, given
>    the 10-second crawl delay in your robots.txt.
>
> Kind regards,
> [Name], [Organization], [Contact email]

### ReBEC (DECIT / Fiocruz) — em português

- **Para:** contato indicado em ensaiosclinicos.gov.br **(não verificado)**
- **Assunto:** Reutilização dos dados do ReBEC em um serviço de busca

> Prezada equipe do ReBEC,
>
> O Clinical Trial Watcher ([Website]) é um serviço de busca de dados públicos
> de registros de ensaios clínicos. A busca, as páginas dos estudos, o
> histórico de mudanças e os alertas básicos por e-mail são gratuitos. Cada
> registro mostra a fonte, o identificador RBR e um link para o registro
> oficial. Um plano pago opcional envia resumos escritos por IA sobre mudanças
> nos estudos que o usuário acompanha. Os dados não são vendidos nem
> redistribuídos como base de dados.
>
> Hoje lemos as páginas públicas de lista e de cada estudo, uma requisição por
> vez, com intervalo mínimo e identificação do robô
> (`ClinicalTrialWatcher/1.0 (+[Contact email])`), respeitando o robots.txt,
> que proíbe `/xml_ictrp/`. Gostaríamos de pedir:
>
> 1. Autorização para reutilizar e exibir os registros com atribuição ao
>    ReBEC, inclusive nos resumos pagos.
> 2. Se possível, acesso autorizado ao XML no padrão da OMS ou a uma
>    exportação periódica, que reduziria a carga sobre o site.
>
> Atenciosamente,
> [Name], [Organization], [Contact email]

### Pharmacy and Poisons Board (Kenya)

- **To:** cta@pharmacyboardkenya.org **(from research)**
- **Subject:** Reuse of the approved clinical trials list

> Dear Pharmacy and Poisons Board,
>
> [Common description]
>
> We read the public lists of approved, stopped and suspended trials and their
> detail pages. We do not import investigator names or contacts. We would like
> your written confirmation that we may display these records with the
> attribution "Source: Pharmacy and Poisons Board, Kenya" and include them in
> paid AI digests, and to know whether you prefer a request rate or an export.
>
> Kind regards,
> [Name], [Organization], [Contact email]

### Egyptian Drug Authority

- **To:** contact channel on edaegypt.gov.eg **(unverified)**
- **Subject:** Reuse of the clinical trials registry PDF

> Dear Egyptian Drug Authority,
>
> [Common description]
>
> We read the clinical trials registry published as a PDF on your website. We
> would like your written confirmation that we may display its records with
> attribution to the EDA and include them in paid AI digests. A machine-
> readable version (CSV or Excel) and a registration number per trial would
> make the data more accurate; please tell us if either exists.
>
> Kind regards,
> [Name], [Organization], [Contact email]

### Ministry of Health of Ukraine and State Expert Center

- **To:** Ministry of Health contact form; State Expert Center (dec.gov.ua) **(unverified)**
- **Subject:** Reuse of trial authorization orders and access to the trials register

> Dear Sir or Madam,
>
> [Common description]
>
> We read the annexes of the Ministry's orders authorizing clinical trials.
> We would like to confirm that these official documents may be reused and
> summarized in paid AI digests with attribution. We also tried to read the
> State Expert Center's clinical trials register
> (`clinicaltrials.dec.gov.ua`), but our requests were blocked by a firewall.
> Could you tell us whether automated, read-only access or an export is
> possible, and under which conditions?
>
> Kind regards,
> [Name], [Organization], [Contact email]

---

## Permissions for importers built but switched off

These importers refuse every request until the permission is recorded.

### UMIN Center (UMIN-CTR) — `REGISTRY_PERMISSION_UMIN`

- **To:** UMIN Center inquiry form **(unverified)**
- **Subject:** Permission to display UMIN-CTR records in a registry search service

> Dear UMIN Center,
>
> [Common description]
>
> Your FAQ says the UMIN-CTR data may not be displayed in commercial web
> services. Our service displays registry records for free, but it also has a
> paid plan, so we ask for your written permission before importing anything.
> We would download the daily English CSV (`ctr_data_e.csv.gz`) once a day,
> display each record with attribution to UMIN-CTR and a link to it, and,
> only if you allow it, include it in paid AI digests. We would follow any
> conditions you set.
>
> Kind regards,
> [Name], [Organization], [Contact email]

### ANZCTR — `REGISTRY_PERMISSION_ANZCTR`

- **To:** info@anzctr.org.au **(unverified)**
- **Subject:** Approval for automated retrieval of ANZCTR records

> Dear ANZCTR team,
>
> [Common description]
>
> Clause 5.5 of your terms allows reuse with conditions, but automated
> collection needs your express approval. We ask for that approval, or,
> preferably, access to an XML feed or export of trial records, which would
> spare your website. We would display records with your required attribution
> and a link to each trial, and, if you allow it, include them in paid AI
> digests.
>
> Kind regards,
> [Name], [Organization], [Contact email]

### HRA research summaries — `REGISTRY_PERMISSION_HRA`

- **To:** HRA contact form / research summaries team **(unverified)**
- **Subject:** Permission to reuse HRA research summaries

> Dear Health Research Authority,
>
> [Common description]
>
> Your copyright notice allows reuse of research summaries only for research
> or private study. We ask for written permission to import the research
> summaries (about 20 pages a day, keyed by IRAS ID), display them with
> attribution to the HRA and a link to each summary, and, if you allow it,
> include them in paid AI digests. We do not import contact names or emails.
>
> Kind regards,
> [Name], [Organization], [Contact email]

### SÚKL — `REGISTRY_PERMISSION_SUKL`

- **To:** SÚKL contact (sukl.cz) **(unverified)**
- **Subject:** Consent to reuse the clinical trials database

> Dear SÚKL,
>
> [Common description]
>
> Your terms require prior consent and say the database is not intended for
> bulk downloading. We ask for your consent to read the trial records (about
> 5,200, at one request every 5 seconds, once) and display them with
> attribution to SÚKL. If you prefer, an export of the data would avoid any
> load on your website.
>
> Kind regards,
> [Name], [Organization], [Contact email]

### Orphanet-AISBL — `REGISTRY_PERMISSION_ORPHANET`

- **To:** Orphadata contact (orphadata.com) **(unverified)**
- **Subject:** Service contract for the clinical trials datasets

> Dear Orphanet team,
>
> [Common description]
>
> We would like to receive the "National/Multinational Clinical Trials"
> Orphadata datasets to tag studies with ORPHAcodes and list rare-disease
> trials. Please send the terms and pricing of the service contract for a
> company, and tell us whether display with attribution and inclusion in paid
> AI digests are covered.
>
> Kind regards,
> [Name], [Organization], [Contact email]

---

## Requests for registries that cannot be imported yet

### WHO ICTRP

- **To:** WHO permissions form for ICTRP data; ictrpinfo@who.int **(unverified)**
- **Subject:** Access to ICTRP data and waiver of section 4(d)

> Dear ICTRP team,
>
> [Common description]
>
> Section 4(d) of the ICTRP terms forbids use of extracted data for commercial
> purposes, and the Search Portal's robots.txt disallows automated access. We
> would like to know whether WHO can grant (1) institutional access to the
> weekly data files and (2) a written waiver of section 4(d) for free display
> with attribution and inclusion in paid AI digests, and under which
> conditions and fees.
>
> Kind regards,
> [Name], [Organization], [Contact email]

### KDCA (CRIS) — commercial use

- **To:** CRIS / KDCA contact **(unverified)**
- **Subject:** Use of CRIS open API data in paid features

> Dear CRIS team,
>
> [Common description]
>
> We use the CRIS open API on data.go.kr under KOGL Type 2 (attribution, no
> commercial use): CRIS records are shown for free and kept out of paid
> features. Could KDCA permit their inclusion in paid AI digests?
>
> Kind regards,
> [Name], [Organization], [Contact email]

### HMA-EMA catalogue of real-world data studies

- **To:** catalogue help desk (catalogues.ema.europa.eu) **(unverified)**
- **Subject:** Export or API for the RWD studies catalogue

> Dear catalogue team,
>
> [Common description]
>
> Your robots.txt disallows `/search`, where the study list and CSV export
> are. Is there an API, a periodic export or a permission for automated,
> read-only access, and may the data be displayed with attribution?
>
> Kind regards,
> [Name], [Organization], [Contact email]

### Plataforma Brasil (CEP/Conep) — em português

- **Para:** Conep / CNS **(não verificado)**
- **Assunto:** Extração de projetos aprovados com CAAE

> Prezada equipe da Conep,
>
> O Clinical Trial Watcher ([Website]) é um serviço gratuito de busca de
> dados públicos de pesquisa clínica, com um plano pago opcional de resumos
> por IA. Gostaríamos de saber se é possível obter uma extração periódica dos
> projetos aprovados com o número CAAE, a situação e a fase, para ligá-los aos
> registros do ReBEC, e em que condições os dados podem ser exibidos.
>
> Atenciosamente,
> [Name], [Organization], [Contact email]

### CDE/NMPA (China) and TİTCK (Turkey)

Both sites block automated access (an anti-bot challenge and a firewall). Use
the *[Common description]* and ask for a data export or an allow-listed,
read-only access route, and for the conditions of reuse.

---

## Operator keys

### NCI Clinical Trials Search API — `REGISTRY_NCI_CTS_API_KEY`

Register for a key on the API's site (clinicaltrialsapi.cancer.gov), read the
key's terms of use, and record them in `registries-evaluation.md` before the
first import.

### MFDS and CRIS on data.go.kr — `REGISTRY_MFDS_SERVICE_KEY`, `REGISTRY_CRIS_SERVICE_KEY`

Create a data.go.kr account and request use of `ClncExamPlanDtlService03`
(MFDS) and dataset 3033869 (CRIS). For the purpose of use (활용목적), a short
text such as:

> 임상시험 등록 정보 검색 서비스(Clinical Trial Watcher)에서 공개 임상시험
> 정보를 출처와 함께 무료로 제공하기 위해 사용합니다. (Used to display public
> clinical trial information, with attribution, for free in a clinical trial
> search service.)

The development key allows 10,000 calls a day; a full CRIS import needs about
9,000, so ask for the production (운영) approval before scheduling it.
