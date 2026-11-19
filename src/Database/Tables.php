<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Database;

/**
 * FreeLink's tables (unprefixed; Laravel adds the connection prefix).
 */
final class Tables
{
    /** Element-link targets, keyed by (fieldId, ownerId, ownerSiteId, sortOrder). The source of truth. */
    public const string LINKS = 'freelink_links';

    /** Migration history. */
    public const string MIGRATIONS = 'freelink_migrations';

    /** One row per link in live content. Derived; rebuilt by the IndexLinks job. */
    public const string INDEX = 'freelink_index';

    /** The last HTTP check of each distinct URL. */
    public const string CHECKS = 'freelink_checks';
}
