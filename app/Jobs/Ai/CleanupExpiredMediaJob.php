<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Models\AiMediaAsset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deletes expired AI media binaries and derivatives while retaining redacted metadata rows.
 */
final class CleanupExpiredMediaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): int
    {
        $purged = 0;

        AiMediaAsset::query()
            ->whereNull('deleted_at_policy')
            ->where('expires_at', '<=', now('UTC'))
            ->orderBy('expires_at')
            ->chunkById(100, function ($assets) use (&$purged): void {
                foreach ($assets as $asset) {
                    $this->purgeAssetFiles($asset);
                    $asset->update([
                        'state' => 'purged',
                        'deleted_at_policy' => now('UTC'),
                    ]);
                    $purged++;
                }
            });

        return $purged;
    }

    private function purgeAssetFiles(AiMediaAsset $asset): void
    {
        try {
            $disk = Storage::disk($asset->private_disk);
            if ($asset->private_key !== '' && $disk->exists($asset->private_key)) {
                $disk->delete($asset->private_key);
            }

            foreach ((array) $asset->derivatives_json as $deriv) {
                $key = $deriv['key'] ?? null;
                if (is_string($key) && $key !== '' && $disk->exists($key)) {
                    $disk->delete($key);
                }
            }
        } catch (Throwable) {
            // Continue metadata expiry marking even if disk object was already removed.
        }
    }
}

