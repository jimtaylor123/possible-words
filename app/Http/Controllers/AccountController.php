<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\User;
use App\Models\Vote;
use App\Models\Word;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AccountController extends Controller
{
    public function show()
    {
        return Inertia::render('Account/Show');
    }

    public function export(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $export = [
            'profile' => $user->only(['id', 'name', 'email', 'email_verified_at', 'provider', 'provider_id', 'avatar', 'is_admin', 'created_at', 'updated_at']),
            'definitions' => $user->definitions()->get()->map->getAttributes(),
            'votes' => $user->votes()->get()->map->getAttributes(),
            'favourites' => $user->favourites()->get()->map->getAttributes(),
            'ownerships' => $user->ownerships()->get()->map->getAttributes(),
            'reports' => Report::query()
                ->where('reporter_id', $user->id)
                ->orWhere('processed_by_user_id', $user->id)
                ->get()
                ->map->getAttributes(),
            'report_review_actions' => $user->reportReviewActions()->get()->map->getAttributes(),
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

        abort_unless(
            config('session.driver') === 'database',
            500,
            'Account deletion requires the database session driver.'
        );

        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($user) {
            $definitionIds = $user->votes()->pluck('definition_id');

            $user->votes()->delete();
            $user->favourites()->delete();

            Word::where('owner_user_id', $user->id)->update([
                'status' => 'available',
                'owner_user_id' => null,
                'owned_until' => null,
            ]);

            DB::table(config('session.table'))->where('user_id', $user->id)->delete();

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
