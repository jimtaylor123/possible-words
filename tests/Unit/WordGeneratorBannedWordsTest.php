<?php

use App\Services\DictionaryService;
use App\Services\WordGenerator;

test('generated cohort candidates retain the banned-word gate by default', function () {
    $dictionary = mock(DictionaryService::class);
    $dictionary->shouldReceive('isInBannedList')->atLeast()->once()->andReturn(false);

    $words = (new WordGenerator($dictionary))->generateWords(1);

    expect($words)->toHaveCount(1);
});
