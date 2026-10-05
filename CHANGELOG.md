# Changelog

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
