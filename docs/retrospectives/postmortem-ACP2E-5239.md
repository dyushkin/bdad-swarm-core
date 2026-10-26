# Post-Mortem: OpenSearch Porter Over-Stemming — ACP2E-5239

*Guidance/analysis ticket (no code change). Objective: record the full diagnosis, the customer-facing answer, and the codebase verification so the recommendation is reproducible for the next similar escalation.*

| | |
|---|---|
| **Current version** | v1.0 |
| **Last updated** | 2026-08-14 |
| **Scope** | ACP2E-5239 — native OpenSearch catalog search returns irrelevant results for "one pass" (default English Porter stemmer over-stems `one → on`). No defect; guidance only. |
| **Ticket chain** | E-002387244 (Dynamics/customer) → ACSD-72087 (T3) → **ACP2E-5239** (core dev, this branch) · linked feature request **DSRS-2472** |
| **Customer** | United Healthcare / Optum — HIPAA, Ultimate Support, Premier. Native Adobe Commerce catalog search on OpenSearch (2.4.8), **not** Live Search. |
| **Status legend** | ✅ done · 🟡 partial · ⬜ not started · 🔎 resolved by investigation |

### Revision history
| Version | Date | Change |
|---|---|---|
| v1.0 | 2026-08-14 | Initial record: root-cause diagnosis, codebase verification, the two sanctioned interim options, customer-ready answer, open items. |

> **How to read this doc:** §1 is the problem + confirmed root cause. §2 is the codebase verification that grounds the answer. §3 is the answer delivered (the two options). §4 covers reproduction/test posture. §5 is open items + secondary questions. Companion to [`postmortem-ACP2E-5216.md`](postmortem-ACP2E-5216.md).

---

## 0. Executive Summary

Native OpenSearch catalog search returns ~31 mostly-irrelevant products for the high-traffic query **"one pass"**. Both Adobe T3 and the customer independently reproduced it on a clean 2.4.8-p5 install: the default **English Porter** stemmer reduces `one → on`, and `on` is ubiquitous in searchable text. This is **expected OpenSearch/Lucene behavior, not a Commerce defect** — there is no admin setting for stemming.

The single unanswered question routed to the dev queue was:

> "Confirmation that stemmer_override/keyword_marker via custom module is the sanctioned interim approach. Or any other suggestions and recommendations to achieve the merchant business goal?"

**Answer: confirmed.** A merchant-owned custom module (shared-responsibility) is the only supported interim mechanism, in one of two variants (§3). The native productized solution is the roadmap item **DSRS-2472 "Stemmer Control (exclusions)" (Beta), no ETA.**

---

## 1. Problem & Confirmed Root Cause

- **Symptom:** `products(search: "one pass")` (GraphQL and storefront) returns 31 products, most containing neither "one" nor "pass" in configured searchable attributes.
- **Mechanism:** `"one pass"` → `one` OR `pass`. `pass` matches 1 product (correct). `one` is stemmed to `on` by the default English Porter stemmer; `on` is extremely common in `description`/`features`, so `one` matches ~31 products (incl. unrelated gift cards).
- **Not a customization:** the customer's `Uhc_SearchModification` module only adds `nin`/`neq` filter operators; disabling it left results unchanged. `search_synonyms` empty. Reproduced on clean 2.4.8-p5 + Luma (`description:one` = 489/2042).
- **Customer-validated facts:** `_analyze` proves `one → on` under Porter; **Snowball/Porter2 keeps "one pass"**; a `keyword_marker` protecting `one` also keeps "one pass". So the issue is isolated to the aggressive Porter stemmer, not stemming in general.
- **Why escalated despite "works as designed":** no admin/config toggle for stemming; Live Search (better relevance controls) is contractually off for this HIPAA account → no supported *native* path. Premier customer, measurable conversion/revenue impact.

## 2. Codebase Verification (grounds the recommendation)

Every claim was confirmed against `2.4-develop`:

| Claim | Evidence |
|---|---|
| Stemmer is hardcoded to aggressive Porter | `app/code/Magento/Elasticsearch/etc/esconfig.xml` → `<stemmer><type>stemmer</type><default>english</default>…`. In Lucene/OpenSearch the `english` language of the `stemmer` filter **is** Porter (over-stems `one → on`). |
| Analyzer chain is built in PHP, no toggle | `Elasticsearch/Model/Adapter/Index/Builder.php::build()` assembles `default` analyzer filters: `lowercase, keyword_repeat, asciifolding, default_stemmer, unique_stem`. `getStemmerConfig()` reads only `esconfig`. **No `system.xml` option exists anywhere** → confirms "no admin option." |
| Customer's fix is 100% custom | `stemmer_override`, `keyword_marker`, `porter2`, `minimal_english`, `kstem` appear **nowhere** in core. |
| A custom module *can* cleanly override the stemmer language | `EsConfig extends \Magento\Framework\Config\Data` (merge-loaded across modules); `esconfig.xsd` types `type`/`default` are free `xs:string` with `xs:any` lax for locales → a module shipping its own `etc/esconfig.xml` overrides `<default>`. |
| The analyzer chain is overridable in code | `Elasticsearch/etc/di.xml:156` preferences `BuilderInterface` → `Builder`; `getFilter()`/`build()` are overridable via plugin/preference. |

**Key nuance that shaped the answer:** `esconfig.xml` controls only the stemmer *language/type*. Inserting `keyword_marker` + `stemmer_override` into the filter chain (and in the right order — before `default_stemmer`) **cannot** be done via `esconfig.xml`; it needs an `after` plugin on `Builder::build()`. Swapping the stemmer language *can* be done with config alone. Hence the simplest sanctioned path is the config swap, not the exclusion filter the customer proposed.

## 3. Answer Delivered — the two sanctioned interim options

Both are merchant-owned custom modules under the shared-responsibility model, deployed via the normal code pipeline (never edited directly on the OpenSearch service — a reindex overwrites that), followed by a full `catalogsearch_fulltext` reindex; validate `_analyze` on `staging_for_support` first.

1. **Stemmer swap (simplest — recommended).** Small module shipping `etc/esconfig.xml` overriding stemmer language `english` → **`porter2`** (Snowball), which the merchant already verified returns "one pass" correctly (or `minimal_english`/`kstem`). Config-only, no PHP. Global change → regression-test top search terms.
2. **Targeted exclusion (surgical — the customer's proposal).** Keep Porter, add `keyword_marker` + `stemmer_override` to protect specific tokens (e.g. `one`). Requires an `after` plugin on `Magento\Elasticsearch\Model\Adapter\Index\Builder::build()` inserting those filters **before** `default_stemmer`. Lowest blast radius, more code to maintain.

**Native/future path:** **DSRS-2472 "Commerce Search | Stemmer Control (exclusions)" (Beta)** — productizes stemmer exclusions; currently **no ETA**.

## 4. Reproduction / Test Posture

- **No failing test to write.** This is "works as designed," not a bug — no Red state per our TDD rule. Reproduction is the search behavior itself, already reproduced by T3 and the customer on clean `develop`.
- **Existing coverage:** only `Elasticsearch/Test/Unit/.../Index/BuilderTest.php` (asserts static analyzer structure). No integration test exercises stemming/over-stemming, and none is warranted for a guidance ticket.

## 5. Open Items & Secondary Questions

- 🟡 **DSRS-2472 availability** — would sharpen the customer answer: is the "Stemmer Control (exclusions)" Beta available to a **HIPAA PaaS** account, and any indicative timeline? Thread says "roadmapped, no ETA." Keep at "no ETA" unless product confirms otherwise.
- ⬜ **Native Synonyms "not working"** after reindex — separate from stemming (Commerce applies `synonym_graph` per store view, rebuilt on reindex, stemming still runs after). Triage independently; not part of this answer.
- ⬜ **"Minimum Terms to Match"** — won't fix stemming; forcing both `one` AND `pass` would drop `on`-only hits but globally turns multi-word search into AND and hurts recall. Blunt trade-off, not a fix.
- 🟡 **Customer requested a Support + Engineering call** (named Optum attendees) before implementing any customization.

## 6. Lessons / Reusable Pattern

- For "search returns irrelevant results" escalations on native OpenSearch, verify against `esconfig.xml` + `Index/Builder.php` first — the analyzer chain is fully code/config-driven and there is **no admin stemming toggle**. That single fact converts most of these into shared-responsibility guidance tickets.
- Distinguish **stemmer-language swap (config, `esconfig.xml` merge)** from **filter-chain injection (`Builder::build()` plugin)** — customers often ask for the latter when the former solves their case with far less code. Lead with the config swap when the customer has already validated a lighter stemmer.
- "Works as designed" + Live Search unavailable (HIPAA) is a recurring dead-end that should route to the roadmap item (here DSRS-2472), not an open-ended custom-code exchange.
