<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Cp;

use CraftCms\Cms\Cp\Html\ElementHtml;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Support\Html;
use Illuminate\Support\Facades\Gate;
use JustinHoltWeb\FreeLink\Plugin;
use JustinHoltWeb\FreeLink\Services\LinkIndex;

use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * "Linked from" on element edit screens: FreeLink links in live content that point at the element
 * being edited.
 *
 * Craft 6's element editor builds its sidebar from Ui nodes with no plugin hook, so this is added to
 * the element's metadata (`ElementMetadataResolving`), which both the Ui editor and the legacy editor
 * show in the sidebar. Read-only markup with no form inputs, so nothing joins the element's form.
 */
final class ReverseLookupPanel
{
    public const int LIMIT = 5;

    /**
     * The panel's HTML, or null when there's nothing to show (no links, or no permission).
     */
    public static function html(ElementInterface $element): ?string
    {
        $user = currentUser();

        if (! $user || ! Gate::forUser($user)->allows(Plugin::PERMISSION_VIEW_LINKS)) {
            return null;
        }

        $targetId = $element->getCanonicalId() ?: $element->id;

        if (! $targetId) {
            return null;
        }

        $index = app(LinkIndex::class);
        $total = $index->countTo((int) $targetId);

        if ($total === 0) {
            return null;
        }

        $result = $index->find(['targetId' => $targetId], self::LIMIT);
        $chips = app(ElementHtml::class);
        $items = '';
        $hidden = 0;
        $seen = [];

        foreach ($result['references'] as $ref) {
            $root = $ref->getRootSource();

            if (! $root || ! Gate::forUser($user)->allows('view', $root)) {
                $hidden++;

                continue;
            }

            $key = $root->id.':'.$ref->fieldId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $field = $ref->getField();
            $items .= Html::tag('li',
                $chips->elementChipHtml($root).
                ($field ? Html::tag('div', Html::encode((string) $field->name), ['class' => 'smalltext light']) : ''),
                ['style' => ['margin-bottom' => 'var(--s, 6px)']],
            );
        }

        $body = $items !== '' ? Html::tag('ul', $items, ['class' => 'freelink-linked-from']) : '';

        if ($hidden > 0) {
            $body .= Html::tag('p', Html::encode(t('{count, plural, =1{# link} other{# links}} from elements you can’t view.', [
                'count' => $hidden,
            ], category: 'freelink')), ['class' => 'smalltext light']);
        }

        $body .= Html::tag('p', Html::a(
            Html::encode(t('View all {count, number} {count, plural, =1{link} other{links}}', ['count' => $total], category: 'freelink')),
            Plugin::linksUrl(['target' => $targetId]),
        ), ['class' => 'smalltext']);

        return Html::tag('div', $body, ['id' => 'freelink-linked-from']);
    }
}
