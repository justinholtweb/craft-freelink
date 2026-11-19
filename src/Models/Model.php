<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Models;

use CraftCms\Cms\Component\Component;

/**
 * Base for FreeLink's models.
 *
 * Craft 6 validates components with Laravel rules (`getRules()`). The checks that don't fit a rule
 * string — a URL whose scheme would run script, an icon name that has to exist in Craft's set — run
 * in `checks()`, after the rules, on every full `validate()`. `addError()`/`getErrors()`/`hasErrors()`
 * keep the Yii-shaped API FreeLink 5 exposed.
 */
abstract class Model extends Component
{
    public function validate(array|string|null $attributeNames = null, bool $clearErrors = true, bool $throw = false): bool
    {
        parent::validate($attributeNames, $clearErrors);

        $this->checks($attributeNames === null ? null : (array) $attributeNames);

        return $this->errors()->isEmpty();
    }

    public function addError(string $attribute, string $message): void
    {
        $this->errors()->add($attribute, $message);
    }

    public function hasErrors(?string $attribute = null): bool
    {
        return $attribute === null ? $this->errors()->isNotEmpty() : $this->errors()->has($attribute);
    }

    /**
     * Errors as plain arrays: every attribute's messages, or one attribute's.
     *
     * @return array<string, list<string>>|list<string>
     */
    public function getErrors(?string $attribute = null): array
    {
        return $attribute === null ? $this->errors()->getMessages() : $this->errors()->get($attribute);
    }

    /**
     * Checks run after the rules. Report problems with `addError()`.
     *
     * @param  list<string>|null  $attributes  The attributes being validated, or null for all
     */
    protected function checks(?array $attributes): void {}

    /**
     * Whether a check for an attribute should run in this validate() call.
     *
     * @param  list<string>|null  $attributes
     */
    protected static function checking(?array $attributes, string $attribute): bool
    {
        return $attributes === null || in_array($attribute, $attributes, true);
    }
}
