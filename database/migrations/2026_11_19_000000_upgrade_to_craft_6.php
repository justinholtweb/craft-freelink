<?php

declare(strict_types=1);

use CraftCms\Cms\Database\Migration;
use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\ProjectConfig\ProjectConfig;
use CraftCms\Cms\ProjectConfig\ProjectConfigHelper;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Json;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\FreeLink\Database\Tables;
use JustinHoltWeb\FreeLink\Fields\FreeLinkField;
use JustinHoltWeb\FreeLink\Helpers\LegacyClassMap;

/**
 * Brings a FreeLink 5.3 install onto FreeLink 6.
 *
 * 1. The field type moved from `justinholtweb\freelink\fields\FreeLinkField` to
 *    `JustinHoltWeb\FreeLink\Fields\FreeLinkField`. Craft stores it in `fields.type` and project
 *    config, so both are rewritten.
 * 2. Craft 6 has no categories. Craft 5's `entrify/categories` keeps element IDs, so a category link
 *    whose target is now an entry becomes an entry link (content JSON, index). One whose target is
 *    something else becomes a URL link to where the category was, or stays an (empty) category link
 *    when it had no URL. Fields that offered categories offer entries instead.
 *
 * Each step only changes what still needs changing, so a second run does nothing, and on a fresh
 * install there is nothing to change.
 */
return new class extends Migration
{
    private const array ENTRY_TYPES = [Entry::class, 'craft\\elements\\Entry'];

    public function up(): void
    {
        $this->renameClasses();
        $this->convertCategoryLinks();
    }

    private function renameClasses(): void
    {
        $renamed = 0;

        foreach (LegacyClassMap::MAP as $old => $new) {
            $renamed += DB::table(Table::FIELDS)->where('type', $old)->update(['type' => $new]);
        }

        $projectConfig = app(ProjectConfig::class);
        $muteEvents = $projectConfig->muteEvents;
        $projectConfig->muteEvents = true;

        try {
            foreach ((array) $projectConfig->get('fields') as $uid => $config) {
                $updated = LegacyClassMap::replace($config);

                if ($updated !== $config) {
                    $projectConfig->set("fields.$uid", $updated, 'Rename the FreeLink field type for Craft 6');
                    $renamed++;
                }
            }

            $projectConfig->saveModifiedConfigData();
        } finally {
            $projectConfig->muteEvents = $muteEvents;
        }

        if ($renamed > 0) {
            Log::info("FreeLink: renamed the FreeLink 5 field type in $renamed places.");
        }
    }

    private function convertCategoryLinks(): void
    {
        $fieldIds = DB::table(Table::FIELDS)->where('type', FreeLinkField::class)->pluck('id', 'uid')->all();

        if ($fieldIds === []) {
            return;
        }

        $this->enableEntriesWhereCategoriesWere(array_keys($fieldIds));

        // Layout element UIDs of each FreeLink field, by layout: the keys of its values in the content JSON.
        $instances = [];

        foreach (DB::table(Table::FIELDLAYOUTS)->whereNull('dateDeleted')->get(['id', 'config']) as $layout) {
            $config = Json::decodeIfJson((string) $layout->config);

            foreach ((array) ($config['tabs'] ?? []) as $tab) {
                foreach ((array) ($tab['elements'] ?? []) as $element) {
                    $fieldUid = $element['fieldUid'] ?? null;

                    if (is_string($fieldUid) && isset($fieldIds[$fieldUid], $element['uid'])) {
                        $instances[(int) $layout->id][(string) $element['uid']] = (int) $fieldIds[$fieldUid];
                    }
                }
            }
        }

        $converted = 0;

        foreach ($instances as $layoutId => $uids) {
            $rows = DB::table(Table::ELEMENTS_SITES.' as es')
                ->join(Table::ELEMENTS.' as e', 'e.id', '=', 'es.elementId')
                ->where('e.fieldLayoutId', $layoutId)
                ->where('es.content', 'like', '%"category"%')
                ->get(['es.id', 'es.elementId', 'es.siteId', 'es.content']);

            foreach ($rows as $row) {
                $content = Json::decodeIfJson((string) $row->content);

                if (! is_array($content)) {
                    continue;
                }

                $changed = false;

                foreach ($uids as $uid => $fieldId) {
                    if (! isset($content[$uid]) || ! is_array($content[$uid])) {
                        continue;
                    }

                    $single = isset($content[$uid]['type']);
                    $links = $single ? [$content[$uid]] : array_values($content[$uid]);

                    foreach ($links as $position => $link) {
                        if (! is_array($link) || ($link['type'] ?? null) !== 'category') {
                            continue;
                        }

                        $links[$position] = $this->convertLink($link, $fieldId, (int) $row->elementId, (int) $row->siteId, $position);
                        $changed = $changed || $links[$position] !== $link;
                    }

                    $content[$uid] = $single ? $links[0] : $links;
                }

                if ($changed) {
                    DB::table(Table::ELEMENTS_SITES)->where('id', $row->id)->update(['content' => Json::encode($content)]);
                    $converted++;
                }
            }
        }

        if ($converted > 0) {
            Log::info("FreeLink: converted category links in $converted element(s).");
        }
    }

    /**
     * @param  array<string, mixed>  $link
     * @return array<string, mixed>
     */
    private function convertLink(array $link, int $fieldId, int $ownerId, int $siteId, int $position): array
    {
        $relation = DB::table(Tables::LINKS)
            ->where(['fieldId' => $fieldId, 'ownerId' => $ownerId, 'ownerSiteId' => $siteId, 'sortOrder' => $position])
            ->first(['id', 'targetId', 'targetSiteId']);

        $targetType = $relation?->targetId
            ? DB::table(Table::ELEMENTS)->where('id', $relation->targetId)->value('type')
            : null;

        if (in_array($targetType, self::ENTRY_TYPES, true)) {
            DB::table(Tables::INDEX)
                ->where(['fieldId' => $fieldId, 'ownerId' => $ownerId, 'ownerSiteId' => $siteId, 'sortOrder' => $position])
                ->update(['type' => 'entry']);

            return ['type' => 'entry'] + $link;
        }

        $url = $relation?->targetId ? $this->lastKnownUrl((int) $relation->targetId, (int) ($relation->targetSiteId ?: $siteId)) : null;

        if ($url === null) {
            Log::warning("FreeLink: element #$ownerId links to category #".($relation->targetId ?? '?').', which is no longer an entry and had no URL; the link is left as it was and renders nothing.');

            return $link;
        }

        DB::table(Tables::LINKS)->where('id', $relation->id)->delete();
        DB::table(Tables::INDEX)
            ->where(['fieldId' => $fieldId, 'ownerId' => $ownerId, 'ownerSiteId' => $siteId, 'sortOrder' => $position])
            ->delete();
        Log::warning("FreeLink: element #$ownerId linked to category #{$relation->targetId}, which is no longer an entry; it is now a URL link to $url.");

        return ['type' => 'url', 'value' => $url] + $link;
    }

    private function lastKnownUrl(int $elementId, int $siteId): ?string
    {
        $row = DB::table(Table::ELEMENTS_SITES)
            ->where('elementId', $elementId)
            ->whereNotNull('uri')
            ->orderByRaw('case when '.DB::getQueryGrammar()->wrap('siteId').' = ? then 0 else 1 end', [$siteId])
            ->first(['siteId', 'uri']);

        if (! $row) {
            return null;
        }

        $baseUrl = Sites::getSiteById((int) $row->siteId, true)?->getBaseUrl();

        if (! $baseUrl) {
            return null;
        }

        return $row->uri === '__home__' ? $baseUrl : rtrim($baseUrl, '/').'/'.ltrim((string) $row->uri, '/');
    }

    /**
     * Fields that offered category links offer entry links instead (categories became entries).
     *
     * @param  list<string>  $fieldUids
     */
    private function enableEntriesWhereCategoriesWere(array $fieldUids): void
    {
        $projectConfig = app(ProjectConfig::class);
        $muteEvents = $projectConfig->muteEvents;
        $projectConfig->muteEvents = true;

        try {
            foreach ($fieldUids as $uid) {
                $config = $projectConfig->get("fields.$uid");

                if (! is_array($config) || ! is_array($config['settings'] ?? null)) {
                    continue;
                }

                // Project config keeps associative arrays packed (`__assoc__`); unpack to read them.
                $settings = ProjectConfigHelper::unpackAssociativeArrays($config['settings']);
                $linkTypes = $settings['linkTypes'] ?? null;

                if (! is_array($linkTypes) || empty($linkTypes['category']['enabled'])) {
                    continue;
                }

                $linkTypes['entry'] = array_merge(
                    ['label' => '', 'sources' => '*', 'sortOrder' => $linkTypes['category']['sortOrder'] ?? 999],
                    is_array($linkTypes['entry'] ?? null) ? $linkTypes['entry'] : [],
                    ['enabled' => true],
                );
                $linkTypes['category']['enabled'] = false;
                $settings['linkTypes'] = $linkTypes;
                $config['settings'] = ProjectConfigHelper::packAssociativeArrays($settings);

                $projectConfig->set("fields.$uid", $config, 'Offer entry links where FreeLink offered category links');
                // The fields table holds settings unpacked.
                DB::table(Table::FIELDS)->where('uid', $uid)->update(['settings' => Json::encode($settings)]);
                Log::info("FreeLink: field {$uid} offered category links; it offers entry links now.");
            }

            $projectConfig->saveModifiedConfigData();
        } finally {
            $projectConfig->muteEvents = $muteEvents;
        }
    }

    public function down(): void {}
};
