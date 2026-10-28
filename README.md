# Member Documents

[![CI](https://github.com/gim007/member-docs/actions/workflows/ci.yml/badge.svg)](https://github.com/gim007/member-docs/actions/workflows/ci.yml)

A WordPress plugin for documents that members must accept, such as membership terms or a privacy policy.
Every edit publishes an **immutable, hashed version**. Every acceptance goes into an **append-only audit log**
with a **PDF copy** that is kept in private storage, emailed to the member and downloadable only through an
authenticated endpoint. Legacy acceptances can be imported with an idempotent WP-CLI migration. The optional
WooCommerce module asks customers to accept a document at checkout. The plugin is written as a code sample:
small, tested (unit, integration and JS), WPCS-clean and PHPStan level 6.

## Quick start

Requirements: Docker, Node 20+, PHP 8.1+ and Composer.

```bash
npm install && npx wp-env start              # WordPress + WooCommerce on http://localhost:8888
composer install
npx wp-env run cli wp member-docs seed       # demo documents, 12 members, pages, acceptances
```

Log in at <http://localhost:8888/wp-admin> as `admin` / `password`, then:

- **Member Documents → All Documents**: edit *Membership Terms*. Saving changed text shows "Version 3 published".
  Saving without changes publishes nothing. *Version history* lists every version with its hash and acceptances.
- **Member Documents → Acceptance Log**: filter by document, version, user or date, download PDFs, and use *Export CSV*.
- Visit <http://localhost:8888/membership-terms/>: as admin you see the document and an **I accept** button.
  Log out to see the login prompt.

Import the legacy CSV (it contains deliberately broken rows) and verify the audit trail:

```bash
npx wp-env run cli wp member-docs migrate wp-content/plugins/member-docs/sample-data/legacy-acceptances.csv --dry-run
npx wp-env run cli wp member-docs migrate wp-content/plugins/member-docs/sample-data/legacy-acceptances.csv
npx wp-env run cli wp member-docs verify
```

WooCommerce checkout: choose a document under **WooCommerce → Settings → Advanced → Page setup → Member document**.
The demo checkout page uses the checkout block, so switch it to the classic checkout first:

```bash
npx wp-env run cli bash -c 'wp post update $(wp option get woocommerce_checkout_page_id) --post_content="[woocommerce_checkout]"'
```

## Architecture

```
member-docs.php            Plugin header, constants, autoloader, bootstrap only
uninstall.php              Deletes data only when explicitly opted in
src/
  Plugin.php               Builds all services and registers hooks: the one file that shows the wiring
  Installer.php            Custom tables (dbDelta), schema version + upgrades, capabilities
  Capabilities.php         md_manage_templates, md_view_acceptances
  View.php                 Renders PHP templates from templates/
  Domain/                  Template, TemplateVersion, Acceptance (readonly value objects),
                           TemplateStatus + AcceptanceSource enums, ContentHash
  Repository/              TemplateRepository, VersionRepository and AcceptanceRepository ($wpdb, prepared queries)
  Service/                 TemplateService (versioning), AcceptanceService, PlaceholderRenderer,
                           DocumentRenderer, ClientInfo
  Pdf/                     PdfRenderer interface, DompdfRenderer, PrivateStorage
  Mail/                    AcceptanceMailer (listens to member_docs_accepted)
  Rest/                    TemplatesController, AcceptancesController, DownloadController
  Admin/                   Menu, list tables, edit/history/version screens, acceptance log, CSV export
  Frontend/                [member_document] shortcode
  Cli/                     wp member-docs migrate|verify|seed, LegacyRowParser, Migrator, Verifier, Seeder
  Integration/WooCommerce/ Classic checkout integration
assets/js/accept.js        Vanilla JS, no build step
templates/                 PHP views; all output escaped
tests/Unit                 Pure PHP tests (no WordPress)
tests/Integration          WordPress test suite (wp-env), including WooCommerce
tests/js                   node:test + jsdom tests for accept.js
sample-data/               legacy-acceptances.csv
```

### Acceptance flow (`AcceptanceService::accept()`)

1. Check that the version is the **current** version (the latest version of an *active* template); otherwise throw `StaleVersionException` (REST: 409).
2. If the member already accepted this version, return the existing record (idempotent).
3. Render the body with placeholders, generate the PDF (`member_docs_pdf_html` filter) and store it in private storage.
4. Insert the acceptance. If the insert fails, delete the PDF again. If a concurrent request won the unique key, return its record.
5. Fire `do_action( 'member_docs_accepted', $acceptance )`.
6. `AcceptanceMailer` listens to that action and emails the PDF (`member_docs_mail_subject`, `member_docs_mail_body`).

Filters and actions: `member_docs_placeholders`, `member_docs_pdf_html`, `member_docs_mail_subject`,
`member_docs_mail_body`, `member_docs_accepted`.

## Design decisions

**Custom tables instead of a CPT.** Versions and acceptances are append-only audit data, not editable content.
Custom tables give real constraints: `UNIQUE (template_id, version)` and `UNIQUE (version_id, user_id)` make
sequential numbering and idempotency enforceable by the database. They also give typed, indexed columns for the
log filters (user, version, date, order) instead of `postmeta` joins, and room for hundreds of thousands of rows.
No revisions, trash, `wp_update_post()` or third-party content plugins can touch the records. Only the
templates table (title, slug, status) is mutable.

**Immutable versions + content hash.** Saving changed content publishes version N+1, and old rows are never
updated. `VersionRepository` and `AcceptanceRepository` have no update or delete method, and a reflection test
enforces that. Each version stores a SHA-256 of its normalized title and body (line endings and whitespace runs
don't count). Each acceptance copies that hash and stores a SHA-256 of its PDF. `wp member-docs verify` recomputes
all of them, so editing a row or a file directly is detectable.

**Idempotent acceptance and stale-version protection.** The client sends the `version_id` it displayed. If the
document changed in the meantime, the API answers 409 and the page reloads, so nobody can accept text they
haven't seen. Accepting twice returns the same record (200 instead of 201). Concurrent double clicks are
resolved by the unique key.

**Private storage and authenticated downloads.** PDFs live in `uploads/member-docs-private/YYYY/MM/` under
128-bit random names, protected by `.htaccess`, `web.config` and `index.php`. They are served only by
`GET /member-docs/v1/acceptances/{id}/pdf` after a permission check: the owner, or users with
`md_view_acceptances`. A missing record and a foreign record get the same 403, so IDs are not confirmed. The
file is streamed through `rest_pre_serve_request` and never loaded into memory or JSON. **Nginx ignores
`.htaccess`**, so add:

```nginx
location ^~ /wp-content/uploads/member-docs-private/ { deny all; }
```

**Decoupled email.** The service only fires `member_docs_accepted`, and the mailer is one listener. Mail can be
disabled, replaced or queued without touching the acceptance flow, and a mail failure can't roll back an
acceptance.

**Migration: idempotent, dry-run, batched, reported.** `LegacyRowParser` is pure PHP and unit-tested (emails,
slugs, versions, real calendar dates, no future dates, IPs). Duplicates inside the file and rows already in
the log are *skipped*, so re-running imports nothing new. Invalid rows *fail* with a reason and go to a CSV
report. `--dry-run` runs every lookup but writes nothing. Rows are processed in `--batch-size` batches with a
progress bar, and the runtime cache is flushed between batches. Imports get `source = migration`, keep their
original date, get no PDF unless `--generate-pdfs`, and send no email. `--create-missing-versions` only creates
*unknown* templates (archived, with placeholder versions). Adding a placeholder to an existing template would
make it the current version and ask every member to re-accept.

**No database transaction around the insert.** The spec asks for one. The acceptance is a single-row INSERT,
which is already atomic, and the unique key covers races. An explicit `START TRANSACTION` would add nothing.
It would also break the WordPress test suite, which wraps every test in a transaction that a nested
`START TRANSACTION` implicitly commits. Cleanup is explicit instead: a failed insert deletes the PDF.

## Security notes

- **Capabilities**: `md_manage_templates` (edit documents) and `md_view_acceptances` (log, export, any PDF),
  granted to administrators on activation. Every admin action checks a capability and a nonce
  (`check_admin_referer`). Every REST route has a real `permission_callback`, and a test asserts none use
  `__return_true`. REST arguments are validated through schemas.
- **Nonces**: admin forms and the export link carry nonces. The frontend uses the `wp_rest` nonce, and the
  download links include it so plain links work with cookie auth.
- **Queries**: all SQL goes through `$wpdb->prepare()`, with `%i` for table names.
- **Escaping**: templates escape late (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`). Document bodies are
  `wp_kses_post`-sanitized on save, and placeholder values are HTML-escaped on render. `accept.js` writes
  response data with `textContent` only.
- **CSV injection**: exported cells starting with `= + - @`, tab or CR get a leading `'`.
- **Notices** travel in a per-user transient, never in query arguments.
- **Client data**: only `REMOTE_ADDR` is trusted (behind a proxy, map the real IP at server level). User agents
  are stripped of control characters and truncated to 255 characters.
- **Uninstall** deletes nothing unless `wp option update member_docs_delete_data_on_uninstall 1` was set.

## Testing

```bash
composer test        # unit + integration tests inside wp-env (WordPress test suite, WooCommerce loaded)
composer test:unit   # pure PHP unit tests on the host, no WordPress
composer lint        # PHPCS (WordPress-Extra; PSR-4 file names allowed)
composer analyse     # PHPStan level 6 with WordPress, WooCommerce and WP-CLI stubs
npm test             # accept.js (node:test + jsdom)
```

CI (GitHub Actions) runs PHPCS, PHPStan and the JS tests. It runs PHPUnit on PHP 8.1, 8.2 and 8.3 against the
latest WordPress and the latest release of the previous minor branch (resolved from the WordPress.org API),
then smoke-tests the WP-CLI commands. Composer and npm caches are enabled.

## Out of scope / what I'd add in production

- **Queue PDF and email work** with Action Scheduler, so a slow PDF engine or SMTP server never delays the
  request. The action hook already separates the two.
- **Block checkout support** through a Store API extension (`woocommerce_store_api_register_endpoint_data`) and
  a checkout block integration. Only the classic checkout is supported here.
- **Guest checkout acceptances** in the member log. Guests have no user account, so today only the order meta
  records the version.
- **GDPR**: personal data exporter and eraser hooks. The eraser would anonymise IP and user agent but keep the
  legal record.
- **Multisite** support (per-site tables, network activation).
- **Signed, expiring download URLs** for links in emails, instead of nonce-based REST links.
- **Retention policy** for PDFs and logs, with a scheduled purge.
- An admin setting for the delete-on-uninstall flag, and trusted-proxy configuration for client IPs.
