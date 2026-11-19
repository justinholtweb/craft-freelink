<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Models;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Field\Contracts\FieldInterface;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Fields;
use DateTime;
use DateTimeZone;
use JustinHoltWeb\FreeLink\Base\Link;
use JustinHoltWeb\FreeLink\Fields\FreeLinkField;
use Throwable;

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

    private ?ElementInterface $source = null;

    private ?ElementInterface $rootSource = null;

    private ?ElementInterface $target = null;

    private bool $loaded = false;

    /** Statuses that count as a problem. */
    public const array PROBLEM_STATUSES = ['missing', 'trashed', 'disabled', 'pending', 'expired', 'broken', 'error', 'blocked'];

    /**
     * Builds a reference from a row of `LinkIndex::createQuery()`.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): self
    {
        $ref = new self;
        $ref->id = (int) $row['id'];
        $ref->fieldId = (int) $row['fieldId'];
        $ref->sourceId = (int) $row['ownerId'];
        $ref->sourceSiteId = (int) $row['ownerSiteId'];
        $ref->rootSourceId = (int) $row['rootOwnerId'];
        $ref->sortOrder = (int) $row['sortOrder'];
        $ref->type = (string) $row['type'];
        $ref->isElement = (bool) $row['isElement'];
        $ref->targetId = isset($row['targetId']) ? (int) $row['targetId'] : null;
        $ref->targetSiteId = isset($row['targetSiteId']) ? (int) $row['targetSiteId'] : null;
        $ref->url = isset($row['url']) ? (string) $row['url'] : null;
        $ref->host = isset($row['host']) ? (string) $row['host'] : null;
        $ref->label = isset($row['label']) ? (string) $row['label'] : null;
        $ref->elementStatus = isset($row['elementStatus']) ? (string) $row['elementStatus'] : null;
        $ref->checkStatus = isset($row['checkStatus']) ? (string) $row['checkStatus'] : null;
        $ref->httpCode = isset($row['httpCode']) ? (int) $row['httpCode'] : null;
        $ref->checkError = isset($row['checkError']) ? (string) $row['checkError'] : null;
        $ref->finalUrl = isset($row['finalUrl']) ? (string) $row['finalUrl'] : null;
        $ref->checkedAt = ! empty($row['checkedAt']) ? new DateTime((string) $row['checkedAt'], new DateTimeZone('UTC')) : null;

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
        return Fields::getFieldById($this->fieldId);
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
        $this->source = $source;
        $this->rootSource = $rootSource;
        $this->target = $target;
        $this->loaded = true;
    }

    public function getSource(): ?ElementInterface
    {
        $this->load();

        return $this->source;
    }

    public function getRootSource(): ?ElementInterface
    {
        $this->load();

        return $this->rootSource;
    }

    public function getTarget(): ?ElementInterface
    {
        $this->load();

        return $this->target;
    }

    /**
     * The link itself, from the source's current field value.
     */
    public function getLink(): ?Link
    {
        $source = $this->getSource();
        $field = $this->getField();

        if (! $source || ! $field instanceof FreeLinkField) {
            return null;
        }

        // The layout may use a different handle than the global field.
        $handle = (string) $field->handle;
        foreach ($source->getFieldLayout()?->getCustomFields() ?? [] as $layoutField) {
            if ($layoutField->id === $field->id) {
                $handle = (string) $layoutField->handle;
                break;
            }
        }

        try {
            $value = $source->getFieldValue($handle);
        } catch (Throwable) {
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
            'rootSourceTitle' => $root ? (string) $root : null,
            'rootSourceType' => $root ? $root::class : null,
            'field' => $this->getFieldHandle(),
            'position' => $this->sortOrder,
            'type' => $this->type,
            'targetId' => $this->targetId,
            'targetSiteId' => $this->targetSiteId,
            'targetTitle' => $target ? (string) $target : null,
            'url' => $this->url,
            'label' => $this->label,
            'status' => $this->getStatus(),
            'httpCode' => $this->httpCode,
            'checkError' => $this->checkError,
            'checkedAt' => $this->checkedAt?->format(DATE_ATOM),
        ];
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        $this->source = Elements::getElementById($this->sourceId, null, $this->sourceSiteId);
        $this->rootSource = $this->rootSourceId === $this->sourceId
            ? $this->source
            : Elements::getElementById($this->rootSourceId, null, $this->sourceSiteId);

        if ($this->targetId) {
            $this->target = Elements::getElementById($this->targetId, null, $this->targetSiteId ?? $this->sourceSiteId)
                ?? Elements::getElementById($this->targetId);
        }
    }
}
