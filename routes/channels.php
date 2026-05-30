<?php

use Illuminate\Support\Facades\Broadcast;

// Public channels for definition updates (no auth required for viewing)
Broadcast::channel('definition.{id}', function ($user, $id) {
    return true; // Anyone can listen to definition vote updates
});

Broadcast::channel('word.{id}.definitions', function ($user, $id) {
    return true; // Anyone can listen to new definitions for a word
});
