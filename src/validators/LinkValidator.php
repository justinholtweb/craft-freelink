<?php

namespace justinholtweb\freelink\validators;

use justinholtweb\freelink\base\ElementLink;
use justinholtweb\freelink\base\Link;
use justinholtweb\freelink\models\LinkCollection;
use yii\validators\Validator;

/**
 * Validates a LinkCollection field value.
 */
class LinkValidator extends Validator
{
    public bool $requireLabel = false;
    public ?int $minLinks = null;
    public ?int $maxLinks = null;

    public function validateAttribute($model, $attribute): void
    {
        $value = $model->$attribute;

        if (!$value instanceof LinkCollection) {
            return;
        }

        $links = $value->getAll();

        // Count validation
        $count = count($links);

        if ($this->minLinks && $count < $this->minLinks) {
            $this->addError($model, $attribute, '{attribute} must have at least {min} links.', [
                'min' => $this->minLinks,
            ]);
        }

        if ($this->maxLinks && $count > $this->maxLinks) {
            $this->addError($model, $attribute, '{attribute} must have no more than {max} links.', [
                'max' => $this->maxLinks,
            ]);
        }

        // Validate each link
        foreach ($links as $i => $link) {
            $this->validateLink($model, $attribute, $link, $i);
        }
    }

    protected function validateLink($model, string $attribute, Link $link, int $index): void
    {
        // Required label check
        if ($this->requireLabel && empty($link->label)) {
            $this->addError($model, $attribute, 'Link #{index} requires a label.', [
                'index' => $index + 1,
            ]);
        }

        // Element link must have a target
        if ($link instanceof ElementLink && !$link->isEmpty() && !$link->targetId) {
            $this->addError($model, $attribute, 'Link #{index} requires an element to be selected.', [
                'index' => $index + 1,
            ]);
        }

        // Run the link's own validation rules
        if (!$link->validate()) {
            foreach ($link->getErrors() as $errors) {
                foreach ($errors as $error) {
                    $this->addError($model, $attribute, $error);
                }
            }
        }
    }
}
