=== Shooting Results ===
Contributors: anttimakela
Tags: shooting, sports, results, scoring, excel
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast, big-button competition results recording for shooting ranges. Every score autosaves immediately — a page refresh never loses data. Exports an Excel report by email.

== Description ==

**Shooting Results** gives shooting/hunting clubs a simple wp-admin page for
recording competition scores on the day, on a tablet or PC, with a big
touch-friendly numeric keypad — and none of the fragility of "fill in a form
and remember to click Save."

* **Start a session** — pick how many shots per round (5/10/15/20/25 or a custom number).
* **Add shooters** as they arrive — one tap adds a row.
* **Enter scores** with a large 0–10 keypad, sized for gloved hands and older users.
* **Running totals** update live per shooter.
* **Start a new round** — shooter names carry over automatically; drop anyone who's done with a trash-icon.
* **Every tap is saved immediately to the database.** There is no Save button and nothing lives only in the browser tab — refresh, close the tab, or lose wifi mid-session and nothing is lost. The session simply reopens exactly where it was (see "Why no Save button" below).
* **Send Report** emails an Excel (.xlsx) workbook — one sheet per round plus a summary — to any address, generated on the fly from the stored data.
* Ships with a dedicated **Range Staff** role/capability, so you don't have to hand out full Administrator accounts to record results.
* Finnish translation included (`fi`). Fully translatable via standard WordPress `.pot`/`.po`/`.mo`.

= Why no Save button =

Every field write (a shot score, a new shooter, a new round) is its own AJAX
call that lands in the database before the UI even finishes animating the
keypad closed. A page refresh just re-reads the same rows back out — there
is no separate "draft" copy sitting only in memory that a refresh could
throw away.

== Installation ==

1. Upload the `shooting-results` folder to `/wp-content/plugins/`, or install the zip via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin. This creates four small database tables and a "Range Staff" role.
3. Go to **Shooting Results** in the admin menu.
4. Assign the `sr_manage_results` capability (or the **Range Staff** role) to whichever staff accounts should record results — Administrators already have it.

== Frequently Asked Questions ==

= Does this need an internet connection at the range? =

It's a wp-admin page, so yes — it needs to reach your WordPress site. There
is no offline mode. What it does guarantee is that once a tap registers,
it's saved server-side; there's nothing to lose to a refresh, a crashed
browser tab, or someone else opening the same session on another device.

= Does it need any external libraries? =

No. The Excel export is generated with a small built-in writer (using PHP's
`ZipArchive`, which ships with PHP) — no Composer install, no PhpSpreadsheet.

= Can I change who can use this? =

Yes — the plugin defines a `sr_manage_results` capability and a "Range
Staff" role that has just that capability. Grant/revoke it like any other
WordPress capability (a role-editor plugin makes this point-and-click if you
don't want to touch code).

== Changelog ==

= 1.0.0 =
* Initial release: sessions, rounds, shooters, autosaving score entry, Excel report by email, Finnish translation.
