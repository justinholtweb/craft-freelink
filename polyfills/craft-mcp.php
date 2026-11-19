<?php

/**
 * Craft 5.12 / 5.12.1 field interfaces for MCP, declared only when the installed Craft lacks them.
 *
 * FreeLinkField implements both so core's MCP element tools can read and write FreeLink values on
 * Craft 5.12.1+, while FreeLink still installs on Craft 5.0–5.12.0 (where nothing calls these
 * methods). Loaded by src/fields/FreeLinkField.php before the class is declared. Not autoloaded and
 * not analysed: on a Craft version that has the interfaces, this file declares nothing.
 */

namespace craft\base;

if (!interface_exists(InputSchemaFieldInterface::class)) {
    interface InputSchemaFieldInterface extends FieldInterface
    {
        /**
         * @return array<string, mixed>
         */
        public function getInputSchema(): array;
    }
}

if (!interface_exists(McpOutputFieldInterface::class)) {
    interface McpOutputFieldInterface extends FieldInterface
    {
        public function getMcpValue(mixed $value, ElementInterface $element, \craft\mcp\McpOutputContext $context): mixed;
    }
}
