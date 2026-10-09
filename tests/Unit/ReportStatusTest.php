<?php

use App\Models\Report;

test('statuses lists every lifecycle status', function () {
    expect(Report::statuses())->toBe([
        Report::STATUS_OPEN,
        Report::STATUS_REVIEWED,
        Report::STATUS_DISMISSED,
    ]);
});

test('lifecycleActionsFor maps each status to its permitted actions', function () {
    expect(Report::lifecycleActionsFor(Report::STATUS_OPEN))
        ->toBe([Report::ACTION_REVIEWED, Report::ACTION_DISMISSED])
        ->and(Report::lifecycleActionsFor(Report::STATUS_REVIEWED))
        ->toBe([Report::ACTION_REOPENED])
        ->and(Report::lifecycleActionsFor(Report::STATUS_DISMISSED))
        ->toBe([Report::ACTION_REOPENED])
        ->and(Report::lifecycleActionsFor('unknown'))
        ->toBe([]);
});

test('statusesForLifecycleAction guards which statuses an action may transition from', function () {
    expect(Report::statusesForLifecycleAction(Report::ACTION_REVIEWED))
        ->toBe([Report::STATUS_OPEN])
        ->and(Report::statusesForLifecycleAction(Report::ACTION_DISMISSED))
        ->toBe([Report::STATUS_OPEN])
        ->and(Report::statusesForLifecycleAction(Report::ACTION_REOPENED))
        ->toBe([Report::STATUS_REVIEWED, Report::STATUS_DISMISSED])
        ->and(Report::statusesForLifecycleAction('unknown'))
        ->toBe([]);
});

test('statusForAction translates reopen back to the open status', function () {
    expect(Report::statusForAction(Report::ACTION_REVIEWED))
        ->toBe(Report::STATUS_REVIEWED)
        ->and(Report::statusForAction(Report::ACTION_DISMISSED))
        ->toBe(Report::STATUS_DISMISSED)
        ->and(Report::statusForAction(Report::ACTION_REOPENED))
        ->toBe(Report::STATUS_OPEN);
});
