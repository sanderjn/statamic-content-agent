<?php

namespace App\Tags;

use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\Asset;
use Statamic\Facades\Image;
use Statamic\Tags\Tags;

class ResponsiveImage extends Tags
{
    public function index(): string
    {
        $asset = $this->resolveAsset($this->params->get(['asset', 'src']));

        if (! $asset) {
            return '';
        }

        $widths = $this->parseIntList($this->params->get('widths'));
        $heights = $this->parseIntList($this->params->get('heights'));

        if (empty($widths) && empty($heights)) {
            return '';
        }

        $heightMode = empty($widths) && ! empty($heights);
        $sizes = $heightMode ? $heights : $widths;
        sort($sizes);

        [$ratioW, $ratioH] = $this->parseRatio($this->params->get('ratio'));

        $format = $this->params->get('format', 'webp');
        $quality = (int) $this->params->get('quality', 85);

        $variants = array_map(
            fn (int $size) => $this->variant($asset, $size, $heightMode, $ratioW, $ratioH, $format, $quality),
            $sizes,
        );

        $largestVariant = end($variants);

        $srcset = $heightMode
            ? $this->buildDensitySrcset($variants, $sizes)
            : implode(', ', array_map(fn ($v) => $v['url'].' '.$v['w'].'w', $variants));

        $attrs = [
            'src' => $largestVariant['url'],
            'srcset' => $srcset,
            'sizes' => $this->params->get('sizes', '100vw'),
            'alt' => $this->params->get('alt', $asset->get('alt') ?? ''),
            'decoding' => $this->params->get('decoding', 'async'),
            'loading' => $this->params->get('loading', 'lazy'),
        ];

        if (! $heightMode) {
            $attrs['width'] = $largestVariant['w'];
            $attrs['height'] = $largestVariant['h'];
        } elseif ($asset->width() && $asset->height()) {
            // Intrinsic dimensions let the browser size the box before the
            // image loads (CSS like `max-h-24 w-auto` resolves via the attr
            // aspect ratio), so rows of height-mode images don't reflow as
            // they stream in.
            $attrs['height'] = $sizes[0];
            $attrs['width'] = (int) round($sizes[0] * $asset->width() / $asset->height());
        }

        if ($fp = $this->params->get('fetchpriority')) {
            $attrs['fetchpriority'] = $fp;
        }

        $objectPosition = $this->params->get('object_position') ?? $asset->augmentedValue('focus_css') ?? '50% 50%';
        $style = 'object-position: '.$objectPosition.';';

        if ($vtName = $this->params->get('vt_name')) {
            $style .= ' view-transition-name: '.$vtName.';';
        }

        if ($vtClass = $this->params->get('vt_class')) {
            $style .= ' view-transition-class: '.$vtClass.';';
        }

        $attrs['style'] = $style;

        if ($class = $this->params->get('class')) {
            $attrs['class'] = $class;
        }

        return $this->renderImg($attrs);
    }

    /**
     * Render only the width-descriptor srcset string for an asset (no <img>).
     *
     * Useful when you need the srcset value on its own, for example a
     * `<link rel="preload" as="image" imagesrcset="..." imagesizes="...">`
     * hint. Pass the same widths/ratio you use on the corresponding <img> so
     * the browser picks (and preloads) the exact same candidate URL.
     */
    public function srcset(): string
    {
        $asset = $this->resolveAsset($this->params->get(['asset', 'src']));

        if (! $asset) {
            return '';
        }

        $widths = $this->parseIntList($this->params->get('widths'));

        if (empty($widths)) {
            return '';
        }

        sort($widths);

        [$ratioW, $ratioH] = $this->parseRatio($this->params->get('ratio'));

        $format = $this->params->get('format', 'webp');
        $quality = (int) $this->params->get('quality', 85);

        $variants = array_map(
            fn (int $size) => $this->variant($asset, $size, false, $ratioW, $ratioH, $format, $quality),
            $widths,
        );

        return implode(', ', array_map(fn ($v) => $v['url'].' '.$v['w'].'w', $variants));
    }

    private function variant(AssetContract $asset, int $size, bool $heightMode, ?int $ratioW, ?int $ratioH, string $format, int $quality): array
    {
        $manipulator = Image::manipulate($asset)->fm($format)->q($quality);

        if ($heightMode) {
            $manipulator->h($size);

            return ['url' => $manipulator->build(), 'w' => null, 'h' => $size];
        }

        $manipulator->w($size);
        $height = null;

        if ($ratioW && $ratioH) {
            $height = (int) round($size * $ratioH / $ratioW);
            // `crop_focus` only crops around a focal point; on assets without one it
            // silently falls back to a plain resize (no crop), so the image keeps its
            // source aspect ratio instead of `ratio`. Use a centred `crop` in that case
            // so `ratio` always yields a true crop. Keep the crop ratio identical across
            // every variant so a responsive srcset swap never shifts the visible crop.
            $manipulator->h($height)->fit($asset->get('focus') ? 'crop_focus' : 'crop');
        }

        return ['url' => $manipulator->build(), 'w' => $size, 'h' => $height];
    }

    private function buildDensitySrcset(array $variants, array $sizes): string
    {
        $smallest = $sizes[0];

        return implode(', ', array_map(function ($variant, $size) use ($smallest) {
            $density = round($size / $smallest, 2);

            return $variant['url'].' '.$density.'x';
        }, $variants, $sizes));
    }

    private function resolveAsset($value): ?AssetContract
    {
        if (! $value) {
            return null;
        }

        if ($value instanceof AssetContract) {
            return $value;
        }

        if (is_iterable($value)) {
            foreach ($value as $item) {
                return $this->resolveAsset($item);
            }

            return null;
        }

        if (is_string($value)) {
            return Asset::find($value) ?? Asset::findByUrl($value);
        }

        return null;
    }

    private function parseIntList(?string $value): array
    {
        if (! $value) {
            return [];
        }

        return array_values(array_filter(array_map('intval', array_map('trim', explode(',', $value)))));
    }

    private function parseRatio(?string $value): array
    {
        if (! $value || ! str_contains($value, '/')) {
            return [null, null];
        }

        [$w, $h] = explode('/', $value, 2);

        return [(int) $w, (int) $h];
    }

    private function renderImg(array $attrs): string
    {
        $html = '<img';

        foreach ($attrs as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $html .= ' '.$key.'="'.e($value).'"';
        }

        return $html.'>';
    }
}
