<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SyncFavicon extends Command
{
    protected $signature = 'app:sync-favicon';

    protected $description = 'Upload favicon to S3 (MiniStack)';

    public function handle(): int
    {
        $disk = Storage::disk('s3');

        if (! $disk->exists('favicon.ico')) {
            $disk->put('favicon.ico', file_get_contents(public_path('favicon.ico')), 'public');
            $this->info('Favicon uploaded to S3.');
        } else {
            $this->info('Favicon already exists on S3.');
        }

        return self::SUCCESS;
    }
}
