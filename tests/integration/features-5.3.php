<?php
/**
 * FreeLink 5.3 integration checks: link index, element-link status, reverse lookups, programmatic
 * targetId writes, validation, icons, Twig, URL checks + SSRF refusals against a local endpoint, and
 * GraphQL mutations / reverse lookups with scoped schemas.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php <plugin>/tests/integration/features-5.3.php
 *
 * Needs the harness's `freelinkItTest` field on Test Entries. The field's settings are changed for the
 * run (multi-link, more link types, icons) and restored at the end, in a fresh PHP process. Every
 * entry it creates is hard-deleted. A throwaway HTTP endpoint is started on 127.0.0.1:8099 for the
 * URL checks; only one request leaves the machine (to example.com).
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\base\Element;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Db;
use craft\models\GqlSchema;
use justinholtweb\freelink\fields\FreeLinkField;
use justinholtweb\freelink\migrations\Install;
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

$plugin = Plugin::getInstance();
if (!$plugin) {
    echo "FreeLink isn't installed.\n";
    exit(1);
}

$fieldsService = Craft::$app->getFields();
/** @var FreeLinkField $field */
$field = $fieldsService->getFieldByHandle('freelinkItTest');
$testSection = Craft::$app->getEntries()->getSectionByHandle('testEntries');
$testType = $testSection?->getEntryTypes()[0] ?? null;
$siteId = Craft::$app->getSites()->getPrimarySite()->id;

if (!$field instanceof FreeLinkField || !$testType) {
    echo "Need the testEntries section with the freelinkItTest field.\n";
    exit(1);
}

$originalSettings = $field->getSettings();
$created = [];

/**
 * Saves the field's settings (project config), re-reads the field.
 *
 * @param array<string, mixed> $settings
 */
function saveFieldSettings(array $settings): FreeLinkField
{
    global $fieldsService, $field;

    $field = $fieldsService->getFieldById($field->id);
    foreach ($settings as $k => $v) {
        $field->$k = $v;
    }
    if (!$fieldsService->saveField($field)) {
        throw new RuntimeException('Could not save field: ' . json_encode($field->getErrors()));
    }
    Craft::$app->getProjectConfig()->flush();
    $fieldsService->refreshFields();
    Craft::$app->getGql()->flushCaches();

    // Entry type layouts keep their own field instances for the rest of this process; give them
    // the same settings so validation in this process sees them.
    global $testType;
    $layoutField = $testType->getFieldLayout()->getFieldByHandle('freelinkItTest');
    foreach ($settings as $k => $v) {
        $layoutField->$k = $v;
    }

    return $field = $fieldsService->getFieldById($field->id);
}

function makeEntry(string $title, array $attrs = [], ?array $links = null): Entry
{
    global $testSection, $testType, $siteId, $created;

    $entry = new Entry([
        'sectionId' => $testSection->id,
        'typeId' => $testType->id,
        'siteId' => $siteId,
        'title' => $title,
    ] + $attrs);

    if ($links !== null) {
        $entry->setFieldValue('freelinkItTest', $links);
    }

    $entry->setScenario(Element::SCENARIO_LIVE);
    if (!Craft::$app->getElements()->saveElement($entry)) {
        throw new RuntimeException("Couldn't save $title: " . json_encode($entry->getErrors()));
    }

    $created[] = $entry->id;

    return $entry;
}

function indexRows(int $ownerId): array
{
    return (new craft\db\Query())->from(Install::TABLE_INDEX)->where(['ownerId' => $ownerId])->orderBy(['sortOrder' => SORT_ASC])->all();
}

$server = null;

/**
 * Schemas must name the sites they may read (Craft enforces it on every request); test schemas get
 * the primary site unless they name sites themselves.
 *
 * @param string[] $scope
 * @return string[]
 */
function withSiteScope(array $scope): array
{
    foreach ($scope as $item) {
        if (str_starts_with($item, 'sites.')) {
            return $scope;
        }
    }
    $scope[] = 'sites.' . Craft::$app->getSites()->getPrimarySite()->uid . ':read';
    return $scope;
}

/**
 * Runs one GraphQL request against an in-memory schema in a fresh PHP process. Craft can't rebuild a
 * schema several times in one process ("multiple types named QueryArgument", FreeLink or not).
 *
 * @param string[] $scope
 * @return array<string, mixed>
 */
function gqlRun(array $scope, string $query): array
{
    $scope = withSiteScope($scope);
    $script = sys_get_temp_dir() . '/freelink-gql-' . getmypid() . '.php';
    file_put_contents($script, '<?php
        chdir(' . var_export(getcwd(), true) . ');
        require "bootstrap.php";
        $app = require CRAFT_VENDOR_PATH . "/craftcms/cms/bootstrap/console.php";
        Craft::$app->getConfig()->getGeneral()->enableGraphqlCaching = false;
        $schema = new craft\\models\\GqlSchema(["name" => "FreeLink test", "scope" => ' . var_export($scope, true) . ']);
        echo "\\n@@JSON@@" . json_encode(Craft::$app->getGql()->executeQuery($schema, ' . var_export($query, true) . '));
    ');
    $out = (string)shell_exec('php ' . escapeshellarg($script) . ' 2>&1');
    @unlink($script);
    $pos = strrpos($out, '@@JSON@@');

    return $pos === false ? ['errors' => [['message' => substr($out, 0, 500)]]] : (json_decode(substr($out, $pos + 8), true) ?? []);
}

register_shutdown_function(function() use (&$created, &$server, $originalSettings) {
    global $field;

    echo "\nCleanup\n";
    foreach (array_reverse($created) as $id) {
        $e = Entry::find()->id($id)->status(null)->trashed(null)->site('*')->unique()->one();
        if ($e) {
            Craft::$app->getElements()->deleteElement($e, true);
        }
    }
    echo "  deleted " . count($created) . " entries\n";
    echo '  removed ' . Plugin::getInstance()->checker->deleteOrphans() . " stored check results\n";

    if ($server) {
        proc_terminate($server);
    }

    // Restore the field in a fresh process (project config in this one is stale by now).
    $script = sys_get_temp_dir() . '/freelink-restore-' . getmypid() . '.php';
    file_put_contents($script, '<?php
        chdir(' . var_export(getcwd(), true) . ');
        require "bootstrap.php";
        $app = require CRAFT_VENDOR_PATH . "/craftcms/cms/bootstrap/console.php";
        $f = Craft::$app->getFields()->getFieldById(' . (int)$field->id . ');
        foreach (' . var_export($originalSettings, true) . ' as $k => $v) { $f->$k = $v; }
        foreach (["allowIcons" => false, "includeProIcons" => false] as $k => $v) { if (!array_key_exists($k, ' . var_export($originalSettings, true) . ')) { $f->$k = $v; } }
        echo Craft::$app->getFields()->saveField($f) ? "  field settings restored\n" : "  FIELD RESTORE FAILED\n";
        Craft::$app->getProjectConfig()->flush();
    ');
    passthru('php ' . escapeshellarg($script));
    @unlink($script);
});

// region Fixtures

section('Setup');

$plugin->checker->deleteOrphans();

saveFieldSettings([
    'linkTypes' => [
        'url' => ['enabled' => true, 'sortOrder' => 0],
        'entry' => ['enabled' => true, 'sortOrder' => 1, 'sources' => '*'],
        'email' => ['enabled' => true, 'sortOrder' => 2],
        'custom' => ['enabled' => true, 'sortOrder' => 3],
        'asset' => ['enabled' => true, 'sortOrder' => 4, 'sources' => '*'],
    ],
    'multipleLinks' => true,
    'showAdvanced' => true,
    'allowIcons' => true,
    'includeProIcons' => false,
]);
echo "  field set to multi-link with icons\n";

$tLive = makeEntry('FL53 target live');
$tDisabled = makeEntry('FL53 target disabled', ['enabled' => false]);
$tTrashed = makeEntry('FL53 target trashed');
$tPending = makeEntry('FL53 target pending', ['postDate' => new DateTime('+30 days')]);
$tGone = makeEntry('FL53 target gone');
$tExpired = makeEntry('FL53 target expired', ['postDate' => new DateTime('-30 days'), 'expiryDate' => new DateTime('-1 day')]);

$base = 'http://localhost:8099';
$owner = makeEntry('FL53 owner', [], [
    // The local endpoint is `localhost`, which the URL type's validator refuses; Custom links with
    // an http(s) URL are checked the same way.
    ['type' => 'custom', 'value' => "$base/ok", 'label' => 'OK', 'icon' => 'arrow-right'],
    ['type' => 'entry', 'targetId' => $tLive->id],
    ['type' => 'entry', 'targetId' => $tDisabled->id],
    ['type' => 'entry', 'targetId' => $tTrashed->id],
    ['type' => 'entry', 'targetId' => $tPending->id],
    ['type' => 'entry', 'targetId' => $tGone->id],
    ['type' => 'entry', 'targetId' => $tExpired->id],
    ['type' => 'custom', 'value' => "$base/missing"],
    ['type' => 'custom', 'value' => "$base/redirect-internal"],
    ['type' => 'url', 'value' => 'http://10.0.0.1/'],
    ['type' => 'email', 'value' => 'hello@example.com'],
]);
$owner2 = makeEntry('FL53 owner two', [], [
    ['type' => 'entry', 'targetId' => $tLive->id],
    ['type' => 'custom', 'value' => "$base/redirect-ok"],
]);

Craft::$app->getElements()->deleteElement($tTrashed);
Craft::$app->getElements()->deleteElement(Entry::find()->id($tGone->id)->status(null)->one(), true);
echo "  created owners {$owner->id}, {$owner2->id}\n";

// endregion

section('Link index');

check('every non-empty link of the owner is indexed in order', function() use ($owner) {
    $rows = indexRows($owner->id);
    $types = array_column($rows, 'type');

    return count($rows) === 11 && $types[0] === 'custom' && $types[10] === 'email' && (int)$rows[10]['sortOrder'] === 10
        ?: json_encode($types);
});

check('URL rows carry host and hash; element rows the target', function() use ($owner, $tLive) {
    $rows = indexRows($owner->id);

    return $rows[0]['host'] === 'localhost' && $rows[0]['urlHash'] === sha1('http://localhost:8099/ok')
        && (int)$rows[1]['targetId'] === $tLive->id && $rows[1]['url'] === null
        && $rows[10]['host'] === null
        ?: json_encode([$rows[0], $rows[1]]);
});

check('a hard-deleted target leaves the row with targetId null (FK set null)', function() use ($owner) {
    return indexRows($owner->id)[5]['targetId'] === null;
});

check('a draft does not touch the index', function() use ($owner) {
    $owner = Entry::find()->id($owner->id)->status(null)->one();
    $draft = Craft::$app->getDrafts()->createDraft($owner, Craft::$app->getUser()->getId() ?? 1);
    $draft->setFieldValue('freelinkItTest', [['type' => 'url', 'value' => 'https://draft-only.test/']]);
    Craft::$app->getElements()->saveElement($draft, false);

    $draftRows = indexRows($draft->id);
    $ownerRows = indexRows($owner->id);
    Craft::$app->getElements()->deleteElement($draft, true);

    return $draftRows === [] && count($ownerRows) === 11 ?: json_encode([count($draftRows), count($ownerRows)]);
});

check('rebuilding the index for an element gives the same rows', function() use ($owner, $plugin) {
    $before = array_map(fn($r) => [$r['sortOrder'], $r['type'], $r['targetId'], $r['url']], indexRows($owner->id));
    Craft::$app->getDb()->createCommand()->delete(Install::TABLE_INDEX, ['ownerId' => $owner->id])->execute();
    $plugin->linkIndex->indexElementId($owner->id, Entry::class);
    $after = array_map(fn($r) => [$r['sortOrder'], $r['type'], $r['targetId'], $r['url']], indexRows($owner->id));

    return $before === $after ?: json_encode([$before, $after]);
});

section('Element link status (database only)');

$statusOf = function(int $position) use ($plugin, $owner): ?string {
    $refs = $plugin->linkIndex->find(['sourceId' => $owner->id], 100)['references'];
    foreach ($refs as $ref) {
        if ($ref->sortOrder === $position) {
            return $ref->getStatus();
        }
    }
    return null;
};

foreach ([1 => 'live', 2 => 'disabled', 3 => 'trashed', 4 => 'pending', 5 => 'missing', 6 => 'expired'] as $pos => $expected) {
    check("link $pos is $expected", fn() => ($s = $statusOf($pos)) === $expected ?: "got $s");
}

check('the problems filter finds exactly the 5 broken element links before any URL check', function() use ($plugin, $owner) {
    $refs = $plugin->linkIndex->find(['sourceId' => $owner->id, 'status' => 'problems'], 100)['references'];
    return count($refs) === 5 ?: count($refs) . ': ' . implode(',', array_map(fn($r) => $r->getStatus(), $refs));
});

check('a trashed owner disappears from the Links query', function() use ($plugin, $owner2) {
    Craft::$app->getElements()->deleteElement($owner2);
    $n = $plugin->linkIndex->find(['sourceId' => $owner2->id], 100)['total'];
    Craft::$app->getElements()->restoreElement(Entry::find()->id($owner2->id)->trashed(true)->status(null)->one());
    $m = $plugin->linkIndex->find(['sourceId' => $owner2->id], 100)['total'];
    return $n === 0 && $m === 2 ?: "$n / $m";
});

section('Reverse lookups');

check('findLinksTo() finds both owners', function() use ($plugin, $tLive, $owner, $owner2) {
    $ids = array_map(fn($r) => $r->sourceId, $plugin->links->findLinksTo($tLive));
    sort($ids);
    return $ids === [$owner->id, $owner2->id] ?: json_encode($ids);
});

check('findLinksTo() narrows by field and type', function() use ($plugin, $tLive) {
    return count($plugin->links->findLinksTo($tLive, ['field' => 'freelinkItTest', 'type' => 'entry'])) === 2
        && count($plugin->links->findLinksTo($tLive, ['type' => 'url'])) === 0;
});

check('a LinkReference resolves its source, field and live link', function() use ($plugin, $tLive, $owner) {
    $ref = array_values(array_filter($plugin->links->findLinksTo($tLive), fn($r) => $r->sourceId === $owner->id))[0];
    return $ref->getSource()?->id === $owner->id && $ref->getFieldHandle() === 'freelinkItTest'
        && $ref->getLink()?->targetId === $tLive->id && $ref->getTarget()?->id === $tLive->id;
});

check('craft.freelink.linksTo() and linkCountTo() in Twig', function() use ($tLive) {
    $out = Craft::$app->getView()->renderString(
        '{{ craft.freelink.linkCountTo(t) }}|{% for r in craft.freelink.linksTo(t) %}{{ r.fieldHandle }};{% endfor %}|{{ freelink.linkCountTo(t) }}',
        ['t' => $tLive],
    );
    return $out === '2|freelinkItTest;freelinkItTest;|2' ?: $out;
});

check('the edit-screen panel lists the sources for an admin', function() use ($tLive, $owner) {
    $admin = craft\elements\User::find()->admin()->one();
    Craft::$app->getUser()->setIdentity($admin);
    $html = justinholtweb\freelink\web\ReverseLookupPanel::html($tLive);
    Craft::$app->getUser()->setIdentity(null);
    return str_contains($html, 'Linked from') && str_contains($html, 'FL53 owner') && str_contains($html, 'target=' . $tLive->id) && !str_contains($html, '<input')
        ?: substr(strip_tags($html), 0, 200);
});

check('the panel is empty for a user without the permission', function() use ($tLive) {
    Craft::$app->getUser()->setIdentity(null);
    return justinholtweb\freelink\web\ReverseLookupPanel::html($tLive) === '';
});

section('Programmatic targetId writes (bug fixed in 5.3)');

check('setting a new targetId on an existing element is not overridden by the stored relation', function() use ($owner, $tExpired) {
    $fresh = Entry::find()->id($owner->id)->status(null)->one();
    $fresh->setFieldValue('freelinkItTest', [['type' => 'entry', 'targetId' => $tExpired->id]]);
    $link = $fresh->getFieldValue('freelinkItTest')->first();
    return $link->targetId === $tExpired->id ?: 'got ' . var_export($link->targetId, true);
});

check('a DB load still reads targets from the relations table', function() use ($owner, $tLive) {
    $fresh = Entry::find()->id($owner->id)->status(null)->one();
    return $fresh->getFieldValue('freelinkItTest')->getAll()[1]->targetId === $tLive->id;
});

section('Validation');

$validate = function(array $links): array {
    global $testSection, $testType, $siteId;
    $e = new Entry(['sectionId' => $testSection->id, 'typeId' => $testType->id, 'siteId' => $siteId, 'title' => 'v']);
    $e->setFieldValue('freelinkItTest', $links);
    $e->setScenario(Element::SCENARIO_LIVE);
    $e->validate();
    return $e->getErrors('freelinkItTest');
};

check('a link type the field does not enable is refused', fn() => count($validate([['type' => 'phone', 'value' => '123']])) === 1);
check('an element target that does not exist is refused', fn() => str_contains(implode(' ', $validate([['type' => 'entry', 'targetId' => 999999999]])), 'doesn’t exist'));
check('an element target of the wrong element type is refused', fn() => count($validate([['type' => 'asset', 'targetId' => $tLive->id]])) === 1);
check('a free icon is accepted', fn() => $validate([['type' => 'url', 'value' => 'https://x.test', 'icon' => 'house']]) === []);
check('a Pro icon is refused while Pro icons are off', fn() => count($validate([['type' => 'url', 'value' => 'https://x.test', 'icon' => '00']])) === 1);
check('an unknown icon is refused', fn() => count($validate([['type' => 'url', 'value' => 'https://x.test', 'icon' => 'no-such-icon-x']])) >= 1);
check('a target the signed-in user cannot view is refused', function() use ($validate, $tLive) {
    $user = new craft\elements\User(['id' => 999999990, 'username' => 'nobody', 'admin' => false]);
    Craft::$app->getUser()->setIdentity($user);
    try {
        return count($validate([['type' => 'entry', 'targetId' => $tLive->id]])) === 1;
    } finally {
        Craft::$app->getUser()->setIdentity(null);
    }
});

section('Grandfathering: unchanged links are never blocked by the 5.3 checks');

$revalidate = function(Entry $entry, ?array $links = null): array {
    $fresh = Entry::find()->id($entry->id)->status(null)->one();
    if ($links !== null) {
        $fresh->setFieldValue('freelinkItTest', $links);
    }
    $fresh->setScenario(Element::SCENARIO_LIVE);
    $fresh->validate();
    return $fresh->getErrors('freelinkItTest');
};

$gfType = makeEntry('FL53 grandfather type', [], [
    ['type' => 'custom', 'value' => '#kept'],
    ['type' => 'url', 'value' => 'https://kept.example.org/'],
]);
$gfView = makeEntry('FL53 grandfather view', [], [['type' => 'entry', 'targetId' => $tLive->id]]);

// Turn the Custom type off on the field, as an admin tightening it after content exists.
$typesWithCustom = $field->linkTypes;
$typesWithoutCustom = $typesWithCustom;
$typesWithoutCustom['custom']['enabled'] = false;
saveFieldSettings(['linkTypes' => $typesWithoutCustom]);

check('an unchanged link of a type the field no longer enables still saves', function() use ($gfType) {
    $fresh = Entry::find()->id($gfType->id)->status(null)->one();
    $fresh->setScenario(Element::SCENARIO_LIVE);
    return Craft::$app->getElements()->saveElement($fresh) ?: json_encode($fresh->getErrors());
});

check('…and still saves with only its label changed', fn() => $revalidate($gfType, [
    ['type' => 'custom', 'value' => '#kept', 'label' => 'New label'],
    ['type' => 'url', 'value' => 'https://kept.example.org/'],
]) === []);

check('changing a link to the disabled type fails', fn() => count($e = $revalidate($gfType, [
    ['type' => 'custom', 'value' => '#kept'],
    ['type' => 'custom', 'value' => 'https://kept.example.org/'],
])) === 1 ?: json_encode($e));

check('changing the disabled-type link’s destination fails', fn() => count($e = $revalidate($gfType, [
    ['type' => 'custom', 'value' => '#changed'],
    ['type' => 'url', 'value' => 'https://kept.example.org/'],
])) === 1 ?: json_encode($e));

check('a disallowed link saved into a draft is refused when the draft is published', function() use ($gfType) {
    $canonical = Entry::find()->id($gfType->id)->status(null)->one();
    $draft = Craft::$app->getDrafts()->createDraft($canonical, 1);
    // Drafts save without field validation, so the disallowed link lands in the draft row.
    $draft->setFieldValue('freelinkItTest', [['type' => 'custom', 'value' => '#smuggled']]);
    Craft::$app->getElements()->saveElement($draft, false);

    // What the CP's "Apply draft" does first (ElementsController::actionApplyDraft): save the draft
    // with live validation; only a valid draft is applied to the canonical entry.
    $reloaded = Entry::find()->id($draft->id)->drafts(true)->status(null)->one();
    $reloaded->setScenario(Element::SCENARIO_LIVE);
    $saved = Craft::$app->getElements()->saveElement($reloaded);
    $errors = $reloaded->getErrors('freelinkItTest');
    Craft::$app->getElements()->deleteElement($reloaded, true);

    $after = Entry::find()->id($gfType->id)->status(null)->one()->getFieldValue('freelinkItTest')->first()?->value;

    return !$saved && count($errors) === 1 && $after === '#kept' ?: json_encode([$saved, $errors, $after]);
});

check('an unchanged live link still saves after a refused draft', function() use ($gfType) {
    $fresh = Entry::find()->id($gfType->id)->status(null)->one();
    $fresh->setScenario(Element::SCENARIO_LIVE);
    return Craft::$app->getElements()->saveElement($fresh) ?: json_encode($fresh->getErrors());
});

saveFieldSettings(['linkTypes' => $typesWithCustom]);

$cannotView = new craft\elements\User(['id' => 999999991, 'username' => 'fl53-nobody', 'admin' => false]);

check('an unchanged link to a target the editor can’t view still validates', function() use ($revalidate, $gfView, $cannotView) {
    Craft::$app->getUser()->setIdentity($cannotView);
    try {
        return ($e = $revalidate($gfView)) === [] ?: json_encode($e);
    } finally {
        Craft::$app->getUser()->setIdentity(null);
    }
});

check('changing the target to one the editor can’t view fails', function() use ($revalidate, $gfView, $cannotView, $tExpired) {
    Craft::$app->getUser()->setIdentity($cannotView);
    try {
        $errors = $revalidate($gfView, [['type' => 'entry', 'targetId' => $tExpired->id]]);
        return count($errors) === 1 && str_contains($errors[0], 'can’t link') ?: json_encode($errors);
    } finally {
        Craft::$app->getUser()->setIdentity(null);
    }
});

check('changing only the target site re-runs the checks', function() use ($revalidate, $gfView, $cannotView, $tLive, $siteId) {
    $otherSite = null;
    foreach (Craft::$app->getSites()->getAllSites() as $site) {
        if ($site->id !== $siteId) {
            $otherSite = $site->id;
            break;
        }
    }
    if (!$otherSite) {
        return 'needs a second site';
    }
    Craft::$app->getUser()->setIdentity($cannotView);
    try {
        $same = $revalidate($gfView, [['type' => 'entry', 'targetId' => $tLive->id]]);
        $moved = $revalidate($gfView, [['type' => 'entry', 'targetId' => $tLive->id, 'targetSiteId' => $otherSite]]);
        return $same === [] && count($moved) === 1 ?: json_encode([$same, $moved]);
    } finally {
        Craft::$app->getUser()->setIdentity(null);
    }
});

section('Drafts and targets the viewer can’t see');

check('a draft save with a new target the editor can’t view fails', function() use ($gfView, $cannotView, $tExpired) {
    $canonical = Entry::find()->id($gfView->id)->status(null)->one();
    $draft = Craft::$app->getDrafts()->createDraft($canonical, 1);
    Craft::$app->getUser()->setIdentity($cannotView);
    try {
        $draft->setFieldValue('freelinkItTest', [['type' => 'entry', 'targetId' => $tExpired->id]]);
        // Drafts skip field validation; the security check still runs.
        $saved = Craft::$app->getElements()->saveElement($draft, false);
        $errors = $draft->getErrors('freelinkItTest');
    } finally {
        Craft::$app->getUser()->setIdentity(null);
        Craft::$app->getElements()->deleteElement($draft, true);
    }
    return !$saved && count($errors) === 1 ?: json_encode([$saved, $errors]);
});

check('a draft keeping an unchanged live link to a now-unviewable target saves', function() use ($gfView, $tLive) {
    // A real user (the draft save records them as an author), with no permission to view entries.
    $cannotView = User::find()->admin(false)->status('active')->one();
    if (!$cannotView || Craft::$app->getElements()->canView($tLive, $cannotView)) {
        return 'need a non-admin that can’t view Test Entries';
    }
    $canonical = Entry::find()->id($gfView->id)->status(null)->one();
    $draft = Craft::$app->getDrafts()->createDraft($canonical, 1);
    Craft::$app->getUser()->setIdentity($cannotView);
    try {
        $draft->title = 'FL53 grandfather view (edited)';
        $draft->setFieldValue('freelinkItTest', [['type' => 'entry', 'targetId' => $tLive->id, 'label' => 'relabelled']]);
        $saved = Craft::$app->getElements()->saveElement($draft, false);
        $errors = $draft->getErrors();
    } finally {
        Craft::$app->getUser()->setIdentity(null);
        Craft::$app->getElements()->deleteElement($draft, true);
    }
    return $saved ?: json_encode($errors);
});

check('the field input hides an unviewable target’s title but keeps the link', function() use ($gfView, $cannotView, $tLive) {
    $owner = Entry::find()->id($gfView->id)->status(null)->one();
    $field = $owner->getFieldLayout()->getFieldByHandle('freelinkItTest');
    Craft::$app->getUser()->setIdentity($cannotView);
    try {
        $html = $field->getInputHtml($owner->getFieldValue('freelinkItTest'), $owner);
    } finally {
        Craft::$app->getUser()->setIdentity(null);
    }
    // (The visible case renders Craft's element chips, which need a web request: cp-5.3.php covers it.)
    return !str_contains($html, $tLive->title) && str_contains($html, 'freelink-hidden-target') && str_contains($html, 'value="' . $tLive->id . '"')
        ?: 'title leaked or link not kept';
});

check('MCP output hides an unviewable target’s URL, title and element', function() use ($gfView, $cannotView, $tLive) {
    if (!class_exists(craft\mcp\McpOutputContext::class)) {
        return true;
    }
    $owner = Entry::find()->id($gfView->id)->status(null)->one();
    $field = $owner->getFieldLayout()->getFieldByHandle('freelinkItTest');
    Craft::$app->getUser()->setIdentity($cannotView);
    try {
        $out = $field->getMcpValue($owner->getFieldValue('freelinkItTest'), $owner, new craft\mcp\McpOutputContext());
    } finally {
        Craft::$app->getUser()->setIdentity(null);
    }
    $first = $field->multipleLinks ? ($out[0] ?? []) : $out;
    return $first['url'] === null && $first['target'] === null && !str_contains((string)json_encode($first), $tLive->title)
        ?: json_encode($first);
});

check('a link to a target deleted since loading becomes empty instead of failing', function() use ($tLive) {
    $doomed = makeEntry('FL53 doomed target');
    $holder = makeEntry('FL53 holder', [], [['type' => 'entry', 'targetId' => $doomed->id]]);
    $loaded = Entry::find()->id($holder->id)->status(null)->one();
    $loaded->getFieldValue('freelinkItTest'); // value loaded with the target
    Craft::$app->getElements()->deleteElement($doomed, true);
    $loaded->setScenario(Element::SCENARIO_LIVE);
    $saved = Craft::$app->getElements()->saveElement($loaded);
    $fresh = Entry::find()->id($holder->id)->status(null)->one();
    return $saved && $fresh->getFieldValue('freelinkItTest')->first()?->targetId === null ?: json_encode($loaded->getErrors());
});

section('Icons');

check('link.icon and link.iconSvg in Twig', function() use ($owner) {
    $fresh = Entry::find()->id($owner->id)->status(null)->one();
    $out = Craft::$app->getView()->renderString('{{ e.freelinkItTest.first.icon }}|{{ e.freelinkItTest.first.getIconSvg({class: "i"}) }}', ['e' => $fresh]);
    return str_starts_with($out, 'arrow-right|<svg') && str_contains($out, 'class="i"') && str_contains($out, 'aria-hidden="true"') ?: substr($out, 0, 120);
});

check('the icon is stored in the content JSON', function() use ($owner) {
    $fresh = Entry::find()->id($owner->id)->status(null)->one();
    $json = $fresh->getFieldLayout()->getFieldByHandle('freelinkItTest')->serializeValue($fresh->getFieldValue('freelinkItTest'), $fresh);
    return ($json[0]['icon'] ?? null) === 'arrow-right' && !isset($json[1]['icon']);
});

check('toApiArray() includes the icon', function() use ($owner) {
    $fresh = Entry::find()->id($owner->id)->status(null)->one();
    return $fresh->freelinkItTest->first()->toApiArray()['icon'] === 'arrow-right';
});

// region URL checks

section('URL checks (local endpoint) and SSRF refusals');

$router = sys_get_temp_dir() . '/freelink-endpoint.php';
file_put_contents($router, <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents(sys_get_temp_dir() . '/freelink-endpoint.log', $_SERVER['REQUEST_METHOD'] . ' ' . $path . ' ' . ($_SERVER['HTTP_USER_AGENT'] ?? '') . "\n", FILE_APPEND);
switch ($path) {
    case '/ok': http_response_code(200); echo str_repeat('x', 200000); break;
    case '/missing': http_response_code(404); break;
    case '/redirect-internal': header('Location: http://169.254.169.254/latest/meta-data/', true, 302); break;
    case '/redirect-loopback': header('Location: http://127.0.0.1:8099/ok', true, 302); break;
    case '/redirect-ok': header('Location: /ok', true, 301); break;
    case '/no-head': if ($_SERVER['REQUEST_METHOD'] === 'HEAD') { http_response_code(405); } else { http_response_code(200); } break;
    default: http_response_code(500);
}
PHP);
@unlink(sys_get_temp_dir() . '/freelink-endpoint.log');
$server = proc_open(['php', '-S', '127.0.0.1:8099', $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', 8099); $i++) {
    usleep(100000);
}

$settings = $plugin->getSettings();
$checker = $plugin->checker;
$checker->guard = null;
$checker->hostDelayMs = 0;

check('with default settings, localhost and private addresses are refused before any request', function() use ($checker) {
    $statuses = [];
    foreach ([
        'http://localhost:8099/ok',
        'http://localhost/ok',
        'http://127.0.0.1/',
        'http://10.0.0.1/',
        'http://169.254.169.254/latest/meta-data/',
        'http://[::1]/',
        'http://2130706433/',
        'http://0177.0.0.1/',
        'http://[::ffff:127.0.0.1]/',
    ] as $url) {
        $statuses[$url] = $checker->checkUrl($url)['status'];
    }
    $log = @file(sys_get_temp_dir() . '/freelink-endpoint.log') ?: [];
    return count(array_filter($statuses, fn($s) => $s !== 'blocked')) === 0 && $log === [] ?: json_encode($statuses) . ' log:' . count($log);
});

// Allow the local endpoint by name and port, as config/freelink.php would.
$settings->checkAllowedHosts = ['localhost'];
$settings->checkAllowedPorts = [80, 443, 8099];
$checker->guard = null;

check('an allowlisted local endpoint: 200 is ok (body capped)', fn() => ($r = $checker->checkUrl("$base/ok"))['status'] === 'ok' && $r['httpCode'] === 200 ?: json_encode($r));
check('404 is broken', fn() => ($r = $checker->checkUrl("$base/missing"))['status'] === 'broken' && $r['httpCode'] === 404 ?: json_encode($r));
check('a relative redirect is followed (redirect, final URL kept)', fn() => ($r = $checker->checkUrl("$base/redirect-ok"))['status'] === 'redirect' && $r['finalUrl'] === "$base/ok" ?: json_encode($r));
check('HEAD 405 falls back to GET', fn() => ($r = $checker->checkUrl("$base/no-head"))['status'] === 'ok' ?: json_encode($r));
check('a redirect to the metadata address is refused', fn() => ($r = $checker->checkUrl("$base/redirect-internal"))['status'] === 'blocked' && str_contains((string)$r['error'], 'Redirects') ?: json_encode($r));
check('a redirect to a non-allowlisted loopback literal is refused', fn() => ($r = $checker->checkUrl("$base/redirect-loopback"))['status'] === 'blocked' ?: json_encode($r));
check('the endpoint saw the FreeLink user agent and never the refused hops', function() {
    $log = file(sys_get_temp_dir() . '/freelink-endpoint.log', FILE_IGNORE_NEW_LINES) ?: [];
    $ua = array_unique(array_map(fn($l) => substr($l, strpos($l, ' ', strpos($l, ' ') + 1) + 1), $log));
    return $ua === ['FreeLinkChecker/5.3 (+Craft CMS link checker)'] && count($log) >= 7 ?: json_encode([$ua, count($log)]);
});

check('one real external request (example.com) is ok', function() use ($checker) {
    $checker->guard = null;
    $r = $checker->checkUrl('https://example.com/');
    return in_array($r['status'], ['ok', 'redirect'], true) ?: json_encode($r);
});

check('run() checks the due test URLs, stores results, and the Links query reflects them', function() use ($checker, $plugin, $owner, $owner2) {
    // Keep the run to this test's URLs: mark every other indexed URL as freshly checked for the run.
    $ours = array_filter(array_merge(array_column(indexRows($owner->id), 'urlHash'), array_column(indexRows($owner2->id), 'urlHash')));
    $others = (new craft\db\Query())->select(['urlHash', 'url'])->distinct()->from(Install::TABLE_INDEX)
        ->where(['not', ['urlHash' => null]])->andWhere(['not in', 'urlHash', $ours])->andWhere(['not', ['host' => null]])->all();
    $existing = (new craft\db\Query())->select(['urlHash'])->from(Install::TABLE_CHECKS)->column();
    $seeded = [];
    foreach ($others as $row) {
        if (!in_array($row['urlHash'], $existing, true)) {
            $checker->saveResult($row['urlHash'], $row['url'], ['status' => 'ok', 'httpCode' => null, 'finalUrl' => null, 'redirects' => 0, 'error' => 'seeded by test']);
            $seeded[] = $row['urlHash'];
        }
    }

    $result = $checker->run(true, 50);

    if ($seeded) {
        Craft::$app->getDb()->createCommand()->delete(Install::TABLE_CHECKS, ['urlHash' => $seeded])->execute();
    }

    // force=true would re-check the seeded ones too, so count only ours.
    $statuses = [];
    foreach ($plugin->linkIndex->find(['sourceId' => $owner->id], 100)['references'] as $ref) {
        if (!$ref->isElement && $ref->host) {
            $statuses[$ref->sortOrder] = $ref->getStatus();
        }
    }

    ksort($statuses);

    return $statuses === [0 => 'ok', 7 => 'broken', 8 => 'blocked', 9 => 'blocked'] ?: json_encode([$statuses, $result]);
});

$settings->checkAllowedHosts = [];
$settings->checkAllowedPorts = [80, 443];
$checker->guard = null;

check('with checks turned off, run() fetches nothing', function() use ($checker, $settings) {
    $settings->enableExternalChecks = false;
    @unlink(sys_get_temp_dir() . '/freelink-endpoint.log');
    $r = $checker->run(true, 50);
    $settings->enableExternalChecks = true;
    return $r['skipped'] && !file_exists(sys_get_temp_dir() . '/freelink-endpoint.log');
});

check('orphaned results are swept', function() use ($checker) {
    $checker->saveResult(sha1('https://orphan.test/'), 'https://orphan.test/', ['status' => 'ok', 'httpCode' => 200, 'finalUrl' => null, 'redirects' => 0, 'error' => null]);
    $checker->deleteOrphans();
    return !(new craft\db\Query())->from(Install::TABLE_CHECKS)->where(['urlHash' => sha1('https://orphan.test/')])->exists();
});

// endregion

// region GraphQL

section('GraphQL');

$gql = Craft::$app->getGql();
$sectionUid = $testSection->uid;
$schemaFor = function(array $scope) use ($gql): GqlSchema {
    $gql->flushCaches();
    return new GqlSchema(['name' => 'FreeLink test', 'scope' => withSiteScope($scope), 'isPublic' => false]);
};
$mutationName = "save_testEntries_{$testType->handle}_Entry";

// Craft can't rebuild a schema with mutations twice in one process ("multiple types named
// QueryArgument", with or without FreeLink installed), so read-only schemas run first, the one
// mutation schema last, and single-link mode in a separate process.
$readScope = ["sections.$sectionUid:read", 'freelinkLinks:read'];

check('reverse lookup query returns sources the schema can read', function() use ($gql, $schemaFor, $readScope, $tLive, $owner) {
    $r = gqlRun($readScope, "{ freelinkLinksTo(elementId: {$tLive->id}) { sourceId rootSourceId field type position status source { id title } link { elementId } } }");
    if (!empty($r['errors'])) {
        return json_encode($r['errors']);
    }
    $ids = array_column($r['data']['freelinkLinksTo'], 'sourceId');
    return in_array($owner->id, $ids, true) && $r['data']['freelinkLinksTo'][0]['link']['elementId'] === $tLive->id
        ?: json_encode($r['data']);
});

check('without the FreeLink schema component the query does not exist', function() use ($schemaFor, $gql, $sectionUid, $tLive) {
    $r = gqlRun(["sections.$sectionUid:read"], "{ freelinkLinksTo(elementId: {$tLive->id}) { sourceId } }");
    $gql->setActiveSchema($schemaFor(["sections.$sectionUid:read"]));
    try {
        $queries = justinholtweb\freelink\gql\queries\LinksToQuery::getQueries();
    } finally {
        $gql->setActiveSchema(null);
    }
    // (A harness plugin strips GraphQL errors, so an unknown field comes back as empty data.)
    return $queries === [] && !isset($r['data']['freelinkLinksTo']) ?: json_encode($r);
});

check('a schema that cannot read the target gets an empty list', function() use ($schemaFor, $gql, $tLive) {
    $news = Craft::$app->getEntries()->getSectionByHandle('news');
    $r = gqlRun(["sections.$news->uid:read", 'freelinkLinks:read'], "{ freelinkLinksTo(elementId: {$tLive->id}) { sourceId } }");
    return ($r['data']['freelinkLinksTo'] ?? null) === [] ?: json_encode($r);
});

check('GraphQL hides the URL and title of a target the schema can’t query', function() use ($tLive, $readScope) {
    $news = Entry::find()->section('news')->one();
    if (!$news) {
        return 'no news entry';
    }
    $holder = makeEntry('FL53 holder of news link', [], [['type' => 'entry', 'targetId' => $news->id]]);
    $q = "{ entry(id: {$holder->id}) { ... on {$GLOBALS['testType']->handle}_Entry { freelinkItTest { url text elementId } } } }";
    $r = gqlRun($readScope, $q);
    $link = $r['data']['entry']['freelinkItTest'][0] ?? null;
    $newsUid = Craft::$app->getEntries()->getSectionByHandle('news')->uid;
    $r2 = gqlRun([...$readScope, "sections.$newsUid:read"], $q);
    $link2 = $r2['data']['entry']['freelinkItTest'][0] ?? null;
    return $link && $link['url'] === null && $link['text'] === null && $link2 && $link2['text'] === $news->title
        ?: json_encode([$link, $link2]);
});

// A target that exists in two sites, and a schema that may read only one of them.
$sites = Craft::$app->getSites()->getAllSites();
$siteA = Craft::$app->getSites()->getPrimarySite();
$siteB = null;
foreach ($sites as $site) {
    if ($site->id !== $siteA->id) {
        $siteB = $site;
        break;
    }
}
$twoSiteTarget = $siteB ? Entry::find()->section('portfolio')->siteId($siteA->id)->status(null)
    ->innerJoin(['b' => '{{%elements_sites}}'], '[[b.elementId]] = [[elements.id]] AND [[b.siteId]] = ' . (int)$siteB->id)
    ->one() : null;
$portfolioUid = Craft::$app->getEntries()->getSectionByHandle('portfolio')?->uid;
$siteAOnly = ["sections.$sectionUid:read", "sections.$sectionUid:save", "sections.$sectionUid:create", "sections.$portfolioUid:read", "sites.$siteA->uid:read", 'freelinkLinks:read'];

check('GqlScope and TargetVisibility honour the schema’s sites (target site, else owner site)', function() use ($gql, $schemaFor, $siteAOnly, $twoSiteTarget, $siteA, $siteB, $plugin) {
    if (!$twoSiteTarget) {
        return 'needs an entry in two sites';
    }
    $link = function(?int $targetSite, ?int $ownerSite) use ($plugin, $twoSiteTarget) {
        $l = $plugin->links->createLink(['type' => 'entry', 'targetId' => $twoSiteTarget->id, 'targetSiteId' => $targetSite]);
        $l->ownerSiteId = $ownerSite;
        return $l;
    };
    $gql->setActiveSchema($schemaFor($siteAOnly));
    try {
        $r = [
            justinholtweb\freelink\gql\GqlScope::canQuery($twoSiteTarget->id, $siteA->id),
            justinholtweb\freelink\gql\GqlScope::canQuery($twoSiteTarget->id, $siteB->id),
            justinholtweb\freelink\helpers\TargetVisibility::canSee($link($siteA->id, null)),
            justinholtweb\freelink\helpers\TargetVisibility::canSee($link($siteB->id, $siteA->id)),
            justinholtweb\freelink\helpers\TargetVisibility::canSee($link(null, $siteA->id)),
            justinholtweb\freelink\helpers\TargetVisibility::canSee($link(null, $siteB->id)),
        ];
        $gql->setActiveSchema($schemaFor([...$siteAOnly, "sites.$siteB->uid:read"]));
        $r[] = justinholtweb\freelink\gql\GqlScope::canQuery($twoSiteTarget->id, $siteB->id);
    } finally {
        $gql->setActiveSchema(null);
    }
    return $r === [true, false, true, false, true, false, true] ?: json_encode($r);
});

check('mutation: a link to the target’s copy on a site the schema can’t read is refused; the readable site’s copy saves', function() use ($siteAOnly, $mutationName, $twoSiteTarget, $siteA, $siteB, &$created) {
    if (!$twoSiteTarget) {
        return 'needs an entry in two sites';
    }
    $refused = gqlRun($siteAOnly, "mutation { $mutationName(title: \"FL53 site B\", freelinkItTest: [{type: \"entry\", targetId: {$twoSiteTarget->id}, targetSiteId: {$siteB->id}}]) { id } }");
    $ok = gqlRun($siteAOnly, "mutation { $mutationName(title: \"FL53 site A\", freelinkItTest: [{type: \"entry\", targetId: {$twoSiteTarget->id}, targetSiteId: {$siteA->id}}]) { id freelinkItTest { elementSiteId text } } }");
    if (isset($ok['data'][$mutationName]['id'])) {
        $created[] = (int)$ok['data'][$mutationName]['id'];
    }
    if (isset($refused['data'][$mutationName]['id'])) {
        $created[] = (int)$refused['data'][$mutationName]['id'];
    }
    return !empty($refused['errors']) && empty($refused['data'][$mutationName]['id'])
        && ($ok['data'][$mutationName]['freelinkItTest'][0]['elementSiteId'] ?? null) === $siteA->id
        ?: json_encode([$refused, $ok]);
});

check('freelinkLinksTo leaves out links that resolve on a site the schema can’t read; output hides that copy', function() use ($siteAOnly, $twoSiteTarget, $siteA, $siteB) {
    if (!$twoSiteTarget) {
        return 'needs an entry in two sites';
    }
    $onA = makeEntry('FL53 links to site A copy', [], [['type' => 'entry', 'targetId' => $twoSiteTarget->id, 'targetSiteId' => $siteA->id]]);
    $onB = makeEntry('FL53 links to site B copy', [], [['type' => 'entry', 'targetId' => $twoSiteTarget->id, 'targetSiteId' => $siteB->id]]);
    $r = gqlRun($siteAOnly, "{ freelinkLinksTo(elementId: {$twoSiteTarget->id}) { sourceId } }");
    $ids = array_column($r['data']['freelinkLinksTo'] ?? [], 'sourceId');
    $out = gqlRun($siteAOnly, "{ entry(id: {$onB->id}) { ... on {$GLOBALS['testType']->handle}_Entry { freelinkItTest { url text } } } }");
    $hidden = $out['data']['entry']['freelinkItTest'][0] ?? null;
    return in_array($onA->id, $ids, true) && !in_array($onB->id, $ids, true) && $hidden && $hidden['url'] === null && $hidden['text'] === null
        ?: json_encode([$ids, $hidden, $r['errors'] ?? null]);
});

check('GqlScope keeps only sources the schema can query', function() use ($schemaFor, $gql, $sectionUid, $owner) {
    $news = Entry::find()->section('news')->one();
    if (!$news) {
        return 'no news entry';
    }
    $gql->setActiveSchema($schemaFor(["sections.$sectionUid:read"]));
    try {
        $allowed = justinholtweb\freelink\gql\GqlScope::filterQueryable([$news->id, $owner->id]);
    } finally {
        $gql->setActiveSchema(null);
    }
    return $allowed === [$owner->id] ?: json_encode($allowed);
});

check('output: icon, iconSvg, elementType on the field query', function() use ($gql, $schemaFor, $readScope, $owner) {
    $r = gqlRun($readScope, "{ entry(id: {$owner->id}) { ... on {$GLOBALS['testType']->handle}_Entry { freelinkItTest { type icon iconSvg elementType elementId } } } }");
    if (!empty($r['errors'])) {
        return json_encode($r['errors']);
    }
    $links = $r['data']['entry']['freelinkItTest'] ?? [];
    return ($links[0]['icon'] ?? null) === 'arrow-right' && str_starts_with((string)$links[0]['iconSvg'], '<svg') && $links[1]['elementType'] === Entry::class
        ?: json_encode($links);
});

$full = ["sections.$sectionUid:read", "sections.$sectionUid:save", "sections.$sectionUid:create", 'freelinkLinks:read'];

check('mutation: multi-mode list input saves url, entry (by id), email and custom links', function() use ($gql, $full, $mutationName, $tLive, &$created) {
    $q = "mutation { $mutationName(title: \"FL53 gql\", freelinkItTest: [
        {type: \"url\", value: \"https://gql.test/a\", label: \"A\", newWindow: true, icon: \"house\"},
        {type: \"entry\", targetId: {$tLive->id}},
        {type: \"email\", value: \"a@b.test\", subject: \"Hi\"},
        {type: \"custom\", value: \"#top\", htmlId: \"x\"}
    ]) { id freelinkItTest { type url elementId icon subject htmlId newWindow } } }";
    $r = gqlRun($full, $q);
    if (!empty($r['errors'])) {
        return json_encode($r['errors']);
    }
    $data = $r['data'][$mutationName];
    $created[] = (int)$data['id'];
    $links = $data['freelinkItTest'];

    return count($links) === 4 && $links[0]['icon'] === 'house' && $links[0]['newWindow'] === true
        && $links[1]['elementId'] === $tLive->id && $links[2]['subject'] === 'Hi' && $links[3]['htmlId'] === 'x'
        && indexRows((int)$data['id'])[1]['targetId'] == $tLive->id
        ?: json_encode($links);
});

check('mutation: a disabled link type is refused like the CP', function() use ($gql, $full, $mutationName) {
    $r = gqlRun($full, "mutation { $mutationName(title: \"FL53 bad\", freelinkItTest: [{type: \"phone\", value: \"1\"}]) { id } }");
    return !empty($r['errors']) && str_contains(json_encode($r['errors']), 'doesn') ?: json_encode($r);
});

check('mutation: an unsafe URL is refused', function() use ($gql, $full, $mutationName) {
    $r = gqlRun($full, "mutation { $mutationName(title: \"FL53 bad\", freelinkItTest: [{type: \"custom\", value: \"javascript:alert(1)\"}]) { id } }");
    return !empty($r['errors']) ?: json_encode($r);
});

check('mutation: a target outside the schema’s scope is refused', function() use ($gql, $full, $mutationName) {
    $other = Entry::find()->section('news')->status(null)->one() ?? Entry::find()->section('post')->status(null)->one();
    if (!$other) {
        return 'no entry outside testEntries';
    }
    $r = gqlRun($full, "mutation { $mutationName(title: \"FL53 bad\", freelinkItTest: [{type: \"entry\", targetId: {$other->id}}]) { id } }");
    return !empty($r['errors']) && str_contains(json_encode($r['errors']), 'can') ?: json_encode($r);
});

check('mutation: updating an existing entry changes its element target', function() use ($gql, $full, $mutationName, $owner2, $tPending) {
    $r = gqlRun($full, "mutation { $mutationName(id: {$owner2->id}, freelinkItTest: [{type: \"entry\", targetId: {$tPending->id}}]) { id freelinkItTest { elementId } } }");
    if (!empty($r['errors'])) {
        return json_encode($r['errors']);
    }
    $fresh = Entry::find()->id($owner2->id)->status(null)->one();
    return $r['data'][$mutationName]['freelinkItTest'][0]['elementId'] === $tPending->id
        && $fresh->freelinkItTest->first()->targetId === $tPending->id;
});

saveFieldSettings(['multipleLinks' => false]);

check('mutation: single-mode takes one input object', function() use ($mutationName, $full, &$created) {
    $r = gqlRun($full, "mutation { $mutationName(title: \"FL53 gql single\", freelinkItTest: {type: \"url\", value: \"https://single.test/\"}) { id freelinkItTest { url } } }");
    if (!empty($r['errors']) || !isset($r['data'][$mutationName])) {
        return json_encode($r);
    }
    $created[] = (int)$r['data'][$mutationName]['id'];
    return $r['data'][$mutationName]['freelinkItTest'][0]['url'] === 'https://single.test/';
});

// endregion

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
