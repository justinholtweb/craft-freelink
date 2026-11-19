<?php
/**
 * FreeLink 5.3 MCP checks over Craft 5.12+'s stdio server (`php craft mcp/serve`).
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php <plugin>/tests/integration/mcp-5.3.php
 *
 * Creates a few Test Entries (hard-deleted at the end) and a stored check result, then calls the
 * FreeLink tools and core's element tools as an admin and as a non-admin.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\base\Element;
use craft\elements\Entry;
use craft\elements\User;
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

if (!class_exists(craft\services\Mcp::class)) {
    echo "Craft's MCP server needs Craft 5.12+.\n";
    exit(0);
}

/**
 * Calls MCP tools over stdio as a user; returns each call's result keyed by request id.
 *
 * @param array<int, array{0: string, 1: array<string, mixed>}> $calls
 * @return array<int, array<string, mixed>>
 */
function mcp(string $user, array $calls, bool $list = false): array
{
    $lines = [
        json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'freelink-test', 'version' => '1']]]),
        json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']),
    ];

    if ($list) {
        $lines[] = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new stdClass()]);
        $lines[] = json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list', 'params' => ['cursor' => null]]);
    }

    foreach ($calls as $id => [$name, $args]) {
        $lines[] = json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => (object)$args]]);
    }

    $proc = proc_open(['php', 'craft', 'mcp/serve', '--user=' . $user], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, getcwd());
    $expected = count($calls) + 1 + ($list ? 2 : 0);
    $out = [];

    foreach ($lines as $line) {
        fwrite($pipes[0], $line . "\n");
    }

    stream_set_blocking($pipes[1], false);
    $buffer = '';
    $deadline = microtime(true) + 60;

    while (microtime(true) < $deadline && count($out) < $expected) {
        $chunk = fread($pipes[1], 65536);
        if ($chunk === '' || $chunk === false) {
            usleep(50000);
            continue;
        }
        $buffer .= $chunk;
        while (($pos = strpos($buffer, "\n")) !== false) {
            $msg = json_decode(substr($buffer, 0, $pos), true);
            $buffer = substr($buffer, $pos + 1);
            if (isset($msg['id'])) {
                $out[$msg['id']] = $msg;
            }
        }
    }

    fclose($pipes[0]);
    proc_terminate($proc);

    return $out;
}

/**
 * Every tool name visible to a user, following tools/list pagination.
 *
 * @return string[]
 */
function mcpTools(string $user): array
{
    $proc = proc_open(['php', 'craft', 'mcp/serve', '--user=' . $user], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, getcwd());
    $send = fn(array $m) => fwrite($pipes[0], json_encode($m) . "\n");
    $buffer = '';
    $read = function(int $id) use ($pipes, &$buffer): ?array {
        $deadline = microtime(true) + 60;
        while (microtime(true) < $deadline) {
            while (($pos = strpos($buffer, "\n")) !== false) {
                $msg = json_decode(substr($buffer, 0, $pos), true);
                $buffer = substr($buffer, $pos + 1);
                if (($msg['id'] ?? null) === $id) {
                    return $msg;
                }
            }
            $chunk = fgets($pipes[1]);
            if ($chunk !== false) {
                $buffer .= $chunk;
            }
        }
        return null;
    };

    $send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 't', 'version' => '1']]]);
    $read(1);
    $send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

    $names = [];
    $cursor = null;
    for ($id = 100; $id < 120; $id++) {
        $send(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/list', 'params' => $cursor ? ['cursor' => $cursor] : new stdClass()]);
        $msg = $read($id);
        foreach ($msg['result']['tools'] ?? [] as $tool) {
            $names[] = $tool['name'];
        }
        $cursor = $msg['result']['nextCursor'] ?? null;
        if (!$cursor) {
            break;
        }
    }

    fclose($pipes[0]);
    proc_terminate($proc);

    return $names;
}

function structured(array $msg): mixed
{
    return $msg['result']['structuredContent'] ?? (isset($msg['result']['content'][0]['text']) ? json_decode($msg['result']['content'][0]['text'], true) : null);
}

$section = Craft::$app->getEntries()->getSectionByHandle('testEntries');
$type = $section->getEntryTypes()[0];
$siteId = Craft::$app->getSites()->getPrimarySite()->id;
$created = [];

$make = function(string $title, ?array $link = null, array $attrs = []) use ($section, $type, $siteId, &$created): Entry {
    $e = new Entry(['sectionId' => $section->id, 'typeId' => $type->id, 'siteId' => $siteId, 'title' => $title] + $attrs);
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

register_shutdown_function(function() use (&$created) {
    foreach (array_reverse($created) as $id) {
        if ($e = Entry::find()->id($id)->status(null)->trashed(null)->one()) {
            Craft::$app->getElements()->deleteElement($e, true);
        }
    }
    echo "\nCleanup: deleted " . count($created) . " entries, removed " . Plugin::getInstance()->checker->deleteOrphans() . " check results\n";
});

$target = $make('FL53 MCP target');
$target2 = $make('FL53 MCP target 2');
$disabled = $make('FL53 MCP disabled target', null, ['enabled' => false]);
$ownerA = $make('FL53 MCP owner A', ['type' => 'entry', 'targetId' => $target->id]);
$ownerB = $make('FL53 MCP owner B', ['type' => 'url', 'value' => 'https://broken.example.org/gone']);
$ownerC = $make('FL53 MCP owner C', ['type' => 'entry', 'targetId' => $disabled->id]);
Plugin::getInstance()->checker->saveResult(sha1('https://broken.example.org/gone'), 'https://broken.example.org/gone', [
    'status' => 'broken', 'httpCode' => 404, 'finalUrl' => null, 'redirects' => 0, 'error' => null,
]);

echo "Admin\n";

$admin = User::find()->admin()->one();
$r = mcp($admin->username, [
    10 => ['freelink_find_links_to', ['targetId' => $target->id]],
    11 => ['freelink_list_links', ['domain' => 'example.org']],
    12 => ['freelink_check_links', ['field' => 'freelinkItTest']],
    13 => ['freelink_migration_status', []],
    14 => ['freelink_migrate', ['source' => 'hyper', 'field' => 'noSuchField']],
    15 => ['freelink_migrate', ['source' => 'hyper', 'dryRun' => false]],
    16 => ['elements_field_schema', ['type' => 'entries', 'id' => $ownerA->id]],
    17 => ['elements_update', ['type' => 'entries', 'id' => $ownerA->id, 'fields' => ['freelinkItTest' => ['type' => 'entry', 'targetId' => $target2->id, 'label' => 'Via MCP']]]],
    18 => ['elements_get', ['type' => 'entries', 'id' => $ownerA->id, 'fields' => ['freelinkItTest']]],
    19 => ['elements_update', ['type' => 'entries', 'id' => $ownerA->id, 'fields' => ['freelinkItTest' => ['type' => 'entry', 'targetId' => 999999999]]]],
    20 => ['elements_update', ['type' => 'entries', 'id' => $ownerA->id, 'fields' => ['freelinkItTest' => ['type' => 'phone', 'value' => '1']]]],
    21 => ['freelink_find_links_to', ['targetId' => 999999999]],
]);

$tools = mcpTools($admin->username);

check('the five FreeLink tools are listed for an admin', function() use ($tools) {
    $ours = array_values(array_filter($tools, fn($n) => str_starts_with($n, 'freelink_')));
    sort($ours);
    return $ours === ['freelink_check_links', 'freelink_find_links_to', 'freelink_list_links', 'freelink_migrate', 'freelink_migration_status'] ?: json_encode($ours);
});

check('freelink_find_links_to returns the owner', function() use ($r, $ownerA) {
    $s = structured($r[10]);
    return ($s['links'][0]['ownerId'] ?? null) === $ownerA->id && $s['links'][0]['field'] === 'freelinkItTest' && $s['target']['title'] === 'FL53 MCP target' ?: json_encode($s);
});

check('freelink_find_links_to answers "not found" for a missing element', fn() => ($r[21]['result']['isError'] ?? false) === true);

check('freelink_list_links filters by domain', function() use ($r, $ownerB) {
    $s = structured($r[11]);
    return count($s['links']) === 1 && $s['links'][0]['ownerId'] === $ownerB->id && $s['links'][0]['url'] === 'https://broken.example.org/gone' ?: json_encode($s);
});

check('freelink_check_links reports the disabled target and the stored 404', function() use ($r, $ownerB, $ownerC) {
    $s = structured($r[12]);
    $found = [];
    foreach ($s['findings'] ?? [] as $f) {
        $found[$f['check'] . ':' . $f['ownerId']] = $f;
    }
    return isset($found['disabledTarget:' . $ownerC->id], $found['httpBroken:' . $ownerB->id]) && $found['httpBroken:' . $ownerB->id]['httpCode'] === 404
        ?: json_encode(array_keys($found));
});

check('freelink_migration_status answers', fn() => is_array(structured($r[13])['history'] ?? null) ?: json_encode($r[13]));
check('freelink_migrate dry-runs by default', function() use ($r) {
    $s = structured($r[14]);
    return ($s['dryRun'] ?? null) === true ?: json_encode($r[14]);
});
check('freelink_migrate refuses a real run without confirm', fn() => ($r[15]['result']['isError'] ?? false) === true && str_contains(json_encode($r[15]), 'confirm'));

check('elements_field_schema describes the FreeLink field from getInputSchema()', function() use ($r) {
    $schema = json_encode(structured($r[16]));
    return str_contains($schema, 'freelinkItTest') && str_contains($schema, 'targetId') && str_contains($schema, '"enum"') ?: substr($schema, 0, 300);
});

check('elements_update writes a new element target (no stale relation)', function() use ($r, $ownerA, $target2) {
    if (!empty($r[17]['result']['isError']) || isset($r[17]['error'])) {
        return json_encode($r[17]);
    }
    $fresh = Entry::find()->id($ownerA->id)->status(null)->one();
    $link = $fresh->getFieldValue('freelinkItTest')->first();
    return $link->targetId === $target2->id && $link->label === 'Via MCP' ?: json_encode([$link->targetId, $link->label]);
});

check('elements_get returns the resolved link with the target element', function() use ($r, $target2) {
    $json = json_encode(structured($r[18]));
    return str_contains($json, '"targetId":' . $target2->id) && str_contains($json, '"url"') && str_contains($json, '"target"') ?: substr($json, 0, 400);
});

check('elements_update refuses a target that does not exist', fn() => !empty($r[19]['result']['isError']) || isset($r[19]['error']) ?: json_encode($r[19]));
check('elements_update refuses a link type the field does not enable', fn() => !empty($r[20]['result']['isError']) || isset($r[20]['error']) ?: json_encode($r[20]));

echo "\nNon-admin\n";

$nonAdmin = User::find()->admin(false)->status(null)->one();
$r2 = mcp((string)$nonAdmin->id, [
    30 => ['freelink_migrate', ['source' => 'hyper']],
    31 => ['freelink_migration_status', []],
]);
$tools2 = mcpTools((string)$nonAdmin->id);

check('migration tools are hidden from a non-admin', fn() => count($tools2) > 0 && !in_array('freelink_migrate', $tools2, true) && !in_array('freelink_migration_status', $tools2, true) ?: json_encode(array_values(array_filter($tools2, fn($n) => str_starts_with($n, 'freelink')))));
check('calling them anyway fails', fn() => isset($r2[30]['error']) || !empty($r2[30]['result']['isError']) ?: json_encode($r2[30]));

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
