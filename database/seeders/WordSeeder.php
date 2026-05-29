<?php

namespace Database\Seeders;

use App\Services\WordGenerator;
use Illuminate\Database\Seeder;

class WordSeeder extends Seeder
{
    public function run(): void
    {
        $generator = new WordGenerator;

        // Generate 50 sample words
        $words = $generator->generateWords(50);
        $generator->createWords($words);

        $this->command->info('Generated '.count($words).' sample words');
    }
}
