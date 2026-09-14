=== Shooting Results ===
Contributors: anttimakela
Tags: shooting, sports, results, scoring, excel
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.6
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
* **Rifle or shotgun** — rifle records a 0–10 score per shot (10 or a custom shots-per-round); shotgun records one final result per round instead.
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

= 1.2.6 =
* Sending a session's report now closes it: a hint above the button explains this before you send, sent sessions are no longer clickable in the list, and reopening one by URL (or a stale link) is blocked server-side too. After sending, you're taken back to the session list instead of staying on a now-closed session.

= 1.2.5 =
* Removed the "Continue open session" shortcut on the session list — it was misleading with more than one draft open, since it only ever jumped to the single most recent one. The session list now shows a hint ("Click a session to edit it.") instead.

= 1.2.4 =
* On narrow phone screens, the results table now stacks each shooter's name on its own line with their scores wrapping in a row underneath, instead of a sticky name column eating space from the score cells.

= 1.2.3 =
* Recording a shot no longer scrolls the results table back to the left — it stays where you were, so you don't have to re-scroll to the shooter/column you're working on after every entry.

= 1.2.2 =
* Fixed the keypad modal (0–10 + Clear) not fitting on screen in phone landscape orientation — it's now compact and centered below a ~480px-tall viewport, and every modal now scrolls internally as a fallback if it still doesn't fully fit.

= 1.2.1 =
* The Settings page's session list now has a "Download report" button next to each session, for getting the .xlsx directly without emailing it.

= 1.2.0 =
* Added shotgun as a discipline alongside rifle: choose it when starting a session, and record one final result per round instead of a per-shot breakdown. Rifle's shots-per-round presets are now just 10 and "Other".
* A shooter can now be removed from any round being viewed, not just the latest one — fixes a mistake on an earlier round without affecting rounds after it.
* Dates in the session list now show as `pp.kk.vvvv` (e.g. 01.09.2026) instead of the raw database timestamp.
* More spacing between "Start new round" / "Send report" / "Add shooter" — they were close enough on a touch screen to risk hitting the wrong one.
* The Settings page now lists every recorded session with a delete button, for removing one you no longer need. This is wp-admin-only (not reachable from the password-gated front-end page).

= 1.1.6 =
* Opening a stale `?session=` link (already handled gracefully by falling back to the session list) no longer logs a "404" network error in the browser console — the server response for that case no longer uses an HTTP error status, since it was never actually a failure the user needed to see.

= 1.1.5 =
* Fixed the actual cause of "You do not have permission to do this." on page builders (confirmed on Breakdance): the AJAX permission check looked for the shortcode inside the page's post_content field, but builders like Breakdance store their content elsewhere and only run the shortcode through WordPress's normal shortcode processing at render time. The check now trusts a marker set the first time the shortcode actually renders on a post, regardless of how that post's content is structured. **After updating, open the results page once (and purge any page cache for it) so the marker gets set before recording results.**

= 1.1.4 =
* AJAX errors now show the real server response (including WordPress's own "-1"/"0" rejection body from a failed nonce check) instead of a generic message, and are shown on the page itself, not just the console — needed to diagnose a 403 that only appeared in production.

= 1.1.3 =
* The AJAX request now always uses the current page's protocol (http/https), instead of whatever admin_url() resolved server-side, to rule out a scheme mismatch under local-dev SSL proxies looking like a cross-origin request.

= 1.1.2 =
* Fixed: some server-level security rules 404'd the plugin's AJAX requests because fetch() (unlike jQuery.ajax()) doesn't send an `X-Requested-With` header by default — now sent explicitly.
* CSS hardening for page-builder containers (e.g. Breakdance) that use flex/grid layouts, where the shortcode's container could collapse instead of filling its column.
* AJAX errors now surface the HTTP status in the on-page error message instead of only logging to the console.

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
