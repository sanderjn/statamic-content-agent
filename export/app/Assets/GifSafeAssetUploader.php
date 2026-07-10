<?php

namespace App\Assets;

use Statamic\Assets\Asset;
use Statamic\Assets\AssetUploader;

class GifSafeAssetUploader extends AssetUploader
{
    public function __construct(private Asset $asset)
    {
        parent::__construct($asset);
    }

    /**
     * Core skips Glide processing for gifs (converting would flatten animations)
     * but still renames them to the source preset's `fm` extension, leaving gif
     * bytes in a mislabeled file (statamic/cms v6.18, AssetUploader). Keep the
     * original extension so the stored file stays a valid gif.
     */
    protected function getNewExtension()
    {
        if (strtolower($this->asset->extension()) === 'gif') {
            return null;
        }

        return parent::getNewExtension();
    }
}
