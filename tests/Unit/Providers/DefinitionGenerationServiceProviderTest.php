<?php

use App\Contracts\DefinitionGenerator;
use App\Services\Definitions\GeminiDefinitionGenerator;

test('it binds the definition generator to the Gemini implementation', function () {
    expect(app(DefinitionGenerator::class))->toBeInstanceOf(GeminiDefinitionGenerator::class);
});
