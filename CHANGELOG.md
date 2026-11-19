# Changelog

## 5.3.0 - Unreleased

> {note} The 5.3 migration adds two tables and queues an "Indexing FreeLink links" job that builds the
> link index from existing content. The Links screen fills in once it has run.

### Security
- **Custom attribute names are now an allowlist, the same as FreeNav 5.2.1.** Before, any name that
  wasn't an event handler or a URL attribute was accepted, so an editor could add `style`, `class`,
  or a front-end framework directive that runs code (`x-init`, `hx-get`, `v-html`, `ng-click`,
  Datastar's `data-on-click`…), or an array value that Craft expands into further attributes. Now
  only `data-*` (except attributes frameworks run as code, such as `data-hx-*`, `data-ng-*`,
  `data-on-*`, `data-bind`, `data-text` and `data-show`), `aria-*` and plain attributes such as
  `title`, `rel`, `id`, `lang`, `role`, `hreflang` and `itemprop` are allowed, and values must be
  text. Anything else is refused when a link is saved (CP, GraphQL, MCP), dropped by the Hyper
  migration, and skipped when a link renders or is returned by MCP — which also covers links saved
  before this release. An entry holding such an attribute shows a validation error naming it until
  it's removed. On a site whose front end runs a framework that executes other `data-*` attributes,
  treat editors as trusted.

### Added
- A **FreeLink** section in the control panel, with a sidebar item whose label and icon can be
  changed in the new plugin settings: an icon from Craft's icon picker, or a pasted SVG (checked to
  be one `<svg>` element and sanitised every time it is drawn).
- A **Links** screen listing every FreeLink link on the site, filterable by link type, field, site,
  source element type, section, target domain and status, 50 per page. **Broken Links** shows only
  the problems.
- Reverse lookups: "what links to this?" on the Links screen, a **Linked from** panel on element
  edit screens, `Plugin::getInstance()->links->findLinksTo($element)`, and
  `craft.freelink.linksTo(element)` / `craft.freelink.linkCountTo(element)` in Twig.
- Broken-link checks. Element links are checked against the database (deleted, in the trash,
  disabled, not yet published, expired). URL links are checked over HTTP by a queue job (**Check
  links now**) or `php craft freelink/links/check`, with strict SSRF protection: http/https only,
  private, loopback, link-local, reserved, multicast and cloud-metadata addresses refused (IPv4 and
  IPv6, including IPv4-mapped and decimal/octal/hex forms), connections pinned to the checked
  address, every redirect hop re-checked (5 at most), 5-second timeouts and a 64 KB cap. Checks can
  be turned off in the settings. Only URLs saved in FreeLink fields are ever requested.
- New permissions: **View the Links screen and link status** and **Queue link checks**.
- An optional icon per link (field settings **Allow icons** and **Include Font Awesome Pro icons**),
  available as `link.icon` and `link.iconSvg` in Twig, in GraphQL and in the API output.
- GraphQL mutations for FreeLink fields (`FreeLinkInput`, a list of them in multi-link mode),
  validated the same way as a control panel save, and a `freelinkLinksTo` query for reverse
  lookups, behind a new **FreeLink → Query reverse link lookups** schema component. The link type
  gained `icon`, `iconSvg`, `elementId`, `elementSiteId`, `elementType`, `subject` and `siteId`.
- MCP (Craft 5.12.1+): FreeLink fields can be read and written through Craft's element tools, and
  there are five FreeLink tools: `freelink_find_links_to`, `freelink_list_links`,
  `freelink_check_links` (stored results only, never a live request), `freelink_migration_status`
  and `freelink_migrate` (dry run by default).
- `php craft freelink/links/reindex` and `php craft freelink/links/status`.

### Changed
- The migration tools moved into the FreeLink section (**Migrations**), where a migration can be
  dry-run or run. The old `freelink/migrate` URL redirects there.
- Saving now refuses a new or changed link of a type the field doesn't enable, an element link to an
  element that doesn't exist, and an element link to an element the person (or GraphQL schema)
  saving can't view. The control panel never offered these; a POST, GraphQL or MCP could.
- Links that are already saved in live content are left alone by these checks and by the selection
  condition: a link whose type and destination (including the target's site) match the published
  element still saves after the field is tightened (a type turned
  off, a condition added) or when the editor can't view its target. Editing its label or other
  attributes doesn't count as a change. Links in drafts are always checked when the draft is
  published. A link whose target was deleted becomes empty.
- Drafts (and any save that skips validation) still get the security check: a new or changed element
  link must point at an element the person or GraphQL schema saving can view. Min/max, required and
  selection conditions stay checks for live saves only.
- The field input, GraphQL (`url`, `text`) and MCP output no longer show the title or URL of a linked
  element the current user or schema can't view. GraphQL checks the site too: a link to an element's
  copy on a site the schema can't read is hidden (and refused in a mutation), even if the element
  exists on a site it can. The field input keeps the link as it is, labelled
  "An element you can't view", so saving doesn't drop it.

### Fixed
- An element link without a target site now resolves its element on the site of the entry (or other
  element) it belongs to, not on the current site. Templates that render an element from its own
  site — nearly always — see no change. A template showing an element loaded from another site
  (`craft.entries.site('fr')`) now gets that site's copy of the linked element, where before it got
  the current site's copy.
- Setting an element link's `targetId` in PHP, GraphQL, Feed Me or MCP on an existing element no
  longer gets replaced by the target stored before. Only a value loaded from the database reads its
  targets from the relations table now.
- `craft.freelink` now exists in Twig. The README always used it, but only the `freelink` global was
  registered.
- Saving an element whose link pointed at an element deleted in the meantime no longer fails on a
  foreign key; the link becomes empty, as it would after the deletion anyway.

## 5.2.0 - 2026-10-09
### Added
- Each element link type (Entry, Asset, Category, User, Product, Variant) now has a selection
  condition in the field settings. It uses the same condition builder as Craft's relation fields.
  The condition filters the element select modal, and it is checked again on save. A link to an
  element that doesn't match is refused, including an element ID posted straight to the form.

### Fixed
- Custom link type labels set in the field settings now show in the link type menu. Before this,
  the menu always used the type's default name.
- Element sources saved as a list are now stored as a list. Before this, they were saved as one
  comma-joined string that the element select modal couldn't use.
- The "Allow multiple links" switch in the field settings now shows and hides the Min/Max Links
  inputs. Before this, its script looked for an input name and an element ID that Craft's field
  settings had namespaced, so it never found them.

## 5.1.2 - 2026-10-05

> {warning} Links now refuse to render script. If a Custom link of yours used a `javascript:` URL
> other than `javascript:void(0)` or `javascript:;`, or a custom attribute named `on…`, it now
> renders with no URL, or without that attribute. Custom attributes no longer render on fields with
> **Show Advanced** off, and no longer replace an attribute the link sets itself (`target`, `rel`,
> `class`, `id`…).

### Security
- Anybody who could edit an entry could save a Custom link to `javascript:…`, or a custom attribute
  such as `onmouseover`, and `link` rendered it into the page. Links with a `javascript:`,
  `vbscript:` or `data:` URL now fail validation, and any that are already stored render with no
  URL. Custom attribute names are checked the same way on save and on render. `javascript:void(0)`
  and `javascript:;` are still allowed.
- Custom attributes rendered even when the field's **Show Advanced** setting was off, where editors
  couldn't see them. They're now ignored there.

## 5.1.1 - 2026-07-19

### Fixed
- Fixed a fatal error when running any built-in migration (Hyper, Linkit, Typed Link Field, or Craft's native Link field). The migration runner read each source field record with object syntax when the records are arrays, so it errored before migrating anything.
- Fixed the Site link type erroring when the target site has no base URL configured; it now resolves to no URL instead of failing.

## 5.1.0 - 2026-07-07

### Added
- Email link type now has an optional subject line, encoded into the `mailto:` URL as `?subject=`. The subject is available in Twig (via the resolved URL), API/JSON output (`subject`), and GraphQL (via the resolved `url`).

## 5.0.2 - 2026-07-07

### Fixed
- Fixed links saved through the control panel coming out with a null destination. The field now maps the CP form's `values[<type>]`/`elements[<type>]` inputs onto the value and target element it stores, so URLs, emails, and other links save correctly. Element links now also capture their target on first save and when changed or cleared in the control panel.

## 5.0.1 - 2026-06-23

### Fixed
- Fixed field inputs rendering as escaped HTML markup (element selects, lightswitch, and nested link blocks) by marking the form macro output as raw

## 5.0.0 - 2026-06-11

### Added
- Initial release
- 12 built-in link types: URL, Email, Phone, SMS, Custom, Site, Entry, Asset, Category, User, Product, Variant
- Hybrid storage with JSON content column and relations table for element links
- Single and multi-link field modes
- Automatic element resolution for element links (entries, assets, etc.)
- GraphQL support
- Custom link type registration via events
- Migration commands for Hyper, Linkit, Typed Link Field, and native Craft Link fields
- Advanced link attributes: ARIA label, title, URL suffix, CSS classes, HTML ID, rel, custom attributes
- Twig variable for reverse element lookups
