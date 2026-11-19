<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Http\Controllers;

use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Url;
use DateTime;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use JustinHoltWeb\FreeLink\Database\Tables;
use JustinHoltWeb\FreeLink\Fields\FreeLinkField;
use JustinHoltWeb\FreeLink\Jobs\CheckLinks;
use JustinHoltWeb\FreeLink\Jobs\IndexLinks;
use JustinHoltWeb\FreeLink\Plugin;
use JustinHoltWeb\FreeLink\Services\LinkIndex;
use JustinHoltWeb\FreeLink\Services\Links;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * The Links screen (every link on the site, filterable) and its actions.
 */
class LinksController
{
    use RespondsWithFlash;

    public const int PAGE_SIZE = 50;

    public const array STATUS_FILTERS = ['all', 'problems', 'ok', 'unchecked'];

    /**
     * `admin/freelink` — the section's landing page.
     */
    public function sectionIndex(): RedirectResponse
    {
        $user = currentUser();

        if ($user && Gate::forUser($user)->allows(Plugin::PERMISSION_VIEW_LINKS)) {
            return redirect(cp_url('freelink/links'));
        }

        abort_unless($user?->isAdmin(), 403, 'User is not authorized to perform this action.');

        return redirect(cp_url('freelink/migrations'));
    }

    public function index(Request $request, LinkIndex $index, Links $links): CpScreenResponse
    {
        return $this->renderLinks($request, $index, $links, 'links', null);
    }

    /**
     * The "Broken Links" subnav item: the Links screen filtered to problems.
     */
    public function broken(Request $request, LinkIndex $index, Links $links): CpScreenResponse
    {
        return $this->renderLinks($request, $index, $links, 'broken', 'problems');
    }

    private function renderLinks(Request $request, LinkIndex $index, Links $links, string $subnav, ?string $defaultStatus): CpScreenResponse
    {
        $status = (string) ($request->query('status') ?? ($defaultStatus ?? 'all'));
        if (! in_array($status, self::STATUS_FILTERS, true)) {
            $status = 'all';
        }

        $filters = [
            'type' => self::stringParam($request->query('type')),
            'field' => self::intParam($request->query('field')),
            'site' => self::intParam($request->query('site')),
            'source' => self::stringParam($request->query('source')),
            'section' => self::intParam($request->query('section')),
            'domain' => LinkIndex::normalizeDomain($request->query('domain')),
            'target' => self::intParam($request->query('target')),
            'status' => $status,
        ];

        $page = max(1, (int) ($request->query('page') ?? 1));

        // Only element types FreeLink actually has sources for are accepted as a filter.
        $sourceTypes = $this->sourceTypes();
        if ($filters['source'] !== null && ! isset($sourceTypes[$filters['source']])) {
            $filters['source'] = null;
        }

        $result = $index->find([
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
        $user = currentUser();
        $gate = $user ? Gate::forUser($user) : null;

        $rows = [];
        foreach ($result['references'] as $ref) {
            $root = $ref->getRootSource();
            $target = $ref->getTarget();

            $rows[] = [
                'ref' => $ref,
                'root' => $root && $gate?->allows('view', $root) ? $root : null,
                'nested' => $ref->sourceId !== $ref->rootSourceId,
                'target' => $target && $gate?->allows('view', $target) ? $target : null,
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
        ], fn ($v) => $v !== null && $v !== '');

        $path = $subnav === 'broken' ? 'freelink/broken' : 'freelink/links';
        $first = $total ? ($page - 1) * self::PAGE_SIZE + 1 : 0;
        $last = min($total, $page * self::PAGE_SIZE);

        $targetElement = null;
        if ($filters['target']) {
            $candidate = Elements::getElementById($filters['target']);
            $targetElement = $candidate && $gate?->allows('view', $candidate) ? $candidate : null;
        }

        $fields = Fields::getAllFields()->filter(fn ($f) => $f instanceof FreeLinkField)->values();
        $lastChecked = DB::table(Tables::CHECKS)->max('checkedAt');
        $title = $subnav === 'broken' ? t('Broken Links', category: 'freelink') : t('Links', category: 'freelink');

        return new CpScreenResponse()
            ->title($title)
            ->selectedSubnavItem($subnav)
            ->contentTemplate('freelink/links', [
                'path' => $path,
                'filters' => $filters,
                'baseParams' => $baseParams,
                'rows' => $rows,
                'pageInfo' => [
                    'first' => $first,
                    'last' => $last,
                    'total' => $total,
                    'prevUrl' => $page > 1 ? Url::cpUrl($path, $baseParams + ['page' => $page - 1]) : null,
                    'nextUrl' => $last < $total ? Url::cpUrl($path, $baseParams + ['page' => $page + 1]) : null,
                ],
                'typeOptions' => collect($links->getAllTypes())->map(fn (string $class, string $handle) => ['label' => $class::displayName(), 'value' => $handle])->values()->all(),
                'fieldOptions' => $fields->map(fn ($f) => ['label' => (string) $f->name, 'value' => $f->id])->all(),
                'siteOptions' => Sites::getAllSites()->count() > 1
                    ? Sites::getAllSites()->map(fn ($s) => ['label' => $s->getName(), 'value' => $s->id])->values()->all()
                    : [],
                'sourceOptions' => collect($sourceTypes)->map(fn (string $label, string $class) => ['label' => $label, 'value' => $class])->values()->all(),
                'sectionOptions' => Sections::getAllSections()->map(fn ($s) => ['label' => (string) $s->name, 'value' => $s->id])->values()->all(),
                'targetElement' => $targetElement,
                'indexBuilt' => $index->isBuilt(),
                'settings' => Plugin::settings(),
                'canCheck' => (bool) $gate?->allows(Plugin::PERMISSION_CHECK_LINKS),
                'isAdmin' => (bool) $user?->isAdmin(),
                'lastChecked' => $lastChecked ? new DateTime((string) $lastChecked, new DateTimeZone('UTC')) : null,
            ]);
    }

    /**
     * Queues a check of the stored URL links. Takes no URL — only what the index holds is checked.
     */
    public function queueCheck(Request $request): Response
    {
        if (! Plugin::settings()->enableExternalChecks) {
            return $this->asFailure(t('External link checks are turned off in the FreeLink settings.', category: 'freelink'));
        }

        CheckLinks::dispatch($request->boolean('force'));

        return $this->asSuccess(t('Link check queued.', category: 'freelink'), redirect: cp_url('freelink/links'));
    }

    /**
     * Queues a rebuild of the link index.
     */
    public function reindex(): Response
    {
        IndexLinks::dispatch();

        return $this->asSuccess(t('Link index rebuild queued.', category: 'freelink'), redirect: cp_url('freelink/links'));
    }

    /**
     * Element types that are root sources of indexed links: class => plural label.
     *
     * @return array<string, string>
     */
    private function sourceTypes(): array
    {
        $classes = DB::table(Tables::INDEX.' as i')
            ->join(Table::ELEMENTS.' as r', 'r.id', '=', 'i.rootOwnerId')
            ->distinct()
            ->pluck('r.type')
            ->all();

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
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function stringParam(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= 255 ? $value : null;
    }
}
