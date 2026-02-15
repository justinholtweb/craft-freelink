<?php

namespace justinholtweb\freelink\services;

use Craft;
use craft\db\Query;
use justinholtweb\freelink\records\FreeLinkRecord;
use yii\base\Component;

/**
 * Manages the freelink_links relations table.
 */
class Relations extends Component
{
    /**
     * Returns element link rows for a given field + owner.
     *
     * @return array<int, array> Keyed by sortOrder
     */
    public function getRelations(int $fieldId, int $ownerId, int $ownerSiteId): array
    {
        $rows = (new Query())
            ->select(['sortOrder', 'targetId', 'targetSiteId'])
            ->from('{{%freelink_links}}')
            ->where([
                'fieldId' => $fieldId,
                'ownerId' => $ownerId,
                'ownerSiteId' => $ownerSiteId,
            ])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['sortOrder']] = [
                'targetId' => $row['targetId'] ? (int)$row['targetId'] : null,
                'targetSiteId' => $row['targetSiteId'] ? (int)$row['targetSiteId'] : null,
            ];
        }

        return $result;
    }

    /**
     * Saves element link relations for a field + owner.
     * Replaces all existing rows for this field/owner/site combination.
     *
     * @param array $relations Array of ['sortOrder' => int, 'targetId' => int, 'targetSiteId' => int|null]
     */
    public function saveRelations(int $fieldId, int $ownerId, int $ownerSiteId, array $relations): void
    {
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            // Delete existing relations for this field/owner/site
            $db->createCommand()
                ->delete('{{%freelink_links}}', [
                    'fieldId' => $fieldId,
                    'ownerId' => $ownerId,
                    'ownerSiteId' => $ownerSiteId,
                ])
                ->execute();

            // Insert new relations
            foreach ($relations as $relation) {
                $record = new FreeLinkRecord();
                $record->fieldId = $fieldId;
                $record->ownerId = $ownerId;
                $record->ownerSiteId = $ownerSiteId;
                $record->sortOrder = $relation['sortOrder'];
                $record->targetId = $relation['targetId'];
                $record->targetSiteId = $relation['targetSiteId'] ?? null;
                $record->save(false);
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Deletes all relations for a field + owner.
     */
    public function deleteRelations(int $fieldId, int $ownerId, int $ownerSiteId): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%freelink_links}}', [
                'fieldId' => $fieldId,
                'ownerId' => $ownerId,
                'ownerSiteId' => $ownerSiteId,
            ])
            ->execute();
    }

    /**
     * Deletes all relations for a given owner element (all fields, all sites).
     */
    public function deleteRelationsByOwner(int $ownerId): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%freelink_links}}', [
                'ownerId' => $ownerId,
            ])
            ->execute();
    }

    /**
     * Returns an eager-loading map: owner element IDs → target element IDs.
     * Used by FreeLinkField::getEagerLoadingMap().
     */
    public function getEagerLoadingMap(int $fieldId, array $ownerElementIds): array
    {
        if (empty($ownerElementIds)) {
            return [];
        }

        return (new Query())
            ->select(['ownerId as source', 'targetId as target'])
            ->from('{{%freelink_links}}')
            ->where([
                'fieldId' => $fieldId,
                'ownerId' => $ownerElementIds,
            ])
            ->andWhere(['not', ['targetId' => null]])
            ->all();
    }

    /**
     * Returns all relations for a field (used for reverse lookups).
     */
    public function getRelatedOwners(int $targetId, ?int $fieldId = null): array
    {
        $query = (new Query())
            ->select(['ownerId', 'ownerSiteId', 'fieldId'])
            ->from('{{%freelink_links}}')
            ->where(['targetId' => $targetId]);

        if ($fieldId !== null) {
            $query->andWhere(['fieldId' => $fieldId]);
        }

        return $query->all();
    }
}
