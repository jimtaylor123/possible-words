<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Services\WordGenerator;

class WordSeeder extends Seeder
{
    public function run(): void
    {
        $generator = new WordGenerator();
        
        // Generate 50 sample words
        $words = $generator->generateWords(50);
        $generator->createWords($words);
        
        $this->command->info('Generated ' . count($words) . ' sample words');
    }
}
