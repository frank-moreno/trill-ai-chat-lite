# Page Indexing — Block 1 of v2.0 (Design Memo)

**Status:** Design locked, ready to code.
**Owner:** Francisco Carracedo.
**Estimate:** 13–15 hours.
**Drafted:** 2026-05-25.

---

## Goal

Enable the Robin chatbot to answer questions about non-product store content
(FAQ, shipping policy, returns, contact, about, category descriptions) by
indexing selected pages and injecting the most relevant snippets into the
system prompt at chat time.

Today the chatbot only knows about products via `wc_get_products`. If a
shopper asks "what's your return policy?", Robin either invents or punts.
After Block 1, Robin answers from the merchant's actual published content.

---

## Locked decisions

| Decision | Choice | Rationale |
|---|---|---|
| Default scope | WP `page` + `product_cat` descriptions | High-signal, low-noise. Opt-in for `post` and CPTs. |
| Storage | Custom table `wp_trcl_content_index` with chunked snippets | FULLTEXT-indexable, granular context injection, mirrors `Migrations.php` pattern. |
| Chunking | ~400 chars per paragraph, 50-char overlap | Granular enough that Top-3 chunks fit ~1200 chars (~300 tokens). |
| Search | FULLTEXT MATCH AGAINST primary, LIKE fallback | Built-in relevance scoring, fast on InnoDB ≥5.6. LIKE catches short queries. |
| Auto-reindex | `save_post` hook + daily cron safety net | Real-time freshness, cron repairs orphans. |
| Inject point | New `RELEVANT STORE CONTENT` section in `PromptBuilder` | Mirrors existing `build_product_section` pattern. |
| Intent gating | Skip content search when product search returned results; prioritise when message matches policy/FAQ patterns | Reuses inverted `is_product_query()` heuristic. |

---

## Schema (Migrations 1.0.0 → 1.1.0)

```sql
CREATE TABLE wp_trcl_content_index (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    post_id         BIGINT UNSIGNED NOT NULL,
    post_type       VARCHAR(20)     NOT NULL,
    chunk_index     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    title           VARCHAR(255)    NOT NULL,
    snippet         TEXT            NOT NULL,
    url             VARCHAR(500)    NOT NULL,
    last_indexed    DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_post (post_id, chunk_index),
    KEY idx_type (post_type),
    FULLTEXT KEY ft_snippet (title, snippet)
) ENGINE=InnoDB CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`Migrations::SCHEMA_VERSION` bumps from `1.0.0` to `1.1.0`. Use `dbDelta` so
the migration is idempotent and additive (existing 3 tables untouched).

Add `wp_trcl_content_index` to the drop-list in `Migrations::drop_tables()`.

For `product_cat`, store `post_id = 0` and `post_type = 'product_cat'`, use
the term ID encoded in metadata if we ever need to round-trip. MVP simply
treats each category description as a single chunk indexed by term_id placed
in `post_id` (reusable column).

---

## File structure additions

```
includes/Content/                       (new namespace)
├── ContentIndexer.php                  Crawl selected post_types + chunk + store
├── ContentSearch.php                   FULLTEXT primary, LIKE fallback, top-3
├── ContentChunker.php                  Pure paragraph splitter (unit-testable in isolation)
└── ContentSettings.php                 Read/write merchant settings (which post_types, which IDs)

includes/Database/Migrations.php         + create_content_index_table(), bump SCHEMA_VERSION
includes/AI/PromptBuilder.php            + with_content_context(), build_content_section()
includes/AI/RestController.php           + step 5b: content search after product search
includes/Activator.php                   + run_initial_content_index(), + cron register
includes/Deactivator.php                 + cron unregister
includes/Admin/Settings.php              + register_content_settings()
includes/Admin/Admin.php                 + content_settings_page() menu hook
includes/Admin/views/settings-content.php   New tab UI
includes/Plugin.php                      + wire save_post hook to ContentIndexer
includes/functions.php                   + trcl_content_post_types() helper
```

---

## ContentChunker contract

```php
ContentChunker::chunk( string $content, int $target = 400, int $overlap = 50 ): array
```

1. Strip shortcodes (`strip_shortcodes`), then HTML (`wp_strip_all_tags`).
2. Collapse whitespace and normalise quotes/dashes.
3. Split on `\n\n` (paragraph boundary).
4. If a paragraph is longer than `target * 1.5`, split by sentences (`. `, `? `, `! `).
5. Walk the list greedily, accumulating until size hits `target`. Emit chunk.
6. Carry the last `overlap` chars into the next chunk so cross-paragraph queries hit.
7. Return `array<string>` of chunks. Empty string filtered out.

Pure function. No WP dependencies. Test target: 1 unit test file covering 5–6 inputs.

---

## ContentIndexer contract

```php
ContentIndexer::index_all( array $post_types, array $opted_in_ids ): array
ContentIndexer::index_post( int $post_id ): bool
ContentIndexer::index_term( int $term_id, string $taxonomy = 'product_cat' ): bool
ContentIndexer::delete_for_post( int $post_id ): bool
ContentIndexer::get_status(): array { indexed: int, last_indexed: string, chunks: int }
```

Flow for `index_post`:

1. Load `WP_Post`. Skip if not in selected post_types or not in opted-in IDs.
2. Skip if `post_status !== 'publish'` (also delete existing chunks → keeps index clean on unpublish).
3. Skip products (`post_type === 'product'` is a hard guard — products are owned by `ProductSearch`).
4. Compose source = `post_title . "\n\n" . post_content . "\n\n" . post_excerpt`.
5. `ContentChunker::chunk()` → array of snippet strings.
6. Transaction-style: `DELETE WHERE post_id = X` then `INSERT` one row per chunk.
7. Update `last_indexed = NOW()`.

Reuses the `dbDelta` schema → safe to call repeatedly.

---

## ContentSearch contract

```php
ContentSearch::search( string $message, int $limit = 3 ): array
ContentSearch::should_search( string $message, bool $product_search_found_results ): bool
```

`should_search()` is the intent gate:
- Returns `false` if `$product_search_found_results === true` (product takes priority).
- Returns `false` if message is pure greeting (reuse short-circuit list from `ProductSearch`).
- Returns `true` if message matches one of these policy/FAQ regex patterns
  (extracted from `ProductSearch::is_product_query` exclusion list):
  - shipping / delivery / postage
  - return / refund / exchange
  - privacy / terms / conditions
  - contact / email / phone / hours
  - track / tracking / where is my order
  - payment methods / accept (paypal|card|...)
- Returns `true` as default fallback after product search returns empty
  (last-resort discovery for ambiguous queries).

`search()`:
1. Normalise query (lowercase, strip punctuation, dedupe whitespace).
2. Run FULLTEXT MATCH IN NATURAL LANGUAGE MODE with relevance scoring.
3. If results < 2 or query length < 4 chars, fallback to LIKE `%term%`
   on `title` and `snippet` (UNION, dedupe by id).
4. Filter results by `score > 0.3` threshold (tunable).
5. Return top-N as `array{title: string, snippet: string, url: string, score: float}`.

Latency target: <100ms. Add `trcl_log('Content search', 'debug', [...])` for
observability matching `ProductSearch`.

---

## PromptBuilder integration

Add two methods, mirroring product context:

```php
public function with_content_context( array $matches ): self
private function build_content_section(): string
```

Section template:

```
RELEVANT STORE CONTENT:
- From "<title>": "<snippet truncated to 300 chars>..."
- From "<title>": "<snippet truncated to 300 chars>..."

Use this content to answer questions about store policies, FAQs, services,
or general store information. Cite the page title when referencing this
information. Do NOT invent details not present in the content provided.
If the content does not answer the question, say so and offer to redirect
the customer to the relevant page.
```

Order in `build()`: persona → store → guardrails → **content (NEW)** →
products → empty-search → guidelines → custom.

Rationale for placement before products: when both content and products
are present (rare), content is policy/factual; products are commercial.
The AI weights commercial intent last so the assistant focuses on closing.

---

## RestController step 5b

After current step 5 (product search), before step 6 (build prompt):

```php
// 5b. Search relevant content (FAQs, policies, etc.)
$content_results = [];
if ( $this->content_search->should_search( $message, ! empty( $product_results ) ) ) {
    $content_results = $this->content_search->search( $message, 3 );
}

if ( ! empty( $content_results ) ) {
    $this->prompt_builder->with_content_context( $content_results );
}
```

Inject `$content_search` via constructor (`ContentSearch` instance, same
pattern as `ProductSearch`). Add to debug log:

```php
'content_results' => count( $content_results ),
'content_titles'  => array_column( $content_results, 'title' ),
```

---

## Settings UI (new tab "Content")

Path: `WP Admin → Trill Chat → Settings → Content` tab.

Sections:

1. **Enable indexing** — single toggle. Default ON for new installs, OFF for
   upgrades from 1.x (don't surprise existing merchants until they opt in).

2. **Which content to index**
   - Checkbox: "WordPress Pages" (ON by default)
   - Checkbox: "Blog posts" (OFF by default)
   - Checkbox: "Product category descriptions" (ON by default)
   - Auto-detected CPTs: render checkbox per CPT found via
     `get_post_types(['public' => true, '_builtin' => false])`. All OFF.

3. **Page selector** (visible when "WordPress Pages" is ON)
   - Multi-select with all published pages, paginated.
   - Default: top 5 pages by `menu_order` are pre-checked (typically
     Home, About, Contact, FAQ, Shipping).
   - Count badge: "12 pages, 5 selected for indexing".

4. **Index status** (read-only display)
   - "Last indexed: 2026-05-25 14:30"
   - "Indexed pages: 5"
   - "Total chunks: 28"
   - Button: "Reindex now" (admin-post handler, batches via `wp_schedule_single_event`).

5. **Auto-reindex toggle** — default ON.

Storage: `trcl_content_settings` option with serialised array:

```php
[
    'enabled'        => '1',
    'post_types'     => [ 'page' => '1', 'post' => '0', 'product_cat' => '1' ],
    'included_ids'   => [ 'page' => [ 12, 34, 56 ] ],
    'auto_reindex'   => '1',
]
```

---

## Activator changes

```php
self::run_migrations();          // bumps to 1.1.0
self::set_default_options();     // + 'trcl_content_settings' defaults
self::create_roles();
self::schedule_cron_jobs();      // + 'trcl_index_content' daily
self::run_initial_index();
self::run_initial_content_index(); // NEW: index opted-in pages on activate
self::register_trial();
```

`run_initial_content_index` is best-effort: never `wp_die` on failure,
log only.

---

## Cron + save_post hooks

In `Plugin::register_hooks()`:

```php
add_action( 'save_post', [ $content_indexer, 'on_save_post' ], 10, 3 );
add_action( 'trashed_post', [ $content_indexer, 'delete_for_post' ] );
add_action( 'deleted_post', [ $content_indexer, 'delete_for_post' ] );
add_action( 'trcl_index_content', [ $content_indexer, 'index_all_opted_in' ] );

// Optional: also product_cat term updates.
add_action( 'edited_product_cat', [ $content_indexer, 'index_term' ] );
```

`on_save_post` guards:
- Skip autosaves (`wp_is_post_autosave`), revisions (`wp_is_post_revision`).
- Skip if post_type not opted-in.
- Skip if post not in `included_ids`.
- Skip if status changes to non-publish → delete chunks instead.

---

## Edge cases to handle

| Case | Handling |
|---|---|
| Plugin Check FULLTEXT compat | Verify InnoDB engine before adding FULLTEXT key. If MyISAM (unlikely on WP 6.0+), skip FULLTEXT, log warning, fall back to LIKE-only. |
| Multilingual sites (WPML, Polylang) | Out of scope for v2.0. Document as known limitation: indexes only the default language pages. Future: per-locale filter. |
| Gutenberg block content | `wp_strip_all_tags` after `do_blocks` is too aggressive (loses semantic structure). Use `post_content` raw + chunker handles HTML stripping. |
| Page builder content (Elementor, etc.) | `get_post_field('post_content', $id)` returns shortcode markup. `strip_shortcodes` then strip tags. Document as "rendered content may differ from indexed content". |
| Very long pages (>10k chars) | Cap at 50 chunks per post to prevent runaway indexing of dumped logs / pasted JSON. |
| Empty snippet after stripping | Skip chunk, don't insert empty rows. |
| Settings tab UX in WP admin | Use existing tabbed view pattern from `settings.php`. Don't introduce new framework. |

---

## What v2.0 explicitly does NOT include (deferred to v2.1+)

- External embeddings (OpenAI ada, etc.) for semantic similarity.
- Vector database (pgvector, Pinecone, etc.).
- Cross-language indexing.
- Per-snippet feedback (was this content helpful?).
- Real-time content suggestions in widget (autocomplete).

---

## Effort breakdown

| Component | Hours |
|---|---|
| Migrations schema + bump | 0.5 |
| ContentChunker + unit tests | 2 |
| ContentIndexer (index + delete + status) | 3 |
| ContentSearch (FULLTEXT + LIKE fallback + intent gate) | 2.5 |
| PromptBuilder section | 0.5 |
| RestController wiring (step 5b) | 0.5 |
| Settings tab UI + sanitization | 2.5 |
| Activator + Deactivator + cron + hooks wiring | 1 |
| Manual smoke + WP CLI test commands | 1.5 |
| **Total** | **~13.5h** |

---

## Acceptance checklist (Block 1 done when…)

- [ ] `Migrations` 1.1.0 creates `wp_trcl_content_index` cleanly on fresh activate.
- [ ] `Migrations` 1.1.0 upgrades existing 1.0.0 installs without error.
- [ ] Settings tab "Content" loads, saves, reads back state.
- [ ] Activate plugin on test site with 5 pages → `run_initial_content_index` produces N chunks (verify via direct query).
- [ ] Edit a page → `save_post` hook re-indexes within same request.
- [ ] Trash a page → chunks removed.
- [ ] Frontend chat: ask "what's your shipping policy?" → AI quotes the shipping page snippet.
- [ ] Frontend chat: ask "do you have red dresses?" → product search wins, content search does not pollute prompt.
- [ ] Edge: page with only shortcodes/embeds → no empty chunks inserted.
- [ ] Manual `Reindex now` button works end-to-end.
- [ ] Daily cron `trcl_index_content` is registered (verify with `wp cron event list`).
- [ ] Deactivate → cron unscheduled.
- [ ] Uninstall → table dropped.

---

## Next block (Block 2: GDPR conversation management) starts after Block 1 acceptance.
