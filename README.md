# Shooting Results

A single-purpose WordPress plugin: fast, big-button competition results
recording for shooting ranges, with an Excel report emailed on demand.
Split out of a larger range-management concept — this plugin does one thing
(results recording) and does it standalone, with no other dependency.

## Why every tap saves immediately (no Save button)

This was the one hard requirement: **a page refresh must never lose a
recorded result.** Rather than building a local-draft/autosave/sync system,
every single mutation (a shot score, adding a shooter, starting a round) is
its own AJAX request that commits straight to the database before the
keypad modal even closes. A refresh just calls `sr_get_session` again and
redraws the exact same rows — there is no separate in-memory draft that a
refresh could discard, because nothing is ever *only* in memory.

The session ID is also mirrored into the page URL
(`?page=shooting-results&session=123`) via `history.replaceState`, so a
refresh reopens the same session automatically rather than dropping back to
the session list.

## Architecture

```
shooting-results.php              Plugin bootstrap, activation hook
includes/
  class-sr-db.php                 Custom table schema (sessions/shooters/rounds/entries)
  class-sr-capabilities.php       "Range Staff" role + sr_manage_results capability
  class-sr-admin-page.php         wp-admin menu page, asset enqueue, i18n strings → JS
  class-sr-ajax.php               All AJAX endpoints — the only place data is written
  class-sr-xlsx-writer.php        Dependency-free .xlsx builder (ZipArchive + raw OOXML)
  class-sr-mailer.php             Builds the workbook from DB state and wp_mail()s it
assets/
  css/admin.css                   Light wp-admin-native styling, hunter-green/blaze-orange accents
  js/admin.js                     Vanilla JS — renders the UI, calls the AJAX endpoints
languages/
  shooting-results.pot            Translation template (regenerate with WP-CLI, see below)
  shooting-results-fi.po/.mo      Finnish translation (included, compiled)
```

### Why custom tables instead of a Custom Post Type

A CPT + postmeta would mean one `wp_postmeta` row write per single shot
saved — fine at first, ugly once you're querying "give me every shot of
every shooter across every round of this session" for the Excel export.
Four small dedicated tables with proper foreign-key-shaped columns make that
query trivial and keep the schema self-documenting.

### Why a hand-rolled .xlsx writer instead of PhpSpreadsheet

The output is genuinely simple — rows of numbers with a bold header — and
PhpSpreadsheet is a multi-megabyte Composer dependency for that. The `.xlsx`
format is just a zip of a handful of small XML files (workbook, one
worksheet per sheet, minimal styles); `class-sr-xlsx-writer.php` builds
those directly with `ZipArchive`, which ships with PHP. It was verified to
round-trip cleanly through `openpyxl` (including non-ASCII names and
special characters) during development.

## Data model

```
wp_sr_sessions(id, created_by, created_at, shots_per_round, status, report_email, report_sent_at)
wp_sr_shooters(id, session_id, name, sort_order, active)
wp_sr_rounds(id, session_id, round_number, created_at)
wp_sr_entries(id, round_id, shooter_id, shots JSON, updated_at)
```

`status` is `draft` until a report has been sent, then `sent` — this is the
literal "saved as a draft" behaviour that was asked for, just implemented as
a database row from the first tap rather than a client-side draft that
needs syncing.

Removing a shooter (`sr_remove_shooter`) sets `active = 0` and deletes only
their entry in the *current* round — their scores in earlier rounds stay in
the database and in the exported report.

## AJAX endpoints (`admin-ajax.php?action=...`)

All require a valid `sr_ajax` nonce and the `sr_manage_results` capability
(enforced in `SR_Ajax::guard()`), and every round/shooter ID received from
the browser is checked to actually belong to the session ID also received,
before touching the database.

| Action | Purpose |
|---|---|
| `sr_list_sessions` | List drafts + sent sessions |
| `sr_create_session` | Start a new session + round 1 |
| `sr_get_session` | Full nested state — used on load/refresh |
| `sr_add_shooter` | Add a shooter to the current round |
| `sr_remove_shooter` | Drop a shooter from future rounds |
| `sr_set_shot` | Write one shot score (0–10 or empty) |
| `sr_start_new_round` | New round, active shooters carried over |
| `sr_send_report` | Build the .xlsx from DB state and email it |

## Roles & capabilities

Activation adds the `sr_manage_results` capability to Administrators and
creates a **Range Staff** role that has just that capability — so a club
doesn't need to hand out full admin accounts to whoever is running the
scoreboard on a given day.

## Translations

Strings are wrapped in standard `__()`/`_e()` calls in PHP; the JS UI text
is translated server-side too (see `SR_Admin_Page::i18n_strings()`) and
handed to the browser via `wp_localize_script`, so there's no separate
JS-side translation catalog to maintain.

To regenerate the `.pot` after changing strings (requires [WP-CLI](https://wp-cli.org/)):

```bash
wp i18n make-pot . languages/shooting-results.pot --domain=shooting-results
```

To update the Finnish translation, edit `languages/shooting-results-fi.po`
and recompile with `msgfmt` (part of GNU gettext, `brew install gettext` on
macOS):

```bash
msgfmt -o languages/shooting-results-fi.mo languages/shooting-results-fi.po
```

## Requirements

- WordPress 6.4+
- PHP 7.4+ with the `zip` extension (near-universal on any real host; used only for building the `.xlsx`)
- A working `wp_mail()` — if the site doesn't already send email reliably, install an SMTP plugin (e.g. WP Mail SMTP) first, otherwise "Send Report" will fail silently into most hosts' disabled default mail transport.

## Installation (development)

```bash
# from your WordPress install:
cd wp-content/plugins
ln -s /Users/anttimakela/Projects/shooting-results shooting-results
wp plugin activate shooting-results
```

Then visit **Shooting Results** in wp-admin.
