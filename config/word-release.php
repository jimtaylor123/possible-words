<?php

return [
    'batch_size' => (int) env('WORD_RELEASE_BATCH_SIZE', 10),
    'eventbridge_expression' => env('WORD_RELEASE_SCHEDULE', 'cron(0 */6 * * ? *)'),
];
