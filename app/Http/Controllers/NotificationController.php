<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        return response()->json($this->payload($request));
    }

    public function markAllRead(Request $request)
    {
        $request->user()->notifications()->whereNull('dismissed_at')->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json($this->payload($request));
    }

    public function markRead(Request $request, DatabaseNotification $notification)
    {
        $request->user()->notifications()->whereKey($notification->id)->whereNull('dismissed_at')->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json($this->payload($request));
    }

    public function clearRead(Request $request)
    {
        $request->user()->notifications()->whereNull('dismissed_at')->whereNotNull('read_at')->update(['dismissed_at' => now()]);

        return response()->json($this->payload($request));
    }

    /** @return array{items: \Illuminate\Support\Collection<int, array<string, mixed>>, unread_count: int} */
    public static function forUser(User $user): array
    {
        $visible = $user->notifications()->whereNull('dismissed_at');

        return [
            'items' => $visible->latest()->limit(15)->get()->map(fn (DatabaseNotification $notification) => [
                'id' => $notification->id,
                'kind' => $notification->kind,
                'data' => $notification->data,
                'read_at' => $notification->read_at?->toISOString(),
                'created_at' => $notification->created_at->toISOString(),
            ]),
            'unread_count' => $user->notifications()->whereNull('dismissed_at')->whereNull('read_at')->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $payload = self::forUser($request->user());

        return [
            'notifications' => $payload['items'],
            'unread_count' => $payload['unread_count'],
        ];
    }
}
