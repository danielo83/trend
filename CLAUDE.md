# CLAUDE.md — AutoPilot Content Generation Platform

This file provides guidance for AI assistants working on this codebase.

---

## Project Overview

**AutoPilot** is a PHP-based content generation and SEO optimization platform. It automates article creation, SEO/GEO analysis, and WordPress publishing for a niche content site ("Sogni e Dormire" — Dreams and Sleep).

**Stack:** Pure PHP (no frameworks, no Composer), custom CSS/JS, JSON/SQLite storage, deployed via FTP.

---

## Repository Structure

```
/
├── src/                        # Core PHP classes (18 classes)
├── dashboard/                  # Dashboard UI tab files and layout components
├── data/                       # JSON data files and SQLite DB (git-ignored)
├── .github/workflows/          # GitHub Actions FTP deployment
├── index.php                   # Public article reader (password-protected)
├── dashboard.php               # Dashboard orchestrator (routes to tabs)
├── main.php                    # Primary content generation entry point
├── run.php / run_stream.php    # Async run with JSONL progress tracking
├── rewrite.php / rewrite_stream.php  # Content rewriting module
├── generate_stream.php         # Stream-based single-article generation
├── factcheck_stream.php        # Fact-checking with stream output
├── cron.php                    # URL-based cron trigger (token-secured)
├── config.php                  # Main configuration (loads .env)
└── .htaccess                   # Apache security rules
```

### `src/` — Core Classes

| File | Purpose |
|------|---------|
| `ContentGenerator.php` | Multi-provider LLM orchestration (OpenAI, Gemini, OpenRouter) |
| `SEOOptimizer.php` | On-page SEO analysis, 0–100 scoring, GEO metrics |
| `FeaturedSnippetOptimizer.php` | Featured snippet targeting, schema generation |
| `SmartLinkBuilder.php` | Semantic internal linking (extends LinkBuilder) |
| `LinkBuilder.php` | Base link discovery and anchor text suggestions |
| `WordPressPublisher.php` | WordPress REST API publishing |
| `ContentAnalytics.php` | Article performance tracking, indexing status |
| `ContentHubManager.php` | Pillar content strategy, topic clustering |
| `ImageGenerator.php` | Fal.ai image generation (Flux Schnell model) |
| `RichResultsGenerator.php` | JSON-LD schema markup (Article, FAQ, HowTo) |
| `RSSFeedBuilder.php` | RSS/Atom feed generation |
| `SocialFeedBuilder.php` | Facebook/Twitter copy generation |
| `SEOMonitor.php` | Article ranking monitoring and alerts |
| `AutocompleteFetcher.php` | Google Autocomplete-based keyword research |
| `TopicFilter.php` | LLM-powered relevance filtering and spam detection |
| `MaxSEOGEOConfig.php` | SEO/GEO configuration profiles and master prompts |
| `EnvLoader.php` | .env file parser for secure API key loading |

### `dashboard/` — UI Components

| File | Purpose |
|------|---------|
| `bootstrap.php` | Authentication, initialization, data loading |
| `js_all.php` | All embedded JavaScript for the dashboard |
| `layout_head.php` | HTML head, CSS, dark theme styles |
| `layout_sidebar.php` | Navigation sidebar |
| `tab_overview.php` | System stats overview |
| `tab_feed.php` | Article feed management |
| `tab_topics.php` | Topic management |
| `tab_config.php` | Full configuration UI |
| `tab_logs.php` | Log viewer |
| `tab_linkbuilding.php` | Link building dashboard |
| `tab_seo.php` | SEO analytics and recommendations |
| `tab_contenthub.php` | Content hub strategy |
| `tab_richresults.php` | Schema markup preview |
| `tab_rewrite.php` | Content rewriting UI |
| `tab_factcheck.php` | Fact-checking interface |

---

## Development Workflows

### Git Branching

- **Main branch:** `main` (deploys automatically to production via FTP)
- **Feature branches:** `claude/<description>-<ID>` pattern used by AI agents
- Always develop on a feature branch; never push directly to `main`.

### Deployment

Deployment is automatic via GitHub Actions (`.github/workflows/deploy.yml`):
- **Trigger:** Push to `main`
- **Method:** FTP upload to `/test.danielo.it/trend/`
- **Secrets required:** `FTP_HOST`, `FTP_USERNAME`, `FTP_PASSWORD`

There is no local build step — files are deployed as-is.

### Configuration

1. Copy `.env.example` to `.env` (or create `.env` manually).
2. Set all required API keys (see Environment Variables below).
3. Adjust `config.php` for niche, model selection, and scheduling.

### Environment Variables (`.env`)

```
OPENAI_API_KEY=
GEMINI_API_KEY=
OPENROUTER_API_KEY=
FAL_API_KEY=
WP_APP_PASSWORD=
DASHBOARD_PASSWORD=
CRON_TOKEN=
```

The `.env` file is **never committed** (in `.gitignore`).

---

## Key Conventions

### PHP Style

- **No namespaces** — all classes are in the global scope.
- **One class per file** — file name matches class name exactly.
- **No Composer** — zero external dependencies; all API calls via `curl`.
- **camelCase** for methods and local variables.
- **CONSTANT_CASE** for class constants.
- PHPDoc blocks on classes and public methods; inline comments for complex logic.
- Doc strings and many comments are written in **Italian** (the project's primary language).

### Error Handling

- Wrap all external API calls in `try-catch` blocks.
- Use `@` suppressor only for `file_get_contents` / `file_put_contents` where graceful failure is acceptable.
- Log errors via the `logMsg()` function (writes to `logs/` directory).
- API failures should fall back to alternative providers where possible.

### Data Storage

- **JSON files** in `data/` for persistent state (SEO monitor, analytics, content hub, settings).
- **SQLite** (`data/history.sqlite`) for article history — optional, configurable in `config.php`.
- **JSONL files** in `data/` for streaming progress (`.run_progress.jsonl`, etc.) — auto-cleaned when > 1 MB.
- Never commit `data/` files that are listed in `.gitignore` (sqlite, jsonl, cache, images).

### Security Rules

- API keys must come from `.env` via `EnvLoader` — **never hardcode credentials**.
- `.htaccess` blocks direct HTTP access to `src/`, `.env`, `.sqlite`, and `logs/`.
- Dashboard and `index.php` are protected by session-based password authentication.
- `cron.php` requires a `CRON_TOKEN` query parameter for authorization.
- The file `id_rsa.ppk` in the repo root is a **security risk** and should be removed/rotated.

### Content Generation Pattern

When adding a new generation feature:
1. Add a method to `ContentGenerator.php` (or a new class in `src/`).
2. Expose it via a `*_stream.php` entry point for async/streaming output.
3. Add a dashboard tab (`dashboard/tab_*.php`) for the UI.
4. Route the new tab in `dashboard.php`.
5. Track output in `ContentAnalytics` if it produces articles.

### Lock File Pattern

Long-running processes (e.g., `main.php`) use a lock file (`data/run.lock`) to prevent concurrent execution. Always wrap the main logic in `try-finally` to ensure the lock is released even on error.

### Progress Tracking Pattern

Async operations write progress lines to `.jsonl` files. The format is:
```json
{"step": "step_name", "message": "Human-readable message", "percent": 42}
```
The browser polls `run.php?action=progress` to display real-time status.

---

## LLM Provider Selection

`ContentGenerator` supports three providers:

| Provider | Config key | Notes |
|----------|------------|-------|
| OpenAI | `openai` | Default; `gpt-4o-mini` by default |
| Google Gemini | `gemini` | `gemini-2.0-flash` by default |
| OpenRouter | `openrouter` | Unified API for many models |

The active provider is set in `config.php` (`default_provider`). Per-request overrides are supported.

---

## Testing

There is currently **no automated test suite**. When making changes:
- Test via the dashboard UI manually.
- For backend-only changes, create a small test PHP script and run it with `php -f test_script.php`.
- Check `logs/` for errors after any generation run.
- Verify API responses in `data/` JSON files.

---

## Common Tasks

### Add a new dashboard tab

1. Create `dashboard/tab_<name>.php`.
2. Add the tab route in `dashboard.php` (the `switch` or `if` block).
3. Add a sidebar link in `dashboard/layout_sidebar.php`.
4. Add any needed JavaScript to `dashboard/js_all.php`.

### Add a new LLM prompt

1. Define the prompt in `MaxSEOGEOConfig.php` if it's SEO-related, or inline in the relevant class method.
2. Pass it through `ContentGenerator::callProvider()` which handles retries and provider fallback.

### Change the content niche

Update `config.php`:
- `niche` — human-readable niche name
- `niche_keywords` — primary keywords
- `wp_category` — WordPress category ID for publishing
- Social feed prompts if relevant

### Rotate API keys

1. Update `.env` on the server directly (never commit).
2. Update GitHub Actions secrets for deployment if needed.
3. Revoke the old key from the provider dashboard.

---

## Files Never to Modify Directly

- `data/*.json` — managed by PHP classes; edit via dashboard or class methods.
- `data/history.sqlite` — managed by `ContentAnalytics`; never edit manually.
- `.env` — edit on server only; never commit.

---

## Known Issues / Tech Debt

- `id_rsa.ppk` is committed to the repo and should be removed and the key rotated.
- No automated tests exist; all verification is manual.
- No rate limiting on public endpoints (`index.php`, dashboard if misconfigured).
- `settings.json` is very large (~42K tokens) and may be slow to read/write.
- Dashboard JavaScript is embedded in a single `js_all.php` file; consider splitting if it grows further.
