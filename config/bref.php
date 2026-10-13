<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Servable Assets
    |--------------------------------------------------------------------------
    |
    | Here you can configure list of public assets that should be servable
    | from your application's domain instead of AWS CloudFront.
    | Read https://bref.sh/docs/use-cases/websites for a better solution.
    |
    */

    'assets' => array_merge([
        'build/manifest.json',
        'favicon.svg',
        'favicon.ico',
    ], array_map(
        fn (string $path) => 'build/assets/'.basename($path),
        glob(public_path('build/assets/*')) ?: [],
    )),

    /*
    |--------------------------------------------------------------------------
    | Shared Log Context
    |--------------------------------------------------------------------------
    |
    | In order to make debugging a little easier, the Lambda `X-Request-ID`
    | value can be added to the shared log context automatically.
    |
    */

    'request_context' => false,

    /*
    |--------------------------------------------------------------------------
    | Jobs Logging
    |--------------------------------------------------------------------------
    |
    | Here you can disable detailed logging of every job execution.
    |
    */

    'log_jobs' => true,

];
