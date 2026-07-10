<?php

namespace App\Assets;

use Statamic\Assets\Asset as BaseAsset;
use Statamic\Events\AssetCreated;
use Statamic\Events\AssetCreating;
use Statamic\Events\AssetUploaded;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class Asset extends BaseAsset
{
    /**
     * Identical to the parent implementation except for the uploader class,
     * which works around gifs being renamed to the source preset's `fm`
     * extension without their contents being converted.
     */
    public function upload(UploadedFile $file)
    {
        if (AssetCreating::dispatch($this) === false) {
            return false;
        }

        $path = GifSafeAssetUploader::asset($this)->upload($file);

        $this
            ->path($path)
            ->syncOriginal()
            ->save();

        AssetUploaded::dispatch($this, $file->getClientOriginalName());

        AssetCreated::dispatch($this);

        return $this;
    }
}
