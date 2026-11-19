<?php
/**
 * Links screen / Linked from / Twig / MCP results are scoped to sources the viewer may see, in the
 * query, so rows, totals and pagination never reveal hidden sources.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php <plugin>/tests/integration/viewer-scope-5.3.php
 *
 * Creates a temporary non-admin user with FreeLink's "view links" permission and view access to the
 * Test Entries section but not to other authors' entries in it, a target and two owners (one by that
 * user, one by an admin). Then removes section access entirely. Everything is deleted at the end.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\base\Element;
use craft\elements\Entry;
use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\freelink\Plugin;
use justinholtweb\freelink\web\ReverseLookupPanel;

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
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

$plugin = Plugin::getInstance();
$section = Craft::$app->getEntries()->getSectionByHandle('testEntries');
$type = $section->getEntryTypes()[0];
$siteId = Craft::$app->getSites()->getPrimarySite()->id;
$admin = User::find()->admin()->one();
$created = [];
$user = null;

register_shutdown_function(function() use (&$created, &$user) {
    foreach (array_reverse($created) as $id) {
        if ($e = Entry::find()->id($id)->status(null)->trashed(null)->one()) {
            Craft::$app->getElements()->deleteElement($e, true);
        }
    }
    if ($user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
    echo "\nCleanup: deleted " . count($created) . " entries and the test user\n";
});

$user = new User(['username' => 'fl53-viewer-' . bin2hex(random_bytes(3)), 'email' => 'fl53-viewer-' . bin2hex(random_bytes(3)) . '@example.test']);
$user->active = true;
Craft::$app->getElements()->saveElement($user, false);
$grant = function(array $permissions) use ($user) {
    Craft::$app->getUserPermissions()->saveUserPermissions($user->id, $permissions);
    Craft::$app->getUserPermissions()->reset();
};
$editSite = 'editSite:' . Craft::$app->getSites()->getPrimarySite()->uid;
$grant(['accessCp', 'accessPlugin-freelink', Plugin::PERMISSION_VIEW_LINKS, "viewEntries:$section->uid", $editSite]);

$make = function(string $title, int $authorId, ?array $link = null) use ($section, $type, $siteId, &$created): Entry {
    $e = new Entry(['sectionId' => $section->id, 'typeId' => $type->id, 'siteId' => $siteId, 'title' => $title]);
    $e->setAuthorIds([$authorId]);
    if ($link) {
        $e->setFieldValue('freelinkItTest', $link);
    }
    $e->setScenario(Element::SCENARIO_LIVE);
    if (!Craft::$app->getElements()->saveElement($e)) {
        throw new RuntimeException(json_encode($e->getErrors()));
    }
    $created[] = $e->id;
    return $e;
};

$target = $make('FL53 scope target', $user->id);
$mine = $make('FL53 scope mine', $user->id, ['type' => 'entry', 'targetId' => $target->id, 'label' => 'visible-label']);
$theirs = $make('FL53 scope theirs', $admin->id, ['type' => 'entry', 'targetId' => $target->id, 'label' => 'SECRET-LABEL']);
// A URL link in the hidden entry too, to make sure its URL and check result never show.
$theirsUrl = $make('FL53 scope theirs url', $admin->id, ['type' => 'url', 'value' => 'https://secret.fl53.example.net/x']);
$plugin->checker->saveResult(sha1('https://secret.fl53.example.net/x'), 'https://secret.fl53.example.net/x', ['status' => 'broken', 'httpCode' => 404, 'finalUrl' => null, 'redirects' => 0, 'error' => 'SECRET-ERROR']);

echo "Peer restriction (viewEntries without viewPeerEntries)\n";

check('the user can view their own entry but not the admin’s (control)', fn() => Craft::$app->getElements()->canView($mine, $user) && !Craft::$app->getElements()->canView($theirs, $user));

check('find() with the viewer counts and returns only the visible source', function() use ($plugin, $target, $user, $mine) {
    $r = $plugin->linkIndex->find(['targetId' => $target->id, 'viewer' => $user], 50);
    $all = $plugin->linkIndex->find(['targetId' => $target->id], 50);
    return $r['total'] === 1 && $r['references'][0]->sourceId === $mine->id && $all['total'] === 2 ?: json_encode([$r['total'], $all['total']]);
});

check('countTo() with the viewer counts only visible links', fn() => $plugin->linkIndex->countTo($target->id, $user) === 1 && $plugin->linkIndex->countTo($target->id) === 2);

check('Links::findLinksTo() honours a viewer', fn() => count($plugin->links->findLinksTo($target, ['viewer' => $user])) === 1);

check('the Linked from panel shows 1 link and nothing about the hidden one', function() use ($user, $target) {
    Craft::$app->getUser()->setIdentity($user);
    try {
        $html = ReverseLookupPanel::html($target);
    } finally {
        Craft::$app->getUser()->setIdentity(null);
    }
    return str_contains($html, 'View all 1 link') && str_contains($html, 'FL53 scope mine') && !str_contains($html, 'theirs') && !str_contains($html, 'can’t view') ?: strip_tags($html);
});

$base = getenv('CP_BASE') ?: 'http://127.0.0.1';
$http = new Client(['base_uri' => $base, 'cookies' => new CookieJar(), 'allow_redirects' => false, 'http_errors' => false, 'timeout' => 60]);
$impersonate = trim((string)shell_exec('php craft users/impersonate ' . (int)$user->id . ' 2>/dev/null | grep -o "http[^ ]*"'));
$parts = parse_url($impersonate);
$http->get(($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : ''));

check('Links screen: only the visible row, total 1, no hidden label/URL/error', function() use ($http, $target) {
    $html = (string)$http->get('/admin/freelink/links?target=' . $target->id)->getBody();
    $rows = substr_count($html, '<tr data-id=');
    return $rows === 1 && !str_contains($html, 'SECRET-LABEL') && preg_match('/1.{1,12}1 of 1/u', strip_tags($html)) ?: "$rows rows";
});

check('Broken Links: the hidden entry’s 404 never shows', function() use ($http) {
    $html = (string)$http->get('/admin/freelink/broken?domain=fl53.example.net')->getBody();
    return substr_count($html, '<tr data-id=') === 0 && !str_contains($html, 'secret.fl53') && !str_contains($html, 'SECRET-ERROR') && str_contains($html, 'No links match') ?: 'leaked';
});

check('MCP freelink_find_links_to: total counts only the visible source', function() use ($user, $target, $mine) {
    if (!class_exists(craft\services\Mcp::class)) {
        return true;
    }
    $lines = [
        json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 't', 'version' => '1']]]),
        json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']),
        json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'freelink_find_links_to', 'arguments' => ['targetId' => $target->id]]]),
    ];
    $proc = proc_open(['php', 'craft', 'mcp/serve', '--user=' . $user->id], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, getcwd());
    foreach ($lines as $l) {
        fwrite($pipes[0], $l . "\n");
    }
    $out = null;
    $deadline = microtime(true) + 60;
    while (microtime(true) < $deadline && $out === null && ($line = fgets($pipes[1])) !== false) {
        $msg = json_decode($line, true);
        if (($msg['id'] ?? null) === 2) {
            $out = $msg;
        }
    }
    fclose($pipes[0]);
    proc_terminate($proc);
    $s = $out['result']['structuredContent'] ?? null;
    return ($s['total'] ?? null) === 1 && ($s['links'][0]['ownerId'] ?? null) === $mine->id ?: json_encode($out);
});

echo "\nNo access to the site\n";

$grant(['accessCp', 'accessPlugin-freelink', Plugin::PERMISSION_VIEW_LINKS, "viewEntries:$section->uid"]);

check('without editSite the visible source disappears too (Elements::canView site check)', function() use ($plugin, $target, $user, $mine) {
    $user = User::find()->id($user->id)->status(null)->one();
    return !Craft::$app->getElements()->canView($mine, $user) && $plugin->linkIndex->find(['targetId' => $target->id, 'viewer' => $user], 50)['total'] === 0;
});

echo "\nNo access to the section at all\n";

$grant(['accessCp', 'accessPlugin-freelink', Plugin::PERMISSION_VIEW_LINKS, $editSite]);

check('find() with the viewer returns nothing and counts nothing', fn() => $plugin->linkIndex->find(['targetId' => $target->id, 'viewer' => $user], 50)['total'] === 0);

check('Links screen: no rows and a zero total', function() use ($http, $target) {
    $html = (string)$http->get('/admin/freelink/links?target=' . $target->id)->getBody();
    return substr_count($html, '<tr data-id=') === 0 && !str_contains($html, 'SECRET-LABEL') && !str_contains($html, 'visible-label') ?: 'rows shown';
});

check('an admin still sees both', fn() => $plugin->linkIndex->find(['targetId' => $target->id, 'viewer' => $admin], 50)['total'] === 2);

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
