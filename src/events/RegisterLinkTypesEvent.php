<?php

namespace justinholtweb\freelink\events;

use yii\base\Event;

class RegisterLinkTypesEvent extends Event
{
    /**
     * @var string[] Array of link type class names
     */
    public array $types = [];
}
