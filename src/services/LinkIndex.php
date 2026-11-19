<?php

namespace justinholtweb\freelink\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\freelink\base\ElementLink;
use justinholtweb\freelink\fields\FreeLinkField;
use justinholtweb\freelink\migrations\Install;
use justinholtweb\freelink\models\LinkCollection;
use justinholtweb\freelink\models\LinkReference;
use justinholtweb\freelink\Plugin;
use yii\base\Component;
use yii\db\Expression;

/**
 * The link index: one row per link in live content (`freelink_index`).
 *
 * Derived data. Field values (content JSON + `freelink_links`) stay the source of truth; this table
 * exists so the Links screen, reverse lookups, GraphQL, MCP and link checks can query every link on
 * the site with indexes instead of decoding JSON.
 *
 * See docs/5.3-design.md §2.3 and §6.
 */
class LinkIndex extends Component
{
    public const MAX_LIMIT = 100;

    /** Link types whose http(s) URLs are checked over HTTP. */
    public const CHECKABLE_TYPES = ['url', 'custom'];

    // region Writing

    /**
     * Replaces the index rows for one field on one element/site.
     *
     * Drafts, revisions and anything nested inside one are not live content and are skipped
     * (their canonical element is indexed when it is saved).
     */
    public function indexValue(FreeLinkField $field, ElementInterface $element, mixed $value = null): void
    {
        if (!$element->id || !$field->id) {
            return;
        }

        if (ElementHelper::isDraftOrRevision($element)) {
            return;
        }

        $root = ElementHelper::rootElement($element);

        if (ElementHelper::isDraftOrRevision($root)) {
            return;
        }

        $siteId = (int)($element->siteId ?? Craft::$app->getSites()->getCurrentSite()->id);

        if (!$value instanceof LinkCollection) {
            $value = $element->getFieldValue($field->handle);
        }

        // Element links whose target was deleted: the relation row survives with a null target
        // (FK SET NULL), the link itself loads as empty. Index them so they show up as `missing`.
        $deletedTargets = (new Query())
            ->select(['sortOrder'])
            ->from('{{%freelink_links}}')
            ->where(['fieldId' => $field->id, 'ownerId' => $element->id, 'ownerSiteId' => $siteId, 'targetId' => null])
            ->column();

        $rows = $value instanceof LinkCollection ? $this->rowsFor($value, array_map('intval', $deletedTargets)) : [];

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $this->deleteFor((int)$field->id, (int)$element->id, $siteId);

            if ($rows) {
                $now = Db::prepareDateForDb(new DateTime());
                $insert = [];

                foreach ($rows as $row) {
                    $insert[] = [
                        (int)$field->id,
                        (int)$element->id,
                        $siteId,
                        (int)($root->id ?? $element->id),
                        $row['sortOrder'],
                        $row['type'],
                        $row['isElement'],
                        $row['targetId'],
                        $row['targetSiteId'],
                        $row['url'],
                        $row['urlHash'],
                        $row['host'],
                        $row['label'],
                        $now,
                        $now,
                        StringHelper::UUID(),
                    ];
                }

                $db->createCommand()->batchInsert(Install::TABLE_INDEX, [
                    'fieldId', 'ownerId', 'ownerSiteId', 'rootOwnerId', 'sortOrder', 'type', 'isElement',
                    'targetId', 'targetSiteId', 'url', 'urlHash', 'host', 'label',
                    'dateCreated', 'dateUpdated', 'uid',
                ], $insert)->execute();
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * The index rows a field value produces (pure; no database).
     *
     * @param int[] $deletedTargets Positions of element links whose target was deleted
     * @return list<array{sortOrder: int, type: string, isElement: bool, targetId: int|null, targetSiteId: int|null, url: string|null, urlHash: string|null, host: string|null, label: string|null}>
     */
    public function rowsFor(LinkCollection $value, array $deletedTargets = []): array
    {
        $rows = [];

        foreach ($value->getAll() as $sortOrder => $link) {
            if ($link->isEmpty() && !($link instanceof ElementLink && in_array((int)$sortOrder, $deletedTargets, true))) {
                continue;
            }

            $isElement = $link instanceof ElementLink;
            $url = null;
            $host = null;

            if (!$isElement) {
                $url = $link->getUrl();
                $host = $url !== null ? self::hostOf($url) : null;
            }

            $rows[] = [
                'sortOrder' => (int)$sortOrder,
                'type' => (string)$link->type,
                'isElement' => $isElement,
                'targetId' => $isElement ? $link->targetId : null,
                'targetSiteId' => $isElement ? $link->targetSiteId : null,
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

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $host = rtrim(strtolower($parts['host']), '.');

        return $host !== '' ? mb_substr($host, 0, 255) : null;
    }

    public function deleteFor(int $fieldId, int $ownerId, int $siteId): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete(Install::TABLE_INDEX, [
                'fieldId' => $fieldId,
                'ownerId' => $ownerId,
                'ownerSiteId' => $siteId,
            ])
            ->execute();
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

        foreach (Craft::$app->getFields()->getAllLayouts() as $layout) {
            if (!$layout->id) {
                continue;
            }

            foreach ($layout->getCustomFields() as $field) {
                if ($field instanceof FreeLinkField) {
                    $ids[] = (int)$layout->id;
                    break;
                }
            }
        }

        return $ids;
    }

    /**
     * Elements whose layout holds a FreeLink field: live (not draft/revision/trashed) only.
     *
     * @return Query<int, array<string, mixed>>
     */
    public function elementsToIndexQuery(): Query
    {
        $layoutIds = $this->freeLinkLayoutIds();

        return (new Query())
            ->select(['id', 'type'])
            ->from(Table::ELEMENTS)
            ->where([
                'fieldLayoutId' => $layoutIds ?: [0],
                'draftId' => null,
                'revisionId' => null,
                'dateDeleted' => null,
            ])
            ->orderBy(['id' => SORT_ASC]);
    }

    /**
     * Reindexes one element ID in every site it exists in.
     */
    public function indexElementId(int $elementId, string $elementType): void
    {
        if (!class_exists($elementType) || !is_subclass_of($elementType, ElementInterface::class)) {
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
     * @param callable(int $done, int $total): void|null $progress
     */
    public function rebuild(?callable $progress = null): int
    {
        $query = $this->elementsToIndexQuery();
        $total = (int)$query->count();
        $done = 0;

        foreach (Db::each($query) as $row) {
            $this->indexElementId((int)$row['id'], (string)$row['type']);
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
        Craft::$app->getCache()->set('freelink:index-built', time());
    }

    /**
     * Whether the index was ever built on this install (the Links screen shows a notice until then).
     */
    public function isBuilt(): bool
    {
        if (Craft::$app->getCache()->get('freelink:index-built')) {
            return true;
        }

        // A non-empty index was built (or filled by saves); either way it has data.
        return (new Query())->from(Install::TABLE_INDEX)->exists();
    }

    // endregion

    // region Reading

    /**
     * The SQL expression for an element link's status. See docs/5.3-design.md §7.1.
     */
    private function elementStatusSql(): string
    {
        return "CASE
            WHEN [[i.isElement]] = 0 THEN NULL
            WHEN [[t.id]] IS NULL THEN 'missing'
            WHEN [[t.dateDeleted]] IS NOT NULL THEN 'trashed'
            WHEN [[t.enabled]] = 0 THEN 'disabled'
            WHEN [[ts.enabled]] IS NOT NULL AND [[ts.enabled]] = 0 THEN 'disabled'
            WHEN [[te.postDate]] IS NOT NULL AND [[te.postDate]] > :freelinkNow THEN 'pending'
            WHEN [[te.expiryDate]] IS NOT NULL AND [[te.expiryDate]] <= :freelinkNow THEN 'expired'
            ELSE 'live' END";
    }

    /**
     * A query over live index rows, filtered by the given criteria. Rows carry the element status and
     * the stored check result.
     *
     * Criteria (all optional): targetId, targetSiteId, fieldId, field (handle), type, siteId,
     * sourceType (element class of the root source), sectionId (entry section of the root source),
     * domain, status (`all`, `problems`, `ok`, `unchecked`, or a single status), sourceId, and
     * viewer (a User: only links whose source they may view — see applyViewerScope()).
     *
     * @param array<string, mixed> $criteria
     * @return Query<int, array<string, mixed>>
     */
    public function createQuery(array $criteria = []): Query
    {
        $now = Db::prepareDateForDb(new DateTime());
        $statusSql = $this->elementStatusSql();
        // Params ride on each expression, so replacing the select (e.g. for a count) drops them too.
        $nowParam = [':freelinkNow' => $now];

        $query = (new Query())
            ->select([
                'i.id', 'i.fieldId', 'i.ownerId', 'i.ownerSiteId', 'i.rootOwnerId', 'i.sortOrder', 'i.type',
                'i.isElement', 'i.targetId', 'i.targetSiteId', 'i.url', 'i.host', 'i.label',
                'elementStatus' => new Expression($statusSql, $nowParam),
                'checkStatus' => 'c.status',
                'httpCode' => 'c.httpCode',
                'checkError' => 'c.error',
                'finalUrl' => 'c.finalUrl',
                'checkedAt' => 'c.checkedAt',
            ])
            ->from(['i' => Install::TABLE_INDEX])
            ->innerJoin(['o' => Table::ELEMENTS], '[[o.id]] = [[i.ownerId]]')
            ->innerJoin(['r' => Table::ELEMENTS], '[[r.id]] = [[i.rootOwnerId]]')
            ->leftJoin(['t' => Table::ELEMENTS], '[[t.id]] = [[i.targetId]]')
            ->leftJoin(['ts' => Table::ELEMENTS_SITES], '[[ts.elementId]] = [[i.targetId]] AND [[ts.siteId]] = COALESCE([[i.targetSiteId]], [[i.ownerSiteId]])')
            ->leftJoin(['te' => Table::ENTRIES], '[[te.id]] = [[i.targetId]]')
            ->leftJoin(['c' => Install::TABLE_CHECKS], '[[c.urlHash]] = [[i.urlHash]]')
            ->where(['o.dateDeleted' => null, 'r.dateDeleted' => null]);

        if (!empty($criteria['targetId'])) {
            $query->andWhere(['i.targetId' => (int)$criteria['targetId']]);
        }

        if (!empty($criteria['targetSiteId'])) {
            $query->andWhere(['or', ['i.targetSiteId' => (int)$criteria['targetSiteId']], ['i.targetSiteId' => null]]);
        }

        if (!empty($criteria['sourceId'])) {
            $query->andWhere(['or', ['i.ownerId' => (int)$criteria['sourceId']], ['i.rootOwnerId' => (int)$criteria['sourceId']]]);
        }

        $fieldId = $criteria['fieldId'] ?? null;
        if (!$fieldId && !empty($criteria['field']) && is_string($criteria['field'])) {
            $field = Craft::$app->getFields()->getFieldByHandle($criteria['field']);
            $fieldId = $field instanceof FreeLinkField ? $field->id : -1;
        }
        if ($fieldId) {
            $query->andWhere(['i.fieldId' => (int)$fieldId]);
        }

        if (!empty($criteria['type']) && (is_string($criteria['type']) || is_array($criteria['type']))) {
            $query->andWhere(['i.type' => array_values(array_filter((array)$criteria['type'], 'is_string'))]);
        }

        if (!empty($criteria['siteId'])) {
            $query->andWhere(['i.ownerSiteId' => (int)$criteria['siteId']]);
        }

        if (!empty($criteria['sourceType']) && is_string($criteria['sourceType'])) {
            $query->andWhere(['r.type' => $criteria['sourceType']]);
        }

        if (($criteria['viewer'] ?? null) instanceof User) {
            $this->applyViewerScope($query, $criteria['viewer']);
        }

        if (!empty($criteria['ownerType']) && is_string($criteria['ownerType'])) {
            $query->andWhere(['o.type' => $criteria['ownerType']]);
        }

        if (!empty($criteria['sectionId'])) {
            $query
                ->innerJoin(['re' => Table::ENTRIES], '[[re.id]] = [[i.rootOwnerId]]')
                ->andWhere(['re.sectionId' => (int)$criteria['sectionId']]);
        }

        $domain = self::normalizeDomain($criteria['domain'] ?? null);
        if ($domain !== null) {
            $query->andWhere(['or', ['i.host' => $domain], ['like', 'i.host', '%.' . $domain, false]]);
        }

        $status = $criteria['status'] ?? 'all';
        $problemCheck = ['broken', 'error', 'blocked'];

        switch ($status) {
            case 'problems':
                $query->andWhere(['or',
                    ['and', ['i.isElement' => true], new Expression("($statusSql) <> 'live'", $nowParam)],
                    ['and', ['i.isElement' => false], ['c.status' => $problemCheck]],
                ]);
                break;
            case 'ok':
                $query->andWhere(['or',
                    ['and', ['i.isElement' => true], new Expression("($statusSql) = 'live'", $nowParam)],
                    ['and', ['i.isElement' => false], ['c.status' => ['ok', 'redirect']]],
                ]);
                break;
            case 'unchecked':
                $query->andWhere(['i.isElement' => false, 'c.id' => null])->andWhere(['not', ['i.host' => null]]);
                break;
            case 'missing':
            case 'trashed':
            case 'disabled':
            case 'pending':
            case 'expired':
                $query->andWhere(['i.isElement' => true])->andWhere(new Expression("($statusSql) = :freelinkStatus", $nowParam + [':freelinkStatus' => $status]));
                break;
            case 'broken':
            case 'error':
            case 'blocked':
            case 'redirect':
                $query->andWhere(['i.isElement' => false, 'c.status' => $status]);
                break;
        }

        return $query;
    }

    /**
     * Runs a filtered query: one page of references plus the total.
     *
     * @param array<string, mixed> $criteria
     * @return array{total: int, references: LinkReference[]}
     */
    public function find(array $criteria = [], int $limit = 50, int $offset = 0, bool $preload = true): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $offset = max(0, $offset);

        $query = $this->createQuery($criteria);
        $total = (int)(clone $query)->select(['i.id'])->count('*');

        $rows = $query
            ->orderBy(['i.dateUpdated' => SORT_DESC, 'i.id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        $references = array_map(fn(array $row) => LinkReference::fromRow($row), $rows);

        if ($preload) {
            $this->preloadElements($references);
        }

        return ['total' => $total, 'references' => $references];
    }

    /**
     * Number of live links pointing at an element.
     */
    public function countTo(int $targetId, ?User $viewer = null): int
    {
        return (int)$this->createQuery(['targetId' => $targetId, 'viewer' => $viewer])->select(['i.id'])->count('*');
    }

    /**
     * Limits a query to links whose top-level source the user may view, in SQL, so totals and
     * pagination never count rows the user can't see.
     *
     * Mirrors Elements::canView() for the core element types (Entry, Asset, Category, User, GlobalSet,
     * Tag): the type's own rules plus, on multi-site installs, `editSite:<site>` for localized ones.
     * Sources of any other element type are left out for non-admins — stricter than canView(), never
     * looser. Grants added through `Element::EVENT_AUTHORIZE_VIEW` aren't reflected.
     *
     * @param Query<int, array<string, mixed>> $query
     */
    public function applyViewerScope(Query $query, User $user): void
    {
        if ($user->admin) {
            return;
        }

        $uid = (int)$user->id;
        $or = ['or'];

        // On a multi-site install, a localized element is only viewable on a site the user may
        // edit (Elements::canView()'s site check). Index rows are per site, so check the row's.
        $siteCheck = ['and'];
        if (Craft::$app->getIsMultiSite()) {
            $editSites = [];
            foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
                if ($user->can("editSite:$site->uid")) {
                    $editSites[] = (int)$site->id;
                }
            }
            $siteCheck[] = ['i.ownerSiteId' => $editSites ?: [0]];
        }

        // Entries: viewEntries:<section>; another author's entry also needs viewPeerEntries
        // (singles excepted).
        $full = [];
        $ownOnly = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if (!$user->can("viewEntries:$section->uid")) {
                continue;
            }
            if ($section->type === \craft\models\Section::TYPE_SINGLE || $user->can("viewPeerEntries:$section->uid")) {
                $full[] = (int)$section->id;
            } else {
                $ownOnly[] = (int)$section->id;
            }
        }
        if ($full || $ownOnly) {
            $query->leftJoin(['rv_e' => Table::ENTRIES], '[[rv_e.id]] = [[i.rootOwnerId]]');
            $entryOr = ['or'];
            if ($full) {
                $entryOr[] = ['rv_e.sectionId' => $full];
            }
            if ($ownOnly) {
                $entryOr[] = ['and',
                    ['rv_e.sectionId' => $ownOnly],
                    ['exists', (new Query())->from(['rv_ea' => Table::ENTRIES_AUTHORS])->where('[[rv_ea.entryId]] = [[i.rootOwnerId]]')->andWhere(['rv_ea.authorId' => $uid])],
                ];
            }
            $or[] = ['and', ['r.type' => \craft\elements\Entry::class], $entryOr, $siteCheck];
        }

        // Assets: your own need viewAssets:<volume>, anyone else's viewPeerAssets:<volume>.
        $viewVolumes = [];
        $peerVolumes = [];
        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            if ($user->can("viewAssets:$volume->uid")) {
                $viewVolumes[] = (int)$volume->id;
            }
            if ($user->can("viewPeerAssets:$volume->uid")) {
                $peerVolumes[] = (int)$volume->id;
            }
        }
        if ($viewVolumes || $peerVolumes) {
            $query->leftJoin(['rv_a' => Table::ASSETS], '[[rv_a.id]] = [[i.rootOwnerId]]');
            $or[] = ['and', ['r.type' => \craft\elements\Asset::class], $siteCheck, ['or',
                ['and', ['rv_a.uploaderId' => $uid], ['rv_a.volumeId' => $viewVolumes ?: [0]]],
                ['and', ['or', ['not', ['rv_a.uploaderId' => $uid]], ['rv_a.uploaderId' => null]], ['rv_a.volumeId' => $peerVolumes ?: [0]]],
            ]];
        }

        // Categories: viewCategories:<group>.
        $groups = [];
        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            if ($user->can("viewCategories:$group->uid")) {
                $groups[] = (int)$group->id;
            }
        }
        if ($groups) {
            $query->leftJoin(['rv_c' => Table::CATEGORIES], '[[rv_c.id]] = [[i.rootOwnerId]]');
            $or[] = ['and', ['r.type' => \craft\elements\Category::class], ['rv_c.groupId' => $groups], $siteCheck];
        }

        // Users: yourself, or anyone with viewUsers.
        $or[] = $user->can('viewUsers')
            ? ['r.type' => \craft\elements\User::class]
            : ['and', ['r.type' => \craft\elements\User::class], ['r.id' => $uid]];

        // Global sets: editGlobalSet:<uid>.
        $sets = [];
        foreach (Craft::$app->getGlobals()->getAllSets() as $set) {
            if ($user->can("editGlobalSet:$set->uid")) {
                $sets[] = (int)$set->id;
            }
        }
        if ($sets) {
            $or[] = ['and', ['r.type' => \craft\elements\GlobalSet::class], ['r.id' => $sets], $siteCheck];
        }

        // Tags: anyone.
        $or[] = ['and', ['r.type' => \craft\elements\Tag::class], $siteCheck];

        $query->andWhere($or);
    }

    /**
     * Loads every source, root source and target of a page of references in one query per element
     * type and site.
     *
     * @param LinkReference[] $references
     */
    public function preloadElements(array $references): void
    {
        if (!$references) {
            return;
        }

        /** @var array<string, array<int, true>> $wanted "class|siteId" => ids */
        $wanted = [];
        $ids = [];

        foreach ($references as $ref) {
            $ids[] = $ref->sourceId;
            $ids[] = $ref->rootSourceId;
            if ($ref->targetId) {
                $ids[] = $ref->targetId;
            }
        }

        $types = (new Query())
            ->select(['type'])
            ->from(Table::ELEMENTS)
            ->where(['id' => array_values(array_unique($ids))])
            ->indexBy('id')
            ->column();

        $add = function(?int $id, int $siteId) use (&$wanted, $types) {
            if ($id && isset($types[$id])) {
                $wanted[$types[$id] . '|' . $siteId][$id] = true;
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

            if (!class_exists($class) || !is_subclass_of($class, ElementInterface::class)) {
                continue;
            }

            try {
                $found = $class::find()
                    ->id(array_keys($idSet))
                    ->siteId((int)$siteId)
                    ->status(null)
                    ->trashed(null)
                    ->all();
            } catch (\Throwable) {
                continue;
            }

            foreach ($found as $element) {
                $loaded[$element->id . '|' . $siteId] = $element;
            }
        }

        foreach ($references as $ref) {
            $targetSite = $ref->targetSiteId ?? $ref->sourceSiteId;
            $ref->setElements(
                $loaded[$ref->sourceId . '|' . $ref->sourceSiteId] ?? null,
                $loaded[$ref->rootSourceId . '|' . $ref->sourceSiteId] ?? null,
                $ref->targetId ? ($loaded[$ref->targetId . '|' . $targetSite] ?? null) : null,
            );
        }
    }

    /**
     * A domain filter value: lower-case host characters only, or null.
     */
    public static function normalizeDomain(mixed $domain): ?string
    {
        if (!is_string($domain)) {
            return null;
        }

        $domain = strtolower(trim($domain));
        // Accept a pasted URL too.
        if (str_contains($domain, '://')) {
            $domain = (string)(parse_url($domain, PHP_URL_HOST) ?? '');
        }
        $domain = rtrim(preg_replace('/^www\./', '', $domain) ?? '', '.');

        return preg_match('/^[a-z0-9]([a-z0-9\-.]*[a-z0-9])?$/', $domain) ? $domain : null;
    }

    // endregion

    /**
     * Distinct checkable URLs that are due a check, grouped so one host's URLs are consecutive.
     *
     * @return list<array{urlHash: string, url: string, host: string}>
     */
    public function urlsDueForCheck(int $limit, bool $force = false): array
    {
        $hours = max(1, Plugin::getInstance()->getSettings()->recheckAfter);
        $cutoff = Db::prepareDateForDb((new DateTime())->modify("-$hours hours"));

        $query = (new Query())
            ->select(['i.urlHash', 'url' => 'MIN([[i.url]])', 'host' => 'MIN([[i.host]])'])
            ->from(['i' => Install::TABLE_INDEX])
            ->innerJoin(['o' => Table::ELEMENTS], '[[o.id]] = [[i.ownerId]]')
            ->innerJoin(['r' => Table::ELEMENTS], '[[r.id]] = [[i.rootOwnerId]]')
            ->leftJoin(['c' => Install::TABLE_CHECKS], '[[c.urlHash]] = [[i.urlHash]]')
            ->where(['o.dateDeleted' => null, 'r.dateDeleted' => null, 'i.isElement' => false])
            ->andWhere(['not', ['i.host' => null]])
            ->andWhere(['not', ['i.urlHash' => null]])
            ->groupBy(['i.urlHash'])
            ->orderBy(['host' => SORT_ASC, 'url' => SORT_ASC])
            ->limit(max(1, $limit));

        if (!$force) {
            $query->andWhere(['or', ['c.id' => null], ['<', 'c.checkedAt', $cutoff]]);
        }

        $rows = [];
        foreach ($query->all() as $row) {
            $rows[] = ['urlHash' => (string)$row['urlHash'], 'url' => (string)$row['url'], 'host' => (string)$row['host']];
        }

        return $rows;
    }
}
