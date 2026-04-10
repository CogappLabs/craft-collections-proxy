<?php

namespace cogapp\collectionsproxy\web\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Asset bundle for the CP "Collections Proxy -> Search" panel.
 * Vanilla JS — no bundler step.
 */
class SearchAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@cogapp/collectionsproxy/web/assets/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['search.js'];

        parent::init();
    }
}
