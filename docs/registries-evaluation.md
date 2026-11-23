# Evaluation of additional trial registries

Status: evaluation only. No import for these registries exists yet.
Roadmap item: "Evaluate other registries such as WHO ICTRP, EU CTR and ReBEC (Brazil)".
Research date: 2026-11-23.

## Scope

Clinical Trial Watcher imports studies from the ClinicalTrials.gov API v2
(`https://clinicaltrials.gov/api/v2/studies`). That API returns JSON, pages with
`nextPageToken` and supports incremental sync through
`filter.advanced=AREA[LastUpdatePostDate]RANGE[date,MAX]`. This document asks
whether, and how, the project could import studies from three more sources:

- WHO ICTRP (International Clinical Trials Registry Platform);
- the EU registries: the EU Clinical Trials Register (EU CTR, EudraCT data) and
  the CTIS public portal (Regulation (EU) No 536/2014);
- ReBEC (Registro Brasileiro de Ensaios Clínicos).

It covers access, terms, fields, update detection, overlap and effort. Registry
content is public registration data, not medical advice or a patient
eligibility decision. **(unverified)** marks claims not confirmed in an
official source on the research date.

## 1. WHO ICTRP

ICTRP is not a registry. It is a search portal that collects records from
primary registries and data providers. The portal lists 20 sources, including
ClinicalTrials.gov, EU-CTR, CTIS, ReBEC, ISRCTN, DRKS, ANZCTR, ChiCTR, CTRI and
jRCT. Each source is re-imported on its own schedule. On 2026-11-23 the portal
showed the most recent imports as 16 Nov 2026 for ClinicalTrials.gov and CTIS,
and 9 Nov 2026 for EU-CTR and ReBEC. Some registries were last imported months
earlier: IRCT in June 2026, LBCTR in February 2026.

**Access**

- **Search Portal export** (`trialsearch.who.int`): search results can be
  exported. The portal page mentions XML. The WHO download page says results
  can be downloaded as CSV or XML, either selected records or all records, at
  no charge. This works for interactive searches, not for an automated sync.
- **Weekly data files**: institutions can request access to a SharePoint
  folder of "weekly ICTRP data files" with new and updated records. Only
  requests made on behalf of an institution are accepted, and identified
  institutions pay "a yearly fee based on their type and size" (the categories
  are non-profit and three commercial tiers). The fee amount is not published.
  Whether these are full snapshots or deltas is **(unverified)**.
- **Search Portal Web Service**: real-time XML web service for research use by
  partner organizations. It is fee-based for businesses and the fee is given on
  request. Technical documentation is not public **(unverified)**.
- **Crawling service**: marked "currently not available".
- **Public REST API**: none. Third-party scrapers exist (for example on Apify).
  They are unofficial and fall under the ICTRP terms.

**Terms of use**: the terms apply to all ICTRP data, whatever the format or
method of acquisition. They require attribution to "WHO ICTRP", keeping the
data current, and showing the date the data were processed by WHO ICTRP. They
forbid use "for marketing, promotional or commercial purposes", forbid use of
the WHO name or emblem, and forbid asserting proprietary rights. The
no-commercial-use clause matters if the project's planned plans and
subscriptions (`Plan`, `Subscription` models) ever cover ICTRP-derived data.
Rate limits are not documented.

**Fields**: records follow the WHO Trial Registration Data Set (TRDS v1.3.1,
24 items). Its items cover the primary registry ID, registration date,
secondary IDs, sponsors, public and scientific titles, countries of
recruitment, conditions, interventions, inclusion and exclusion criteria, study
type, date of first enrollment, sample size, recruitment status, outcomes,
ethics review, completion date, summary results and the IPD sharing statement.
Phase is part of the study-type and design data. Values are free text as
supplied by each source registry. They are not normalized into ClinicalTrials.gov
enums, and statuses and phases vary in wording from source to source.

**Identifiers and updates**: each record keeps its primary registry ID (NCT,
EUCTR..., RBR-...), and secondary IDs include the WHO UTN (`U1111-...`) when
given. The portal "bridges (groups together)" records about the same trial. A
per-record last-refreshed date in the export is **(unverified)**. Incremental
updates officially come only from the paid weekly files.

**Effort/risk**: high. There is no open machine interface, bulk access is paid
and restricted to institutions, the terms forbid commercial use, and the data
duplicate sources the project already has.

## 2. EU CTR and CTIS (European Union)

**Transition.** Since 31 January 2023, all initial EU/EEA clinical trial
applications go through CTIS under Regulation 536/2014. Trials authorized under
the old Directive that were still ongoing after 30 January 2025 had to move to
CTIS as "transitional" applications. They keep their EudraCT number, which is
linked to the new CTIS record and cannot be changed. EudraCT still receives
results and global end-of-trial notifications for Directive trials, and the EU
CTR keeps publishing them. In practice the EU CTR is a mostly frozen legacy
archive (44,416 trials with a EudraCT protocol on 2026-11-23) and CTIS holds
all new and transitioned trials.

### 2a. EU Clinical Trials Register (`clinicaltrialsregister.eu`)

- **Access**: web search only. "Download Options" exports either the trials on
  the current results page or the selected trials, as Summary, Details or Full
  Trial Details, in **plain text only**. Results pages and downloads are
  paginated: the `ctrdata` R package downloads EU CTR data in batches of
  20 trials. An RSS feed lists trials added or updated in the last 7 days for a
  saved search. There is no public API and no bulk dump, so automation means
  parsing the search pages and text exports **(scraping)**.
- **Terms**: the legal notice requires attribution as "EU Clinical Trials
  Register" with the date the data were accessed, and forbids using extracted
  e-mail addresses for marketing. EMA and the national competent authorities
  accept no liability, and the data "should not be used as a recommendation to
  use the medicine". Copyright follows the EMA legal notice. Automated access
  and rate limits are not addressed.
- **Fields**: EudraCT number (`YYYY-NNNNNN-CC`, for example `2015-002555-10`),
  sponsor protocol number, sponsor, start date, medical condition (MedDRA),
  population age, gender, IMP details, and **per-member-state** protocol status
  (for example `GB (Completed)`), plus results where posted. A multi-country
  trial has one protocol record per member state.
- **Updates**: the 7-day RSS feed is the only change signal seen. A per-record
  last-modified date is **(unverified)**.

### 2b. CTIS public portal (`euclinicaltrials.eu`)

- **Access**: web search. Since 20 September 2024 the full list of a search
  can be downloaded as CSV ("Download results"), and full trial details can be
  downloaded per trial as HTML, with documents as PDF. RSS subscriptions are
  available for saved searches. EMA has published no official API. EMA slides
  from July 2024 answer a stakeholder request for an API with "Not yet!". The
  portal's own JSON endpoints (`POST /ctis-public-api/search`,
  `GET /ctis-public-api/retrieve/{EU CT number}`) are described only by third
  parties (Firecrawl, Apify, `ctrdata`). They are undocumented and could change
  without notice **(unverified as a supported interface)**.
- **Terms**: no terms specific to data reuse were found on the portal. EMA's
  general legal notice is assumed to apply **(unverified)**. Rate limits are
  not documented.
- **Fields**: EU CT number (format `YYYY-5NNNNN-NN-NN`, inferred from portal
  examples, **unverified** against an official specification), the linked
  EudraCT number for transitioned trials, title, sponsor, conditions,
  products, phase, member states concerned, per-country status, dates,
  participant age groups, and results summaries. The detailed field list of the
  CSV export is **(unverified)**.
- **Updates**: RSS per saved search. A machine-readable last-update field is
  **(unverified)**.

**Effort/risk**: medium-high for CTIS, high for EU CTR. CTIS has the most
useful data (the current EU trials) and a CSV export, but no stable API. EU CTR
access means parsing text exports, and its per-member-state records need
merging.

## 3. ReBEC (Brazil)

ReBEC is a WHO primary registry run by the Ministry of Health (DECIT/MS), PAHO
and Fiocruz. On 2026-11-23 its home page reported 9,882 registered trials
(19,343 records in total, including drafts and records under review).

- **Access**: no public API, no bulk export and no search-result export were
  found. The site offers:
  - a paginated listing, `/list/{page}`, with 20 rows per page (495 pages on
    the research date), apparently newest registrations first;
  - a public page per trial, `/rg/RBR-xxxxxxx`, with numbered revisions
    (`/rg/RBR-xxxxxxx/{revision}`);
  - a per-trial **ICTRP-format XML** download,
    `/xml_ictrp/downloadxmlictrp/{internal id}`. Its root element is `<root>`
    and it follows the WHO TRDS layout (main, contacts, countries, criteria,
    conditions, interventions, outcomes, sponsors, secondary IDs, ethics
    reviews).

  The internal numeric ID used by the XML link must be read from the trial
  page, so a full sync means crawling list pages, then trial pages, then the
  XML files **(scraping)**.
- **Terms**: no reuse terms or rate limits were found. A polite crawl rate and
  a request to ReBEC before bulk access are advisable **(terms unverified)**.
- **Fields**: RBR ID (`^RBR-\w+$`), WHO UTN, secondary IDs (for example
  Plataforma Brasil CAAE and ethics opinion numbers), titles in Portuguese,
  English and Spanish, recruitment status, study type and design, phase, target
  sample size, countries, primary, secondary and supporting sponsors,
  conditions (with ICD-10 codes), interventions, inclusion and exclusion
  criteria, age limits, outcomes, contacts and ethics approval.
- **Updates**: the listing and trial pages show "Last approval date" and a
  revision number. The sampled XML had **no last-update element**. Change
  detection would compare the approval date or revision number with stored
  values.

**Effort/risk**: medium. The data are small and well structured (TRDS XML),
but discovery needs HTML scraping and there is no documented contract or
terms. The main value is regional: Brazilian trials, many of which appear in
no other registry, plus Portuguese-language text.

## 4. Comparison

| Aspect | ClinicalTrials.gov (current) | WHO ICTRP | EU CTR | CTIS | ReBEC |
|---|---|---|---|---|---|
| Machine API | Public REST, JSON | None public; paid XML web service | None | Undocumented portal JSON | None |
| Bulk / export | API pagination | Search export (CSV/XML); paid weekly files for institutions | Plain text, per page or selection | CSV of search results; HTML/PDF per trial | Per-trial ICTRP XML |
| Scraping needed | No | Yes, without the paid feed | Yes | Partly | Yes (discovery) |
| Registration / fee | No | Fee for weekly files and web service | No | No | No |
| Reuse terms | Public | Attribution; no commercial use | Attribution and access date | Not found | Not found |
| Rate limits documented | — | No | No | No | No |
| Primary ID | NCT | Source ID + UTN | EudraCT `YYYY-NNNNNN-CC` | EU CT number | `RBR-xxxxxxx` |
| Update signal | `LastUpdatePostDate` | Weekly file (paid) | 7-day RSS | RSS per search | Approval date / revision |
| Normalized enums | Yes | No (source text) | Partly | Partly | Partly |
| Unique value | Baseline | Breadth (20 sources) | Legacy EU trials | Current EU trials | Brazilian trials |
| Effort / risk | — | High / high | High / medium | Medium-high / high | Medium / medium |

## 5. Deduplication

- The same trial is often registered in several registries. ICTRP groups such
  records itself ("bridging"), and it already contains ClinicalTrials.gov,
  EU-CTR, CTIS and ReBEC. Importing ICTRP next to the current import would
  duplicate every NCT record unless records whose primary ID starts with `NCT`
  are filtered out.
- Cross-references come from secondary IDs: ClinicalTrials.gov
  `identificationModule.secondaryIdInfos` (EudraCT numbers, UTNs, other
  registry IDs) and `orgStudyIdInfo`; TRDS `secondary_ids` in ICTRP and ReBEC;
  the EudraCT number linked to transitioned CTIS trials.
- Suggested model: keep one row per source record with `source` and
  `source_id`, and add a separate link table of normalized identifiers (NCT,
  EudraCT, EU CT, RBR, UTN). Matching on normalized identifiers, never on
  titles, would group records into one logical study. This would require a new
  migration. Today `nct_id` is the only match key and `updateOrCreate` relies
  on it, so non-NCT sources cannot be stored without a schema change.
- Secondary IDs are sponsor-entered and may be missing or malformed; store
  matches as reviewable links, not silent merges, and keep conflicting values
  (status, dates) attributed to their source.

## 6. Recommendation

1. **CTIS first (priority 1)**, as an experimental importer. It holds all
   current EU trials, including transitioned EudraCT trials, and has the most
   distinct value. Before building it, ask EMA (CTIS support) whether the
   portal JSON endpoints may be used programmatically, and what the terms and
   rate limits are. If not, fall back to the official CSV export. Record
   fixtures and test with `Http::fake()`.
2. **Generalize the storage model (prerequisite for 1 and 3)**: add
   `source`/`source_id` and an identifier link table through new migrations,
   keeping `nct_id` working for ClinicalTrials.gov.
3. **ReBEC second (priority 2)**: contact ReBEC/Fiocruz about bulk access or
   permitted crawling. If allowed, implement a throttled listing crawl plus
   per-trial TRDS XML parsing. A TRDS parser could later be reused for other
   WHO-format registries.
4. **EU CTR: do not import for now (priority 3)**. It is a legacy archive with
   text-only exports. Link to it through EudraCT numbers already present in
   ClinicalTrials.gov secondary IDs and CTIS records. Revisit only if legacy
   EU-only trials are needed.
5. **WHO ICTRP: do not integrate (priority 4)**. The no-commercial-use terms,
   the institutional fee for bulk files and the overlap with the other sources
   outweigh the benefit. Reconsider only for a non-commercial deployment or
   with a WHO agreement; ICTRP could then cover many registries at once.

## Sources

All accessed 2026-11-23.

- WHO ICTRP home: https://www.who.int/ictrp/en/
- ICTRP Search Portal: https://trialsearch.who.int/
- Downloading records from the ICTRP database (terms): https://www.who.int/tools/clinical-trials-registry-platform/network/who-data-set/downloading-records-from-the-ictrp-database
- ICTRP weekly data files access form: https://extranet.who.int/dataformv6/index.php/643633?lang=en
- ICTRP Search Portal Web Service / crawling service: https://www.who.int/tools/clinical-trials-registry-platform/the-ictrp-search-portal/ictrp-search-portal-crawling-service
- WHO Trial Registration Data Set: https://www.who.int/tools/clinical-trials-registry-platform/network/who-data-set
- EU Clinical Trials Register search: https://www.clinicaltrialsregister.eu/ctr-search/search
- EU CTR legal notice: https://www.clinicaltrialsregister.eu/disclaimer.html
- EudraCT (transition notice): https://eudract.ema.europa.eu/
- EMA CTIS page: https://www.ema.europa.eu/en/node/70294/latest
- EMA CTIS sponsor quick guide (transition): https://www.ema.europa.eu/system/files/documents/other/cttm23-quick-guide_sponsor_en.pdf
- EMA CTIS info day, May 2025: https://www.ema.europa.eu/en/documents/presentation/presentation-update-ctis-programme-ctis-info-day-may-2025-m-sultani_en.pdf
- EMA new public portal presentation (API "Not yet!"): https://www.ema.europa.eu/en/documents/presentation/presentation-new-version-public-portal-f-scotti_en.pdf
- ACT EU news, CTIS search improvements (2024-09-20): https://accelerating-clinical-trials.europa.eu/newsroom/news/improved-search-clinical-trials-ctis-2024-09-20_sk
- CTIS search tips and guidance: https://euclinicaltrials.eu/search-tips-and-guidance
- Third-party description of CTIS portal endpoints (unofficial): https://www.firecrawl.dev/alexandria/agents/providers/euclinicaltrials-eu
- `ctrdata` R package README (unofficial): https://stat.ethz.ch/CRAN/web/packages/ctrdata/readme/README.html
- ReBEC home: https://ensaiosclinicos.gov.br/
- ReBEC FAQ: https://ensaiosclinicos.gov.br/faq
- ReBEC listing: https://ensaiosclinicos.gov.br/list
- ReBEC sample trial and ICTRP XML: https://ensaiosclinicos.gov.br/rg/RBR-57xv2xm , https://ensaiosclinicos.gov.br/xml_ictrp/downloadxmlictrp/16255
- Bioregistry ReBEC ID pattern: https://bioregistry.io/rebec
