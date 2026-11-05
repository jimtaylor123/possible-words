<?php

namespace App\Services;

use App\Models\Definition;
use App\Models\Word;
use App\Models\WordBatchSlot;

class WordBatchDefinitionContext
{
    /**
     * @return array{batch_id: int|null, definitions: list<string>, word_ids: list<int>}|null Null means an earlier slot is not yet complete.
     */
    public static function forWord(Word $word): ?array
    {
        $slot = WordBatchSlot::query()->where('word_id', $word->id)->first();
        if ($slot === null) {
            return ['batch_id' => null, 'definitions' => [], 'word_ids' => []];
        }

        $lowerSlots = WordBatchSlot::query()
            ->where('word_batch_id', $slot->word_batch_id)
            ->where('slot_number', '<', $slot->slot_number)
            ->with(['word.definitions' => fn ($query) => $query->where('origin', Definition::ORIGIN_AI)])
            ->orderBy('slot_number')
            ->get();

        if ($lowerSlots->count() !== $slot->slot_number - 1) {
            return null;
        }

        $definitions = [];
        $wordIds = [];
        foreach ($lowerSlots as $lowerSlot) {
            $definition = $lowerSlot->word?->definitions->first();
            if ($lowerSlot->word === null || $definition === null) {
                return null;
            }

            $definitions[] = $definition->text;
            $wordIds[] = $lowerSlot->word->id;
        }

        return ['batch_id' => $slot->word_batch_id, 'definitions' => $definitions, 'word_ids' => $wordIds];
    }
}
