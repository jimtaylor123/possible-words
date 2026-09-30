<?php

use Illuminate\Support\Facades\File;

/**
 * Serving built assets over HTTP.
 *
 * Given a visitor requests an asset from /build,
 * When the path tries to escape the build directory,
 * Then the request is refused rather than serving a file from outside public/build.
 */
describe('serving built assets', function () {
    beforeEach(function () {
        File::ensureDirectoryExists(public_path('build/assets'));
        File::ensureDirectoryExists(public_path('build-secrets'));
        File::put(public_path('build/assets/traversal-probe.js'), 'console.log(1)');
        File::put(public_path('build-secrets/probe.txt'), 'should not be served');
    });

    afterEach(function () {
        File::delete(public_path('build/assets/traversal-probe.js'));
        File::delete(public_path('build-secrets/probe.txt'));
        File::deleteDirectory(public_path('build-secrets'));
    });

    test('Given a legitimate asset, it is served with the right content type', function () {
        $response = $this->get('/build/assets/traversal-probe.js');

        $response->assertStatus(200);
        expect($response->headers->get('Content-Type'))->toStartWith('text/javascript');
    });

    test('Given a traversal attempt, the request is refused', function (string $path) {
        $this->get($path)->assertStatus(404);
    })->with([
        'literal traversal to .env' => '/build/../../.env',
        'encoded traversal to .env' => '/build/%2e%2e%2f%2e%2e%2f.env',
        'mixed encoded traversal' => '/build/..%2F..%2F.env',
        'double-encoded traversal' => '/build/%252e%252e%252f.env',
        'traversal from a subdirectory' => '/build/assets/../../../.env',
        'traversal to the app root' => '/build/../index.php',
        'traversal to a sibling directory' => '/build/../build-secrets/probe.txt',
        'traversal to a prefixed sibling' => '/build/../build-secrets',
    ]);

    test('Given a traversal attempt, no file contents leak in the response body', function () {
        $response = $this->get('/build/%2e%2e%2f%2e%2e%2f.env');

        $response->assertStatus(404);
        expect($response->getContent())->not->toContain('APP_KEY');
    });

    test('Given a directory request, it is refused', function () {
        File::ensureDirectoryExists(public_path('build/assets/nested'));

        try {
            $this->get('/build/assets/nested')->assertStatus(404);
        } finally {
            File::deleteDirectory(public_path('build/assets/nested'));
        }
    });
});
