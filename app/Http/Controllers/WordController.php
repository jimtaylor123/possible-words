<?php

namespace App\Http\Controllers;

use App\Events\DefinitionCreated;
use App\Events\DefinitionVoted;
use App\Models\Definition;
use App\Models\Favourite;
use App\Models\Vote;
use App\Models\Word;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class WordController extends Controller
{
    public function index(Request $request)
    {
        $query = Word::with([
            'definitions' => function ($q) {
                $q->orderBy('votes_count', 'desc')->limit(1);
            },
            'definitions.user',
        ])->withCount('definitions');

        // Apply filters
        if ($request->filled('search')) {
            $query->where('text', 'like', '%'.$request->search.'%');
        }

        if ($request->filled('syllables')) {
            $syllables = (int) $request->syllables;
            if ($syllables >= 5) {
                $query->where('syllables', '>=', $syllables);
            } else {
                $query->where('syllables', $syllables);
            }
        }

        if ($request->filled('length')) {
            $length = (int) $request->length;
            if ($length >= 8) {
                $query->whereRaw('LENGTH(text) >= ?', [$length]);
            } else {
                $query->whereRaw('LENGTH(text) = ?', [$length]);
            }
        }

        if ($request->filled('starts_with')) {
            $query->where('text', 'like', $request->starts_with.'%');
        }

        $query->where('status', 'available')
            ->whereIn('dictionary_status', ['unchecked', 'not_found', 'exists_as_name']);

        // Apply sorting
        $sort = $request->input('sort', 'created_at');
        $direction = $request->input('direction', 'desc');

        switch ($sort) {
            case 'alphabetical':
                $query->orderBy('text', $direction);
                break;
            case 'letters':
                $query->orderByRaw('LENGTH(text) '.$direction);
                break;
            case 'popularity':
                $query->orderBy(function ($q) {
                    $q->selectRaw('COALESCE(MAX(votes_count), 0)')
                        ->from('definitions')
                        ->whereColumn('word_id', 'words.id');
                }, $direction);
                break;
            default:
                $query->orderBy('created_at', $direction);
                break;
        }

        $words = $query->paginate(20)->withQueryString();

        $filters = array_merge(
            ['search' => null, 'syllables' => null, 'length' => null, 'starts_with' => null, 'sort' => 'created_at', 'direction' => 'desc'],
            $request->only(['search', 'syllables', 'length', 'starts_with', 'sort', 'direction'])
        );

        return Inertia::render('Home', [
            'words' => $words,
            'filters' => $filters,
        ]);
    }

    public function show(Word $word)
    {
        $word->load([
            'definitions' => function ($q) {
                $q->orderBy('votes_count', 'desc');
            },
            'definitions.user',
            'definitions.votes' => function ($q) {
                $q->where('user_id', Auth::id());
            },
        ]);

        return Inertia::render('Words/Show', [
            'word' => $word,
        ]);
    }

    public function storeDefinition(Request $request, Word $word)
    {
        $request->validate([
            'text' => 'required|string|max:1000',
        ]);

        $definition = $word->definitions()->create([
            'user_id' => Auth::id(),
            'text' => $request->text,
        ]);

        DB::transaction(function () use ($definition) {
            $definition->votes()->create([
                'user_id' => Auth::id(),
                'value' => 1,
            ]);

            $definition->updateVotesCount();
        });

        broadcast(new DefinitionCreated($definition))->toOthers();

        return redirect()->to(route('words.show', $word))->with('success', 'Definition added successfully!');
    }

    public function voteDefinition(Request $request, Definition $definition)
    {
        $request->validate([
            'value' => 'required|in:-1,1',
        ]);

        if ($definition->user_id === Auth::id()) {
            return redirect()->back()->with('error', 'You cannot vote on your own definition.');
        }

        $vote = Vote::updateOrCreate(
            [
                'definition_id' => $definition->id,
                'user_id' => Auth::id(),
            ],
            [
                'value' => $request->value,
            ]
        );

        $definition->updateVotesCount();

        broadcast(new DefinitionVoted($definition, $vote))->toOthers();

        return redirect()->back();
    }

    public function toggleFavourite(Word $word)
    {
        $favourite = Favourite::where('user_id', Auth::id())
            ->where('word_id', $word->id)
            ->first();

        if ($favourite) {
            $favourite->delete();

            return redirect()->back()->with('success', 'Word removed from favourites.');
        }

        Favourite::create([
            'user_id' => Auth::id(),
            'word_id' => $word->id,
        ]);

        return redirect()->back()->with('success', 'Word added to favourites!');
    }

    public function favourites(Request $request)
    {
        $query = Word::whereHas('favourites', function ($q) {
            $q->where('user_id', Auth::id());
        })->with([
            'definitions' => function ($q) {
                $q->orderBy('votes_count', 'desc')->limit(1);
            },
            'definitions.user',
        ])->withCount('definitions');

        $words = $query->paginate(20)->withQueryString();

        return Inertia::render('Favourites/Index', [
            'words' => $words,
        ]);
    }
}
