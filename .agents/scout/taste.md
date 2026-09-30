# Scout taste

Jim's preferences, learned from `/triage` decisions. The issue-scout reads this before
proposing anything, so the queue converges on what he actually wants rather than on
generic best practices.

## How this file works

- `/triage <N>` appends an entry when Jim **rejects** a proposal, generalized from his reason.
- Entries are rules, not anecdotes. Write them as "don't propose X" / "prefer X".
- Keep it short. If it grows past ~30 entries it stops being guidance and becomes noise;
  consolidate duplicates and drop entries that no longer apply.

## Confirmed preferences

- Improvements must carry a measured or observed cost. No taste-based findings.
- Frontend UX friction found by actually driving the app is valued.
- Do not propose refactors for their own sake. The repo has essentially no TODO debt
  (verified: no actionable TODO/FIXME outside vendor/ and lockfiles) — do not go hunting there.

## Rejected patterns

_(none recorded yet — entries appear here after the first `/triage` rejection)_
