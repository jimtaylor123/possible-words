<?php

use App\Models\ReportCase;

test('report case lifecycle permits only the agreed transitions', function () {
    expect(ReportCase::statuses())->toBe(['open', 'dismissed', 'reviewed', 'muted'])
        ->and(ReportCase::actionsFor(ReportCase::STATUS_OPEN))->toBe(['dismissed', 'removed', 'muted'])
        ->and(ReportCase::actionsFor(ReportCase::STATUS_MUTED))->toBe(['unmuted'])
        ->and(ReportCase::actionsFor(ReportCase::STATUS_DISMISSED))->toBe([])
        ->and(ReportCase::actionsFor(ReportCase::STATUS_REVIEWED))->toBe([])
        ->and(ReportCase::statusForAction(ReportCase::ACTION_DISMISSED))->toBe(ReportCase::STATUS_DISMISSED)
        ->and(ReportCase::statusForAction(ReportCase::ACTION_UNMUTED))->toBe(ReportCase::STATUS_OPEN);
});
