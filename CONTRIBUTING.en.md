# Contributing to Trade-Free Link Guardian

Thank you for considering a contribution. A few useful things to know before you start.

## The single-file structure — deliberate, not accidental

All of the plugin's code lives in a single PHP file, `trade-free-link-guardian.php`. This is not a passing choice, it is the project's philosophy: "without borders" — no build step, no external dependencies to install, working just by copying one file. It is part of a wider portfolio of single-file applications built on the same principle.

If you come with modern WordPress development experience (namespaces, autoloading, a separate file per class), the structure may look unusual. It is intentional, not technical debt to be fixed with a big refactor. A contribution that proposes splitting the file into several will not be accepted on that ground alone — but a contribution that improves something *inside* this structure (a new function, a fix, an extra test) is welcome.

## Quick orientation in the file

At the top of the file there is a table of contents ("TABLE OF CONTENTS") — each class has an exact search string (for example `SECTION: TFLG_Checker`) that you can search for (Ctrl+F / Cmd+F) in any editor to jump straight to it. Preferred over line numbers, which would go stale with any change.

The thirteen classes, in brief:
- `TFLG_Util` — stateless helper functions, used everywhere
- `TFLG_DB` — the schema and all direct database queries
- `TFLG_Scanner` — extracts links from content
- `TFLG_Checker` — makes the HTTP request that decides whether a link is alive
- `TFLG_Fixer` — the six deterministic fixes, apply/undo
- `TFLG_List_Table` — the table of the Links screen (admin only)
- `TFLG_Admin` — the HTML of every admin page, the menu, CSS/JS
- `TFLG_Ajax` — every `wp_ajax_` endpoint
- `TFLG_Network_Admin` — the network-wide view, multisite only
- `TFLG_Editor_Check` — the warning when saving a post
- `TFLG_404_Monitor` — the passive 404 error log
- `TFLG_Cron` — the optional scheduled scan
- `TFLG_Plugin` — activation, deactivation, uninstall, the constructor that wires everything together

## Testing — mandatory, not optional

Every new function or fix must come with tests. The existing tests do not run through a classic PHPUnit installed as a dependency — they are standalone PHP scripts that extract the real code from the main file (by counting braces, not by assuming text) and run it in isolation, with minimal stubs for the WordPress functions required. For the JavaScript logic, the real tests run with Node.js — those that touch DOM manipulation (for example the sorting in Book View) use `jsdom` for a real DOM environment, not a superficial simulation.

A test that only confirms what you already expect to happen does not help much. The most useful tests in this project are those that check exactly the edge case that is easy to get wrong — for example, that a preview function never writes to the database, or that a table of contents stays in sync with the real content after a reorder.

## Security — direct verification, not assumption

Every new SQL query must use `$wpdb->prepare()` with real parameters, not string interpolation. Every action that changes state (AJAX, form) must check both the capability (`current_user_can`) and a nonce. Any displayed content that might come from a source less trusted than an administrator — the title of an external page, an HTTP header sent by a visitor — must be escaped on output, not just sanitized on input.

## No telemetry, no exceptions

Nothing in this plugin sends data to external services without the user's explicit action. A contribution that adds any form of automatic reporting to an external server, however well-intentioned, will not be accepted.

## How to submit a contribution

The project is to be published on WordPress.org, using the platform's standard SVN workflow. For code contributions, the simplest way is to describe the proposed change and its reason clearly, together with the corresponding tests, directly to the author (see the contact details in the plugin header or in readme.txt).
