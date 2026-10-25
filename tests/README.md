# Regression checks

Run from the plugin root:

```sh
php -l Plugin.php
php tests/run.php
node --check assets/js/PandaBangumiEditor.js
node --check assets/js/PandaBangumiAdmin.js
node tests/subject-card.js
node tests/editor-insert.js
node tests/admin-settings.js
git diff --check
```

Serve the plugin directory locally and open `tests/editor-dom.html` in a browser.
It reports its assertions in the page and requires no test framework. It covers
panel lifecycle, tab switching and arrow keys, code preview, selection
restoration, invalid input, draft/session state, category semantics,
delayed/replaced toolbars and repeated initialization.
It does **not** prove Typecho undo/redo or autosave behavior.

For integration, use a disposable Typecho installation with a separate database
and a copy of this plugin. Do not use an author's existing draft for testing.
Check both `admin/write-post.php` and `admin/write-page.php`, with VOID enabled:

- Type text with the keyboard, select a valid ID, then insert a card. Check the
  original range is replaced and preview updates. Test an invalid ID and cancel.
- Insert a collection and calendar consecutively. Use both the native undo/redo
  toolbar buttons and Cmd/Ctrl+Z, Cmd/Ctrl+Shift+Z or Ctrl+Y; each step should undo
  or redo exactly one insertion and retain earlier typing. Check dirty state and
  autosave on disposable content.
- Verify a fresh activation (the `markdownEditor` hook exposes `textOperation`)
  and an existing activation without that hook. The latter uses PageDown's
  existing command runner without replacing its handler. Re-activation is not
  needed just to use the menu.
- Exercise Tab/Shift+Tab, tab and radio arrow keys, Enter, Esc, the copy button
  and outside clicks. The original GIF and toolbar position must remain.
- Check 320/360/390px and desktop, scroll/zoom and short visible viewports, as
  well as an actual phone software keyboard when available. Check light/dark
  editor backgrounds. Desktop viewport shrinking is not a mobile keyboard test.
- On the plugin settings page, verify there is no insertion-code block; 86400,
  3600 and 300 refresh values show 1 day, 1 hour and 5 minutes, and cover-mode
  descriptions update without saving the form.

The editor stores no server preference and makes no Bangumi lookup request.
