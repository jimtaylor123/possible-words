<?php

use App\Services\WordBatchDefinitionLock;

test('a batch definition lock is exclusive until released', function () {
    $firstLock = WordBatchDefinitionLock::acquire(123);

    expect($firstLock)->not->toBeNull()
        ->and(WordBatchDefinitionLock::acquire(123))->toBeNull();

    $firstLock->release();
    $nextLock = WordBatchDefinitionLock::acquire(123);

    expect($nextLock)->not->toBeNull();

    $nextLock->release();
});
