<?php

namespace App\Services;

use App\Mail\NotificationMail;
use App\Models\User;
use App\Models\Word;
use App\Models\WordBatch;
use App\Notifications\AppNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class NotificationService
{
    /** @param array<string, mixed> $data */
    public function notify(User $user, array $data, bool $adminReport = false, ?string $dedupeKey = null): bool
    {
        $data['email_category'] = $adminReport ? 'admin_report' : 'general';
        try {
            $attributes = [
                'notifiable_type' => $user->getMorphClass(),
                'notifiable_id' => $user->getKey(),
                'dedupe_key' => $dedupeKey,
            ];
            $values = [
                'id' => (string) Str::uuid(),
                'type' => AppNotification::class,
                'kind' => $data['kind'] ?? 'generic',
                'subject_type' => $data['subject_type'] ?? null,
                'subject_id' => $data['subject_id'] ?? null,
                'data' => $data,
            ];
            $notification = $dedupeKey === null
                ? DatabaseNotification::query()->create([...$attributes, ...$values])
                : DatabaseNotification::query()->firstOrCreate($attributes, $values);
        } catch (Throwable $exception) {
            Log::warning('Notification could not be persisted.', ['user_id' => $user->id, 'dedupe_key' => $dedupeKey, 'exception' => $exception]);

            return false;
        }

        if ($notification->wasRecentlyCreated) {
            try {
                $user->notify(new AppNotification($data, $notification->id));
            } catch (Throwable $exception) {
                // The database notification remains available on refresh even when
                // a transient broadcast provider failure prevents realtime delivery.
                Log::warning('Notification broadcast failed.', ['notification_id' => $notification->id, 'exception' => $exception]);
            }
        }

        $mode = $adminReport ? $user->admin_report_email_mode : $user->notification_email_mode;
        if ($mode !== 'immediate') {
            return true;
        }

        if ($notification->email_sent_at !== null) {
            return true;
        }

        $claimToken = (string) Str::uuid();
        $claimed = DatabaseNotification::query()->whereKey($notification->id)->whereNull('email_sent_at')->whereNull('email_claimed_at')->update(['email_claimed_at' => now(), 'email_claim_token' => $claimToken]);
        if ($claimed !== 1) {
            return true;
        }

        try {
            Mail::to($user)->send(new NotificationMail([$data]));
            DatabaseNotification::query()->whereKey($notification->id)->where('email_claim_token', $claimToken)->update(['email_sent_at' => now(), 'email_claimed_at' => null, 'email_claim_token' => null]);
        } catch (Throwable $exception) {
            DatabaseNotification::query()->whereKey($notification->id)->where('email_claim_token', $claimToken)->update(['email_claimed_at' => null, 'email_claim_token' => null]);
            Log::warning('Immediate notification email failed.', ['notification_id' => $notification->id, 'exception' => $exception]);

            return false;
        }

        return true;
    }

    public function notifyNewWord(Word $word): bool
    {
        $delivered = true;

        User::query()->chunkById(100, function ($users) use ($word, &$delivered): void {
            foreach ($users as $user) {
                $delivered = $this->notify($user, [
                    'kind' => 'new_word',
                    'title' => 'New word: '.$word->text,
                    'body' => '“'.$word->text.'” is new and its .com domain is '.($word->domain_status === Word::DOMAIN_AVAILABLE ? 'available' : 'taken').'.',
                    'url' => route('words.show', $word),
                    'subject_type' => $word->getMorphClass(),
                    'subject_id' => $word->id,
                ], false, "new-word:{$word->id}:{$user->id}") && $delivered;
            }
        });

        return $delivered;
    }

    public function notifyNewWordBatch(WordBatch $batch): bool
    {
        $words = $batch->slots()->with('word')->get()->map(fn ($slot) => [
            'text' => $slot->word->text,
            'url' => route('words.show', $slot->word),
            'domain_status' => $slot->word->domain_status,
        ])->all();
        if (count($words) !== $batch->target_count) {
            return false;
        }
        $delivered = true;
        User::query()->chunkById(100, function ($users) use ($batch, $words, &$delivered): void {
            foreach ($users as $user) {
                $delivered = $this->notify($user, [
                    'kind' => 'new_word_batch', 'title' => 'Five new Possible Words', 'body' => 'A new five-word cohort is ready to explore.',
                    'url' => $words[0]['url'], 'words' => $words, 'subject_type' => $batch->getMorphClass(), 'subject_id' => $batch->id,
                ], false, "new-word-batch:{$batch->id}:{$user->id}") && $delivered;
            }
        });

        return $delivered;
    }

    public function retryImmediateEmails(): void
    {
        $claimedAt = now();

        DatabaseNotification::query()->whereNull('email_sent_at')->where(function ($query) use ($claimedAt) {
            $query->whereNull('email_claimed_at')->orWhere('email_claimed_at', '<', $claimedAt->copy()->subMinutes(30));
        })->orderBy('created_at')->each(function (DatabaseNotification $notification) use ($claimedAt): void {
            $user = $notification->notifiable;
            if (! $user instanceof User) {
                return;
            }

            $adminReport = ($notification->data['email_category'] ?? 'general') === 'admin_report';
            $mode = $adminReport ? $user->admin_report_email_mode : $user->notification_email_mode;
            if ($mode !== 'immediate') {
                return;
            }

            $claimToken = (string) Str::uuid();
            $claimed = DatabaseNotification::query()->whereKey($notification->id)->whereNull('email_sent_at')->where(function ($query) use ($claimedAt) {
                $query->whereNull('email_claimed_at')->orWhere('email_claimed_at', '<', $claimedAt->copy()->subMinutes(30));
            })->update(['email_claimed_at' => $claimedAt, 'email_claim_token' => $claimToken]);
            if ($claimed !== 1) {
                return;
            }

            try {
                Mail::to($user)->send(new NotificationMail([$notification->data]));
                DatabaseNotification::query()->whereKey($notification->id)->where('email_claim_token', $claimToken)->update(['email_sent_at' => now(), 'email_claimed_at' => null, 'email_claim_token' => null]);
            } catch (Throwable $exception) {
                DatabaseNotification::query()->whereKey($notification->id)->where('email_claim_token', $claimToken)->update(['email_claimed_at' => null, 'email_claim_token' => null]);
                Log::warning('Immediate notification email retry failed.', ['notification_id' => $notification->id, 'exception' => $exception]);
            }
        });
    }

    public function sendDailyDigests(): void
    {
        User::query()->where('notification_email_mode', 'daily')->orWhere(fn ($query) => $query->where('is_admin', true)->where('admin_report_email_mode', 'daily'))
            ->each(function (User $user): void {
                $claimToken = (string) Str::uuid();
                $claimedAt = now();
                $notifications = $user->notifications()->whereNull('email_sent_at')->where(function ($query) use ($claimedAt) {
                    $query->whereNull('email_claimed_at')->orWhere('email_claimed_at', '<', $claimedAt->copy()->subMinutes(30));
                })->orderBy('created_at')->get()->filter(function ($notification) use ($user): bool {
                    return ($notification->data['email_category'] ?? 'general') === 'admin_report'
                        ? $user->is_admin && $user->admin_report_email_mode === 'daily'
                        : $user->notification_email_mode === 'daily';
                });
                $claimedIds = $notifications->filter(function ($notification) use ($claimToken, $claimedAt): bool {
                    return DatabaseNotification::query()->whereKey($notification->id)->whereNull('email_sent_at')->where(function ($query) use ($claimedAt) {
                        $query->whereNull('email_claimed_at')->orWhere('email_claimed_at', '<', $claimedAt->copy()->subMinutes(30));
                    })->update(['email_claimed_at' => $claimedAt, 'email_claim_token' => $claimToken]) === 1;
                })->pluck('id');
                $notifications = $notifications->whereIn('id', $claimedIds);
                if ($notifications->isEmpty()) {
                    return;
                }
                try {
                    Mail::to($user)->send(new NotificationMail($notifications->map(fn ($n) => $n->data)->all(), true));
                    $user->notifications()->whereIn('id', $claimedIds)->where('email_claim_token', $claimToken)->update(['email_sent_at' => now(), 'email_claimed_at' => null, 'email_claim_token' => null]);
                } catch (Throwable $exception) {
                    $user->notifications()->whereIn('id', $claimedIds)->where('email_claim_token', $claimToken)->update(['email_claimed_at' => null, 'email_claim_token' => null]);
                    Log::warning('Notification digest email failed.', ['user_id' => $user->id, 'exception' => $exception]);
                }
            });
    }
}
