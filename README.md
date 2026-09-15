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
shooting-results.php              Plugin bootstrap, activation hook, legacy-role cleanup
includes/
  class-sr-db.php                 Custom table schema (sessions/shooters/rounds/entries)
  class-sr-shortcode.php          [shooting_results] shortcode — the front-end recording page
  class-sr-admin-page.php         wp-admin Settings page (copyable shortcode + instructions only)
  class-sr-ajax.php               All AJAX endpoints — the only place data is written
  class-sr-xlsx-writer.php        Dependency-free .xlsx builder (ZipArchive + raw OOXML)
  class-sr-mailer.php             Builds the workbook from DB state and wp_mail()s it
assets/
  css/app.css                     Mobile-first styling for the front-end shortcode UI
  js/app.js                       Vanilla JS — renders the UI, calls the AJAX endpoints
  css/settings.css, js/settings.js  The wp-admin Settings page's "copy shortcode" button
languages/
  shooting-results.pot            Translation template (regenerate with WP-CLI, see below)
  shooting-results-fi.po/.mo      Finnish translation (included, compiled)
```

### Access model: page password, not a WordPress role

Results recording lives on whatever front-end page the site owner puts the
`[shooting_results]` shortcode on, and access is controlled by WordPress's
built-in page-password protection rather than a login or capability. This
was a deliberate trade against the earlier "Range Staff" role/capability
(removed in 1.1.0): recorders often change mid-competition and need to
switch devices, which a per-user login model makes clunky. With a shared
page password, anyone who has it can open the page on any device and pick
up the currently open session from the list.

`SR_Ajax::guard()` (`includes/class-sr-ajax.php`) enforces the same rule
server-side on every AJAX call: the request must name a `post_id` that
`SR_Shortcode::hosts_shortcode()` recognizes as a legitimate host (so an
arbitrary unrelated, unprotected post/page ID can't be used to bypass the
check), and `post_password_required()` must be false for that post —
i.e. the visitor's `wp-postpass_*` cookie matches, or they're logged in with
edit rights on the post (WordPress's own password-bypass rule, which is why
admins never need the password). If the page isn't password-protected at
all, the AJAX endpoints are exactly as open as the page itself — the
plugin doesn't add its own access control beyond mirroring the page's.

`hosts_shortcode()` checks a post meta flag (`_sr_hosts_shortcode`) set the
first time `SR_Shortcode::render()` actually executes for a post, rather
than checking `has_shortcode( $post->post_content, ... )` directly. Page
builders — Breakdance among them — store their content outside
`post_content` and only run the shortcode through WordPress's normal
shortcode processing at render time, so checking `post_content` missed it
entirely on such builders and made every AJAX call fail with a permission
error. The meta flag works regardless of how the page was built, since it's
set by the shortcode's own callback actually running, not by inspecting
where the builder chose to store its markup.

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
wp_sr_sessions(id, created_by, created_at, shots_per_round, discipline, distance, status, report_email, report_sent_at)
wp_sr_shooters(id, session_id, name, sort_order, active)
wp_sr_rounds(id, session_id, round_number, created_at)
wp_sr_entries(id, round_id, shooter_id, shots JSON, updated_at)
```

`status` is `draft` until a report has been sent, then `sent` — this is the
literal "saved as a draft" behaviour that was asked for, just implemented as
a database row from the first tap rather than a client-side draft that
needs syncing.

`discipline` is `rifle` (per-shot 0–10 entries, `shots_per_round` chosen at
session creation) or `shotgun` (`shots_per_round` is always 1 — a shotgun
round records a single final result, e.g. hits out of however many
targets, entered as one number up to 200 rather than a per-shot
breakdown).

`distance` is `NULL` unless the site has the Settings-page "Show Rifle
75 m and 100 m" option on (`SR_Admin_Page::OPTION_RIFLE_DISTANCES`,
localized to the front end as `SR.enableRifleDistances`) and the session
is `rifle` — only then does the new-session modal ask for it, and only
`75`/`100` are accepted server-side regardless of what's posted.

Removing a shooter (`sr_remove_shooter`) always deletes their entry in
*whichever round is currently being viewed* (any round, not just the
latest — fixes a shooter mistakenly listed on an earlier round without
touching later ones). It additionally sets `active = 0` — stopping them
from being carried into future rounds — only when the round being edited
is the session's latest one; removing them from an earlier round doesn't
retroactively pull them out of later rounds already in progress.

## AJAX endpoints (`admin-ajax.php?action=...`)

All require a valid `sr_ajax` nonce and pass the page-password check in
`SR_Ajax::guard()` (see "Access model" above), and every round/shooter ID
received from the browser is checked to actually belong to the session ID
also received, before touching the database.

| Action | Purpose |
|---|---|
| `sr_list_sessions` | List drafts + sent sessions |
| `sr_create_session` | Start a new session + round 1 |
| `sr_get_session` | Full nested state — used on load/refresh. Refuses once the session's `status` is `sent`: sending the report closes a session for good, so it can no longer be reopened for viewing or editing (the front end also stops linking to it in the session list) |
| `sr_add_shooter` | Add a shooter to the current round |
| `sr_remove_shooter` | Drop a shooter from the round being viewed |
| `sr_set_shot` | Write one shot/result score (0–10 rifle, 0–200 shotgun, or empty) |
| `sr_start_new_round` | New round, active shooters carried over |
| `sr_send_report` | Build the .xlsx from DB state and email it |

`sr_admin_delete_session` is separate: it's wp-admin-only (`manage_options`,
its own `sr_admin_ajax` nonce, no `_nopriv_` hook), reachable only from the
Settings page's session list, since deleting a whole session is
destructive and shouldn't be reachable from the password-gated front end.

The Settings page's "Download report" link is not an AJAX action at all —
it's `admin_post_sr_download_report` (`SR_Admin_Page::download_report()`),
since a file download is a plain navigation, not something JS needs a JSON
response for. Same `manage_options` + per-session nonce pattern as the
delete action. Both it and `send_report`/`sr_send_report` build the
workbook through the same `SR_Mailer::build_report()`.

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

## Releasing updates

The plugin bundles [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)
(`includes/plugin-update-checker/`), pointed at this GitHub repo, so sites
with the plugin installed see new versions show up under **Dashboard →
Updates** / **Plugins**, same as a wordpress.org-hosted plugin — no separate
update server needed.

To ship a new version:

1. Bump `Version:` in `shooting-results.php` (and `SR_VERSION`) and `Stable tag:` in `readme.txt`.
2. Commit, then tag and push:
   ```bash
   git tag vX.Y.Z
   git push origin main --tags
   ```
3. Publish a GitHub Release from that tag (`gh release create vX.Y.Z --generate-notes`).

Sites poll for updates on their normal WP cron schedule (roughly every 12
hours), or immediately if an admin clicks "Check again" on the Updates page.
