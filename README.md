# PHP SEO Auditor

Dependency-free CLI tool that audits a single URL for on-page SEO basics and prints a **Markdown report** (or JSON with `--json`). No API keys, no accounts — just run it.

## Usage

```bash
php seo-audit.php https://example.com/page
php seo-audit.php https://example.com/page --json > report.json
php seo-audit.php https://example.com/page --no-color | tee audit.md
```

Exit code is `1` when any check fails, so it works in CI.

## What it checks

| Category | Checks |
|---|---|
| Transport | HTTP status, redirect chain (each hop shown), load time, HTML size, gzip/deflate compression |
| Meta | Title length (30–60 ideal), meta description length (120–160 ideal), canonical, robots meta, viewport |
| Headings | Exactly one H1, at least one H2 |
| Social | Open Graph tag coverage, Twitter card tags |
| Images | Alt-text coverage percentage |
| Content | Body word count (300+ recommended) |
| Crawl | `robots.txt` and `sitemap.xml` presence at the origin |

Redirects are followed **manually** so the full hop chain is visible in the report.

## Sample output

```markdown
# SEO audit: https://example.com/page

Grade: **Good** — 14 pass, 2 warnings, 0 failures

| Check | Result | Detail |
|---|---|---|
| Title tag | PASS | "Example Domain" (14 chars; ideal 30–60) |
...
```

## Notes

- Uses cURL when available, with a `stream_context` fallback.
- HTML parsing is regex-based on purpose (no ext-dom required).
- Be polite: don't hammer other people's sites in a loop.

Requires PHP 7.4+. MIT licensed.
