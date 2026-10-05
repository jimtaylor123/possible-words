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
            // The browse card quotes the top definition. A removed one is still listed
            // on the word page, but quoting "[deleted]" in a card is worse than falling
            // through to the next definition, so the teaser skips removed rows.
            'definitions' => function ($q) {
                $q->withoutRemoved()->orderBy('votes_count', 'desc')->limit(1);
            },
            'definitions.user',
        ])->withCount('definitions');

        // Apply filters
        $search = $this->queryString($request, 'search');
        if ($search !== '') {
            $query->whereRaw("text LIKE ? ESCAPE '\\'", ['%'.$this->escapeLike($search).'%']);
        }

        $syllables = (int) $this->queryString($request, 'syllables');
        if ($syllables > 0) {
            if ($syllables >= 5) {
                $query->where('syllables', '>=', $syllables);
            } else {
                $query->where('syllables', $syllables);
            }
        }

        $length = (int) $this->queryString($request, 'length');
        if ($length > 0) {
            if ($length >= 8) {
                $query->whereRaw('LENGTH(text) >= ?', [$length]);
            } else {
                $query->whereRaw('LENGTH(text) = ?', [$length]);
            }
        }

        $startsWith = $this->queryString($request, 'starts_with');
        if ($startsWith !== '') {
            $query->whereRaw("text LIKE ? ESCAPE '\\'", [$this->escapeLike($startsWith).'%']);
        }

        $query->publishable();

        // Apply sorting
        $sort = $this->queryString($request, 'sort');
        if (! in_array($sort, ['alphabetical', 'letters', 'popularity', 'created_at'], true)) {
            $sort = 'created_at';
        }

        // Whitelist the direction: anything that is not exactly "asc" falls back to "desc".
        // Interpolated into orderByRaw() below, so it must never carry request input.
        $direction = strtolower($this->queryString($request, 'direction')) === 'asc' ? 'asc' : 'desc';

        switch ($sort) {
            case 'alphabetical':
                $query->orderBy('text', $direction);
                break;
            case 'letters':
                // Safe: $direction is one of two literals, not request input.
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

        // Reflect the sanitised values back to the client, never the raw input.
        $filters = [
            'search' => $search,
            'syllables' => $syllables > 0 ? $syllables : null,
            'length' => $length > 0 ? $length : null,
            'starts_with' => $startsWith,
            'sort' => $sort,
            'direction' => $direction,
        ];

        return Inertia::render('Home', [
            'words' => $words,
            'filters' => $filters,
        ]);
    }

    /**
     * Read a query parameter as a trimmed string.
     *
     * A repeated or bracketed parameter (?direction[]=asc&direction[]=id) arrives as an
     * array, and casting or concatenating that throws "Array to string conversion",
     * which is a 500. Anything non-scalar is treated as absent.
     */
    private function queryString(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Escape a user value for a LIKE pattern that uses backslash as its escape character.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
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

    public function suggestions(Request $request)
    {
        $term = $this->queryString($request, 'q');

        if (mb_strlen($term) < 2 || str_contains($term, '%') || str_contains($term, '_') || str_contains($term, '\\')) {
            return response()->json([]);
        }

        // Scout builds its own Eloquent query, so the publishable scope has to be
        // applied through the query callback. Soft deletes are already excluded:
        // Scout starts from newQuery() and config/scout.php leaves soft_delete off.
        $words = Word::search($term)
            ->query(fn ($query) => $query->publishable())
            ->take(8)
            ->get()
            ->map(fn (Word $word) => [
                'id' => $word->id,
                'text' => $word->text,
                'slug' => $word->slug,
                'syllables' => $word->syllables,
            ]);

        return response()->json($words);
    }

    public function storeDefinition(Request $request, Word $word)
    {
        $validated = $request->validate([
            'text' => 'required|string|max:1000',
            'part_of_speech' => 'nullable|in:noun,verb,other',
        ]);

        $definition = $word->definitions()->create([
            'user_id' => Auth::id(),
            'text' => $validated['text'],
            'part_of_speech' => $validated['part_of_speech'] ?? null,
        ]);

        DB::transaction(function () use ($definition) {
            $definition->votes()->create([
                'user_id' => Auth::id(),
            ]);

            $definition->updateVotesCount();
        });

        broadcast(new DefinitionCreated($definition))->toOthers();

        return redirect()->to(route('words.show', $word))->with('success', 'Definition added successfully!');
    }

    public function voteDefinition(Definition $definition)
    {
        // A removed definition is inert: its text is redacted and it offers no
        // actions, so Show.vue already hides the vote button. That was only a UI
        // convention though — the button is gone, the endpoint is not, and this
        // app has no policies or gates to fall back on. Guarding here makes the
        // server agree with the UI, which is the invariant the E2E spec asserts
        // (`card.locator('button')).toHaveCount(0)`).
        if ($definition->isRemoved()) {
            return redirect()->back();
        }

        $vote = Vote::where([
            'definition_id' => $definition->id,
            'user_id' => Auth::id(),
        ])->first();

        if ($vote) {
            $vote->delete();
            $liked = false;
        } else {
            $definition->votes()->create([
                'user_id' => Auth::id(),
            ]);
            $liked = true;
        }

        $definition->updateVotesCount();

        broadcast(new DefinitionVoted($definition, $liked))->toOthers();

        return redirect()->back();
    }

    public function removeDefinition(Definition $definition)
    {
        // Inline ownership check: this app has no policies and no gates (AGENTS.md).
        // This runs before the removed-state check so a non-author cannot use the
        // response to probe whether a definition has already been removed.
        if ($definition->user_id !== Auth::id()) {
            abort(403, 'Forbidden.');
        }

        // Idempotent: removing a definition that is already removed is a no-op, and
        // votes_count is never touched here at all — only removed_at is written, so
        // every existing like stands (including the author's own auto-self-like).
        if ($definition->removed_at === null) {
            // Assign directly rather than mass-assigning: removed_at is deliberately
            // not fillable, and mass assignment would silently drop it and write nothing.
            $definition->removed_at = now();
            $definition->save();
        }

        return redirect()->back()->with('success', 'Definition removed.');
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
            // Same reason as index(): a "[deleted]" teaser on a favourites card reads as
            // a broken card, so fall through to the definition that is still live.
            'definitions' => function ($q) {
                $q->withoutRemoved()->orderBy('votes_count', 'desc')->limit(1);
            },
            'definitions.user',
        ])->withCount('definitions');

        $words = $query->paginate(20)->withQueryString();

        return Inertia::render('Favourites/Index', [
            'words' => $words,
        ]);
    }
}
