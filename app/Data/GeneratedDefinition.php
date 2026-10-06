<?php

namespace App\Data;

class GeneratedDefinition
{
    public function __construct(
        public readonly string $text,
        public readonly string $partOfSpeech,
    ) {}
}
