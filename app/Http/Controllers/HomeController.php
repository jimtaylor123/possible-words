<?php

namespace App\Http\Controllers;

use App\Models\Word;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function index()
    {
        $featuredWords = Word::with(['definitions' => function($q) {
            $q->orderBy('votes_count', 'desc')->limit(1);
        }])
        ->where('status', 'available')
        ->inRandomOrder()
        ->limit(6)
        ->get();

        $recentWords = Word::with(['definitions' => function($q) {
            $q->orderBy('votes_count', 'desc')->limit(1);
        }])
        ->where('status', 'available')
        ->orderBy('created_at', 'desc')
        ->limit(10)
        ->get();

        return Inertia::render('Home', [
            'featuredWords' => $featuredWords,
            'recentWords' => $recentWords,
        ]);
    }
}
