<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Vote;
use App\Models\Word;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AccountController extends Controller
{
    public function show()
    {
        return Inertia::render('Account/Show');
    }

    public function updateNotifications(Request $request)
    {
        $user = $request->user();
        $rules = [
            'notification_email_mode' => ['required', 'in:daily,immediate,off'],
        ];
        if ($user->is_admin) {
            $rules['admin_report_email_mode'] = ['nullable', 'in:daily,immediate,off'];
        }
        $validated = $request->validate($rules);

        $user->notification_email_mode = $validated['notification_email_mode'];
        if ($user->is_admin && array_key_exists('admin_report_email_mode', $validated)) {
            $user->admin_report_email_mode = $validated['admin_report_email_mode'];
        }
        $user->save();

        return back()->with('success', 'Notification preferences updated.');
    }

    public function export(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        // Internal foreign keys are stripped from nested records so the export never
        // exposes identifiers that link activity back to an account.
        $identityColumns = ['user_id', 'reporter_id', 'processed_by_user_id', 'admin_user_id'];
        $scrub = fn ($records) => $records->map->getAttributes()
            ->map(fn (array $attributes) => Arr::except($attributes, $identityColumns))
            ->values();

        $export = [
            'profile' => $user->only(['id', 'name', 'email', 'email_verified_at', 'provider', 'provider_id', 'avatar', 'is_admin', 'created_at', 'updated_at']),
            'definitions' => $scrub($user->definitions()->get()),
            'votes' => $scrub($user->votes()->get()),
            'favourites' => $scrub($user->favourites()->get()),
            'ownerships' => $scrub($user->ownerships()->get()),
            'reports' => $scrub($user->reports()->get()),
            'report_cases' => $scrub($user->processedReportCases()->get()),
            'report_review_actions' => $scrub($user->reportReviewActions()->get()),
            'notifications' => $user->notifications()
                ->where('kind', '!=', 'admin_report_created')
                ->get()
                ->map(fn ($notification) => Arr::except($notification->getAttributes(), ['notifiable_type', 'notifiable_id', 'subject_type', 'subject_id']))
                ->values(),
        ];

        return response()->streamDownload(
            fn () => print json_encode($export, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            'possiblewords-account-export.json',
            ['Content-Type' => 'application/json']
        );
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'confirmation' => ['required', 'string', 'in:DELETE'],
        ]);

        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($user) {
            $definitionIds = $user->votes()->pluck('definition_id');

            $user->votes()->delete();
            $user->favourites()->delete();
            $user->notifications()->delete();

            Word::where('owner_user_id', $user->id)->update([
                'status' => 'available',
                'owner_user_id' => null,
                'owned_until' => null,
            ]);

            if (config('session.driver') === 'database') {
                DB::table(config('session.table'))->where('user_id', $user->id)->delete();
            }

            User::query()->where('suspended_by_user_id', $user->id)->update([
                'suspended_by_user_id' => null,
            ]);

            User::query()->whereKey($user->id)->delete();

            Vote::query()
                ->select('definition_id', DB::raw('count(*) as votes_count'))
                ->whereIn('definition_id', $definitionIds)
                ->groupBy('definition_id')
                ->get()
                ->each(fn ($vote) => DB::table('definitions')->where('id', $vote->definition_id)->update(['votes_count' => $vote->votes_count]));

            DB::table('definitions')
                ->whereIn('id', $definitionIds)
                ->whereNotIn('id', Vote::query()->select('definition_id')->whereIn('definition_id', $definitionIds))
                ->update(['votes_count' => 0]);
        });

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('success', 'Your account has been deleted.');
    }
}
