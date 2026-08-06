<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ServeBuildAssets
{
    public function handle(Request $request, Closure $next): mixed
    {
        $path = rawurldecode(ltrim($request->getPathInfo(), '/'));

        if (!str_starts_with($path, 'build/') && !str_starts_with($path, 'favicon')) {
            return $next($request);
        }

        $file = public_path($path);

        if (!file_exists($file) || is_dir($file)) {
            return $next($request);
        }

        return new BinaryFileResponse($file, 200, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Content-Type' => $this->getMimeType($file),
        ]);
    }

    private function getMimeType(string $file): string
    {
        $mime = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file);

        if ($mime === 'image/vnd.microsoft.icon') {
            return 'image/x-icon';
        }

        if ($mime !== 'text/plain') {
            return $mime;
        }

        return match (pathinfo($file, PATHINFO_EXTENSION)) {
            'js' => 'text/javascript',
            'css' => 'text/css',
            default => 'text/plain',
        };
    }
}
