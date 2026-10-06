<?php

namespace App\Contracts;

use App\Data\GeneratedDefinition;

interface DefinitionGenerator
{
    public function generate(string $word): GeneratedDefinition;
}
