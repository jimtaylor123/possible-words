<?php

namespace App\Contracts;

use App\Data\GeneratedDefinition;

interface DefinitionGenerator
{
    /**
     * @param  list<string>  $excludedDefinitions
     */
    public function generate(string $word, array $excludedDefinitions = []): GeneratedDefinition;

    public function generateExampleSentence(string $word, string $definition, string $partOfSpeech): string;
}
