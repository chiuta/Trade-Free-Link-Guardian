=== Trade-Free Link Guardian ===
Contributors: alexio
Tags: broken links, link checker, maintenance, redirects, gutenberg
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.33.1
License: MIT
License URI: https://opensource.org/license/mit
Donate link: https://buymeacoffee.com/echo.of.the.strings

Finds broken links in posts, pages, menus and widgets, fixes what can be fixed deterministically, and helps resolve the rest, on your own server.

== Description ==

Trade-Free Link Guardian is a **local** link checker: every check it performs is either answered structurally from your own database and filesystem, or is a single HTTP request your server makes directly to the link's own destination. There is no SaaS account, no third-party API, no telemetry, and no premium tier. The one optional exception is a Wayback Machine lookup, which is off by default, clearly labelled, and never applied automatically — it only ever runs when you click "Find archived version" for a specific dead link.

= What it scans =

* Posts, pages, and any public custom post type you choose
* Classic menu custom links
* Block-based Navigation menus
* Widgets (text, custom HTML, and block-based widgets)
* Optionally, image sources as well as links

Extraction combines a DOM parse of your content (so it copes with real-world markup, not just tidy HTML) with a walk of the parsed block tree, so it also catches URLs that only exist in a dynamic block's attributes, not in any visible link.

= What "broken" means, precisely =

Internal links are checked structurally wherever possible — no HTTP request needed:

* A link into `/wp-content/uploads/` is resolved straight to a file path and checked with a filesystem check.
* A link to a post or page is resolved with `url_to_postid()` and checked against its post status.
* A link to a slug that no longer exists is checked against WordPress's own `_wp_old_slug` record, so a renamed post's old links can be followed precisely — no guessing.
* Only if none of the above apply does it fall back to an optional, toggleable live request to your own site.

External links get a real HTTP check, following redirects one hop at a time so a **permanent** (301/308) chain can be told apart from a **temporary** (302/303/307) one. This matters: only a permanent redirect is ever offered as an automatic fix. A temporary redirect still shows as working, but is left for you to review rather than silently rewritten.

= What gets fixed automatically, and what doesn't =

Every automatic fix is verified before it's applied — none of them are guesses:

* Switching `http://` to `https://`, if the secure version works and the plain one doesn't
* Toggling the `www.` prefix, if that resolves it
* Updating a stored URL to its final destination, but only across an unbroken chain of *permanent* redirects
* Following a renamed slug via WordPress's own old-slug record

For everything else — a destination that's genuinely gone, with no redirect and nothing to follow — there's no way for any tool to conjure a working replacement out of nothing. What Link Guardian gives you instead is a fast, one-click path to resolve it by hand: replace it with a specific URL, unlink it (removing just the `<a>` tag while keeping whatever text or image was inside it), restore the item if it's sitting in the trash, or look up an archived copy on the Wayback Machine if you've turned that on.

Every fix — automatic or manual — is logged and, with the exception of unlinking (see Limitations below), reversible with one click from the Fix Log screen.

= Design choices worth knowing about =

* **One unique URL is checked once per scan**, no matter how many places link to it, and reused across all of them.
* **External links are checked concurrently** (in groups, not one at a time) using the same HTTP library WordPress itself is built on — real scans are several times faster than checking each link in strict sequence, with identical accuracy either way.
* **Multiple content types are covered without needing separate tools**: a URL that only exists in a block's saved attributes (not in any visible HTML) is still found and fixed, using the same `parse_blocks()`/`serialize_blocks()` WordPress itself uses.
* **A 403 is flagged as worth a second look, not an automatic verdict** — plenty of sites block unfamiliar bots, and a script confidently calling a link "broken" over that is a common source of false positives.
* No JavaScript framework, no build step, no bundled dependencies — one file, using WordPress's own admin UI conventions and the Settings API.

== Security ==

Checked directly, not just claimed — everything below can be re-verified against the code itself:

* Every direct database query uses `$wpdb->prepare()` with real placeholders, never raw interpolation of anything beyond a hardcoded table name. The one thing that genuinely can't be parameterized this way — sort column, sort direction — goes through an explicit allowlist with a safe fallback instead.
* Every action that changes anything — every AJAX endpoint, every CSV export, every redirect created or deleted — checks both a capability and a nonce, first, before anything else runs.
* Content from a source less trusted than an administrator — an external page's own title, a visitor's own Referer header, a URL pulled from post content — is escaped at the point it's displayed, not only cleaned at the point it's stored.
* Outbound requests this plugin makes to check a link are protected against reaching internal-network addresses, using WordPress's own mechanism for exactly this (`reject_unsafe_urls`), with this site's own domain explicitly exempted so checking this site's own internal links keeps working normally.
* No debug code — no `var_dump`, `print_r`, or `error_log` calls anywhere in the file.
* No telemetry. No external service is ever contacted without an individually-enabled setting (the optional Wayback Machine / archive.today / Memento lookups) or an explicit click (the Buy Me a Coffee link).

This has been through several rounds of adversarial review during development — deliberately trying to find what's wrong, not confirming what's right — and real issues found that way were fixed and re-verified: a visitor-controlled header with no length limit against its column, a redirect rule that could send a visitor in a circle through other existing rules, and outbound link-checking requests that needed the same internal-network protection WordPress itself documents for user-influenced URLs.

None of this is independent, third-party verification. It's a description of what the code does, checkable by reading it — see Limitations, below, for what a description like this can't substitute for.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the zip via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin.
3. Go to **Link Guardian → Settings** to choose which content types and sources to scan, and to review the automatic-fix options (sensible defaults are already on).
4. Go to **Link Guardian → Dashboard** and click **Scan site now**.

== Upgrade Notice ==

= 1.33.1 =
Security and data-integrity fixes: link checks run in concurrent mode now apply the same internal-network (SSRF) protection as single checks, and fixes no longer strip backslashes from post content. Recommended for everyone.

== Frequently Asked Questions ==

= Does this ever contact anything outside my own server? =

Only two kinds of outbound request happen, ever: (1) a check of a link your own content already points to — that's the whole point of a link checker — and (2) an optional, off-by-default Wayback Machine lookup that only runs when you explicitly click for it. Nothing else. No update checks beyond WordPress's own plugin-update mechanism, no analytics, no license phone-home.

= Will it break my Gutenberg content? =

URL rewrites use WordPress's own HTML API (`WP_HTML_Tag_Processor`) to change only the specific attribute value that matched — the rest of the markup is untouched. For the rarer case of a URL that lives only inside a block's saved attributes (not in any visible HTML), the fix goes through a full `parse_blocks()` / `serialize_blocks()` round trip, which can slightly normalise incidental whitespace between blocks elsewhere in that same post. If a URL happens to be stored in *both* places for one block, only the visible link is updated automatically — reopening that specific block in the editor may show a "block doesn't match" notice, the same limitation WP-CLI's own `search-replace` has, for the same reason.

= What happens if I uninstall it? =

Deactivating keeps your data (so re-activating picks up where you left off). Deleting the plugin from the Plugins screen removes its database tables, every option and transient it created, and the one small per-post meta value it uses to skip re-scanning unchanged content. Nothing is left behind. It never touches your actual post content except through the fixes you explicitly asked for.

= Does it slow down my site? =

Scanning and checking never run on visitor page loads. The one thing that does run for visitors is the optional 404 monitor: on a request that ends in a 404 it looks up your redirect rules and (if "404 log" is on, which is the default) stores the requested path, the Referer header if sent, and a bot/human flag — never the IP address. Entries are pruned after 60 days; turn the log off in Settings if you do not want it. Scanning and checking happen only when you click "Scan site now," or on the schedule you choose in Settings (which runs via WP-Cron in small chunks, the same mechanism WordPress uses for its own scheduled tasks).

= Why does it check images too? =

By default it doesn't — that's an opt-in setting, since "link checker" usually means links. Turning it on adds broken-image detection using the same engine.

= Does it work on a WordPress multisite network? =

Yes. Each site on a network has its own separate set of tracked links, its own settings, and its own scan schedule — activating this plugin network-wide sets that up correctly for every existing site, and for any new site created afterward. Activating it on individual sites one at a time (rather than network-wide) works the same as on an ordinary single-site install.

= Which WordPress and PHP versions are supported? =

Declared: WordPress 6.5 or newer and PHP 7.4 or newer (the `Requires at least` and `Requires PHP` headers, which WordPress itself enforces on install/update). The header also says `Tested up to: 7.1`; that is the maintainer's declaration, not the result of a certified test matrix: so far the plugin has been checked by code analysis and `php -l` (PHP 8.3), and a quick search for common PHP 8-only constructs (`match`, nullsafe `?->`, enums, `readonly`, attributes) found none, but it has not yet been run end to end on every WordPress/PHP combination in that range. Treat compatibility as declared, not certified, and keep a backup before the first bulk fix. Multisite is supported (see below). If you find a combination that fails, please open a support thread with your WordPress and PHP versions.

= How does the scheduled scan work (WP-Cron)? =

In **Link Guardian → Settings → Schedule & notifications** choose Off, Daily or Weekly (the default is Weekly). The plugin registers one WP-Cron event (`tflg_cron_tick`); the first run happens about an hour after you save the setting. Each tick works in small chunks and chains a one-minute follow-up event until the pass is done, so no single request has to finish a whole large site; a short-lived lock stops two overlapping ticks from checking links twice. A separate daily event (`tflg_daily_snapshot`) records the health history and prunes old data, even when scanning is set to Off. Choosing Off removes the scan event, and deleting the plugin removes every event.

WP-Cron is "pseudo-cron": it only runs when someone visits the site. On a low-traffic site, or if your host sets `DISABLE_WP_CRON`, trigger it from the server's own scheduler instead (for example, a system cron job that requests `wp-cron.php` or runs `wp cron event run --due-now`). Scans never run during visitor page loads; they run inside the cron request.

= Does it send an email report? =

Yes, by default. When a **scheduled** scan finishes and the number of broken links is higher than it was when that scan started, the plugin sends one plain-text email through `wp_mail()` with the number of new broken links, the total needing attention and a link to the filtered list in the admin. It goes to the address in **Settings → Email notification**, or to the site's admin email if that field is blank; untick the box to turn it off. It is not sent for manual scans or when nothing new is broken, and it does not list the individual URLs. If your server cannot send mail, use an SMTP plugin; the plugin does not include its own mail transport or any external email service.

= Which external services can it contact? =

None except the destinations your own content links to (that is what a link checker does) and one optional lookup: **Wayback Machine (archive.org)**, off by default, which sends the single dead URL you clicked "Find archived version" for to `archive.org/wayback/available`. The archive.today and Memento options are only links that your browser opens when you click them. See archive.org's terms and privacy policy at https://archive.org/about/terms.php (verify the current address before relying on it).

== Screenshots ==

1. Dashboard — tracked, broken, unverified, fixed, and ignored link counts, each a link straight into the filtered view behind it.
2. Health Map — every page as a colored tile, sized by how many links it carries, worst first.
3. Book View — the site page by page, sortable by title or by link count, with a table of contents and a search box that jumps straight to a page by name.
4. Redirects — local 301/302/410 rules for known-dead paths, created directly from a logged 404 hit in one click.
5. Settings — every automatic fix is its own switch, off by default where it makes a live request, on by default where it's a pure, verified pattern match.

== Ideas considered, not (yet) built ==

From deliberate reviews of the plugin at a few different points, roughly
in order of likely value: rotation for the fix log, which currently has
no cap — the most pressing of these now that bulk auto-fixing is a
one-click action that can touch hundreds of rows at once; a basic
sanity check on a completed scan (e.g. tracked-link count dropping
sharply between one scan and the next is far more likely to mean
something broke than that hundreds of links vanished, and currently
nothing would flag that); shipping this plugin's own test suite
alongside it, so a future change to this file can be checked the same
rigorous way it was built, without starting from nothing; splitting
this single file into a small set of includes, purely for readability
at its current size — WordPress has no technical requirement either
way; URLs embedded in shortcode attributes (e.g. `[button url="..."]`,
relevant to shortcode-based builders — a generic scanner here would
help several plugins at once, not just one); an ad-hoc "test this URL"
tool outside tracked content; `srcset` checking for responsive images;
a permanent "always skip, this is fine" designation distinct from
ignoring one broken link; a "flagged for later" state distinct from
both "ignored" and "needs attention," for triaging a large backlog of
unverified links over time rather than all at once; a context snippet
(surrounding text) alongside the anchor text for each occurrence; a
visual flag distinguishing a link found only in block attributes from
one found in visible HTML; search/filter by source page rather than
only by URL; a preview of what an automatic fix will change before
it's applied; a REST API and WP-CLI commands for scripted use. None of
these are implemented — naming them here is a record of what was
considered, not a promise.

== Limitations ==

* Widget scanning covers the standard text, custom HTML, and block-based widgets. A third-party widget with unusual storage may not be picked up — the `tflg_scan_queue` filter lets a developer register additional sources without editing this plugin.
* Full Site Editor template parts and `theme.json` are not scanned.
* Unlinking (removing an `<a>` tag while keeping its contents) cannot be auto-reverted, since where the link used to sit in the content isn't separately tracked. Every other fix type can be undone from the Fix Log.
* Bulk actions on a large number of selected external links run in the same page request and may take a little while; the "Scan site now" button is the better choice for whole-site work, since it processes in small background batches with a progress bar instead.
* Every test in this codebase was written by the same person who wrote the code it tests. That catches a real, wide range of mistakes, but it's not the same thing as independent review — a test author tends to test the cases they already thought of when writing the code, not necessarily the ones a different, unfamiliar user would actually hit. If something here behaves wrong on your site, that gap is almost certainly why — please open a support thread; that's exactly the kind of thing internal testing alone can't find.

== Changelog ==

= 1.33.1 =
* Security: the concurrent link checker (the default path, used whenever
  more than one link is checked at once) built its requests with the
  bundled Requests library directly and therefore skipped WordPress's
  `reject_unsafe_urls` validation that the single-request path applies.
  A link pointing at a private, loopback or link-local address (for
  example the cloud metadata address 169.254.169.254) could therefore be
  requested by the server. Every hop, including redirect targets, is now
  validated with `wp_http_validate_url()` before it is fetched; this
  site's own host stays exempt as before.
* Data integrity: `wp_update_post()` expects slashed data, but the fixer
  passed unslashed content, so every backslash in the post was removed
  when a link was replaced, reverted or unlinked (for instance the
  `\u0022` escapes inside block comment JSON). Content is now passed
  through `wp_slash()`. Posts already rewritten by an earlier version
  can be restored from their revisions.
* A fix whose new URL is rejected by `esc_url_raw()` (disallowed scheme)
  no longer rewrites links to an empty `href`; it now reports an error.
* The optional content-drift check and the Wayback lookup now cap the
  response size they will read.
* The scheduled scan takes a short-lived lock so two overlapping WP-Cron
  runs cannot check links (and hit remote servers) twice at the same time.
* The "known broken links" editor notice is shown only to users who can
  edit that post.
* Uninstall now also removes the remaining `tflg_` transients.
* Readme: corrected the table count, the shortened short description
  (WordPress.org limit is 150 characters) and the description of what runs
  on visitor requests (the 404 monitor).

= 1.33.0 =
* Implemented the one recommendation from a SWOT/TOWS pass that was
  genuinely actionable through code — the other three quadrants
  called for time, adoption, or an actual WordPress.org submission,
  none of which more writing here changes. Added a Security section
  to the readme, placed right after the description rather than
  buried in changelog history, stating plainly and specifically what
  had already been verified in prior review passes: parameterized
  queries throughout, capability plus nonce on every state-changing
  action, output-side escaping for anything from a less-trusted
  source, the existing SSRF protection on outbound link checks, and
  no debug code anywhere in the file.
* Every claim in it was re-verified directly against the current
  code before being written down, not assumed still true from an
  earlier pass — including a full re-count of every AJAX and
  admin_post handler now that a few were added since the last such
  check, and a fresh run of the same systematic SQL-safety scan built
  during the original adversarial review.
* The section closes by naming what it is not: not independent
  verification, just a description of the code checkable by reading
  it — and points to the existing, separate statement about
  self-testing as what a description like this can't substitute for.

= 1.32.1 =
* Renamed the project's own guiding phrase from "fara frontiere" to
  "without borders" — the same principle (no build step, no external
  dependency, works by being copied to one place), a different name
  for it. Updated everywhere it appeared: the file's own table of
  contents comment and CONTRIBUTING.md. No functional change.

= 1.32.0 =
* The one donation mention on the Settings page now states this
  installation's own real, specific count of automatically fixed
  links, when it has fixed at least one — concrete rather than a
  generic "if this saved you time." Falls back to the original,
  honest wording when nothing has been auto-fixed yet, rather than
  ever stating a count of zero, which would undercut the point
  instead of making it. Verified directly, including that it never
  mixes up singular and plural at exactly one fix.

= 1.31.1 =
* The Buy Me a Coffee link (readme "Donate link" and the one mention
  on the Settings page) now points to a real address instead of the
  placeholder left in a few versions back pending it. No other
  change.

= 1.31.0 =
* Added a CSV export to the Sitemap Tree: the same hierarchy a
  spreadsheet can't show directly, flattened into depth and a
  breadcrumb-style full path (Parent > Child > Grandchild) per row,
  alongside each page's own broken-link count.
* Added the same tree to the network-wide rollup: each active site
  now also shows its published page count and a direct link to its
  own Sitemap Tree, without building every site's full tree up front
  — only the lightweight count is gathered network-wide; the detailed
  tree itself is still built on demand, per site, exactly as before.
* Verified directly rather than assumed: a breadcrumb path never
  leaks between sibling branches (a page under "Branch A" cannot end
  up mislabeled under "Branch B" purely because they share a
  flattening pass), and the network view's new per-site page count is
  never mixed up between sites.
* Caught and fixed, honestly: extending the network rollup broke an
  older test that had no way to know a new function was now being
  called — not a real regression in the plugin, but a stand-in that
  hadn't kept pace with it. Fixed before calling this done, the same
  as every other time this has come up.

= 1.30.0 =
* Added Sitemap Tree: every published page as a hierarchy, following
  WordPress's own native parent/child structure, with each page
  showing its own broken-link count where it has any tracked links at
  all. Expand and collapse natively, no JavaScript involved, since
  that's simply what a <details> element already does.
* Split by language wherever one is detected in a page's own URL —
  stated honestly as a heuristic, not read from a multilingual
  plugin's own data: a first path segment shaped like a language code
  (en, fr, pt-br) is treated as that language's own section; a page
  with no such prefix goes into this site's own default group. A
  short, real page slug that happens to be shaped like a language
  code is indistinguishable from an actual one this way — a real,
  named limitation, not a silent gap.
* Linked from the setup wizard's final step and from the Dashboard's
  "More views", the same way every other secondary screen already is.
* Verified directly rather than assumed correct from reading the
  code: a page whose own parent is genuinely deleted, or sits in a
  different detected language group entirely, still appears rather
  than silently disappearing from the tree; a circular parent
  reference (a real, if unlikely, data-corruption case) resolves
  without hanging; and a page title containing raw HTML renders as
  safely escaped text, never as executable markup.

= 1.29.0 =
* The first-run Dashboard is now a short, four-step guided setup
  instead of a single welcome panel: what to scan, which automatic
  fixes to enable (grouped by whether each one needs a live check
  first or is a pure, verified pattern match), then straight into the
  first scan — with a visible progress indicator, skippable at the
  very first step for anyone who'd rather just use the defaults.
  Every choice can still be changed afterward in Settings; nothing
  here is final.
* A real risk found and designed around before it ever shipped: the
  setting-save logic already treats an unchecked box as "turn this
  off," which meant a simplified setup screen that doesn't show every
  single toggle could have silently disabled settings the person
  never even saw, let alone decided against. Resolved by only showing
  the choices that are genuinely worth asking about, carrying every
  other setting's own actual default forward explicitly rather than
  leaving it to chance.
* Added a separate flag for "the setup screen has been completed,"
  independent of whether a scan has actually run yet — needed because
  otherwise a site that finishes setup but hasn't scanned would see
  the exact same setup screen again on every visit, since nothing
  about finishing it, on its own, changes the "nothing scanned yet"
  condition it's built on.
* Verified directly with a real, simulated browser DOM: every step
  transition, forward and backward, including that a completed step's
  indicator turns solid, that the current one is highlighted, that
  the last step can't advance past itself, and that keyboard focus
  moves to each new step's own heading as it appears -- not verified
  by reading the code and assuming it would behave correctly.

= 1.28.0 =
* Implemented the three concrete, addressable points raised across four
  perspective-based analyses (an SEO specialist, an institutional system
  administrator, a prospective code contributor, a first-time user) —
  not the points that were really about time and adoption, which stay
  unaddressed on purpose, same as always.
* For the contributor's perspective: added CONTRIBUTING.md, explaining
  the single-file structure as a deliberate choice rather than
  something to "fix" by splitting it up, plus the file's own table of
  contents, testing expectations, and security requirements for
  anything new.
* For the beginner's perspective: every one of the twelve admin
  screens now opens with one plain-language sentence explaining what
  it does, before anything else — three screens (Links, Settings, Fix
  Log) had been missing this, confirmed systematically by checking
  all twelve rather than assuming from memory, which also caught that
  Orphan Pages already had one and didn't need a second, redundant
  addition.
* For the SEO specialist's perspective: a broken link now shows a
  small "wider impact" badge when it's worth prioritizing — appearing
  in a navigation menu, or on three or more pages at once — using
  data this plugin already tracks locally, deliberately not page
  authority or traffic data from an external service, which would
  have meant a phone-home this plugin has never made and isn't
  starting now.
* Verified directly rather than assumed: the badge's exact wording
  and thresholds across every combination (menu only, count only,
  both, neither, and the boundary case of exactly three pages), that
  it never appears on a link that isn't currently broken, and that
  the new SQL correctly extends the existing per-row query without
  touching the sort behavior already documented and relied upon
  there.

= 1.27.0 =
* Implemented exactly the two things recommended in response to an
  adversarial review, and nothing more — the review's other points
  (no independent track record, no outside benchmarks, the scope and
  single-file shape being deliberate choices this plugin's own
  philosophy calls for) aren't things more code can fix, and nothing
  here pretends otherwise.
* Added a table of contents at the top of the file: every class,
  searchable by exact marker text rather than a line number that
  would only go stale, since there's no folder tree to browse in a
  deliberately single, dependency-free file. Verified programmatically
  against the file's real content, not just written and assumed
  correct — which caught a real, pre-existing mistake in the process:
  one section's own comment header had been left behind, 271 lines
  out of place, from when a later class was inserted right after it
  without moving it along too. That class had had no heading of its
  own at all since. Fixed, then re-verified the same way until the
  table and the file agreed exactly, in the same order, start to
  finish.
* Added one direct, specific line to the readme: every test in this
  codebase was written by the same person who wrote the code — a
  real limitation, plainly stated, with an open invitation to report
  whatever that gap missed.

= 1.26.1 =
* A third adversarial security pass, aimed specifically at everything
  added since the last one (the repair report, Book View sorting,
  multiple undo, the menu reorganization) rather than re-covering
  ground already checked twice. Confirmed clean, directly rather than
  assumed: every one of the twelve admin screens still checks its own
  capability independently of the sidebar menu (the reason hiding
  five of them from the sidebar earlier was safe to do at all);
  every single external link in the plugin — all fifteen of them —
  correctly guards against reverse tabnabbing; both new asynchronous,
  batched actions correctly disable their own button before any
  request goes out, so a double click can't start two overlapping
  runs; and hiding a page from the sidebar menu doesn't affect the
  hook-suffix its script and style loading already depended on,
  confirmed by reading that mechanism directly rather than assuming
  the two were unrelated.
* One real, if minor, finding: the "Undo selected" summary message
  glued a number directly onto a single, untranslatable word ("3
  undone"), which doesn't hold up correctly under translation into a
  language with its own plural forms — including Romanian's own.
  Fixed with a proper singular/plural pair.

= 1.26.0 =
* Added multiple undo: a checkbox on every undoable Fix Log entry, a
  "select all", and an "Undo selected" action that reverts everything
  checked in one go — reusing the exact same per-entry logic (and its
  exact same limits — an unlink or ignore entry still can't be
  auto-reverted, checked or not) the single Undo link already used,
  so a bulk selection can never do anything the single link wouldn't
  have allowed. Verified directly, including a case easy to get
  wrong: un-checking any one row after "select all" correctly
  un-checks "select all" itself too, since it no longer means what it
  claims to.
* A pass specifically aimed at WordPress.org acceptance, prompted by
  looking directly at what a very recently submitted, similarly-named
  competing plugin in this same category had to fix to get approved:
  escaping, sanitization, and nonce usage (already covered by the
  security review two versions back) — and, specifically, leftover
  debug code. Checked this codebase directly for exactly that: no
  var_dump, print_r, error_log, console.log, or TODO/FIXME markers
  anywhere in it.
* Added a Screenshots section to the readme with real, specific
  captions for five representative screens. The captions are ready;
  the image files themselves have to be actual screenshots of a real
  site's data, which only exists once this is installed somewhere.
* Delivered, alongside the plugin itself, a ready-to-use
  trunk/tags/assets folder layout matching WordPress.org's own SVN
  repository structure exactly, so a submission is a direct copy
  rather than something to figure out from scratch.

= 1.25.0 =
* Added sorting to Book View: title (A–Z, the previous and still the
  default order), most links first, or fewest links first. Re-sorting
  reorders in place, in the browser, without a page reload — and
  keeps you on the same page you were reading, just at its new
  position, rather than resetting back to the first page. Verified
  directly, with a real simulated browser DOM rather than assumed
  correct from reading the code: the table of contents stays in
  exact sync with the reordered pages (including the easy detail to
  miss — the link inside each contents entry carries its own,
  separate position marker that also needed updating), and the
  search box still finds the right page afterward, proving the
  page list it searches was genuinely rebuilt rather than left
  stale from before the sort.
* Also added, pending one detail: a "Donate" link (readme header)
  and a small, one-time mention on the Settings page — both use a
  placeholder Buy Me a Coffee address until the real one is
  confirmed. Not yet a working link.

= 1.24.0 =
* Added a repair-proposal report on the Links screen: every currently
  broken link, and — for each — the deterministic fix this plugin
  would propose, computed exactly the same way "Auto-fix where
  possible" would, including the same live checks a protocol or www
  swap genuinely needs to be trustworthy rather than guessed. Nothing
  is applied; this only ever reads. Runs in time-boxed batches (the
  same shape already used for bulk actions), since checking hundreds
  of broken links live can genuinely take a while — never one long
  request. Downloads as a CSV once complete, built and downloaded
  directly in the browser rather than needing anything new stored on
  the server.
* Under the hood, the detection logic auto-fix already used was
  split into its own function, reused by both this new report and the
  real fix — the same code decides what would happen either way, so
  the two can never quietly drift apart from each other over time.
* Tested adversarially, specifically for what's genuinely new here:
  the report's CSV is built in JavaScript this time, not PHP, so it
  needed its own formula-injection guard and proper quoting rather
  than inheriting the one already used elsewhere in this plugin —
  confirmed directly against a real, known CSV-injection payload
  pattern (not just a synthetic one), and against the hardest
  combined case, a payload that needs both the formula guard and
  standard CSV quoting at once, where the two have to layer correctly
  or the guard stops working. Also confirmed the refactored detection
  function is called for a preview exactly as before, in the exact
  same priority order, and never once writes to the database on its
  own — the property the entire report depends on for being safe to
  run at all.

= 1.23.0 =
* Preparation for WordPress.org submission, from simplest to most
  involved:
* This changelog itself, condensed from 40 entries down to a form a
  reviewer or a prospective user can actually scan — the detailed,
  version-by-version history behind each entry still exists, just not
  as the first thing anyone sees here.
* Added directory-listing assets (plugin icon and banner, both
  required sizes) — hand-built as SVG and rendered to exact pixel
  dimensions, checked visually rather than assumed correct, since
  getting an icon's proportions right by coordinate math alone isn't
  the same as confirming it actually looks right.
* The admin sidebar now shows the screens used most often (Dashboard,
  Links, Health Map, 404 Log, Redirects, Settings, Fix Log); Book
  View, By Domain, Trends, Orphan Pages, and Compliance Report are
  each still exactly the same full, working screen they always were,
  one click away from a new "More views" section on the Dashboard
  instead of permanently in the sidebar. Confirmed directly, not
  assumed, that every hidden page and every new link to it point at
  the exact same slug — nothing lost, nothing orphaned by the
  reorganization itself.
* Corrected "Tested up to" after getting it wrong in both directions
  in the same short span: first overcorrected from 7.1 down to 7.0 on
  the strength of a single, hedged search result, then confirmed
  properly, from several independent, current sources including
  WordPress core's own release page, that 7.1 was in fact already out
  — restored to 7.1, this time actually verified rather than assumed
  either way.

= 1.22.0 =
* Visual design pass across all admin screens: colors consolidated
  into one consistent set (CSS custom properties) in place of values
  that had drifted slightly inconsistent across many prior versions;
  the generic WordPress admin blue replaced with a more distinctive
  deep teal; a plain shield icon in the admin menu in place of the
  generic chain-link dashicon several other link plugins also use.
* A genuine first-run Dashboard: a site that has never been scanned
  shows a short welcome panel explaining what a scan does, instead of
  five zeroed-out stat cards — kept distinct from "scanned before,
  currently empty," which still shows the ordinary numbers.

= 1.21.0 =
* Adversarial security review of the whole plugin: every SQL query,
  every AJAX/form handler, every place external content reaches the
  screen, checked directly. Two real gaps found and fixed: a
  visitor-supplied Referer header with no length cap against its
  column (fixed with multibyte-safe truncation that doesn't depend on
  the mbstring extension), and no protection against a redirect rule
  sending a visitor in a circle, including indirectly through other
  existing rules. WordPress's own SSRF protection (reject_unsafe_urls)
  enabled for link-checking requests, with this site's own domain
  explicitly exempted so internal link checking keeps working.

= 1.20.0 =
* Added CSV export for the Compliance Report, alongside the existing
  print/PDF option.
* Content drift detection now also watches each page's <h1>, not just
  its <title> — catches a real case title-only checking would miss:
  a generic, site-wide title staying identical while the heading, the
  page's own specific subject, genuinely changes.

= 1.19.0 =
* Added content drift detection: flags a working external link whose
  destination page has quietly changed subject without ever
  technically "breaking." Off by default — it needs a real, separate
  request per link beyond the regular check, so it runs on its own,
  much slower schedule (30 days by default).
* Added a Compliance Report screen: a dated, printable summary of
  link-health activity for sharing with a supervisor or auditor,
  built from data already tracked elsewhere in the plugin.

= 1.18.0 =
* Added Orphan Pages: detects published pages nothing on the site
  links to internally — the inverse of everything else the plugin
  checks. Built entirely from data already on hand; nothing new sent
  anywhere.

= 1.17.0 =
* Added a note field to each link, for the admin's own reminders.
* Added a configurable notification email address, independent of the
  site's registered admin email.

= 1.16.0 =
* Added a Redirect Manager: 301/302/410 rules for known-dead paths,
  entirely local. A "Create redirect" action on the 404 Log turns a
  logged dead URL into a working redirect in one click.

= 1.15.0 =
* Added passive 404 monitoring: logs real front-end requests hitting
  a missing page — something content scanning alone can never catch,
  since the request never touches anything the scanner looks at. Hit
  count, referrer, and a bot/human split (heuristic, openly labelled
  as such) — never an IP address, by design. Common attack-probe
  paths and static-asset requests are filtered out by default.

= 1.14.0 =
* Added Trends: a line chart of tracked/broken/fixed counts over
  time, one snapshot per day, kept for roughly two years.
* Orphaned-link cleanup (links with zero remaining occurrences,
  already run automatically after every scan) is now also available
  on demand from the Dashboard, without waiting for a full scan.

= 1.13.0 =
* Added By Domain: groups links by destination host rather than by
  the page they were found on, so a pattern like "everything on one
  external site broke at once" shows as a single row instead of many
  unrelated-looking broken links scattered across different pages.

= 1.12.0 =
* Added Health Map: every tracked page as a colored tile, sized by
  link count and colored by health, worst first — click a tile to
  jump to that page in Book View.

= 1.11.0 =
* Added optional archive-lookup links (Wayback Machine, archive.today,
  Memento Time Travel) for dead external links — each its own setting,
  off by default, each a plain link the user clicks, never an
  automatic check.
* The Dashboard's stat cards are now clickable, each opening the
  Links screen already filtered to match.

= 1.10.0 – 1.10.7 =
* Added Book View: the site presented page by page, each a real page
  of the site with the links found there and the same edit/undo
  actions available elsewhere. Table of contents, keyboard navigation,
  and a search box that jumps to a page by title. Refined over several
  point releases: full link text (no truncation) throughout the
  plugin, clickable links in every activity feed and log, a "Found in"
  sortable column, and a bare-domain classification fix (a same-site
  link written without its scheme was previously mis-filed as broken).

= 1.7.0 – 1.9.0 =
* A batch of correctness and polish fixes found through direct review
  rather than reports: an internal link written without its scheme
  (e.g. "example.com/page" with no "https://") is now correctly
  recognized as this site's own, instead of being folded into a
  broken, doubled-up path; a new automatic fix normalizes a link's
  www./non-www. prefix to match the site's actual address; links
  throughout the plugin are fully clickable and never cut off; browser
  User-Agent presets added to Settings.

= 1.4.0 – 1.6.0 =
* Found and fixed the root cause of scanning appearing to silently
  stop partway through on a WordPress multisite network: table setup
  was only running for whichever single site happened to be current
  at that moment, not for every site in the network. Activation,
  deactivation, and uninstall are now fully multisite-aware.
* Added an automatic fix for a specific, verified corruption pattern:
  this site's own URL followed by a quote-mangled external one (a
  stray quote character ending up inside an href value) is unwrapped
  back to the real external link. Added "apply to all N links
  matching this filter" for bulk actions across an entire filtered
  view, not just the current page.

= 1.1.0 – 1.3.2 =
* Built the concurrent checking engine: links are checked in parallel
  batches instead of strictly one at a time, several times faster on
  a real site, with a same-host interleaving pass so multiple links to
  one external destination don't all fire in the same instant. A
  time-budget safety mechanism keeps any single request within a
  server's execution-time limits, including when concurrency itself
  isn't available on a given server.

= 1.0.0 – 1.0.2 =
* Initial release: content scanning across posts, pages, menus, and
  widgets; link checking with a live progress view, a Stop button, and
  automatic retry on a failed or slow request.
