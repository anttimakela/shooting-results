=== Shooting Results ===
Contributors: anttimakela
Tags: shooting, sports, results, scoring, excel
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast, big-button competition results recording for shooting ranges, on a password-protected front-end page. Every score autosaves immediately — a page refresh never loses data. Exports an Excel report by email.

== Description ==

**Shooting Results** gives shooting/hunting clubs a mobile-first front-end
page for recording competition scores on the day, on a phone, tablet, or PC,
with a big touch-friendly numeric keypad — and none of the fragility of
"fill in a form and remember to click Save."

* **Add it to any page** with the `[shooting_results]` shortcode — no separate wp-admin screen to find.
* **No WordPress account needed** — password-protect that page (a built-in WordPress feature) and share the password with whoever is recording results.
* **Hand off mid-competition** — anyone with the password can open the same page on another device, pick the open session from the list, and keep recording where the last person left off.
* **Start a session** — pick how many shots per round (5/10/15/20/25 or a custom number).
* **Add shooters** as they arrive — one tap adds a row.
* **Enter scores** with a large 0–10 keypad, sized for gloved hands and older users.
* **Running totals** update live per shooter.
* **Start a new round** — shooter names carry over automatically; drop anyone who's done with a trash-icon.
* **Every tap is saved immediately to the database.** There is no Save button and nothing lives only in the browser tab — refresh, close the tab, or lose wifi mid-session and nothing is lost. The session simply reopens exactly where it was (see "Why no Save button" below).
* **Send Report** emails an Excel (.xlsx) workbook — one sheet per round plus a summary — to any address, generated on the fly from the stored data.
* Finnish translation included (`fi`). Fully translatable via standard WordPress `.pot`/`.po`/`.mo`.

= Why no Save button =

Every field write (a shot score, a new shooter, a new round) is its own AJAX
call that lands in the database before the UI even finishes animating the
keypad closed. A page refresh just re-reads the same rows back out — there
is no separate "draft" copy sitting only in memory that a refresh could
throw away.

== Installation ==

1. Upload the `shooting-results` folder to `/wp-content/plugins/`, or install the zip via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin. This creates four small database tables.
3. Go to **Shooting Results** in the admin menu and copy the `[shooting_results]` shortcode.
4. Paste it into whatever page should host results recording, and publish that page.
5. On that page, set **Visibility → Password Protected** and share the password with whoever is recording results.

== Frequently Asked Questions ==

= Does this need an internet connection at the range? =

Yes — it's a web page, so it needs to reach your WordPress site. There is no
offline mode. What it does guarantee is that once a tap registers, it's
saved server-side; there's nothing to lose to a refresh, a crashed browser
tab, or someone else opening the same session on another device.

= Does it need any external libraries? =

No. The Excel export is generated with a small built-in writer (using PHP's
`ZipArchive`, which ships with PHP) — no Composer install, no PhpSpreadsheet.

= Do staff need a WordPress account? =

No. Access is controlled by the WordPress page password on whichever page
carries the `[shooting_results]` shortcode, not by a login. Anyone with the
password can record results, including switching to a different device
mid-competition — they just open the same page, enter the password, and
continue the open session from the list.

= Can I still control access with logins instead of a page password? =

Not directly — 1.1.0 replaced the old "Range Staff" role/capability with
the page-password model, since staff needing to hand off a session to
someone else on another device mid-competition was a hard requirement.
Administrators (and anyone who can edit the page) always bypass the
password automatically, same as WordPress's normal password-protected page
behavior.

== Changelog ==

= 1.1.1 =
* Fixed: the front-end shortcode's CSS/JS could fail to load entirely under some page-builder rendering (e.g. Breakdance), leaving the shortcode's container empty with no console errors.
* Fixed: opening a stale/invalid `?session=` link left the page blank with an unhandled error instead of falling back to the session list.

= 1.1.0 =
* Results recording moved from a wp-admin page to a `[shooting_results]` shortcode, so it can be placed on any front-end page.
* Access is now controlled by WordPress's page-password protection instead of a login — no WordPress account needed to record results, and a session can be picked up on a different device mid-competition.
* Removed the "Range Staff" role/capability (existing installs have it cleaned up automatically on upgrade).
* Added a Settings page (admin menu → Shooting Results) with a copyable shortcode and setup instructions.
* Front-end UI redone mobile-first.

= 1.0.0 =
* Initial release: sessions, rounds, shooters, autosaving score entry, Excel report by email, Finnish translation.
