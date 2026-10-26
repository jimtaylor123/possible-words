<?php

namespace App\Http\Controllers;

use App\Mail\AccountSuspensionMail;
use App\Models\AccountSuspensionAction;
use App\Models\Definition;
use App\Models\User;
use App\Models\Word;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AdminUserController extends Controller
{
    /** @return array{users: \Illuminate\Pagination\LengthAwarePaginator, filters: array{search: string, sort: string, direction: string}} */
    public function directoryPayload(Request $request): array
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'in:last_active,name,email,definitions,reports'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $sort = $filters['sort'] ?? 'last_active';
        $direction = $filters['direction'] ?? 'desc';

        $users = User::query()
            ->with(['suspender:id,name'])
            ->withCount([
                'definitions',
                'reports',
                'reports as word_reports_count' => fn ($query) => $query->where('reportable_type', Word::class),
                'reports as definition_reports_count' => fn ($query) => $query->where('reportable_type', Definition::class),
            ])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($search).'%'])
                        ->orWhereRaw('LOWER(email) LIKE ?', ['%'.strtolower($search).'%']);
                });
            });

        match ($sort) {
            'name', 'email' => $users->orderBy($sort, $direction)->orderBy('id'),
            'definitions' => $users->orderBy('definitions_count', $direction)->orderBy('id'),
            'reports' => $users->orderBy('reports_count', $direction)->orderBy('id'),
            default => $users->orderByRaw('last_active_at IS NULL')->orderBy('last_active_at', $direction)->orderBy('id'),
        };

        $users = $users->paginate(20)->withQueryString()->through(fn (User $user): array => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'last_active_at' => $user->last_active_at?->toISOString(),
            'activity_state' => $this->activityState($user),
            'definitions_count' => $user->definitions_count,
            'reports_count' => $user->reports_count,
            'word_reports_count' => $user->word_reports_count,
            'definition_reports_count' => $user->definition_reports_count,
            'suspended_at' => $user->suspended_at?->toISOString(),
            'suspended_by' => $user->suspender ? ['id' => $user->suspender->id, 'name' => $user->suspender->name] : null,
        ]);

        return [
            'users' => $users,
            'filters' => ['search' => $search, 'sort' => $sort, 'direction' => $direction],
        ];
    }

    public function suspend(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $reason = trim($data['reason']);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A suspension reason is required.']);
        }

        $actor = $request->user();
        $suspended = DB::transaction(function () use ($user, $actor, $reason): User {
            // Every suspension takes the active-admin locks in ID order before it
            // locks its target. This prevents two requests for different admins
            // from each holding a target lock while waiting for the other's row.
            $activeAdmins = User::query()
                ->where('is_admin', true)
                ->whereNull('suspended_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $target = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($target->id === $actor->id) {
                throw ValidationException::withMessages(['user' => 'You cannot suspend your own account.']);
            }
            if ($target->suspended_at !== null) {
                throw ValidationException::withMessages(['user' => 'This account is already suspended.']);
            }
            if ($target->is_admin && $activeAdmins->count() === 1) {
                throw ValidationException::withMessages(['user' => 'The final active administrator cannot be suspended.']);
            }

            $target->forceFill([
                'suspended_at' => now(),
                'suspended_by_user_id' => $actor->id,
                'suspension_reason' => $reason,
            ])->save();
            AccountSuspensionAction::create(['user_id' => $target->id, 'admin_user_id' => $actor->id, 'action' => AccountSuspensionAction::ACTION_SUSPENDED, 'reason' => $reason]);
            if (config('session.driver') === 'database') {
                DB::table(config('session.table'))->where('user_id', $target->id)->delete();
            }

            return $target;
        });

        $this->sendStatusMail($suspended, true);

        return back()->with('success', 'Account suspended.');
    }

    public function unsuspend(Request $request, User $user): RedirectResponse
    {
        $restored = DB::transaction(function () use ($user, $request): User {
            $target = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($target->suspended_at === null) {
                throw ValidationException::withMessages(['user' => 'This account is not suspended.']);
            }

            $target->forceFill(['suspended_at' => null, 'suspended_by_user_id' => null, 'suspension_reason' => null])->save();
            AccountSuspensionAction::create(['user_id' => $target->id, 'admin_user_id' => $request->user()->id, 'action' => AccountSuspensionAction::ACTION_UNSUSPENDED]);

            return $target;
        });

        $this->sendStatusMail($restored, false);

        return back()->with('success', 'Account restored.');
    }

    private function activityState(User $user): string
    {
        if ($user->last_active_at === null || $user->last_active_at->lt(now()->subMinutes(5))) {
            return 'red';
        }

        return $user->last_active_at->gt(now()->subMinute()) ? 'green' : 'orange';
    }

    private function sendStatusMail(User $user, bool $suspended): void
    {
        if (! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        try {
            Mail::to($user)->send(new AccountSuspensionMail($user, $suspended));
        } catch (\Throwable $exception) {
            Log::warning('Unable to send account suspension status mail.', ['user_id' => $user->id, 'exception' => $exception]);
        }
    }
}
