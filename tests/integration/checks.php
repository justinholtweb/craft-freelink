<?php
/**
 * FreeLink integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-freelink/tests/integration/checks.php
 *
 * Covers what the unit suite can't: selection conditions built by Craft's condition service, applied
 * to a real element query (what the select modal does) and enforced against real entries when an
 * owner is validated and saved.
 *
 * Uses the harness's `freelinkItTest` field on the Test Entries entry type. Every setting change is
 * made in memory on the field instance the entry's layout uses, so project config is never touched.
 * The one owner entry it saves is hard-deleted at the end.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\base\Element;
use craft\elements\Asset;
use craft\elements\conditions\assets\AssetCondition;
use craft\elements\conditions\entries\EntryCondition;
use craft\elements\conditions\entries\SectionConditionRule;
use craft\elements\Entry;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use justinholtweb\freelink\base\ElementLink;
use justinholtweb\freelink\fields\FreeLinkField;
use justinholtweb\freelink\models\LinkCollection;
use justinholtweb\freelink\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

/**
 * What the condition builder posts for "section is one of …": the base config JSON-encoded under
 * `config`, the rules alongside.
 *
 * @param string[] $sectionUids
 * @return array<string, mixed>
 */
function sectionConditionPost(array $sectionUids): array
{
    return [
        'class' => EntryCondition::class,
        'config' => Json::encode(['elementType' => Entry::class, 'fieldContext' => 'global']),
        'conditionRules' => [[
            'class' => SectionConditionRule::class,
            'uid' => StringHelper::UUID(),
            'operator' => 'in',
            'values' => $sectionUids,
        ]],
    ];
}

/**
 * A POSTed single-link value pointing an Entry link at an element ID, in the CP form's shape.
 *
 * @return array<string, mixed>
 */
function entryLinkPost(int $targetId): array
{
    return ['type' => 'entry', 'elements' => ['entry' => [$targetId]], 'values' => []];
}

if (!Plugin::getInstance()) {
    echo "FreeLink isn't installed in this project.\n";
    exit(1);
}

// region Fixtures

$primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;

// Two sections with at least one entry each: one the condition allows, one it doesn't.
$allowed = null;
$blocked = null;
foreach (Craft::$app->getEntries()->getAllSections() as $candidate) {
    $entry = Entry::find()->sectionId($candidate->id)->siteId($primarySiteId)->status(null)->one();
    if (!$entry) {
        continue;
    }
    if (!$allowed) {
        $allowed = [$candidate, $entry];
    } elseif (!$blocked) {
        $blocked = [$candidate, $entry];
        break;
    }
}

if (!$allowed || !$blocked) {
    echo "Need two sections with entries in the harness.\n";
    exit(1);
}

[$allowedSection, $allowedEntry] = $allowed;
[$blockedSection, $blockedEntry] = $blocked;

$testSection = Craft::$app->getEntries()->getSectionByHandle('testEntries');
$testType = $testSection?->getEntryTypes()[0] ?? null;

if (!$testType || !$testType->getFieldLayout()->getFieldByHandle('freelinkItTest')) {
    echo "Need the testEntries section with the freelinkItTest field.\n";
    exit(1);
}

/**
 * A fresh owner entry and the field instance its layout validates with, configured in memory.
 *
 * @param array<string, mixed>|null $condition
 * @return array{0: Entry, 1: FreeLinkField}
 */
function owner(?array $condition): array
{
    global $testSection, $testType, $primarySiteId;

    $entry = new Entry([
        'sectionId' => $testSection->id,
        'typeId' => $testType->id,
        'siteId' => $primarySiteId,
        'title' => 'FreeLink condition check',
    ]);

    /** @var FreeLinkField $field */
    $field = $entry->getFieldLayout()->getFieldByHandle('freelinkItTest');
    $field->linkTypes = [
        'url' => ['enabled' => true, 'sortOrder' => 0],
        'entry' => array_filter([
            'enabled' => true,
            'sortOrder' => 1,
            'sources' => '*',
            'selectionCondition' => $condition,
        ], fn($v) => $v !== null),
    ];
    $field->multipleLinks = false;

    return [$entry, $field];
}

function validateOwner(Entry $entry): array
{
    $entry->setScenario(Element::SCENARIO_LIVE);
    $entry->validate();

    return $entry->getErrors('freelinkItTest');
}

// endregion

section('Settings');

check('the builder payload is stored as a condition config with its rule', function() use ($allowedSection) {
    [, $field] = owner(sectionConditionPost([$allowedSection->uid]));
    $config = $field->getSettings()['linkTypes']['entry']['selectionCondition'] ?? null;

    if (!is_array($config)) {
        return 'no selectionCondition in settings';
    }

    return ($config['class'] ?? null) === EntryCondition::class
        && !isset($config['config'])
        && ($config['conditionRules'][0]['class'] ?? null) === SectionConditionRule::class
        && ($config['conditionRules'][0]['values'] ?? null) === [$allowedSection->uid]
        ?: Json::encode($config);
});

check('the stored config builds the same condition back', function() use ($allowedSection) {
    [, $field] = owner(sectionConditionPost([$allowedSection->uid]));
    $stored = $field->getSettings()['linkTypes']['entry']['selectionCondition'];

    [, $reloaded] = owner($stored);
    $condition = $reloaded->getSelectionCondition('entry');

    return $condition instanceof EntryCondition && count($condition->getConditionRules()) === 1;
});

check('a condition with no rules is dropped from settings', function() {
    $empty = sectionConditionPost([]);
    $empty['conditionRules'] = [];
    [, $field] = owner($empty);

    return !isset($field->getSettings()['linkTypes']['entry']['selectionCondition'])
        && $field->getSelectionCondition('entry') === null;
});

check('a condition for another element type is ignored', function() {
    [, $field] = owner([
        'class' => AssetCondition::class,
        'elementType' => Asset::class,
        'conditionRules' => [['class' => craft\elements\conditions\assets\FilenameConditionRule::class, 'operator' => 'contains', 'value' => 'x']],
    ]);

    return $field->getSelectionCondition('entry') === null;
});

check('an unknown condition class is ignored, not fatal', function() {
    [, $field] = owner(['class' => 'Not\\A\\Condition', 'conditionRules' => []]);

    return $field->getSelectionCondition('entry') === null
        && !isset($field->getSettings()['linkTypes']['entry']['selectionCondition']);
});

check('a simple link type never has a selection condition', function() use ($allowedSection) {
    [, $field] = owner(null);
    $field->linkTypes['url']['selectionCondition'] = sectionConditionPost([$allowedSection->uid]);

    return $field->getSelectionCondition('url') === null;
});

check('comma-joined sources from older settings come back as a list', function() {
    [, $field] = owner(null);
    $field->linkTypes['entry']['sources'] = 'section:aaa,section:bbb';

    return $field->getSettings()['linkTypes']['entry']['sources'] === ['section:aaa', 'section:bbb'];
});

check('settings show a condition builder per element type and none for simple types', function() {
    [, $field] = owner(null);

    // The condition builder reads request headers (htmx), which a console request doesn't have.
    // Render it under a web request, as the field settings screen does.
    $consoleRequest = Craft::$app->getRequest();
    Craft::$app->set('request', new craft\web\Request(['cookieValidationKey' => 'freelink-checks']));
    try {
        $html = $field->getSettingsHtml();
    } finally {
        Craft::$app->set('request', $consoleRequest);
    }

    return str_contains($html, 'name="linkTypes[entry][selectionCondition][class]"')
        && str_contains($html, 'data-freelink-condition-for="entry"')
        && !str_contains($html, 'data-freelink-condition-for="url"')
        && str_contains($html, 'Selectable Entries Condition');
});

section('Select modal');

check('the condition narrows an entry query to the allowed section (what the modal runs)', function() use ($allowedSection, $blockedSection) {
    [, $field] = owner(sectionConditionPost([$allowedSection->uid]));
    $condition = $field->getSelectionCondition('entry');

    $query = Entry::find()->status(null)->site('*');
    $condition->modifyQuery($query);
    $sectionIds = array_unique($query->select(['entries.sectionId'])->column());

    $all = Entry::find()->sectionId($allowedSection->id)->status(null)->site('*')->count();

    return $sectionIds === [(int)$allowedSection->id] || $sectionIds === [(string)$allowedSection->id]
        ? ((int)$query->count() === (int)$all ?: "count {$query->count()} vs $all")
        : 'sections in result: ' . implode(',', $sectionIds) . " (blocked {$blockedSection->id})";
});

check('the input hands the condition and reference element to the element select modal', function() use ($allowedSection) {
    [$entry, $field] = owner(sectionConditionPost([$allowedSection->uid]));
    $view = Craft::$app->getView();
    $view->startJsBuffer();
    $field->getInputHtml(new LinkCollection(), $entry);
    $js = (string)$view->clearJsBuffer(false);

    return str_contains($js, 'SectionConditionRule') && str_contains($js, $allowedSection->uid) && str_contains($js, '"referenceElementSiteId"')
        ?: 'no condition in the element select JS';
});

check('the input leaves the modal unfiltered when there is no condition', function() {
    [$entry, $field] = owner(null);
    $view = Craft::$app->getView();
    $view->startJsBuffer();
    $field->getInputHtml(new LinkCollection(), $entry);
    $js = (string)$view->clearJsBuffer(false);

    return str_contains($js, '"condition":null') ?: 'condition present without a setting';
});

check('a custom type label reaches the input', function() {
    [$entry, $field] = owner(null);
    $field->linkTypes['entry']['label'] = 'Internal page';

    return str_contains($field->getInputHtml(new LinkCollection(), $entry), 'Internal page');
});

section('Validation (a POST can name any element ID)');

check('a link to an entry the condition allows validates', function() use ($allowedSection, $allowedEntry) {
    [$entry, $field] = owner(sectionConditionPost([$allowedSection->uid]));
    $entry->setFieldValue('freelinkItTest', entryLinkPost($allowedEntry->id));
    $errors = validateOwner($entry);

    return $errors === [] ?: implode(' | ', $errors);
});

check('a forged link to an entry outside the condition is refused', function() use ($allowedSection, $blockedEntry) {
    [$entry] = owner(sectionConditionPost([$allowedSection->uid]));
    $entry->setFieldValue('freelinkItTest', entryLinkPost($blockedEntry->id));
    $errors = validateOwner($entry);

    return count($errors) === 1 && str_contains($errors[0], 'isn’t allowed here') ?: Json::encode($errors);
});

check('a link to an element ID that does not exist is refused when a condition is set', function() use ($allowedSection) {
    [$entry] = owner(sectionConditionPost([$allowedSection->uid]));
    $missing = (int)(new craft\db\Query())->from('{{%elements}}')->max('id') + 100000;
    $entry->setFieldValue('freelinkItTest', entryLinkPost($missing));

    return validateOwner($entry) !== [];
});

check('without a condition the same entry is accepted (no behaviour change)', function() use ($blockedEntry) {
    [$entry] = owner(null);
    $entry->setFieldValue('freelinkItTest', entryLinkPost($blockedEntry->id));

    return validateOwner($entry) === [];
});

check('linkMatchesSelectionCondition() answers for a link on its own', function() use ($allowedSection, $allowedEntry, $blockedEntry) {
    [$entry, $field] = owner(sectionConditionPost([$allowedSection->uid]));
    $links = Plugin::getInstance()->links;

    /** @var ElementLink $ok */
    $ok = $links->createLink(['type' => 'entry', 'targetId' => $allowedEntry->id]);
    /** @var ElementLink $no */
    $no = $links->createLink(['type' => 'entry', 'targetId' => $blockedEntry->id]);
    /** @var ElementLink $empty */
    $empty = $links->createLink(['type' => 'entry']);

    return $field->linkMatchesSelectionCondition($ok, $entry)
        && !$field->linkMatchesSelectionCondition($no, $entry)
        && $field->linkMatchesSelectionCondition($empty, $entry);
});

check('a matching link saves, and its relation row points at the target', function() use ($allowedSection, $allowedEntry) {
    [$entry] = owner(sectionConditionPost([$allowedSection->uid]));
    $entry->setFieldValue('freelinkItTest', entryLinkPost($allowedEntry->id));

    if (!Craft::$app->getElements()->saveElement($entry)) {
        return 'save failed: ' . Json::encode($entry->getErrors());
    }

    try {
        $targetId = (new craft\db\Query())
            ->select('targetId')
            ->from('{{%freelink_links}}')
            ->where(['ownerId' => $entry->id])
            ->scalar();

        return (int)$targetId === (int)$allowedEntry->id ?: "relation targetId: " . var_export($targetId, true);
    } finally {
        Craft::$app->getElements()->deleteElement($entry, true);
    }
});

check('a refused link stops the save', function() use ($allowedSection, $blockedEntry) {
    [$entry] = owner(sectionConditionPost([$allowedSection->uid]));
    $entry->setFieldValue('freelinkItTest', entryLinkPost($blockedEntry->id));
    $entry->setScenario(Element::SCENARIO_LIVE);

    $saved = Craft::$app->getElements()->saveElement($entry);
    if ($saved) {
        Craft::$app->getElements()->deleteElement($entry, true);
        return 'saved a link the condition refuses';
    }

    return $entry->id === null;
});

echo "\n$passed passed, $failed failed\n";

exit($failed === 0 ? 0 : 1);
