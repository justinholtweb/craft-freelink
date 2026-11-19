<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Services;

use CraftCms\Cms\Support\Query;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JustinHoltWeb\FreeLink\Database\Tables;

/**
 * Manages the freelink_links relations table.
 */
class Relations
{
    /**
     * Returns element link rows for a given field + owner.
     *
     * @return array<int, array{targetId: int|null, targetSiteId: int|null}> Keyed by sortOrder
     */
    public function getRelations(int $fieldId, int $ownerId, int $ownerSiteId): array
    {
        $rows = DB::table(Tables::LINKS)
            ->select(['sortOrder', 'targetId', 'targetSiteId'])
            ->where([
                'fieldId' => $fieldId,
                'ownerId' => $ownerId,
                'ownerSiteId' => $ownerSiteId,
            ])
            ->orderBy('sortOrder')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->sortOrder] = [
                'targetId' => $row->targetId ? (int) $row->targetId : null,
                'targetSiteId' => $row->targetSiteId ? (int) $row->targetSiteId : null,
            ];
        }

        return $result;
    }

    /**
     * Saves element link relations for a field + owner, replacing every existing row for this
     * field/owner/site.
     *
     * @param  array<int, array{sortOrder: int, targetId: int, targetSiteId?: int|null}>  $relations
     */
    public function saveRelations(int $fieldId, int $ownerId, int $ownerSiteId, array $relations): void
    {
        DB::transaction(function () use ($fieldId, $ownerId, $ownerSiteId, $relations): void {
            $this->deleteRelations($fieldId, $ownerId, $ownerSiteId);

            if ($relations === []) {
                return;
            }

            $now = Query::prepareDateForDb(now());
            $rows = [];

            foreach ($relations as $relation) {
                $rows[] = [
                    'fieldId' => $fieldId,
                    'ownerId' => $ownerId,
                    'ownerSiteId' => $ownerSiteId,
                    'sortOrder' => (int) $relation['sortOrder'],
                    'targetId' => (int) $relation['targetId'],
                    'targetSiteId' => isset($relation['targetSiteId']) ? (int) $relation['targetSiteId'] : null,
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => (string) Str::uuid(),
                ];
            }

            DB::table(Tables::LINKS)->insert($rows);
        });
    }

    /**
     * Deletes all relations for a field + owner.
     */
    public function deleteRelations(int $fieldId, int $ownerId, int $ownerSiteId): void
    {
        DB::table(Tables::LINKS)
            ->where([
                'fieldId' => $fieldId,
                'ownerId' => $ownerId,
                'ownerSiteId' => $ownerSiteId,
            ])
            ->delete();
    }

    /**
     * Deletes all relations for a given owner element (all fields, all sites).
     */
    public function deleteRelationsByOwner(int $ownerId): void
    {
        DB::table(Tables::LINKS)->where('ownerId', $ownerId)->delete();
    }

    /**
     * Owners whose element links point at a target (drafts and revisions included).
     *
     * @return list<array{ownerId: int, ownerSiteId: int, fieldId: int}>
     */
    public function getRelatedOwners(int $targetId, ?int $fieldId = null): array
    {
        $query = DB::table(Tables::LINKS)
            ->select(['ownerId', 'ownerSiteId', 'fieldId'])
            ->where('targetId', $targetId);

        if ($fieldId !== null) {
            $query->where('fieldId', $fieldId);
        }

        return $query->get()
            ->map(fn (object $row) => [
                'ownerId' => (int) $row->ownerId,
                'ownerSiteId' => (int) $row->ownerSiteId,
                'fieldId' => (int) $row->fieldId,
            ])
            ->values()
            ->all();
    }
}
