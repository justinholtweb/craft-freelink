<?php

namespace justinholtweb\freelink\models;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\base\Model;
use DateTime;
use justinholtweb\freelink\base\Link;
use justinholtweb\freelink\fields\FreeLinkField;

/**
 * One link in live content, as the link index records it: where it is (source element, field,
 * position), where it points, and its last known status.
 *
 * Returned by `Links::findLinksTo()`, `craft.freelink.linksTo()`, the Links screen, GraphQL's
 * `freelinkLinksTo` and the MCP tools.
 */
class LinkReference extends Model
{
    public int $id = 0;
    public int $fieldId = 0;
    /** The element whose field holds the link (a nested entry, for links inside Matrix). */
    public int $sourceId = 0;
    public int $sourceSiteId = 0;
    /** The top-level element an editor opens (the source itself unless it is nested). */
    public int $rootSourceId = 0;
    public int $sortOrder = 0;
    public string $type = '';
    public bool $isElement = false;
    public ?int $targetId = null;
    public ?int $targetSiteId = null;
    public ?string $url = null;
    public ?string $host = null;
    public ?string $label = null;

    /** Element links: `live`, `missing`, `trashed`, `disabled`, `pending` or `expired`. */
    public ?string $elementStatus = null;
    /** URL links: the stored check status, or null when the URL was never checked. */
    public ?string $checkStatus = null;
    public ?int $httpCode = null;
    public ?string $checkError = null;
    public ?string $finalUrl = null;
    public ?DateTime $checkedAt = null;

    private ?ElementInterface $_source = null;
    private ?ElementInterface $_rootSource = null;
    private ?ElementInterface $_target = null;
    private bool $_loaded = false;

    /** Statuses that count as a problem. */
    public const PROBLEM_STATUSES = ['missing', 'trashed', 'disabled', 'pending', 'expired', 'broken', 'error', 'blocked'];

    /**
     * Builds a reference from a row of `LinkIndex::createQuery()`.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $ref = new self();
        $ref->id = (int)$row['id'];
        $ref->fieldId = (int)$row['fieldId'];
        $ref->sourceId = (int)$row['ownerId'];
        $ref->sourceSiteId = (int)$row['ownerSiteId'];
        $ref->rootSourceId = (int)$row['rootOwnerId'];
        $ref->sortOrder = (int)$row['sortOrder'];
        $ref->type = (string)$row['type'];
        $ref->isElement = (bool)$row['isElement'];
        $ref->targetId = $row['targetId'] !== null ? (int)$row['targetId'] : null;
        $ref->targetSiteId = $row['targetSiteId'] !== null ? (int)$row['targetSiteId'] : null;
        $ref->url = $row['url'] ?? null;
        $ref->host = $row['host'] ?? null;
        $ref->label = $row['label'] ?? null;
        $ref->elementStatus = $row['elementStatus'] ?? null;
        $ref->checkStatus = $row['checkStatus'] ?? null;
        $ref->httpCode = isset($row['httpCode']) ? (int)$row['httpCode'] : null;
        $ref->checkError = $row['checkError'] ?? null;
        $ref->finalUrl = $row['finalUrl'] ?? null;
        $ref->checkedAt = !empty($row['checkedAt']) ? new DateTime($row['checkedAt'], new \DateTimeZone('UTC')) : null;

        return $ref;
    }

    /**
     * The link's overall status: the element status for element links, the check status for URL
     * links, `unchecked` for an http(s) URL never checked, or `n/a` for links that are never checked
     * (email, phone, site paths…).
     */
    public function getStatus(): string
    {
        if ($this->isElement) {
            return $this->elementStatus ?? 'missing';
        }

        if ($this->checkStatus !== null) {
            return $this->checkStatus;
        }

        return $this->host !== null ? 'unchecked' : 'n/a';
    }

    public function isProblem(): bool
    {
        return in_array($this->getStatus(), self::PROBLEM_STATUSES, true);
    }

    public function getField(): ?FieldInterface
    {
        return Craft::$app->getFields()->getFieldById($this->fieldId);
    }

    public function getFieldHandle(): ?string
    {
        return $this->getField()?->handle;
    }

    /**
     * Injects preloaded elements (the Links screen loads a page's elements in a few queries).
     */
    public function setElements(?ElementInterface $source, ?ElementInterface $rootSource, ?ElementInterface $target): void
    {
        $this->_source = $source;
        $this->_rootSource = $rootSource;
        $this->_target = $target;
        $this->_loaded = true;
    }

    public function getSource(): ?ElementInterface
    {
        $this->_load();
        return $this->_source;
    }

    public function getRootSource(): ?ElementInterface
    {
        $this->_load();
        return $this->_rootSource;
    }

    public function getTarget(): ?ElementInterface
    {
        $this->_load();
        return $this->_target;
    }

    /**
     * The link itself, from the source's current field value.
     */
    public function getLink(): ?Link
    {
        $source = $this->getSource();
        $field = $this->getField();

        if (!$source || !$field instanceof FreeLinkField) {
            return null;
        }

        // The layout may use a different handle than the global field.
        $handle = $field->handle;
        foreach ($source->getFieldLayout()?->getCustomFields() ?? [] as $layoutField) {
            if ($layoutField->id === $field->id) {
                $handle = $layoutField->handle;
                break;
            }
        }

        try {
            $value = $source->getFieldValue($handle);
        } catch (\Throwable) {
            return null;
        }

        return $value instanceof LinkCollection ? ($value->getAll()[$this->sortOrder] ?? null) : null;
    }

    /**
     * Plain data for APIs (MCP, JSON).
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $root = $this->getRootSource();
        $target = $this->getTarget();

        return [
            'sourceId' => $this->sourceId,
            'sourceSiteId' => $this->sourceSiteId,
            'rootSourceId' => $this->rootSourceId,
            'rootSourceTitle' => $root ? (string)$root : null,
            'rootSourceType' => $root ? $root::class : null,
            'field' => $this->getFieldHandle(),
            'position' => $this->sortOrder,
            'type' => $this->type,
            'targetId' => $this->targetId,
            'targetSiteId' => $this->targetSiteId,
            'targetTitle' => $target ? (string)$target : null,
            'url' => $this->url,
            'label' => $this->label,
            'status' => $this->getStatus(),
            'httpCode' => $this->httpCode,
            'checkError' => $this->checkError,
            'checkedAt' => $this->checkedAt?->format(DATE_ATOM),
        ];
    }

    private function _load(): void
    {
        if ($this->_loaded) {
            return;
        }

        $this->_loaded = true;
        $elements = Craft::$app->getElements();

        $this->_source = $elements->getElementById($this->sourceId, null, $this->sourceSiteId);
        $this->_rootSource = $this->rootSourceId === $this->sourceId
            ? $this->_source
            : $elements->getElementById($this->rootSourceId, null, $this->sourceSiteId);

        if ($this->targetId) {
            $this->_target = $elements->getElementById($this->targetId, null, $this->targetSiteId ?? $this->sourceSiteId)
                ?? $elements->getElementById($this->targetId);
        }
    }
}
