# FreeLink for Craft CMS 5

A powerful, lightweight link field plugin for Craft CMS 5. Hybrid storage with proper element relations, 12 built-in link types, and migration paths from Hyper, Linkit, Typed Link Field, and native Craft Link fields.

## Why FreeLink?

FreeLink takes a different approach to link fields:

- **Hybrid storage** — Simple links (URL, email, phone) are stored as JSON in the content column. Element links (entries, assets, etc.) get proper rows in a relations table with foreign keys. This gives you referential integrity and reverse lookups without a separate cache table.
- **Lightweight models** — Links extend `yii\base\Model`, not Craft's `Element` class. Less overhead, simpler API.
- **Native CP UI** — Field UI uses Craft's Garnish library and vanilla JS. No Vue.js, no Alpine.js.

## Requirements

- Craft CMS 5.0.0+ (the MCP features need Craft 5.12.1+; everything else works on any Craft 5)
- PHP 8.2+

## Installation

```bash
composer require justinholtweb/craft-freelink
php craft plugin/install freelink
```

## Configuration

Add a FreeLink field in **Settings > Fields**. The settings UI lets you:

- Enable/disable individual link types and set custom labels
- Limit which elements each element link type can link to, with a selection condition
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
| Custom | `custom` | Any value except a script URL (`javascript:`, `vbscript:`, `data:`; `javascript:void(0)` is allowed) |
| Site | `site` | Relative path resolved against a site's base URL |
| Entry | `entry` | Craft entry element |
| Asset | `asset` | Craft asset element |
| Category | `category` | Craft category element |
| User | `user` | Craft user element |
| Product | `product` | Commerce product (requires Commerce) |
| Variant | `variant` | Commerce variant (requires Commerce) |

### Selection conditions

Each element link type (Entry, Asset, Category, User, Product, Variant) has its own
**Selectable … Condition** in the field settings. It's the same condition builder Craft's own
relation fields use. For example, Entry links can be limited to entries of type *Page*, and Product
links to products that are in stock.

The condition does two things:

- **It filters the element select modal**, so editors are only offered elements that match.
- **It is checked again when the entry is saved.** A new or changed link whose element doesn't match
  is refused with a validation error, including element IDs posted directly. Links already saved
  before the condition was added keep saving as long as their target doesn't change (5.3+). Like
  Craft's own field validation, this check runs on live saves, not on drafts.

The same rule covers the other save checks: a link whose type and destination (including the target's
site) are unchanged from the published element is never refused because its link type was turned off
later or because the editor can't view its target. Only new or changed links are checked, and links
in a draft are compared with the published element, so they're checked when the draft is applied.

Rules that depend on the entry being edited, such as "related to the current entry", are given that
entry as their reference element. Fields with no condition behave as they did before.

### Link icons

Turn on **Allow icons** in the field settings and each link gets Craft's icon picker. The icon name
is stored with the link (`"icon": "arrow-right"`).

Craft's icon set includes Font Awesome **Pro** icons, which are licensed for use in the control
panel. Link icons render on your site, so the picker only offers the free icons unless you turn on
**Include Font Awesome Pro icons** — do that only if you have your own Font Awesome Pro licence.

```twig
{{ entry.myLink.icon }}                         {# 'arrow-right' or null #}
{{ entry.myLink.iconSvg }}                      {# inline <svg aria-hidden="true" …> #}
{{ entry.myLink.getIconSvg({class: 'icon'}) }}  {# with your own attributes #}

{% for link in entry.myLinks.all %}
    <a href="{{ link.url }}">{{ link.iconSvg }} {{ link.text }}</a>
{% endfor %}
```

## The FreeLink section

FreeLink adds a section to the control panel sidebar.

### Links

**FreeLink → Links** lists every link in every FreeLink field on the site, 50 to a page. Filter by
status, link type, field, site, source element type, section and target domain (a domain matches its
subdomains too). Each row shows where the link is (linked to its edit page), the field, the type,
where it points and its status. **Broken Links** shows only the links with a problem.

The screen reads a link index FreeLink keeps alongside your content (`freelink_index`), so it stays
fast on large sites. It covers live content: drafts and revisions are left out. After updating to
5.3 the index is built by an "Indexing FreeLink links" queue job; admins can rebuild it any time
with **Rebuild index** or `php craft freelink/links/reindex`.

### Link checks

- **Element links** are checked against the database whenever the screen loads. A link is flagged
  when its target was deleted, is in the trash, is disabled, isn't published yet, or has expired.
- **URL links** (URL links, and Custom links with an http/https URL) are checked over HTTP, but
  only from a queue job — **Check links now** — or the console:

  ```bash
  php craft freelink/links/check            # URLs not checked in the last 24 hours
  php craft freelink/links/check --force    # all of them
  php craft freelink/links/status           # counts
  ```

  A run requests each distinct URL once (HEAD, then GET when a server refuses HEAD), one at a time,
  with a pause between requests to the same host, and records the status, HTTP code, final URL and
  time. Schedule it with cron if you want regular checks.

The checker is built to never become a way into your network. It requests only http and https URLs
saved in FreeLink fields — nothing a visitor, an editor's request, GraphQL or MCP supplies. Before
every request, and again for every redirect (5 at most), it resolves the host and refuses
private, loopback, link-local, shared (CGNAT), reserved, documentation, multicast and cloud-metadata
addresses (`169.254.169.254`, `fd00:ec2::254`…), in IPv4 and IPv6, including IPv4-mapped IPv6 and
the decimal, octal and hex ways of writing an IPv4 address. It connects to the address it checked
(so DNS can't be switched between the check and the request), ignores proxy settings, sends no
cookies, gives up after 5 seconds and reads at most 64 KB. Its user agent is
`FreeLinkChecker/5.3 (+Craft CMS link checker)`.

To check intranet links, list their hosts — and any extra ports — in `config/freelink.php`. These
can't be set from the control panel:

```php
<?php
return [
    'checkAllowedHosts' => ['intranet.example.com'],
    'checkAllowedPorts' => [80, 443, 8443],
];
```

### Settings

**Settings → Plugins → FreeLink** (also linked from the section) has:

- **Sidebar label** and **Sidebar icon** — rename the sidebar item and pick an icon from Craft's icon
  set, or paste your own SVG. Pasted SVG must be a single `<svg>` element and is sanitised before it
  is shown (scripts, event handlers and external references are removed). Craft 5 has no built-in
  sidebar editor; if you use a plugin that customises the sidebar, its settings win.
- **Show "Linked from" on edit screens**.
- **Check URL links over HTTP** (turn it off and FreeLink never requests a URL), **Recheck after**
  (hours), **Delay between requests to one host** (ms) and **URLs per check run**.

All of these can also be set in `config/freelink.php`.

### Permissions

| Permission | Allows |
|---|---|
| Access FreeLink | Seeing the section |
| View the Links screen and link status | Links, Broken Links and the Linked from panel |
| ↳ Queue link checks | The **Check links now** button |

Migrations, **Rebuild index** and the plugin settings are for admins. Rows whose source a user can't
view are listed without the source's name.

### Migrations

**FreeLink → Migrations** lists the link fields from Hyper, Linkit, Typed Link Field and Craft's Link
field that can be converted, runs a migration (a dry run by default, with an optional database
backup first) and shows the history. Running one for real needs admin changes to be allowed and
asks for your password again. The console commands below do the same thing. The page used to be at
`admin/freelink/migrate`, which now redirects.

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

Editors can't make a link run script. A `javascript:`, `vbscript:` or `data:` URL fails
validation, and one already stored renders with no URL. Custom attribute names are an
allowlist (5.3+, the same as FreeNav): `data-*`, `aria-*`, and the plain attributes `title`, `rel`,
`id`, `lang`, `dir`, `role`, `tabindex`, `accesskey`, `translate`, `hreflang`, `download`, `type`,
`itemprop`, `itemscope`, `itemtype`, `itemid` and `referrerpolicy`. Event handlers, URL attributes,
`style`, `class` (use **CSS Classes**) and front-end framework directives that run code are refused —
Alpine (`x-*`), htmx (`hx-*`, `data-hx-*`), Vue (`v-*`), Angular (`ng-*`, `data-ng-*`) and Datastar's
`data-on-*`, `data-bind`, `data-text`, `data-show` and the like. Values must be text. If your front end
runs another framework that executes `data-*` attributes, treat editors as trusted. Attributes can't
replace one the link sets itself, such as `target` or `rel`. They render only while the field's **Show Advanced** setting is on. Attributes
passed to `link({…})` in a template are yours and aren't checked.

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

### Element Resolution

Element links (entry, asset, category, user, product, variant) resolve their
target element automatically when you access it — no `.with()` needed:

```twig
{% for entry in craft.entries.section('news').all() %}
    {{ entry.myLink.link }}              {# resolves the linked element on access #}
    {{ entry.myLink.element.title }}
{% endfor %}
```

> **Note:** Because the field value is a `LinkCollection` value object (not a raw
> element list), it does **not** participate in Craft's `.with([...])` eager
> loading. Don't pass a FreeLink field handle to `.with()` — element links
> resolve lazily on access instead.

### Reverse Lookups

Find the links that point at an element. `linksTo()` reads the link index, so it covers live
content (not drafts or revisions) and tells you where each link is:

```twig
{% for ref in craft.freelink.linksTo(entry) %}
    {{ ref.source.title }} links here from its {{ ref.fieldHandle }} field
    ({{ ref.type }} link, position {{ ref.sortOrder }}, status {{ ref.status }}).
    {# ref.rootSource is the top-level element when the link is inside a Matrix entry #}
    {# ref.link is the Link itself #}
{% endfor %}

{# Options: field, type, site, limit (max 100), offset, status #}
{% set refs = craft.freelink.linksTo(entry, {field: 'myLink', limit: 10}) %}

{{ craft.freelink.linkCountTo(entry) }} links point here.
```

The same from PHP:

```php
use justinholtweb\freelink\Plugin as FreeLink;

$refs = FreeLink::getInstance()->links->findLinksTo($entry, ['field' => 'myLink']);
```

`getRelatedElements()` still returns the elements whose FreeLink fields point at an element:

```twig
{% set linkedFrom = craft.freelink.getRelatedElements(entry) %}
{% set linkedFrom = craft.freelink.getRelatedElements(entry, 'myLink') %}
```

`craft.freelink` exists from 5.3. Before that only the `freelink` global did, which still works.

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
      icon
      iconSvg
      elementId
      elementSiteId
      elementType
      subject   # email links
      siteId    # site links
    }
  }
}
```

### Mutations

FreeLink fields can be written with Craft's entry (and other element) save mutations. A field takes
one `FreeLinkInput` in single-link mode and a list of them in multi-link mode:

```graphql
mutation {
  save_news_article_Entry(
    title: "Hello"
    cta: { type: "url", value: "https://example.com", label: "Read more", newWindow: true }
    relatedLinks: [
      { type: "entry", targetId: 42 }
      { type: "email", value: "hello@example.com", subject: "Hi" }
      { type: "custom", value: "#top", htmlId: "back-to-top", icon: "arrow-up" }
    ]
  ) { id }
}
```

`FreeLinkInput` has `type` (required), `value`, `targetId`, `targetSiteId`, `siteId`, `subject`,
`label`, `newWindow`, `ariaLabel`, `title`, `urlSuffix`, `classes`, `htmlId`, `rel`, `icon` and
`customAttributes: [{attribute, value}]`. Element link types take `targetId`; omit it to keep the
element already at that position, or pass `null` to clear it.

A mutation is validated exactly like a save in the control panel: only link types the field enables,
per-type validation, no script URLs or event-handler attributes, icons, min/max links, required,
selection conditions — and the target element must exist and be readable by the schema. As in the
control panel, links already stored on the element and unchanged (same type and destination) are not
re-checked against the type, condition and visibility rules.

### Reverse lookups

Give a schema **FreeLink → Query reverse link lookups** and it can ask what links to an element:

```graphql
{
  freelinkLinksTo(elementId: 42, field: "relatedLinks", limit: 20) {
    sourceId
    rootSourceId
    field
    type
    position
    status
    source { id title url }
    link { url text }
  }
}
```

It returns nothing for an element the schema can't read, and leaves out any source the schema
can't read, so it never reveals content outside the schema's scope.

## Element API

FreeLink works out of the box with [`craftcms/element-api`](https://github.com/craftcms/element-api). `Link` and `LinkCollection` implement `JsonSerializable`, so field values serialize to clean JSON objects with resolved URLs, display text, and element metadata.

```php
// config/element-api.php
use craft\elementapi\Plugin as ElementApi;
use craft\elements\Entry;

return [
    'endpoints' => [
        'news.json' => function() {
            return [
                'elementType' => Entry::class,
                'criteria' => ['section' => 'news'],
                'transformer' => function(Entry $entry) {
                    return [
                        'title' => $entry->title,
                        'link' => $entry->myLink,  // Single link → object
                        'links' => $entry->myLinks, // Multi link → array
                    ];
                },
            ];
        },
    ],
];
```

Single-link fields produce a JSON object:

```json
{
  "link": {
    "type": "url",
    "url": "https://example.com#pricing",
    "text": "Visit Example",
    "target": "_blank",
    "newWindow": true,
    "isEmpty": false,
    "isElement": false
  }
}
```

Element link fields include additional metadata:

```json
{
  "link": {
    "type": "entry",
    "url": "https://example.com/blog/my-post",
    "text": "My Blog Post",
    "target": null,
    "newWindow": false,
    "isEmpty": false,
    "isElement": true,
    "elementId": 42,
    "elementSiteId": 1,
    "elementType": "craft\\elements\\Entry",
    "elementTitle": "My Blog Post",
    "elementUrl": "https://example.com/blog/my-post",
    "elementStatus": "live"
  }
}
```

Multi-link fields produce an array of objects.

You can also call `toApiArray()` directly on any link for programmatic use:

```php
$link = $entry->myLink->one();
$data = $link->toApiArray();
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

## MCP

On Craft 5.12.1 and later, FreeLink takes part in Craft's MCP server.

- **Fields**: Craft's element tools (`elements_get`, `elements_update`, `elements_field_schema`…) read
  and write FreeLink fields. The field describes its own input (only the link types it enables, only
  the inputs it shows) and returns resolved links with their target element, which Craft filters by
  what the agent's user may view. Writes are validated like any save, and a new target the user
  can't view is refused.
- **Tools** (for users with control panel access; results limited to sources they can view):
  - `freelink_find_links_to` — what links to an element.
  - `freelink_list_links` — the Links screen: by field, link type, domain, site, owner type.
  - `freelink_check_links` — broken links from stored state: element-link targets, unsafe or
    malformed URLs, and the results of FreeLink's HTTP checks. It never makes a request.
- **Admin tools**: `freelink_migration_status`, and `freelink_migrate` (only where admin changes are
  allowed; a dry run unless `dryRun: false` and `confirm` set to the source name).

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

### Link index and check results (5.3)

- `freelink_index` — one row per link in live content (owner, root owner, field, position, type,
  target or URL and host). Derived from the two stores above; rebuilt by
  `php craft freelink/links/reindex`.
- `freelink_checks` — the last HTTP check of each distinct URL (status, HTTP code, final URL, error,
  time).

The design of 5.3 is documented in `docs/5.3-design.md`.

## License

This plugin requires a commercial license purchasable through the [Craft Plugin Store](https://plugins.craftcms.com).
