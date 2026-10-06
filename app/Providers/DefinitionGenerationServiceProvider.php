<?php

namespace App\Providers;

use App\Contracts\DefinitionGenerator;
use App\Services\Definitions\GeminiDefinitionGenerator;
use Illuminate\Support\ServiceProvider;

class DefinitionGenerationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DefinitionGenerator::class, GeminiDefinitionGenerator::class);
    }
}
