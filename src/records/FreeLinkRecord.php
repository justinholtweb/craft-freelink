<?php

namespace justinholtweb\freelink\records;

use craft\db\ActiveRecord;

/**
 * ActiveRecord for the freelink_links table.
 *
 * @property int $id
 * @property int $fieldId
 * @property int $ownerId
 * @property int $ownerSiteId
 * @property int $sortOrder
 * @property int|null $targetId
 * @property int|null $targetSiteId
 */
class FreeLinkRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%freelink_links}}';
    }
}
