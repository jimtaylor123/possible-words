<?php

use App\Models\Word;
use App\Services\NotificationService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function releasableWord(string $text, array $attributes = []): Word
{
    return Word::create(array_merge([
        'text' => $text,
        'slug' => $text,
        'status' => 'available',
        'dictionary_status' => Word::DICTIONARY_NOT_FOUND,
        'generated_at' => now(),
    ], $attributes));
}

describe('releasing words via artisan', function () {
    test('Given eligible inventory, it releases the oldest words up to the configured batch size', function () {
        config()->set('word-release.batch_size', 2);
        $oldest = releasableWord('oldest', ['generated_at' => now()->subHours(2)]);
        $next = releasableWord('next', ['generated_at' => now()->subHour()]);
        $newest = releasableWord('newest');

        $this->artisan('words:release')
            ->expectsOutputToContain('Released 2 of 2 word(s).')
            ->assertExitCode(0);

        expect($oldest->fresh()->published_at)->not->toBeNull()
            ->and($next->fresh()->published_at)->not->toBeNull()
            ->and($newest->fresh()->published_at)->toBeNull()
            ->and($oldest->fresh()->published_at->toDateTimeString())
            ->toBe($next->fresh()->published_at->toDateTimeString());
    });

    test('Given ineligible inventory, it skips it and releases a partial batch', function () {
        config()->set('word-release.batch_size', 3);
        $eligible = releasableWord('eligible', ['generated_at' => now()->subHour()]);
        $realWord = releasableWord('realword', ['dictionary_status' => Word::DICTIONARY_EXISTS_AS_WORD]);
        $owned = releasableWord('ownedword', ['status' => 'owned']);
        $withdrawn = releasableWord('withdrawn');
        $withdrawn->delete();

        $this->artisan('words:release')
            ->expectsOutputToContain('Released 1 of 3 word(s).')
            ->assertExitCode(0);

        expect($eligible->fresh()->published_at)->not->toBeNull()
            ->and($realWord->fresh()->published_at)->toBeNull()
            ->and($owned->fresh()->published_at)->toBeNull()
            ->and(Word::withTrashed()->find($withdrawn->id)->published_at)->toBeNull();
    });

    test('Given no eligible inventory, it performs no release', function () {
        releasableWord('unchecked', ['dictionary_status' => Word::DICTIONARY_UNCHECKED]);

        $this->artisan('words:release')
            ->expectsOutputToContain('Released 0 of 10 word(s).')
            ->assertExitCode(0);
    });

    test('Given notification delivery fails, it leaves the word pending for a retry', function () {
        $word = releasableWord('pending-notification', ['domain_status' => Word::DOMAIN_AVAILABLE]);
        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('notifyNewWord')->once()->with(Mockery::on(fn (Word $candidate) => $candidate->is($word)))->andReturnFalse();
        app()->instance(NotificationService::class, $notifications);

        $this->artisan('words:release')->assertExitCode(0);

        expect($word->fresh()->published_at)->not->toBeNull()
            ->and($word->fresh()->notification_sent_at)->toBeNull();
    });

    test('Given a previously published word, it does not notify users after notification support is deployed', function () {
        releasableWord('already-published', ['domain_status' => Word::DOMAIN_AVAILABLE, 'published_at' => now()->subDay(), 'notification_sent_at' => now()]);
        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldNotReceive('notifyNewWord');
        app()->instance(NotificationService::class, $notifications);

        $this->artisan('words:release')->assertExitCode(0);
    });
});
