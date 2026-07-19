<?php

namespace justinholtweb\freelink\tests\unit;

use justinholtweb\freelink\migrators\BaseMigrator;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the BaseMigrator run-loop.
 *
 * getSourceFields() returns craft\db\Query::all() rows, which are associative
 * ARRAYS — not objects. The run() loop must read those rows with array access
 * (`$field['id']`, `$field['handle']`); an earlier version used object syntax
 * (`$field->id`) which fatals ("Attempt to read property on array") the moment a
 * real migration runs. These tests drive run() with fake array rows and a
 * dry-run migrator (which short-circuits every DB call) so the contract is
 * exercised without a database.
 */
class BaseMigratorTest extends TestCase
{
    /**
     * @param array<int, array<string, mixed>> $sourceFields
     */
    private function makeMigrator(array $sourceFields, bool $succeed = true): BaseMigrator
    {
        return new class($sourceFields, $succeed) extends BaseMigrator {
            /** @var array<int, array<string, mixed>> */
            public array $migratedRows = [];

            /** @var array<int, array<string, mixed>> */
            private array $fakeFields;
            private bool $succeed;

            /**
             * @param array<int, array<string, mixed>> $fakeFields
             */
            public function __construct(array $fakeFields, bool $succeed)
            {
                // dryRun = true so _logMigrationStatus / DB writes short-circuit.
                parent::__construct(null, true);
                $this->fakeFields = $fakeFields;
                $this->succeed = $succeed;
            }

            public static function sourcePlugin(): string
            {
                return 'test';
            }

            public static function sourceFieldType(): string
            {
                return 'test\\SourceField';
            }

            protected function mapType(string $sourceType): ?string
            {
                return $sourceType;
            }

            /**
             * @return array<int, array<string, mixed>>
             */
            protected function getSourceFields(): array
            {
                return $this->fakeFields;
            }

            /**
             * @param array<string, mixed> $field
             */
            protected function migrateField(array $field): bool
            {
                $this->migratedRows[] = $field;

                return $this->succeed;
            }
        };
    }

    public function testRunConsumesFieldRowsAsArrays(): void
    {
        $migrator = $this->makeMigrator([
            ['id' => 5, 'handle' => 'firstField', 'settings' => '{}', 'columnSuffix' => null],
            ['id' => 9, 'handle' => 'secondField', 'settings' => '{}', 'columnSuffix' => null],
        ]);

        $result = $migrator->run();

        self::assertTrue($result);
        self::assertCount(2, $migrator->migratedRows);
        self::assertSame(5, $migrator->migratedRows[0]['id']);
        self::assertSame('firstField', $migrator->migratedRows[0]['handle']);

        // The run() loop interpolates $field['handle'] into its log messages;
        // object-syntax access on an array row would have logged an empty handle.
        self::assertContains('Successfully migrated field: firstField', $migrator->getLog());
        self::assertContains('Successfully migrated field: secondField', $migrator->getLog());
    }

    public function testRunHaltsAndLogsWhenMigrateFieldFails(): void
    {
        $migrator = $this->makeMigrator([
            ['id' => 1, 'handle' => 'brokenField', 'settings' => '{}', 'columnSuffix' => null],
            ['id' => 2, 'handle' => 'neverReached', 'settings' => '{}', 'columnSuffix' => null],
        ], succeed: false);

        $result = $migrator->run();

        self::assertFalse($result);
        // Halts on the first failure, so the second row is never migrated.
        self::assertCount(1, $migrator->migratedRows);
        self::assertContains('Failed to migrate field: brokenField', $migrator->getLog());
    }

    public function testRunSucceedsWithNoMatchingFields(): void
    {
        $migrator = $this->makeMigrator([]);

        self::assertTrue($migrator->run());
        self::assertContains('No matching fields found.', $migrator->getLog());
    }
}
