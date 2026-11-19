<?php
/**
 * FreeLink 5.3 control panel checks over HTTP (the harness's CP at http://127.0.0.1).
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php <plugin>/tests/integration/cp-5.3.php
 *
 * Logs in as the harness admin (CP_USER / CP_PASSWORD, default admin / claudepassword), creates 55
 * owner entries plus a target, and checks: the section landing redirect, the Links screen with
 * filters and pagination, the Broken Links view, the Linked from panel, the settings screen with a
 * custom nav label and sanitised SVG icon, the migration screen and its legacy URL, the icon picker
 * in the field UI, queueing a check (the job is removed unrun), and refusals for a user without
 * the permissions. Everything it changes is put back; entries are hard-deleted.
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
use justinholtweb\freelink\fields\FreeLinkField;
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
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

$base = getenv('CP_BASE') ?: 'http://127.0.0.1';
$jar = new CookieJar();
$http = new Client(['base_uri' => $base, 'cookies' => $jar, 'allow_redirects' => false, 'http_errors' => false, 'timeout' => 60]);

function csrf(Client $http, string $path = '/admin/login'): string
{
    $html = (string)$http->get($path)->getBody();
    preg_match('/name="CRAFT_CSRF_TOKEN" value="([^"]+)"|csrfTokenValue":"([^"]+)"/', $html, $m);
    return $m[1] ?: ($m[2] ?? '');
}

function login(Client $http, string $user, string $password): bool
{
    $token = csrf($http);
    $res = $http->post('/admin/actions/users/login', [
        'headers' => ['Accept' => 'application/json'],
        'form_params' => ['loginName' => $user, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $token],
    ]);
    return $res->getStatusCode() === 200 && str_contains((string)$res->getBody(), 'returnUrl');
}

function post(Client $http, string $action, array $params, string $from = '/admin/freelink/links'): \Psr\Http\Message\ResponseInterface
{
    $token = csrf($http, $from);
    // Posted to the page the form lives on, with an action param, as the browser does: a failed
    // save re-renders that page with its errors.
    return $http->post($from, ['form_params' => $params + ['action' => $action, 'CRAFT_CSRF_TOKEN' => $token]]);
}

$adminUser = getenv('CP_USER') ?: 'admin';
if (!login($http, $adminUser, getenv('CP_PASSWORD') ?: 'claudepassword')) {
    // The harness admin's password drifts; restore it the way Craft does (see plugin-testing notes).
    $admin = User::find()->username($adminUser)->status(null)->one();
    $admin->newPassword = getenv('CP_PASSWORD') ?: 'claudepassword';
    Craft::$app->getElements()->saveElement($admin, false);
    if (!login($http, $adminUser, getenv('CP_PASSWORD') ?: 'claudepassword')) {
        echo "Couldn't log in.\n";
        exit(1);
    }
}

$plugin = Plugin::getInstance();
$section = Craft::$app->getEntries()->getSectionByHandle('testEntries');
$type = $section->getEntryTypes()[0];
$siteId = Craft::$app->getSites()->getPrimarySite()->id;
$created = [];
$fieldId = Craft::$app->getFields()->getFieldByHandle('freelinkItTest')->id;
$queuedJobIds = [];

register_shutdown_function(function() use (&$created, &$queuedJobIds, $fieldId) {
    foreach (array_reverse($created) as $id) {
        if ($e = Entry::find()->id($id)->status(null)->trashed(null)->one()) {
            Craft::$app->getElements()->deleteElement($e, true);
        }
    }
    if ($queuedJobIds) {
        Craft::$app->getDb()->createCommand()->delete('{{%queue}}', ['id' => $queuedJobIds])->execute();
    }
    echo "\nCleanup: deleted " . count($created) . " entries, " . count($queuedJobIds) . " queued jobs, " . Plugin::getInstance()->checker->deleteOrphans() . " check results\n";

    // Settings and field changes were made over HTTP; put them back from a fresh process.
    $script = sys_get_temp_dir() . '/freelink-cp-restore.php';
    file_put_contents($script, '<?php
        chdir(' . var_export(getcwd(), true) . ');
        require "bootstrap.php";
        $app = require CRAFT_VENDOR_PATH . "/craftcms/cms/bootstrap/console.php";
        $pc = Craft::$app->getProjectConfig();
        $pc->remove("plugins.freelink.settings");
        $f = Craft::$app->getFields()->getFieldById(' . (int)$fieldId . ');
        $f->allowIcons = false;
        Craft::$app->getFields()->saveField($f);
        $pc->flush();
        echo "  settings and field restored\n";
    ');
    passthru('php ' . escapeshellarg($script));
    @unlink($script);
});

$make = function(string $title, ?array $link = null) use ($section, $type, $siteId, &$created): Entry {
    $e = new Entry(['sectionId' => $section->id, 'typeId' => $type->id, 'siteId' => $siteId, 'title' => $title]);
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

echo "Fixtures\n";
$target = $make('FL53 CP target');
for ($i = 1; $i <= 55; $i++) {
    $make("FL53 CP owner $i", $i % 5 === 0
        ? ['type' => 'entry', 'targetId' => $target->id]
        : ['type' => 'url', 'value' => "https://cp$i.fl53.example.net/page"]);
}
echo "  56 entries\n";

echo "\nSection and Links screen\n";

check('admin/freelink redirects to the Links screen', function() use ($http) {
    $res = $http->get('/admin/freelink');
    return $res->getStatusCode() === 302 && str_contains($res->getHeaderLine('Location'), 'freelink/links') ?: $res->getStatusCode() . ' ' . $res->getHeaderLine('Location');
});

check('the Links screen renders with the subnav and actions', function() use ($http) {
    $html = (string)$http->get('/admin/freelink/links')->getBody();
    return str_contains($html, 'freelink-links-table') && str_contains($html, 'Broken Links') && str_contains($html, 'Migrations')
        && str_contains($html, 'freelink/links/queue-check') ?: substr(strip_tags($html), 0, 300);
});

check('domain filter + pagination: 44 URL links on fl53.example.net, 50 on page 1, 44 of 44', function() use ($http) {
    $p1 = (string)$http->get('/admin/freelink/links?domain=fl53.example.net&type=url')->getBody();
    $rows1 = substr_count($p1, '<tr data-id=');
    $p2 = (string)$http->get('/admin/freelink/links?domain=fl53.example.net&type=url&page=2')->getBody();
    $rows2 = substr_count($p2, '<tr data-id=');
    // 44 URL links: one page of 44.
    return $rows1 === 44 && $rows2 === 0 && preg_match('/1.{1,12}44 of 44/u', strip_tags($p1)) ?: "$rows1 / $rows2";
});

check('pagination over 50: the section has 55+ links, page 1 shows 50, page 2 the rest', function() use ($http, $section) {
    $total = Plugin::getInstance()->linkIndex->find(['sectionId' => $section->id], 1)['total'];
    $p1 = (string)$http->get('/admin/freelink/links?section=' . $section->id)->getBody();
    $p2 = (string)$http->get('/admin/freelink/links?section=' . $section->id . '&page=2')->getBody();
    $a = substr_count($p1, '<tr data-id=');
    $b = substr_count($p2, '<tr data-id=');
    return $total >= 55 && $a === 50 && $b === $total - 50 && str_contains($p1, 'page=2') && str_contains(strip_tags($p1), "of $total") ?: "$total: $a/$b";
});

check('type filter: entry links to the target, with the reverse-lookup header', function() use ($http, $target) {
    $html = (string)$http->get('/admin/freelink/links?target=' . $target->id)->getBody();
    return substr_count($html, '<tr data-id=') === 11 && str_contains($html, 'Links to') && str_contains($html, 'FL53 CP target') ?: substr_count($html, '<tr data-id=');
});

check('the Broken Links view shows only problems (none among the new rows)', function() use ($http) {
    $html = (string)$http->get('/admin/freelink/broken?domain=fl53.example.net')->getBody();
    return substr_count($html, '<tr data-id=') === 0 && str_contains($html, 'No links match') ?: substr_count($html, '<tr data-id=');
});

check('a stored 404 appears under Broken Links with its code', function() use ($http, $plugin) {
    $plugin->checker->saveResult(sha1('https://cp1.fl53.example.net/page'), 'https://cp1.fl53.example.net/page', ['status' => 'broken', 'httpCode' => 404, 'finalUrl' => null, 'redirects' => 0, 'error' => null]);
    $html = (string)$http->get('/admin/freelink/broken?domain=fl53.example.net')->getBody();
    return substr_count($html, '<tr data-id=') === 1 && str_contains($html, '(404)') ?: substr_count($html, '<tr data-id=');
});

check('a hostile domain filter is ignored, not injected', function() use ($http) {
    $res = $http->get('/admin/freelink/links?domain=' . rawurlencode("x' OR 1=1 --") . '&type=url&field=abc&section=-1');
    return $res->getStatusCode() === 200;
});

check('"Check links now" queues a CheckLinks job (removed unrun)', function() use ($http, &$queuedJobIds) {
    $before = (int)(new craft\db\Query())->from('{{%queue}}')->max('id');
    $res = post($http, 'freelink/links/queue-check', ['redirect' => Craft::$app->getSecurity()->hashData('freelink/links')]);
    $jobs = (new craft\db\Query())->select(['id', 'description'])->from('{{%queue}}')->where(['>', 'id', $before])->all();
    $ours = array_filter($jobs, fn($j) => str_contains((string)$j['description'], 'FreeLink'));
    $queuedJobIds = array_merge($queuedJobIds, array_column($ours, 'id'));
    return $res->getStatusCode() === 302 && count($ours) === 1 ?: $res->getStatusCode() . ' ' . json_encode($jobs);
});

check('queue-check refuses a GET', fn() => $http->get('/admin/actions/freelink/links/queue-check')->getStatusCode() >= 400);

echo "\nLinked from panel\n";

check('the target’s edit screen shows "Linked from" with a link to the filtered Links screen', function() use ($http, $target) {
    $html = (string)$http->get('/admin/entries/testEntries/' . $target->id)->getBody();
    return str_contains($html, 'freelink-linked-from') && str_contains($html, 'View all 11 links') ?: (str_contains($html, 'Linked from') ? 'partial' : 'missing');
});

echo "\nSettings and sidebar\n";

$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" onload="alert(1)"><script>alert(2)</script><circle cx="5" cy="5" r="4" fill="currentColor"/></svg>';

check('the settings screen renders the sidebar and check settings', function() use ($http) {
    $html = (string)$http->get('/admin/settings/plugins/freelink')->getBody();
    return str_contains($html, 'navLabel') && str_contains($html, 'icon-picker') && str_contains($html, 'navIconSvg') && str_contains($html, 'enableExternalChecks')
        && !str_contains($html, 'checkAllowedHosts"') ?: substr(strip_tags($html), 0, 200);
});

check('invalid SVG is refused', function() use ($http) {
    $res = post($http, 'plugins/save-plugin-settings', ['pluginHandle' => 'freelink', 'settings' => ['navIconSvg' => '<div>nope</div>']], '/admin/settings/plugins/freelink');
    $html = (string)$res->getBody();
    return $res->getStatusCode() === 200 && str_contains($html, 'Paste a single') ?: $res->getStatusCode();
});

check('a custom label + SVG are saved; config-only settings are not', function() use ($http, $svg) {
    $res = post($http, 'plugins/save-plugin-settings', ['pluginHandle' => 'freelink', 'settings' => [
        'navLabel' => 'Link Desk',
        'navIcon' => 'link',
        'navIconSvg' => $svg,
        'checkAllowedHosts' => ['169.254.169.254'],
        'checkAllowedPorts' => [22],
    ]], '/admin/settings/plugins/freelink');
    $stored = Craft::$app->getProjectConfig()->get('plugins.freelink.settings', true) ?? [];
    return $res->getStatusCode() === 302 && !array_key_exists('checkAllowedHosts', $stored) && !array_key_exists('checkAllowedPorts', $stored)
        ?: $res->getStatusCode() . ' ' . json_encode(array_keys($stored));
});

check('the sidebar shows the custom label and the sanitised SVG', function() use ($http) {
    $html = (string)$http->get('/admin/dashboard')->getBody();
    preg_match('/<li[^>]*id="nav-freelink".*?<\/li>/s', $html, $m);
    $item = $m[0] ?? '';
    return str_contains($item, 'Link Desk') && str_contains($item, '<circle') && !str_contains($item, 'onload') && !str_contains($item, '<script')
        ?: substr($item, 0, 400);
});

check('with the SVG cleared, the picked icon is used', function() use ($http) {
    post($http, 'plugins/save-plugin-settings', ['pluginHandle' => 'freelink', 'settings' => ['navLabel' => 'Link Desk', 'navIcon' => 'link', 'navIconSvg' => '']], '/admin/settings/plugins/freelink');
    $html = (string)$http->get('/admin/dashboard')->getBody();
    preg_match('/<li[^>]*id="nav-freelink".*?<\/li>/s', $html, $m);
    $item = $m[0] ?? '';
    return str_contains($item, 'Link Desk') && str_contains($item, '<svg') && !str_contains($item, '<circle') ?: substr($item, 0, 300);
});

echo "\nMigrations\n";

check('the migration screen renders', function() use ($http) {
    $res = $http->get('/admin/freelink/migrations');
    $html = (string)$res->getBody();
    return $res->getStatusCode() === 200 && str_contains($html, 'Migration history') ?: $res->getStatusCode();
});

check('the pre-5.3 URL redirects', function() use ($http) {
    $res = $http->get('/admin/freelink/migrate');
    return $res->getStatusCode() === 301 && str_contains($res->getHeaderLine('Location'), 'freelink/migrations') ?: $res->getStatusCode();
});

check('a dry run from the CP reports its log and changes nothing', function() use ($http) {
    $fieldsBefore = (new craft\db\Query())->select(['id', 'type'])->from('{{%fields}}')->all();
    $res = post($http, 'freelink/migrations/run', ['source' => 'hyper', 'field' => 'noSuchFieldFl53', 'dryRun' => '1'], '/admin/freelink/migrations');
    $html = (string)$http->get('/admin/freelink/migrations')->getBody();
    $fieldsAfter = (new craft\db\Query())->select(['id', 'type'])->from('{{%fields}}')->all();
    return $res->getStatusCode() === 302 && str_contains($html, 'freelink-migration-log') && $fieldsBefore === $fieldsAfter ?: $res->getStatusCode();
});

check('an unknown migration source is a 400', fn() => post($http, 'freelink/migrations/run', ['source' => 'evil', 'dryRun' => '1'], '/admin/freelink/migrations')->getStatusCode() === 400);

echo "\nField UI\n";

check('with icons allowed, the link block has an icon picker (free icons only)', function() use ($http, $fieldId, $created) {
    // Project config was changed over HTTP, so this process's copy is stale: save from a fresh one.
    $script = sys_get_temp_dir() . '/freelink-cp-icons.php';
    file_put_contents($script, '<?php chdir(' . var_export(getcwd(), true) . '); require "bootstrap.php"; $app = require CRAFT_VENDOR_PATH . "/craftcms/cms/bootstrap/console.php";
        $f = Craft::$app->getFields()->getFieldById(' . (int)$fieldId . '); $f->allowIcons = true; Craft::$app->getFields()->saveField($f); Craft::$app->getProjectConfig()->flush();');
    shell_exec('php ' . escapeshellarg($script));
    @unlink($script);
    $html = (string)$http->get('/admin/entries/testEntries/' . $created[1])->getBody();
    return str_contains($html, 'freelink-icon-field') && str_contains($html, 'data-free-only="1"') && str_contains($html, 'Craft.IconPicker') ?: 'no picker';
});

check('an admin sees the linked element’s chip in the field input', function() use ($http, $created, $target) {
    // created[5] is "FL53 CP owner 5", which links to the target entry.
    $html = (string)$http->get('/admin/entries/testEntries/' . $created[5])->getBody();
    return str_contains($html, 'FL53 CP target') && !str_contains($html, 'freelink-hidden-target') ?: 'no chip';
});

echo "\nPermissions\n";

$plain = new Client(['base_uri' => $base, 'cookies' => new CookieJar(), 'allow_redirects' => false, 'http_errors' => false]);
$nonAdmin = User::find()->admin(false)->status('active')->one();
$url = trim((string)shell_exec('php craft users/impersonate ' . (int)$nonAdmin->id . ' 2>/dev/null | grep -o "http[^ ]*"'));

check('a non-admin without FreeLink permissions is refused everywhere', function() use ($plain, $url, $nonAdmin) {
    if (!$url) {
        return 'no impersonation URL';
    }
    $parts = parse_url($url);
    $plain->get(($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : ''));
    $codes = [];
    foreach (['/admin/freelink/links', '/admin/freelink/broken', '/admin/freelink/migrations'] as $path) {
        $codes[$path] = $plain->get($path)->getStatusCode();
    }
    $can = Craft::$app->getUserPermissions()->doesUserHavePermission($nonAdmin->id, Plugin::PERMISSION_VIEW_LINKS);
    return !$can && count(array_filter($codes, fn($c) => $c === 403)) === 3 ?: json_encode($codes);
});

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
