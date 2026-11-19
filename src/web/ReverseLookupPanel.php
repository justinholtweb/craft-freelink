<?php

namespace justinholtweb\freelink\web;

use Craft;
use craft\base\ElementInterface;
use craft\helpers\Cp;
use craft\helpers\Html;
use justinholtweb\freelink\Plugin;

/**
 * The "Linked from" panel on element edit screens: FreeLink links in live content that point at the
 * element being edited.
 *
 * Read-only markup with no form inputs, so nothing joins the host element's form.
 */
class ReverseLookupPanel
{
    public const LIMIT = 5;

    public static function html(ElementInterface $element): string
    {
        $user = Craft::$app->getUser();
        $identity = $user->getIdentity();

        if (!$identity || !$user->checkPermission(Plugin::PERMISSION_VIEW_LINKS)) {
            return '';
        }

        $targetId = $element->getCanonicalId() ?: $element->id;

        if (!$targetId) {
            return '';
        }

        $index = Plugin::getInstance()->linkIndex;
        $total = $index->countTo((int)$targetId);

        if ($total === 0) {
            return '';
        }

        $result = $index->find(['targetId' => $targetId], self::LIMIT);
        $elements = Craft::$app->getElements();
        $items = '';
        $hidden = 0;
        $seen = [];

        foreach ($result['references'] as $ref) {
            $root = $ref->getRootSource();

            if (!$root || !$elements->canView($root, $identity)) {
                $hidden++;
                continue;
            }

            $key = $root->id . ':' . $ref->fieldId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $field = $ref->getField();
            $items .= Html::tag('li',
                Cp::elementChipHtml($root) .
                ($field ? Html::tag('div', Html::encode($field->name), ['class' => 'smalltext light']) : ''),
                ['style' => 'margin-bottom: var(--s);'],
            );
        }

        $body = $items !== '' ? Html::tag('ul', $items, ['class' => 'freelink-linked-from']) : '';

        if ($hidden > 0) {
            $body .= Html::tag('p', Html::encode(Craft::t('freelink', '{count, plural, =1{# link} other{# links}} from elements you can’t view.', [
                'count' => $hidden,
            ])), ['class' => 'smalltext light']);
        }

        $body .= Html::tag('p', Html::a(
            Html::encode(Craft::t('freelink', 'View all {count, number} {count, plural, =1{link} other{links}}', ['count' => $total])),
            Plugin::linksUrl(['target' => $targetId]),
        ), ['class' => 'smalltext']);

        return Html::tag('fieldset',
            Html::tag('legend', Html::encode(Craft::t('freelink', 'Linked from')), ['class' => 'h6']) .
            Html::tag('div', Html::tag('div', $body, ['style' => 'padding: var(--s) var(--m);']), ['class' => 'meta']),
            ['id' => 'freelink-linked-from'],
        );
    }
}
