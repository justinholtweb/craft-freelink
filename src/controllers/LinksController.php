<?php

namespace justinholtweb\freelink\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\freelink\fields\FreeLinkField;
use justinholtweb\freelink\jobs\CheckLinks;
use justinholtweb\freelink\jobs\IndexLinks;
use justinholtweb\freelink\migrations\Install;
use justinholtweb\freelink\Plugin;
use justinholtweb\freelink\services\LinkIndex;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The Links screen (every link on the site, filterable) and its actions.
 */
class LinksController extends Controller
{
    public const PAGE_SIZE = 50;

    public const STATUS_FILTERS = ['all', 'problems', 'ok', 'unchecked'];

    /**
     * `admin/freelink` — the section's landing page.
     */
    public function actionSectionIndex(): Response
    {
        $this->requireCpRequest();
        $user = Craft::$app->getUser();

        if ($user->checkPermission(Plugin::PERMISSION_VIEW_LINKS)) {
            return $this->redirect('freelink/links');
        }

        if ($user->getIsAdmin()) {
            return $this->redirect('freelink/migrations');
        }

        throw new ForbiddenHttpException('User is not authorized to perform this action.');
    }

    public function actionIndex(): Response
    {
        return $this->renderLinks('links', null);
    }

    /**
     * The "Broken Links" subnav item: the Links screen filtered to problems.
     */
    public function actionBroken(): Response
    {
        return $this->renderLinks('broken', 'problems');
    }

    private function renderLinks(string $subnav, ?string $defaultStatus): Response
    {
        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_VIEW_LINKS);

        $request = $this->request;
        $plugin = Plugin::getInstance();
        $index = $plugin->linkIndex;

        $status = (string)$request->getQueryParam('status', $defaultStatus ?? 'all');
        if (!in_array($status, self::STATUS_FILTERS, true)) {
            $status = 'all';
        }

        $filters = [
            'type' => self::stringParam($request->getQueryParam('type')),
            'field' => self::intParam($request->getQueryParam('field')),
            'site' => self::intParam($request->getQueryParam('site')),
            'source' => self::stringParam($request->getQueryParam('source')),
            'section' => self::intParam($request->getQueryParam('section')),
            'domain' => LinkIndex::normalizeDomain($request->getQueryParam('domain')),
            'target' => self::intParam($request->getQueryParam('target')),
            'status' => $status,
        ];

        $page = max(1, (int)$request->getQueryParam('page', 1));

        // Only element types FreeLink actually has sources for are accepted as a filter.
        $sourceTypes = $this->sourceTypes();
        if ($filters['source'] !== null && !isset($sourceTypes[$filters['source']])) {
            $filters['source'] = null;
        }

        $user = Craft::$app->getUser()->getIdentity();

        // Rows whose source the user can't view are left out in the query, so neither the rows nor
        // the totals and pagination reveal them.
        $result = $index->find([
            'viewer' => $user,
            'type' => $filters['type'],
            'fieldId' => $filters['field'],
            'siteId' => $filters['site'],
            'sourceType' => $filters['source'],
            'sectionId' => $filters['section'],
            'domain' => $filters['domain'],
            'targetId' => $filters['target'],
            'status' => $filters['status'],
        ], self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);

        $total = $result['total'];
        $elements = Craft::$app->getElements();

        $rows = [];
        foreach ($result['references'] as $ref) {
            $root = $ref->getRootSource();
            $target = $ref->getTarget();

            // Defence in depth: the query already scoped rows to viewable sources; a source that
            // still isn't viewable (an EVENT_AUTHORIZE_VIEW veto) is dropped, never half-shown.
            if (!$root || !$user || !$elements->canView($root, $user)) {
                continue;
            }

            $rows[] = [
                'ref' => $ref,
                'root' => $root,
                'nested' => $ref->sourceId !== $ref->rootSourceId,
                'target' => $target && $elements->canView($target, $user) ? $target : null,
                'field' => $ref->getField(),
            ];
        }

        $baseParams = array_filter([
            'type' => $filters['type'],
            'field' => $filters['field'],
            'site' => $filters['site'],
            'source' => $filters['source'],
            'section' => $filters['section'],
            'domain' => $filters['domain'],
            'target' => $filters['target'],
            'status' => $filters['status'] !== ($defaultStatus ?? 'all') ? $filters['status'] : null,
        ], fn($v) => $v !== null && $v !== '');

        $path = $subnav === 'broken' ? 'freelink/broken' : 'freelink/links';
        $first = $total ? ($page - 1) * self::PAGE_SIZE + 1 : 0;
        $last = min($total, $page * self::PAGE_SIZE);

        $targetElement = null;
        if ($filters['target']) {
            $candidate = $elements->getElementById($filters['target']);
            $targetElement = $candidate && $user && $elements->canView($candidate, $user) ? $candidate : null;
        }

        $fields = array_filter(
            Craft::$app->getFields()->getAllFields(),
            fn($f) => $f instanceof FreeLinkField,
        );

        return $this->renderTemplate('freelink/_cp/links', [
            'subnav' => $subnav,
            'path' => $path,
            'filters' => $filters,
            'baseParams' => $baseParams,
            'rows' => $rows,
            'pageInfo' => [
                'first' => $first,
                'last' => $last,
                'total' => $total,
                'prevUrl' => $page > 1 ? UrlHelper::cpUrl($path, $baseParams + ['page' => $page - 1]) : null,
                'nextUrl' => $last < $total ? UrlHelper::cpUrl($path, $baseParams + ['page' => $page + 1]) : null,
            ],
            'typeOptions' => $this->typeOptions(),
            'fieldOptions' => array_map(fn($f) => ['label' => $f->name, 'value' => $f->id], array_values($fields)),
            'siteOptions' => Craft::$app->getIsMultiSite()
                ? array_map(fn($s) => ['label' => $s->getName(), 'value' => $s->id], Craft::$app->getSites()->getAllSites())
                : [],
            'sourceOptions' => array_map(fn($label, $class) => ['label' => $label, 'value' => $class], $sourceTypes, array_keys($sourceTypes)),
            'sectionOptions' => array_map(fn($s) => ['label' => $s->name, 'value' => $s->id], Craft::$app->getEntries()->getAllSections()),
            'targetElement' => $targetElement,
            'indexBuilt' => $index->isBuilt(),
            'settings' => $plugin->getSettings(),
            'canCheck' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_CHECK_LINKS),
            'isAdmin' => Craft::$app->getUser()->getIsAdmin(),
            'lastChecked' => ($max = (new Query())->from(Install::TABLE_CHECKS)->max('checkedAt'))
                ? new \DateTime((string)$max, new \DateTimeZone('UTC'))
                : null,
        ]);
    }

    /**
     * Queues a check of the stored URL links. Takes no URL — only what the index holds is checked.
     */
    public function actionQueueCheck(): ?Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_CHECK_LINKS);

        if (!Plugin::getInstance()->getSettings()->enableExternalChecks) {
            $this->setFailFlash(Craft::t('freelink', 'External link checks are turned off in the FreeLink settings.'));
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getQueue()->push(new CheckLinks([
            'force' => (bool)$this->request->getBodyParam('force', false),
        ]));

        $this->setSuccessFlash(Craft::t('freelink', 'Link check queued.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Queues a rebuild of the link index.
     */
    public function actionReindex(): ?Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requireAdmin(false);

        Craft::$app->getQueue()->push(new IndexLinks());
        $this->setSuccessFlash(Craft::t('freelink', 'Link index rebuild queued.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function typeOptions(): array
    {
        $options = [];

        foreach (Plugin::getInstance()->links->getAvailableTypes() as $handle => $class) {
            $options[] = ['label' => $class::displayName(), 'value' => $handle];
        }

        return $options;
    }

    /**
     * Element types that are root sources of indexed links: class => plural label.
     *
     * @return array<string, string>
     */
    private function sourceTypes(): array
    {
        $classes = (new Query())
            ->select(['r.type'])
            ->distinct()
            ->from(['i' => Install::TABLE_INDEX])
            ->innerJoin(['r' => Table::ELEMENTS], '[[r.id]] = [[i.rootOwnerId]]')
            ->column();

        $types = [];
        foreach ($classes as $class) {
            if (is_string($class) && class_exists($class) && is_subclass_of($class, ElementInterface::class)) {
                $types[$class] = $class::pluralDisplayName();
            }
        }

        asort($types);

        return $types;
    }

    private static function intParam(mixed $value): ?int
    {
        return is_numeric($value) && (int)$value > 0 ? (int)$value : null;
    }

    private static function stringParam(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= 255 ? $value : null;
    }
}
