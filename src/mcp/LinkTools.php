<?php

namespace justinholtweb\freelink\mcp;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use craft\mcp\attributes\RequiresPermission;
use craft\mcp\Authorization;
use craft\mcp\McpActor;
use justinholtweb\freelink\base\ElementLink;
use justinholtweb\freelink\fields\FreeLinkField;
use justinholtweb\freelink\helpers\UrlSafety;
use justinholtweb\freelink\models\LinkReference;
use justinholtweb\freelink\Plugin;
use justinholtweb\freelink\services\LinkIndex;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * FreeLink link tools for Craft 5.12+'s MCP server. Read-only: nothing here fetches a URL or writes.
 *
 * Tool names, inputs and gates follow plugin-testing-craft6/porting/mcp.md so the Craft 6 port can
 * mirror them. Plugin services are reached through Plugin::getInstance() — the MCP container would
 * otherwise autowire fresh, unconfigured instances.
 */
class LinkTools
{
    /** Index rows one freelink_check_links call examines at most. */
    public const SCAN_LIMIT = 1000;

    public const CHECKS = [
        'missingTarget', 'disabledTarget', 'pendingTarget', 'expiredTarget', 'noTargetUrl',
        'targetSiteMismatch', 'disallowedBySelectionCondition', 'unsafeUrl', 'malformedUrl',
        'httpBroken', 'httpError', 'httpBlocked',
    ];

    public function __construct(private McpActor $actor)
    {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'freelink_find_links_to',
        description: 'Finds FreeLink links that point at an element (reverse lookup), from live content unless includeDrafts is true. Element links only: URL links that happen to point at the element’s page are not matched. Only owners you can view are returned.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    public function findLinksTo(
        #[Schema(minimum: 1, description: 'ID of the element the links point at.')]
        int $targetId,
        ?int $targetSiteId = null,
        #[Schema(description: 'FreeLink field handle.')]
        ?string $field = null,
        #[Schema(description: 'Only links in this site.')]
        ?int $ownerSiteId = null,
        bool $includeDrafts = false,
        #[Schema(minimum: 1, maximum: 100)]
        int $limit = 50,
        #[Schema(minimum: 0)]
        int $offset = 0,
    ): array {
        $user = $this->actor->user();
        $target = $this->viewableElement($user, $targetId, $targetSiteId);

        if (!$target) {
            // Same answer for "doesn't exist" and "can't see it".
            throw new ToolCallException('Element not found.');
        }

        $result = Plugin::getInstance()->linkIndex->find([
            'viewer' => $user,
            'targetId' => $targetId,
            'targetSiteId' => $targetSiteId,
            'field' => $field,
            'siteId' => $ownerSiteId,
        ], $limit, $offset);

        $links = [];
        foreach ($result['references'] as $ref) {
            $owner = $ref->getSource();
            $root = $ref->getRootSource();

            if (!$owner || !$root || !$this->canView($user, $root)) {
                continue;
            }

            $links[] = $this->ownerRow($ref, $owner, $root) + [
                'linkType' => $ref->type,
                'label' => $ref->label,
                'draft' => false,
            ];
        }

        if ($includeDrafts) {
            foreach ($this->draftLinks($user, $targetId, $field, $ownerSiteId) as $row) {
                $links[] = $row;
            }
        }

        $next = $offset + $limit;

        return [
            'target' => [
                'type' => $target::class,
                'id' => $target->id,
                'title' => (string)$target,
                'status' => $target->getStatus(),
            ],
            'total' => $result['total'],
            'count' => count($links),
            'nextOffset' => $next < $result['total'] ? $next : null,
            'links' => $links,
        ];
    }

    /**
     * @param string|string[]|null $linkType
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'freelink_list_links',
        description: 'Lists FreeLink links in live content, filterable by field, link type, URL domain (matches subdomains), site and owner element type. Only owners you can view are returned.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    public function listLinks(
        #[Schema(description: 'FreeLink field handle.')]
        ?string $field = null,
        #[Schema(definition: ['type' => ['string', 'array', 'null'], 'items' => ['type' => 'string'], 'description' => 'Link type handle(s): url, email, phone, sms, custom, site, entry, asset, category, user, product, variant.'])]
        string | array | null $linkType = null,
        #[Schema(description: 'Host name, e.g. example.com (URL links only; subdomains match too).')]
        ?string $domain = null,
        ?int $siteId = null,
        #[Schema(description: 'Owner element type handle, e.g. entry, asset, category, user.')]
        ?string $ownerType = null,
        #[Schema(minimum: 1, maximum: 100)]
        int $limit = 50,
        #[Schema(minimum: 0)]
        int $offset = 0,
    ): array {
        $user = $this->actor->user();

        $criteria = [
            'field' => $field,
            'type' => $linkType,
            'domain' => $domain,
            'siteId' => $siteId,
        ];

        if ($ownerType !== null && $ownerType !== '') {
            $class = $this->elementTypeByHandle($ownerType);
            if (!$class) {
                throw new ToolCallException("Unknown element type: $ownerType");
            }
            $criteria['ownerType'] = $class;
        }

        if ($domain !== null && $domain !== '' && LinkIndex::normalizeDomain($domain) === null) {
            throw new ToolCallException('Invalid domain.');
        }

        $criteria['viewer'] = $user;
        $result = Plugin::getInstance()->linkIndex->find($criteria, $limit, $offset);
        $links = [];

        foreach ($result['references'] as $ref) {
            $owner = $ref->getSource();
            $root = $ref->getRootSource();

            if (!$owner || !$root || !$this->canView($user, $root)) {
                continue;
            }

            $row = $this->ownerRow($ref, $owner, $root) + [
                'type' => $ref->type,
                'label' => $ref->label,
            ];

            if ($ref->isElement) {
                $row['targetId'] = $ref->targetId;
                $row['status'] = $ref->getStatus();
            } else {
                $row['url'] = $ref->url;
                $row['status'] = $ref->getStatus();
            }

            $links[] = $row;
        }

        $next = $offset + $limit;

        return [
            'total' => $result['total'],
            'count' => count($links),
            'nextOffset' => $next < $result['total'] ? $next : null,
            'links' => $links,
        ];
    }

    /**
     * @param string[] $checks
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'freelink_check_links',
        description: 'Reports broken FreeLink links from stored state only: element-link targets (deleted, trashed, disabled, not yet published, expired, no URL, missing in the site, outside the field’s selection condition), unsafe or malformed URLs, and the stored results of FreeLink’s HTTP link checks (broken, error, refused). Never makes a network request; HTTP checks run from the queue (CP “Check links now” or `php craft freelink/links/check`).',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    public function checkLinks(
        #[Schema(description: 'FreeLink field handle.')]
        ?string $field = null,
        ?int $siteId = null,
        #[Schema(description: 'Only links held by this element (or nested inside it).')]
        ?int $ownerId = null,
        #[Schema(items: ['type' => 'string', 'enum' => self::CHECKS], description: 'Checks to run; default all.')]
        array $checks = [],
        #[Schema(minimum: 1, maximum: 100)]
        int $limit = 50,
        #[Schema(minimum: 0, description: 'Index rows to skip (use nextOffset from the previous call).')]
        int $offset = 0,
    ): array {
        $user = $this->actor->user();
        $checks = $checks ? array_values(array_intersect($checks, self::CHECKS)) : self::CHECKS;
        $wanted = array_flip($checks);

        $index = Plugin::getInstance()->linkIndex;
        $rows = $index->createQuery([
            'viewer' => $user,
            'field' => $field,
            'siteId' => $siteId,
            'sourceId' => $ownerId,
        ])
            ->orderBy(['i.id' => SORT_ASC])
            ->offset($offset)
            ->limit(self::SCAN_LIMIT)
            ->all();

        $references = array_map(fn(array $row) => LinkReference::fromRow($row), $rows);

        $findings = [];
        $examined = 0;

        foreach (array_chunk($references, 100) as $chunk) {
            $index->preloadElements($chunk);

            foreach ($chunk as $ref) {
                $examined++;

                $root = $ref->getRootSource();
                if (!$root || !$this->canView($user, $root)) {
                    continue;
                }

                foreach ($this->findingsFor($ref, $wanted) as $finding) {
                    $findings[] = $finding;
                }

                if (count($findings) >= $limit) {
                    break 2;
                }
            }
        }

        $more = $examined < count($references) || count($references) === self::SCAN_LIMIT;

        return [
            'checked' => $examined,
            'count' => count($findings),
            'nextOffset' => $more ? $offset + $examined : null,
            'findings' => array_slice($findings, 0, $limit),
        ];
    }

    /**
     * @param array<string, int> $wanted
     * @return list<array<string, mixed>>
     */
    private function findingsFor(LinkReference $ref, array $wanted): array
    {
        $found = [];
        $add = function(string $check, string $message, array $extra = []) use (&$found, $ref, $wanted) {
            if (!isset($wanted[$check])) {
                return;
            }
            $owner = $ref->getSource();
            $found[] = [
                'check' => $check,
                'ownerType' => $owner ? $owner::class : null,
                'ownerId' => $ref->sourceId,
                'ownerSiteId' => $ref->sourceSiteId,
                'rootOwnerId' => $ref->rootSourceId,
                'field' => $ref->getFieldHandle(),
                'position' => $ref->sortOrder,
                'linkType' => $ref->type,
                'message' => $message,
            ] + $extra;
        };

        if ($ref->isElement) {
            $target = ['targetId' => $ref->targetId];

            switch ($ref->elementStatus) {
                case 'missing':
                case 'trashed':
                    $add('missingTarget', $ref->elementStatus === 'trashed' ? 'The target is in the trash.' : 'The target was deleted.', $target);
                    return $found;
                case 'disabled':
                    $add('disabledTarget', 'The target is disabled.', $target);
                    break;
                case 'pending':
                    $add('pendingTarget', 'The target isn’t published yet.', $target);
                    break;
                case 'expired':
                    $add('expiredTarget', 'The target has expired.', $target);
                    break;
            }

            $element = $ref->getTarget();

            if (!$element) {
                $add('targetSiteMismatch', 'The target doesn’t exist in the site the link points at.', $target + ['targetSiteId' => $ref->targetSiteId]);
                return $found;
            }

            if ($ref->elementStatus === 'live' && isset($wanted['noTargetUrl']) && $element->getUrl() === null) {
                $add('noTargetUrl', 'The target has no URL, so the link renders nothing.', $target);
            }

            if (isset($wanted['disallowedBySelectionCondition'])) {
                $field = $ref->getField();
                $link = $ref->getLink();
                $owner = $ref->getSource();

                if ($field instanceof FreeLinkField && $link instanceof ElementLink && !$field->linkMatchesSelectionCondition($link, $owner)) {
                    $add('disallowedBySelectionCondition', 'The target no longer matches the field’s selection condition.', $target);
                }
            }

            return $found;
        }

        // URL-ish links.
        if ($ref->url === null || ($ref->type === 'url' && $ref->host === null)) {
            if (isset($wanted['unsafeUrl']) || isset($wanted['malformedUrl'])) {
                $link = $ref->getLink();
                $raw = $link?->value;

                if ($raw !== null && UrlSafety::isUnsafeUrl($raw)) {
                    $add('unsafeUrl', 'The URL uses a script scheme and is never rendered.', ['value' => mb_substr($raw, 0, 200)]);
                } elseif ($link && $ref->type === 'url' && !$link->validate(['value'])) {
                    $add('malformedUrl', 'The URL isn’t valid.', ['value' => mb_substr((string)$raw, 0, 200)]);
                }
            }

            return $found;
        }

        $http = [
            'value' => $ref->url,
            'httpCode' => $ref->httpCode,
            'checkedAt' => $ref->checkedAt?->format(DATE_ATOM),
        ];

        match ($ref->checkStatus) {
            'broken' => $add('httpBroken', 'The URL answered HTTP ' . $ref->httpCode . '.', $http),
            'error' => $add('httpError', 'The last check failed: ' . ($ref->checkError ?? 'error') . '.', $http),
            'blocked' => $add('httpBlocked', 'The checker refused this URL: ' . ($ref->checkError ?? 'not a public address') . '.', $http),
            default => null,
        };

        return $found;
    }

    /**
     * @return array<string, mixed>
     */
    private function ownerRow(LinkReference $ref, ElementInterface $owner, ElementInterface $root): array
    {
        return [
            'ownerType' => $owner::class,
            'ownerId' => $ref->sourceId,
            'ownerSiteId' => $ref->sourceSiteId,
            'ownerTitle' => (string)$owner,
            'rootOwnerId' => $ref->rootSourceId,
            'rootOwnerTitle' => (string)$root,
            'field' => $ref->getFieldHandle(),
            'position' => $ref->sortOrder,
        ];
    }

    /**
     * Links to a target held by drafts (from `freelink_links`, which also stores draft values).
     *
     * @return list<array<string, mixed>>
     */
    private function draftLinks(User $user, int $targetId, ?string $field, ?int $siteId): array
    {
        $query = (new Query())
            ->select(['l.fieldId', 'l.ownerId', 'l.ownerSiteId', 'l.sortOrder'])
            ->from(['l' => '{{%freelink_links}}'])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[l.ownerId]]')
            ->where(['l.targetId' => $targetId, 'e.dateDeleted' => null, 'e.revisionId' => null])
            ->andWhere(['not', ['e.draftId' => null]])
            ->limit(100);

        if ($siteId) {
            $query->andWhere(['l.ownerSiteId' => $siteId]);
        }

        if ($field) {
            $f = Craft::$app->getFields()->getFieldByHandle($field);
            $query->andWhere(['l.fieldId' => $f?->id ?? 0]);
        }

        $rows = [];
        foreach ($query->all() as $row) {
            $owner = Craft::$app->getElements()->getElementById((int)$row['ownerId'], null, (int)$row['ownerSiteId']);

            if (!$owner || !$this->canView($user, $owner)) {
                continue;
            }

            $rows[] = [
                'ownerType' => $owner::class,
                'ownerId' => (int)$row['ownerId'],
                'ownerSiteId' => (int)$row['ownerSiteId'],
                'ownerTitle' => (string)$owner,
                'field' => Craft::$app->getFields()->getFieldById((int)$row['fieldId'])?->handle,
                'position' => (int)$row['sortOrder'],
                'linkType' => null,
                'label' => null,
                'draft' => true,
            ];
        }

        return $rows;
    }

    private function viewableElement(User $user, int $id, ?int $siteId): ?ElementInterface
    {
        $element = Craft::$app->getElements()->getElementById($id, null, $siteId);

        return $element && $this->canView($user, $element) ? $element : null;
    }

    private function canView(User $user, ElementInterface $element): bool
    {
        return Authorization::allows($user, 'view', $element);
    }

    /**
     * @return class-string<ElementInterface>|null
     */
    private function elementTypeByHandle(string $handle): ?string
    {
        $elements = Craft::$app->getElements();
        $handle = strtolower($handle);

        foreach (array_unique([$handle, preg_replace('/ies$/', 'y', $handle), preg_replace('/s$/', '', $handle)]) as $candidate) {
            if ($candidate && ($class = $elements->getElementTypeByRefHandle($candidate))) {
                return $class;
            }
        }

        return null;
    }
}
