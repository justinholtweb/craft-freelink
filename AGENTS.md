# AGENTS.md — FreeLink Plugin

> Generic AI agent instructions. For Claude Code-specific context, see `CLAUDE.md`.

## Project

FreeLink is a commercial Craft CMS 5 link field plugin.

- **Package**: `justinholtweb/craft-freelink`
- **Namespace**: `justinholtweb\freelink`
- **Handle**: `freelink`
- **PHP**: 8.2+ | **Craft CMS**: ^5.0.0
- **License**: Commercial (Craft Plugin Store)

## Architecture

### Hybrid Storage

- **Simple links** (URL, email, phone, SMS, custom, site): JSON in Craft's content column.
- **Element links** (entry, asset, category, user, product, variant): JSON metadata in content column + foreign-keyed row in `freelink_links` relations table. Element links store `"value": null` in JSON; the `targetId` FK is the source of truth.

### Models (Not Elements)

Links extend `yii\base\Model`, NOT `craft\base\Element`. This avoids the overhead and cache table that element-based link plugins require.

### Key Classes

| Class | File | Role |
|-------|------|------|
| `Plugin` | `src/Plugin.php` | Entry point, registers services/field type/twig/console |
| `Link` | `src/base/Link.php` | Base link model (all simple types extend this) |
| `ElementLink` | `src/base/ElementLink.php` | Element link model (adds `targetId`, lazy loading, eager loading) |
| `LinkCollection` | `src/models/LinkCollection.php` | Field value. Transparent proxy pattern for single-link mode. Implements `IteratorAggregate`, `Countable`, `ArrayAccess`, `Stringable`, `JsonSerializable` |
| `FreeLinkField` | `src/fields/FreeLinkField.php` | Craft field type. Normalization, serialization, lifecycle, eager loading, validation, GQL |
| `Links` | `src/services/Links.php` | Link type registry |
| `Relations` | `src/services/Relations.php` | `freelink_links` table CRUD, eager loading maps, reverse lookups |
| `Migrate` | `src/services/Migrate.php` | Migration orchestrator for Hyper/Linkit/Typed Link/Craft Link |

### Link Types (12 built-in)

Simple: `Url`, `Email`, `Phone`, `Sms`, `Custom`, `Site` (in `src/links/`)
Element: `Entry`, `Asset`, `Category`, `User`, `Product`, `Variant` (in `src/links/`)

Commerce types (`Product`, `Variant`) check `isAvailable()` — returns false without Commerce installed.

### Database Tables

- `freelink_links` — Element relations. FKs to `fields`, `elements`, `sites`. Indexed on `(fieldId, ownerId, ownerSiteId)` and `(targetId)`.
- `freelink_migrations` — Migration tracking/logging.

## Serialization

Two serialization modes exist:

- **`toArray()`** — Raw storage data for the content table (type, value, label, newWindow, urlSuffix, etc.). Used by `FreeLinkField::serializeValue()`.
- **`toApiArray()`** — Resolved/computed values for API consumers (resolved URL with suffix applied, display text with fallback, target attribute, element metadata). Used by `JsonSerializable::jsonSerialize()`.

`serializeValue()` returns plain arrays (not JSON strings). Craft's `Db::prepareValueForDb()` handles encoding for `text` columns.

## Code Conventions

- PHP 8.2+ features: typed properties, union types, null-safe operator, named arguments, trailing commas
- PSR-4 autoloading under `src/`
- Craft CMS 5 patterns for fields, services, migrations, records, events, GQL
- No JS build step — `FreeLinkField.js` ships directly, uses Craft's Garnish + jQuery
- Twig templates use Craft's `_includes/forms` macros
- PHP files use `// region` / `// endregion` comments to organize sections

## Data Flow

### Save
1. POST data → `FreeLinkField::normalizeValue()` → `LinkCollection`
2. `FreeLinkField::serializeValue()` → array for content column
3. `FreeLinkField::afterElementSave()` → saves element link rows to `freelink_links`

### Load
1. Content column JSON → `FreeLinkField::normalizeValue()`
2. Decoded JSON + `freelink_links` query for element types → `Links::createLink()` → `LinkCollection`

### Eager Loading
- `getEagerLoadingMap()` queries `freelink_links` for all owner IDs at once
- Craft batch-loads target elements → `ElementLink::setElement()`

## File Layout

```
src/
├── Plugin.php
├── base/
│   ├── Link.php
│   └── ElementLink.php
├── fields/
│   └── FreeLinkField.php
├── models/
│   ├── LinkCollection.php
│   └── Settings.php
├── links/                    # 12 link type classes
├── services/
│   ├── Links.php
│   ├── Relations.php
│   └── Migrate.php
├── migrations/
│   └── Install.php
├── migrators/                # 4 migrator classes + BaseMigrator
├── console/controllers/
│   └── MigrateController.php
├── gql/                      # GraphQL interface, types, resolvers, arguments
├── records/
│   └── FreeLinkRecord.php
├── events/
│   └── RegisterLinkTypesEvent.php
├── validators/
│   └── LinkValidator.php
├── twig/
│   └── Extension.php
├── web/assets/field/         # FieldAsset bundle, JS, CSS
└── templates/
    ├── field/                # input.twig, settings.twig, _link-block.twig, _inputs/
    └── migrate/              # Migration CP page
```

## Common Tasks

### Adding a link type
1. Create class in `src/links/` extending `Link` or `ElementLink`
2. Implement `displayName()` and `handle()` static methods
3. Register in `Links::_loadTypes()` (built-in) or via `EVENT_REGISTER_LINK_TYPES` (third-party)

### Adding a migrator
1. Create class in `src/migrators/` extending `BaseMigrator`
2. Implement `sourcePlugin()`, `sourceFieldType()`, `mapType()`, `migrateField()`
3. Register in `Migrate::MIGRATORS` constant
4. Add console action in `MigrateController`

### Field UI changes
- Templates: `src/templates/field/input.twig`, `_link-block.twig`
- JS: `src/web/assets/field/dist/js/FreeLinkField.js` (vanilla JS + Garnish)
- CSS: `src/web/assets/field/dist/css/FreeLinkField.css` (Craft CSS variables)

### Schema changes
- New migration in `src/migrations/`, update `Plugin::$schemaVersion`

## Testing

No automated test suite. Manual verification:
1. Install plugin → confirm `freelink_links` table created
2. Create field, configure types, add links, save → verify JSON + relations rows
3. Eager loading: `craft.entries.with(['field']).all()` — no N+1
4. GraphQL queries
5. Element API: `json_encode($linkCollection)` returns proper objects
6. Migration commands with `--dry-run` first
