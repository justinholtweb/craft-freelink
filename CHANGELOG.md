# Changelog

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
