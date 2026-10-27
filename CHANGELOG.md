# Changelog

All notable changes to Native Command. Versions follow [Semantic Versioning](https://semver.org/).

## 1.0.3 — 2026-10-27

First release in the ProcessWire modules directory.

### Fixed
- Plain textarea fields with line breaks (or long text) are no longer flattened by the single-line palette input — they open in the native field editor instead.
- Text values keep characters that look like markup (e.g. `I <3 jazz`, `a < b`) instead of losing them.
- *Move under…* no longer fails when the new parent already has a page with the same name: the page gets a unique name, and Undo restores the original one.
- Undo of *Move to trash* now always puts the page back where it was, even when ProcessWire's own restore information isn't usable.

### Improved
- *Publish* is refused while required fields are empty, like in the page editor (single page and bulk).
- Non-superusers can't search with selectors on fields they aren't allowed to view.
- Front-end configuration is a JSON data block instead of inline script, so the palette works with a strict Content-Security-Policy.
- Shorter *Edit field…* description.
- Verified on ProcessWire 3.0.200 (MySQL/MariaDB) and 3.0.274 (SQLite) with PHP 8.3.

## 1.0.2 — 2026-10-22
- **Edit field…** (was “Set field…”) covers every field: simple fields inline (now incl. Toggle), all others — rich text, images, files, repeaters, Repeater Matrix, Combo, Table, map, third-party fields — open in ProcessWire's native editor in a modal, showing only that field.
- Field list shows a preview for every field; fieldsets and tabs are left out.

## 1.0.1 — 2026-10-22
- Configurable shortcut (modifier + key, matched by physical key). Default is now **Alt/Option+K**, because the Konkat admin theme uses Ctrl/Cmd+K for its search.
- Page reference fields work with every configuration (parent, template, selector incl. `page.` context, custom code, any page).
- Keys typed in the palette no longer reach page or theme shortcuts.

## 1.0.0 — 2026-10-22
- First public version: command palette in the admin and on the front-end, page commands, bulk actions with selectors, undo, search with template/ID badges, global commands, no-code shortcuts, multi-language fields, pins, built-in help, dark mode, developer API.
