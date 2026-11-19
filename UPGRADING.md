# Upgrading FreeLink from Craft 5 to Craft 6

FreeLink 6 is a native Craft 6 plugin. It runs on Laravel and has no dependency on the Yii 2
adapter. Your fields, their stored values, the link index, link check results, settings and
permissions carry over. What changes is the PHP around them: namespaces, events, console commands,
the config file and the field's editor.

> **Beta.** FreeLink 6 is in beta, like Craft 6 itself. Test the upgrade on a copy of your site
> before relying on it in production. FreeLink 5 remains the stable release for Craft 5.

| | FreeLink 5.x | FreeLink 6.x |
| --- | --- | --- |
| Status | Stable | Beta |
| Craft CMS | 5.0+ | 6.0+ |
| PHP | 8.2+ | 8.5+ |
| Commands | `php craft freelink/links/check` | `php artisan freelink:links:check` (old names still work) |
| Config file | `config/freelink.php` | `config/craft/freelink.php` |

## Before you start

1. **Update to FreeLink 5.3.0** while the site is still on Craft 5, and run its migrations.
   FreeLink 6 won't install over anything older.

   ```bash
   composer require justinholtweb/craft-freelink:^5.3.0
   php craft up
   ```

2. **Convert categories to entries before upgrading Craft.** Craft 6 has no categories, and Craft's
   own upgrade expects you to have run `php craft entrify/categories` on Craft 5. That keeps each
   category's element ID, so FreeLink's upgrade can turn category links into entry links. A
   category link whose category didn't become an entry becomes a URL link to where the category
   was, or, when it had no URL, stays as it is and renders nothing (it's logged).

3. **Migrate Hyper, Linkit and Typed Link Field fields on Craft 5.** None of those plugins has a
   Craft 6 version, so their content can only be converted on Craft 5:

   ```bash
   php craft freelink/migrate/from-hyper --dry-run
   php craft freelink/migrate/from-hyper
   ```

   Fields that use Craft's own Link field can be migrated before or after the upgrade.

4. **Commerce product and variant links** need Commerce 6, which isn't released yet. They're kept
   in the database but load as empty links until then.

5. **Find your extensions.** If a plugin or module registers custom link types or calls FreeLink's
   classes, it needs the changes under [Custom code](#custom-code).

## Upgrade

Follow Craft's [Craft 6 upgrade guide](https://craftcms.com/docs/6.x/upgrade.html), requiring
FreeLink 6 in the same step:

```bash
composer require "craftcms/cms:^6.0@beta" "justinholtweb/craft-freelink:^6.0@beta" -W
php artisan craft:up
```

FreeLink's upgrade migration renames the stored field type, converts category links and lets
fields that offered categories offer entries. Running it again changes nothing. Then rebuild the
link index once, so it reflects the converted links:

```bash
php artisan freelink:links:reindex
```

Drop the `@beta` flags once Craft 6 and FreeLink 6 have stable releases.

## What carries over

- **Fields and values.** Field settings, the JSON stored for each link and the `freelink_links`
  relations are unchanged. Craft 6 keeps field values in `elements_sites.content` as Craft 5 did.
- **Link index and check results** (`freelink_index`, `freelink_checks`).
- **Templates.** `entry.myLink.url`, `.text`, `.link`, `.iconSvg`, `craft.freelink.linksTo()`,
  `linkCountTo()`, `getRelatedElements()` and the `freelink` global work as before.
- **GraphQL.** `FreeLinkInterface`, `FreeLinkInput` mutations, `freelinkLinksTo` and the **FreeLink
  → Query reverse link lookups** schema component keep their names.
- **MCP.** The five `freelink_*` tools keep their names, inputs and permissions.
- **Settings and permissions.** Plugin settings stay in project config; *View the Links screen and
  link status* and *Queue link checks* keep their names.
- **CP URLs.** `admin/freelink/links`, `…/broken`, `…/migrations` (and the old `…/migrate`
  redirect).

## What changes

### The field's editor

Each link is a row of Craft 6's own link editor, so element links use Craft's element picker and
the editor follows Craft's look. Icons are picked in an icon column, and custom attributes (when
the field shows advanced settings) are typed one `name=value` per line. On a multi-site install,
Site links appear once per site. Email subjects are typed as `you@example.com?subject=Hello`.

### Config file

Move `config/freelink.php` to `config/craft/freelink.php`. `checkAllowedHosts` and
`checkAllowedPorts` are only ever read from that file.

### Sidebar

The sidebar label and icon settings still work. Craft 6's **Settings → Control Panel Navigation**
can also rename and re-icon FreeLink, and its overrides win.

### Linked from

The **Linked from** list moves into the element editor's metadata, under the element's ID and
status.

### Stricter link checks on drafts and sites

A draft can't add a link to an element its author can't view, and a target's title and URL aren't
shown to anyone (or any GraphQL schema) that can't view it. Element link targets now load on the
link's target site, else the site of the element holding the link, and every visibility and
GraphQL scope check uses that same site, including the schema's site scope.

## Custom code

| FreeLink 5 | FreeLink 6 |
| --- | --- |
| `justinholtweb\freelink\Plugin` | `JustinHoltWeb\FreeLink\Plugin` (`Plugin::getInstance()->links` still works) |
| `justinholtweb\freelink\base\Link` / `ElementLink` | `JustinHoltWeb\FreeLink\Base\Link` / `ElementLink` |
| `justinholtweb\freelink\fields\FreeLinkField` | `JustinHoltWeb\FreeLink\Fields\FreeLinkField` |
| `justinholtweb\freelink\models\LinkCollection` | `JustinHoltWeb\FreeLink\Models\LinkCollection` |
| `Event::on(Links::class, Links::EVENT_REGISTER_LINK_TYPES, …)` | `Event::listen(RegisterLinkTypes::class, …)` |
| `rules()` on a custom link type | `getRules()` (Laravel rules) or `checks()` |
| `Twig\Markup` from `getLink()` | `Illuminate\Support\HtmlString` |
| `Plugin::getInstance()->linkIndex` | `app(LinkIndex::class)` (the property still works) |
