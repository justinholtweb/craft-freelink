# FreeLink for Craft CMS 5

A powerful, lightweight link field plugin for Craft CMS 5. Hybrid storage with proper element relations, 12 built-in link types, and migration paths from Hyper, Linkit, Typed Link Field, and native Craft Link fields.

## Why FreeLink?

FreeLink takes a different approach to link fields:

- **Hybrid storage** — Simple links (URL, email, phone) are stored as JSON in the content column. Element links (entries, assets, etc.) get proper rows in a relations table with foreign keys. This gives you referential integrity, efficient eager loading, and reverse lookups without a separate cache table.
- **Lightweight models** — Links extend `yii\base\Model`, not Craft's `Element` class. Less overhead, simpler API.
- **Native CP UI** — Field UI uses Craft's Garnish library and vanilla JS. No Vue.js, no Alpine.js.

## Requirements

- Craft CMS 5.0.0+
- PHP 8.2+

## Installation

```bash
composer require justinholtweb/craft-freelink
php craft plugin/install freelink
```

## Configuration

Add a FreeLink field in **Settings > Fields**. The settings UI lets you:

- Enable/disable individual link types and set custom labels
- Toggle between single-link and multi-link modes (with min/max constraints)
- Show/hide the link text field, new window toggle, and advanced attributes
- Set default link type and default new window behavior

### Available Link Types

| Type | Handle | Description |
|------|--------|-------------|
| URL | `url` | Any URL with validation |
| Email | `email` | Email address (outputs `mailto:` links) |
| Phone | `phone` | Phone number (outputs `tel:` links) |
| SMS | `sms` | Phone number (outputs `sms:` links) |
| Custom | `custom` | Arbitrary value, no validation |
| Site | `site` | Relative path resolved against a site's base URL |
| Entry | `entry` | Craft entry element |
| Asset | `asset` | Craft asset element |
| Category | `category` | Craft category element |
| User | `user` | Craft user element |
| Product | `product` | Commerce product (requires Commerce) |
| Variant | `variant` | Commerce variant (requires Commerce) |

## Twig Usage

### Single-Link Mode

The field value is a `LinkCollection` that transparently proxies to the first link:

```twig
{# Output the URL #}
{{ entry.myLink }}
{{ entry.myLink.url }}

{# Display text (custom label or element title or URL) #}
{{ entry.myLink.text }}

{# Full <a> tag #}
{{ entry.myLink.link }}

{# With extra HTML attributes #}
{{ entry.myLink.link({ class: 'btn btn-primary', 'data-track': 'cta' }) }}

{# Access the linked element (entry, asset, etc.) #}
{{ entry.myLink.element }}
{{ entry.myLink.element.title }}

{# Check link properties #}
{{ entry.myLink.type }}          {# 'url', 'entry', etc. #}
{{ entry.myLink.isEmpty }}       {# true/false #}
{{ entry.myLink.isElement }}     {# true/false #}
{{ entry.myLink.newWindow }}     {# true/false #}
{{ entry.myLink.target }}        {# '_blank' or null #}

{# Advanced attributes #}
{{ entry.myLink.ariaLabel }}
{{ entry.myLink.title }}
{{ entry.myLink.classes }}
{{ entry.myLink.urlSuffix }}
```

### Multi-Link Mode

```twig
{# Iterate all links #}
{% for link in entry.myLinks.all %}
    {{ link.link }}
{% endfor %}

{# Count and first link #}
{{ entry.myLinks.count }}
{{ entry.myLinks.first.url }}

{# Filter by type #}
{% for link in entry.myLinks.filter(l => l.type == 'entry') %}
    {{ link.link }}
{% endfor %}
```

### Eager Loading

Element links are stored in a relations table, so eager loading works efficiently:

```twig
{% set entries = craft.entries.with(['myLink']).all() %}
{% for entry in entries %}
    {{ entry.myLink.link }}  {# No N+1 queries #}
{% endfor %}
```

### Reverse Lookups

Find elements that link to a given element:

```twig
{% set linkedFrom = craft.freelink.getRelatedElements(entry) %}
{% set linkedFrom = craft.freelink.getRelatedElements(entry, 'myLink') %}
```

### Conditionals

```twig
{% if not entry.myLink.isEmpty %}
    {{ entry.myLink.link }}
{% endif %}

{% if entry.myLink.isElement %}
    {# It's an element link, safe to access .element #}
    <img src="{{ entry.myLink.element.url }}" alt="{{ entry.myLink.element.title }}">
{% endif %}
```

## GraphQL

FreeLink fields return `[FreeLinkInterface]` (always an array; single-link mode returns an array of one).

```graphql
{
  entries {
    myLink {
      type
      url
      text
      label
      newWindow
      target
      ariaLabel
      title
      urlSuffix
      classes
      htmlId
      rel
      isEmpty
      isElement
    }
  }
}
```

## Custom Link Types

Register custom link types via the `EVENT_REGISTER_LINK_TYPES` event:

```php
use justinholtweb\freelink\events\RegisterLinkTypesEvent;
use justinholtweb\freelink\services\Links;
use yii\base\Event;

Event::on(
    Links::class,
    Links::EVENT_REGISTER_LINK_TYPES,
    function(RegisterLinkTypesEvent $event) {
        $event->types[] = MyCustomLinkType::class;
    },
);
```

Your custom type should extend `justinholtweb\freelink\base\Link` (for simple links) or `justinholtweb\freelink\base\ElementLink` (for element links), and implement the `displayName()` and `handle()` static methods.

## Migrating from Other Plugins

FreeLink includes console commands to migrate data from four popular link plugins. Each command converts the field type, transforms content data, and creates relations table rows for element links.

```bash
# Migrate from Verbb Hyper
php craft freelink/migrate/from-hyper

# Migrate from Linkit
php craft freelink/migrate/from-linkit

# Migrate from Typed Link Field
php craft freelink/migrate/from-typed-link

# Migrate from Craft's native Link field
php craft freelink/migrate/from-craft-link

# Check migration status
php craft freelink/migrate/status
```

### Options

| Flag | Description |
|------|-------------|
| `--field=<handle>` | Migrate a specific field only |
| `--backup` | Create a full database backup before migrating |
| `--dry-run` | Preview changes without applying them |

### Safety

- All migrators log each step to the `freelink_migrations` table
- `--dry-run` previews the entire migration without writing anything
- `--backup` uses Craft's built-in backup system before starting
- Migrate one field at a time with `--field` to reduce risk

## Storage Architecture

### Content Column (JSON)

Every link stores its metadata as JSON in the field's content column:

```json
{
  "type": "url",
  "value": "https://example.com",
  "label": "Visit Example",
  "newWindow": true,
  "ariaLabel": "Visit the Example website",
  "title": "Example Site",
  "urlSuffix": "#pricing",
  "classes": "btn btn-primary",
  "id": "",
  "rel": "noopener",
  "customAttributes": [
    {"attribute": "data-track", "value": "cta"}
  ]
}
```

For element links, `value` is `null` in the JSON. The actual element reference lives in the relations table.

### Relations Table (`freelink_links`)

Element link references are stored with proper foreign keys:

| Column | FK Target | On Delete |
|--------|-----------|-----------|
| `fieldId` | `fields.id` | CASCADE |
| `ownerId` | `elements.id` | CASCADE |
| `ownerSiteId` | `sites.id` | CASCADE |
| `targetId` | `elements.id` | SET NULL |
| `targetSiteId` | `sites.id` | SET NULL |

This means:
- Deleting a field or owner element automatically cleans up relations
- Deleting a target element sets `targetId` to null (the link gracefully becomes empty)
- Queries against the relations table use proper indexes and foreign keys

## License

This plugin requires a commercial license purchasable through the [Craft Plugin Store](https://plugins.craftcms.com).
