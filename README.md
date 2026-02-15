# FreeLink for Craft CMS 5

A powerful, lightweight link field plugin for Craft CMS 5 with hybrid storage, proper element relations, and migration paths from popular link plugins.

## Features

- **Hybrid storage**: JSON content column for simple links + proper relations table with foreign keys for element links
- **Lightweight models**: Links extend `yii\base\Model`, not Craft's `Element` class
- **12 built-in link types**: URL, Email, Phone, SMS, Custom, Site, Entry, Asset, Category, User, Product (Commerce), Variant (Commerce)
- **Single & multi-link modes**: One field, configurable for single or multiple links
- **Eager loading**: Efficient element loading via the relations table — no N+1 queries
- **GraphQL support**: Full GQL interface with typed fields
- **Custom link types**: Register your own via events
- **Migration paths**: Migrate from Hyper, Linkit, Typed Link Field, and native Craft Link fields

## Requirements

- Craft CMS 5.0.0 or later
- PHP 8.2 or later

## Installation

```bash
composer require justinholtweb/craft-freelink
php craft plugin/install freelink
```

## Usage

### Twig

```twig
{# Single link mode #}
{{ entry.myLinkField.url }}
{{ entry.myLinkField.text }}
{{ entry.myLinkField.link }}
{{ entry.myLinkField.link({ class: 'btn' }) }}
{{ entry.myLinkField.element }}

{# Multi-link mode #}
{% for link in entry.myLinksField.all %}
    {{ link.link }}
{% endfor %}

{# Eager loading #}
{% set entries = craft.entries.with(['myLinkField']).all() %}

{# Reverse lookups #}
{% set linkedFrom = craft.freelink.getRelatedElements(entry, 'myLinkField') %}
```

### Custom Link Types

```php
use justinholtweb\freelink\events\RegisterLinkTypesEvent;
use justinholtweb\freelink\services\Links;
use yii\base\Event;

Event::on(Links::class, Links::EVENT_REGISTER_LINK_TYPES, function(RegisterLinkTypesEvent $event) {
    $event->types[] = MyCustomLinkType::class;
});
```

## Migrating from Other Plugins

```bash
php craft freelink/migrate/from-hyper [--field=<handle>] [--backup] [--dry-run]
php craft freelink/migrate/from-linkit [--field=<handle>] [--backup] [--dry-run]
php craft freelink/migrate/from-typed-link [--field=<handle>] [--backup] [--dry-run]
php craft freelink/migrate/from-craft-link [--field=<handle>] [--backup] [--dry-run]
php craft freelink/migrate/status
```

## License

This plugin requires a commercial license purchasable through the [Craft Plugin Store](https://plugins.craftcms.com).
