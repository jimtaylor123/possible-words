<?php

namespace App\Http\Controllers;

use App\Models\Word;
use App\Models\Definition;
use App\Models\Vote;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class WordController extends Controller
{
    public function index(Request $request)
    {
        $query = Word::with(['definitions' => function($q) {
            $q->orderBy('votes_count', 'desc')->limit(3);
        }]);

        // Apply filters
        if ($request->filled('search')) {
            $query->where('text', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('syllables')) {
            $query->where('syllables', $request->syllables);
        }

        if ($request->filled('length')) {
            $query->whereRaw('LENGTH(text) = ?', [$request->length]);
        }

        if ($request->filled('starts_with')) {
            $query->where('text', 'like', $request->starts_with . '%');
        }

        $words = $query->where('status', 'available')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return Inertia::render('Words/Index', [
            'words' => $words,
            'filters' => $request->only(['search', 'syllables', 'length', 'starts_with']),
        ]);
    }

    public function show(Word $word)
    {
        $word->load(['definitions.user', 'definitions.votes' => function($q) {
            $q->where('user_id', Auth::id());
        }]);

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

        return redirect()->back()->with('success', 'Definition added successfully!');
    }

    public function voteDefinition(Request $request, Definition $definition)
    {
        $request->validate([
            'value' => 'required|in:-1,1',
        ]);

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

        return redirect()->back();
    }
}
