<?php

namespace justinholtweb\freelink\web\assets\field;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class FieldAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            'js/FreeLinkField.js',
        ];

        $this->css = [
            'css/FreeLinkField.css',
        ];

        parent::init();
    }
}
