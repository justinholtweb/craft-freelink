<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Services;

use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\ElementHelper;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Query;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JustinHoltWeb\FreeLink\Base\ElementLink;
use JustinHoltWeb\FreeLink\Database\Tables;
use JustinHoltWeb\FreeLink\Fields\FreeLinkField;
use JustinHoltWeb\FreeLink\Models\LinkCollection;
use JustinHoltWeb\FreeLink\Models\LinkReference;
use JustinHoltWeb\FreeLink\Plugin;
use Throwable;

/**
 * The link index: one row per link in live content (`freelink_index`).
 *
 * Derived data. Field values (content JSON + `freelink_links`) stay the source of truth; this table
 * exists so the Links screen, reverse lookups, GraphQL, MCP and link checks can query every link on
 * the site with indexes instead of decoding JSON.
 *
 * See docs/5.3-design.md §2.3 and §6.
 */
class LinkIndex
{
    public const int MAX_LIMIT = 100;

    /** Link types whose http(s) URLs are checked over HTTP. */
    public const array CHECKABLE_TYPES = ['url', 'custom'];

    public const string BUILT_CACHE_KEY = 'freelink:index-built';

    // Writing
    // -------------------------------------------------------------------------

    /**
     * Replaces the index rows for one field on one element/site.
     *
     * Drafts, revisions and anything nested inside one are not live content and are skipped
     * (their canonical element is indexed when it is saved).
     */
    public function indexValue(FreeLinkField $field, ElementInterface $element, mixed $value = null): void
    {
        if (! $element->id || ! $field->id) {
            return;
        }

        if (ElementHelper::isDraftOrRevision($element)) {
            return;
        }

        $root = ElementHelper::rootElement($element);

        if (ElementHelper::isDraftOrRevision($root)) {
            return;
        }

        $siteId = (int) $element->siteId;

        if (! $value instanceof LinkCollection) {
            $value = $element->getFieldValue((string) $field->handle);
        }

        // Element links whose target was deleted: the relation row survives with a null target
        // (FK SET NULL), the link itself loads as empty. Index them so they show up as `missing`.
        $deletedTargets = DB::table(Tables::LINKS)
            ->where(['fieldId' => $field->id, 'ownerId' => $element->id, 'ownerSiteId' => $siteId])
            ->whereNull('targetId')
            ->pluck('sortOrder')
            ->map(fn ($v) => (int) $v)
            ->all();

        $rows = $value instanceof LinkCollection ? $this->rowsFor($value, $deletedTargets) : [];

        DB::transaction(function () use ($field, $element, $siteId, $root, $rows): void {
            $this->deleteFor((int) $field->id, (int) $element->id, $siteId);

            if ($rows === []) {
                return;
            }

            $now = Query::prepareDateForDb(now());
            $insert = [];

            foreach ($rows as $row) {
                $insert[] = [
                    'fieldId' => (int) $field->id,
                    'ownerId' => (int) $element->id,
                    'ownerSiteId' => $siteId,
                    'rootOwnerId' => (int) ($root->id ?? $element->id),
                    'sortOrder' => $row['sortOrder'],
                    'type' => $row['type'],
                    'isElement' => $row['isElement'],
                    'targetId' => $row['targetId'],
                    'targetSiteId' => $row['targetSiteId'],
                    'url' => $row['url'],
                    'urlHash' => $row['urlHash'],
                    'host' => $row['host'],
                    'label' => $row['label'],
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => (string) Str::uuid(),
                ];
            }

            DB::table(Tables::INDEX)->insert($insert);
        });
    }

    /**
     * The index rows a field value produces (pure; no database).
     *
     * @param  int[]  $deletedTargets  Positions of element links whose target was deleted
     * @return list<array{sortOrder: int, type: string, isElement: bool, targetId: int|null, targetSiteId: int|null, url: string|null, urlHash: string|null, host: string|null, label: string|null}>
     */
    public function rowsFor(LinkCollection $value, array $deletedTargets = []): array
    {
        $rows = [];

        foreach ($value->getAll() as $sortOrder => $link) {
            if ($link->isEmpty() && ! ($link instanceof ElementLink && in_array((int) $sortOrder, $deletedTargets, true))) {
                continue;
            }

            $isElement = $link instanceof ElementLink;
            $url = null;
            $host = null;

            if (! $isElement) {
                $url = $link->getUrl();
                $host = $url !== null ? self::hostOf($url) : null;
            }

            $rows[] = [
                'sortOrder' => (int) $sortOrder,
                'type' => $link->type,
                'isElement' => $isElement,
                'targetId' => $link instanceof ElementLink ? $link->targetId : null,
                'targetSiteId' => $link instanceof ElementLink ? $link->targetSiteId : null,
                'url' => $url,
                'urlHash' => $url !== null ? sha1($url) : null,
                // Only http(s) links of a checkable type get a host: that is what makes a row checkable.
                'host' => in_array($link->type, self::CHECKABLE_TYPES, true) ? $host : null,
                'label' => $link->label !== null ? mb_substr($link->label, 0, 255) : null,
            ];
        }

        return $rows;
    }

    /**
     * The lower-cased host of an http(s) URL, or null.
     */
    public static function hostOf(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $host = rtrim(strtolower($parts['host']), '.');

        return $host !== '' ? mb_substr($host, 0, 255) : null;
    }

    public function deleteFor(int $fieldId, int $ownerId, int $siteId): void
    {
        DB::table(Tables::INDEX)
            ->where(['fieldId' => $fieldId, 'ownerId' => $ownerId, 'ownerSiteId' => $siteId])
            ->delete();
    }

    /**
     * Reindexes every FreeLink value in one element (all its FreeLink fields, one site).
     */
    public function indexElement(ElementInterface $element): int
    {
        $count = 0;

        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($field instanceof FreeLinkField) {
                $this->indexValue($field, $element);
                $count++;
            }
        }

        return $count;
    }

    /**
     * IDs of field layouts that contain a FreeLink field.
     *
     * @return int[]
     */
    public function freeLinkLayoutIds(): array
    {
        $ids = [];

        foreach (Fields::getAllLayouts() as $layout) {
            if (! $layout->id) {
                continue;
            }

            foreach ($layout->getCustomFields() as $field) {
                if ($field instanceof FreeLinkField) {
                    $ids[] = (int) $layout->id;
                    break;
                }
            }
        }

        return $ids;
    }

    /**
     * Elements whose layout holds a FreeLink field: live (not draft/revision/trashed) only.
     */
    public function elementsToIndexQuery(): Builder
    {
        $layoutIds = $this->freeLinkLayoutIds();

        return DB::table(Table::ELEMENTS)
            ->select(['id', 'type'])
            ->whereIn('fieldLayoutId', $layoutIds ?: [0])
            ->whereNull('draftId')
            ->whereNull('revisionId')
            ->whereNull('dateDeleted')
            ->orderBy('id');
    }

    /**
     * Reindexes one element ID in every site it exists in.
     */
    public function indexElementId(int $elementId, string $elementType): void
    {
        if (! class_exists($elementType) || ! is_subclass_of($elementType, ElementInterface::class)) {
            return;
        }

        $elements = $elementType::find()
            ->id($elementId)
            ->site('*')
            ->status(null)
            ->all();

        foreach ($elements as $element) {
            $this->indexElement($element);
        }
    }

    /**
     * Rebuilds the whole index synchronously (console). Returns the number of elements read.
     *
     * @param  (callable(int $done, int $total): void)|null  $progress
     */
    public function rebuild(?callable $progress = null): int
    {
        $query = $this->elementsToIndexQuery();
        $total = (clone $query)->count();
        $done = 0;

        foreach ($query->lazyById(100, 'id') as $row) {
            $this->indexElementId((int) $row->id, (string) $row->type);
            $done++;

            if ($progress && ($done % 50 === 0 || $done === $total)) {
                $progress($done, $total);
            }
        }

        $this->markBuilt();

        return $done;
    }

    public function markBuilt(): void
    {
        Cache::forever(self::BUILT_CACHE_KEY, time());
    }

    /**
     * Whether the index was ever built on this install (the Links screen shows a notice until then).
     */
    public function isBuilt(): bool
    {
        if (Cache::get(self::BUILT_CACHE_KEY)) {
            return true;
        }

        // A non-empty index was built (or filled by saves); either way it has data.
        return DB::table(Tables::INDEX)->exists();
    }

    // Reading
    // -------------------------------------------------------------------------

    /**
     * The SQL expression for an element link's status, with its `?` bindings. See
     * docs/5.3-design.md §7.1.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function elementStatusSql(): array
    {
        $w = fn (string $column): string => DB::getQueryGrammar()->wrap($column);
        $now = (string) Query::prepareDateForDb(now());

        $sql = 'CASE'
            .' WHEN '.$w('i.isElement').' = 0 THEN NULL'
            .' WHEN '.$w('t.id').' IS NULL THEN \'missing\''
            .' WHEN '.$w('t.dateDeleted').' IS NOT NULL THEN \'trashed\''
            .' WHEN '.$w('t.enabled').' = 0 THEN \'disabled\''
            .' WHEN '.$w('ts.enabled').' IS NOT NULL AND '.$w('ts.enabled').' = 0 THEN \'disabled\''
            .' WHEN '.$w('te.postDate').' IS NOT NULL AND '.$w('te.postDate').' > ? THEN \'pending\''
            .' WHEN '.$w('te.expiryDate').' IS NOT NULL AND '.$w('te.expiryDate').' <= ? THEN \'expired\''
            .' ELSE \'live\' END';

        return [$sql, [$now, $now]];
    }

    /**
     * A query over live index rows, filtered by the given criteria. Rows carry the element status and
     * the stored check result.
     *
     * Criteria (all optional): targetId, targetSiteId, fieldId, field (handle), type, siteId,
     * sourceType (element class of the root source), ownerType (element class of the owner),
     * sectionId (entry section of the root source), domain, status (`all`, `problems`, `ok`,
     * `unchecked`, or a single status), sourceId.
     *
     * @param  array<string, mixed>  $criteria
     */
    public function createQuery(array $criteria = []): Builder
    {
        [$statusSql, $statusBindings] = $this->elementStatusSql();

        $query = DB::table(Tables::INDEX.' as i')
            ->select([
                'i.id', 'i.fieldId', 'i.ownerId', 'i.ownerSiteId', 'i.rootOwnerId', 'i.sortOrder', 'i.type',
                'i.isElement', 'i.targetId', 'i.targetSiteId', 'i.url', 'i.host', 'i.label', 'i.dateUpdated',
                'c.status as checkStatus',
                'c.httpCode as httpCode',
                'c.error as checkError',
                'c.finalUrl as finalUrl',
                'c.checkedAt as checkedAt',
            ])
            // Bindings ride on the select, so a count (which drops the select) never leaves one unbound.
            ->selectRaw("($statusSql) as ".DB::getQueryGrammar()->wrap('elementStatus'), $statusBindings)
            ->join(Table::ELEMENTS.' as o', 'o.id', '=', 'i.ownerId')
            ->join(Table::ELEMENTS.' as r', 'r.id', '=', 'i.rootOwnerId')
            ->leftJoin(Table::ELEMENTS.' as t', 't.id', '=', 'i.targetId')
            ->leftJoin(Table::ELEMENTS_SITES.' as ts', function (JoinClause $join): void {
                $join->on('ts.elementId', '=', 'i.targetId')
                    ->whereRaw(DB::getQueryGrammar()->wrap('ts.siteId').' = COALESCE('.DB::getQueryGrammar()->wrap('i.targetSiteId').', '.DB::getQueryGrammar()->wrap('i.ownerSiteId').')');
            })
            ->leftJoin(Table::ENTRIES.' as te', 'te.id', '=', 'i.targetId')
            ->leftJoin(Tables::CHECKS.' as c', 'c.urlHash', '=', 'i.urlHash')
            ->whereNull('o.dateDeleted')
            ->whereNull('r.dateDeleted');

        if (! empty($criteria['targetId'])) {
            $query->where('i.targetId', (int) $criteria['targetId']);
        }

        if (! empty($criteria['targetSiteId'])) {
            $query->where(fn (Builder $q) => $q->where('i.targetSiteId', (int) $criteria['targetSiteId'])->orWhereNull('i.targetSiteId'));
        }

        if (! empty($criteria['sourceId'])) {
            $sourceId = (int) $criteria['sourceId'];
            $query->where(fn (Builder $q) => $q->where('i.ownerId', $sourceId)->orWhere('i.rootOwnerId', $sourceId));
        }

        $fieldId = $criteria['fieldId'] ?? null;
        if (! $fieldId && ! empty($criteria['field']) && is_string($criteria['field'])) {
            $field = Fields::getFieldByHandle($criteria['field']);
            $fieldId = $field instanceof FreeLinkField ? $field->id : -1;
        }
        if ($fieldId) {
            $query->where('i.fieldId', (int) $fieldId);
        }

        if (! empty($criteria['type']) && (is_string($criteria['type']) || is_array($criteria['type']))) {
            $query->whereIn('i.type', array_values(array_filter((array) $criteria['type'], 'is_string')));
        }

        if (! empty($criteria['siteId'])) {
            $query->where('i.ownerSiteId', (int) $criteria['siteId']);
        }

        if (! empty($criteria['sourceType']) && is_string($criteria['sourceType'])) {
            $query->where('r.type', $criteria['sourceType']);
        }

        if (! empty($criteria['ownerType']) && is_string($criteria['ownerType'])) {
            $query->where('o.type', $criteria['ownerType']);
        }

        if (! empty($criteria['sectionId'])) {
            $query->join(Table::ENTRIES.' as re', 're.id', '=', 'i.rootOwnerId')
                ->where('re.sectionId', (int) $criteria['sectionId']);
        }

        $domain = self::normalizeDomain($criteria['domain'] ?? null);
        if ($domain !== null) {
            $query->where(fn (Builder $q) => $q->where('i.host', $domain)->orWhere('i.host', 'like', '%.'.$domain));
        }

        $status = $criteria['status'] ?? 'all';
        $problemCheck = ['broken', 'error', 'blocked'];

        switch ($status) {
            case 'problems':
                $query->where(fn (Builder $q) => $q
                    ->where(fn (Builder $q) => $q->where('i.isElement', true)->whereRaw("($statusSql) <> 'live'", $statusBindings))
                    ->orWhere(fn (Builder $q) => $q->where('i.isElement', false)->whereIn('c.status', $problemCheck)));
                break;
            case 'ok':
                $query->where(fn (Builder $q) => $q
                    ->where(fn (Builder $q) => $q->where('i.isElement', true)->whereRaw("($statusSql) = 'live'", $statusBindings))
                    ->orWhere(fn (Builder $q) => $q->where('i.isElement', false)->whereIn('c.status', ['ok', 'redirect'])));
                break;
            case 'unchecked':
                $query->where('i.isElement', false)->whereNull('c.id')->whereNotNull('i.host');
                break;
            case 'missing':
            case 'trashed':
            case 'disabled':
            case 'pending':
            case 'expired':
                $query->where('i.isElement', true)->whereRaw("($statusSql) = ?", [...$statusBindings, $status]);
                break;
            case 'broken':
            case 'error':
            case 'blocked':
            case 'redirect':
                $query->where('i.isElement', false)->where('c.status', $status);
                break;
        }

        return $query;
    }

    /**
     * Runs a filtered query: one page of references plus the total.
     *
     * @param  array<string, mixed>  $criteria
     * @return array{total: int, references: LinkReference[]}
     */
    public function find(array $criteria = [], int $limit = 50, int $offset = 0, bool $preload = true): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $offset = max(0, $offset);

        $query = $this->createQuery($criteria);
        $total = (clone $query)->count('i.id');

        $rows = $query
            ->orderByDesc('i.dateUpdated')
            ->orderByDesc('i.id')
            ->limit($limit)
            ->offset($offset)
            ->get();

        $references = $rows->map(fn (object $row) => LinkReference::fromRow((array) $row))->values()->all();

        if ($preload) {
            $this->preloadElements($references);
        }

        return ['total' => $total, 'references' => $references];
    }

    /**
     * Number of live links pointing at an element.
     */
    public function countTo(int $targetId): int
    {
        return $this->createQuery(['targetId' => $targetId])->count('i.id');
    }

    /**
     * Loads every source, root source and target of a page of references in one query per element
     * type and site.
     *
     * @param  LinkReference[]  $references
     */
    public function preloadElements(array $references): void
    {
        if ($references === []) {
            return;
        }

        $ids = [];

        foreach ($references as $ref) {
            $ids[] = $ref->sourceId;
            $ids[] = $ref->rootSourceId;
            if ($ref->targetId) {
                $ids[] = $ref->targetId;
            }
        }

        /** @var array<int, string> $types */
        $types = DB::table(Table::ELEMENTS)
            ->whereIn('id', array_values(array_unique($ids)))
            ->pluck('type', 'id')
            ->all();

        /** @var array<string, array<int, true>> $wanted "class|siteId" => ids */
        $wanted = [];

        $add = function (?int $id, int $siteId) use (&$wanted, $types): void {
            if ($id && isset($types[$id])) {
                $wanted[$types[$id].'|'.$siteId][$id] = true;
            }
        };

        foreach ($references as $ref) {
            $add($ref->sourceId, $ref->sourceSiteId);
            $add($ref->rootSourceId, $ref->sourceSiteId);
            $add($ref->targetId, $ref->targetSiteId ?? $ref->sourceSiteId);
        }

        /** @var array<string, ElementInterface> $loaded "id|siteId" => element */
        $loaded = [];

        foreach ($wanted as $key => $idSet) {
            [$class, $siteId] = explode('|', $key);

            if (! class_exists($class) || ! is_subclass_of($class, ElementInterface::class)) {
                continue;
            }

            try {
                $found = $class::find()
                    ->id(array_keys($idSet))
                    ->siteId((int) $siteId)
                    ->status(null)
                    ->trashed(null)
                    ->all();
            } catch (Throwable) {
                continue;
            }

            foreach ($found as $element) {
                $loaded[$element->id.'|'.$siteId] = $element;
            }
        }

        foreach ($references as $ref) {
            $targetSite = $ref->targetSiteId ?? $ref->sourceSiteId;
            $ref->setElements(
                $loaded[$ref->sourceId.'|'.$ref->sourceSiteId] ?? null,
                $loaded[$ref->rootSourceId.'|'.$ref->sourceSiteId] ?? null,
                $ref->targetId ? ($loaded[$ref->targetId.'|'.$targetSite] ?? null) : null,
            );
        }
    }

    /**
     * A domain filter value: lower-case host characters only, or null.
     */
    public static function normalizeDomain(mixed $domain): ?string
    {
        if (! is_string($domain)) {
            return null;
        }

        $domain = strtolower(trim($domain));
        // Accept a pasted URL too.
        if (str_contains($domain, '://')) {
            $domain = (string) (parse_url($domain, PHP_URL_HOST) ?? '');
        }
        $domain = rtrim(preg_replace('/^www\./', '', $domain) ?? '', '.');

        return preg_match('/^[a-z0-9]([a-z0-9\-.]*[a-z0-9])?$/', $domain) ? $domain : null;
    }

    /**
     * Distinct checkable URLs that are due a check, grouped so one host's URLs are consecutive.
     *
     * @return list<array{urlHash: string, url: string, host: string}>
     */
    public function urlsDueForCheck(int $limit, bool $force = false): array
    {
        $hours = max(1, Plugin::settings()->recheckAfter);
        $cutoff = Query::prepareDateForDb(now()->subHours($hours));
        $w = fn (string $column): string => DB::getQueryGrammar()->wrap($column);

        $query = DB::table(Tables::INDEX.' as i')
            ->select('i.urlHash')
            ->selectRaw('MIN('.$w('i.url').') as '.$w('url'))
            ->selectRaw('MIN('.$w('i.host').') as '.$w('host'))
            ->join(Table::ELEMENTS.' as o', 'o.id', '=', 'i.ownerId')
            ->join(Table::ELEMENTS.' as r', 'r.id', '=', 'i.rootOwnerId')
            ->leftJoin(Tables::CHECKS.' as c', 'c.urlHash', '=', 'i.urlHash')
            ->whereNull('o.dateDeleted')
            ->whereNull('r.dateDeleted')
            ->where('i.isElement', false)
            ->whereNotNull('i.host')
            ->whereNotNull('i.urlHash')
            ->groupBy('i.urlHash')
            ->orderBy('host')
            ->orderBy('url')
            ->limit(max(1, $limit));

        if (! $force) {
            $query->where(fn (Builder $q) => $q->whereNull('c.id')->orWhere('c.checkedAt', '<', $cutoff));
        }

        $rows = [];
        foreach ($query->get() as $row) {
            $rows[] = ['urlHash' => (string) $row->urlHash, 'url' => (string) $row->url, 'host' => (string) $row->host];
        }

        return $rows;
    }
}
