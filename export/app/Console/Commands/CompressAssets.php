<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Statamic\Assets\ReplacementFile;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Glide;

class CompressAssets extends Command
{
    protected $signature = 'assets:compress {container=assets} {--dry-run : Report what would change without modifying any files}';

    protected $description = 'Resize and recompress existing image assets in place (same format, capped at 3840px) to mirror the upload source preset.';

    private const MAX_DIMENSION = 3840;

    private const QUALITY = 90;

    /** Within-bounds re-encodes must save at least this fraction to be kept. */
    private const MINIMUM_SAVINGS = 0.1;

    private const COMPRESSIBLE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    private const WORK_DIRECTORY = 'assets-compress';

    public function handle(): int
    {
        $this->raiseMemoryLimit();

        if (! $container = AssetContainer::findByHandle($this->argument('container'))) {
            $this->error("Asset container [{$this->argument('container')}] does not exist.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $compressed = 0;
        $bytesSaved = 0;

        foreach ($container->assets() as $asset) {
            if (! in_array(strtolower($asset->extension()), self::COMPRESSIBLE_EXTENSIONS, true)) {
                continue;
            }

            $bytesSaved += $saved = $this->compress($asset, $dryRun);
            $compressed += $saved > 0 ? 1 : 0;
        }

        Storage::disk('local')->deleteDirectory(self::WORK_DIRECTORY);

        $this->info(sprintf(
            '%s %d %s, saving %s.',
            $dryRun ? 'Would compress' : 'Compressed',
            $compressed,
            $compressed === 1 ? 'image' : 'images',
            Number::fileSize($bytesSaved),
        ));

        return self::SUCCESS;
    }

    /**
     * Recompress a single asset and return the bytes saved (0 when skipped).
     */
    private function compress(Asset $asset, bool $dryRun): int
    {
        $originalSize = $asset->size();
        $oversized = $asset->width() > self::MAX_DIMENSION || $asset->height() > self::MAX_DIMENSION;

        $workDisk = Storage::disk('local');
        $workPath = self::WORK_DIRECTORY.'/'.md5($asset->id()).'.'.$asset->extension();
        $workDisk->put($workPath, $asset->container()->disk()->get($asset->path()));

        $server = Glide::server([
            'source' => $workDisk->path(self::WORK_DIRECTORY),
            'cache' => $cacheDirectory = storage_path('statamic/glide/tmp'),
            'cache_with_file_extensions' => false,
        ]);

        try {
            $processedPath = $cacheDirectory.'/'.$server->makeImage(basename($workPath), [
                'w' => self::MAX_DIMENSION,
                'h' => self::MAX_DIMENSION,
                'fit' => 'max',
                'q' => self::QUALITY,
            ]);
        } catch (\Exception $exception) {
            $this->warn("Skipped {$asset->path()}: {$exception->getMessage()}");
            $workDisk->delete($workPath);

            return 0;
        }

        $newSize = filesize($processedPath);

        $keep = $newSize < $originalSize
            && ($oversized || $newSize <= $originalSize * (1 - self::MINIMUM_SAVINGS));

        if ($keep && ! $dryRun) {
            $replacementPath = self::WORK_DIRECTORY.'/processed-'.basename($workPath);
            $workDisk->put($replacementPath, fopen($processedPath, 'r'));
            $asset->reupload(new ReplacementFile($replacementPath));
        }

        if ($keep) {
            $this->line(sprintf(
                '%s: %s → %s%s',
                $asset->path(),
                Number::fileSize($originalSize),
                Number::fileSize($newSize),
                $oversized ? ' (resized)' : '',
            ));
        }

        $workDisk->delete($workPath);
        @unlink($processedPath);

        return $keep ? $originalSize - $newSize : 0;
    }

    /**
     * GD holds the full decompressed bitmap in memory, which busts the default
     * 128M limit on photos beyond roughly 10 megapixels.
     */
    private function raiseMemoryLimit(): void
    {
        $limit = ini_get('memory_limit');

        if ($limit === '-1') {
            return;
        }

        $multipliers = ['K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3];
        $suffix = strtoupper(substr($limit, -1));
        $bytes = (int) $limit * ($multipliers[$suffix] ?? 1);

        if ($bytes < 512 * 1024 ** 2) {
            ini_set('memory_limit', '512M');
        }
    }
}
