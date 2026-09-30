---
description: Show the issue queue (ranked digest of proposed issues) without changing anything
agent: build
---

Show the current issue queue for jimtaylor123/possible-words.

This command is strictly READ-ONLY. It must not create, edit, comment on, label, or close any
issue. It only reads.

Steps:
1. Fetch the queue and the board summary:
   - `gh issue list --state open --label proposed --limit 100 --json number,title,labels,createdAt,url`
   - `gh issue list --state open --label "Ready for development" --json number,title`
   - `gh issue list --state open --label wip --json number,title`
   - `gh issue list --state open --label stale --json number,title`
2. Rank the `proposed` issues:
   - **Defect class first**: `type:bug`, `security`, then `type:perf`, then `type:debt`.
   - **Opinionated class last**: `type:ux`, `type:idea`.
   - Within a class, order by issue number ascending (oldest first — they have waited longest).
   - If Jim asks for the opinionated stuff first, invert the two groups.
3. Print exactly this shape:

```
QUEUE — possible-words

<N> proposed, <M> ready for development, <K> wip, <S> stale

  #<n>  type:<class>  <area>  <title>
       why: <one line — the problem or the measured/observed cost>
       evidence: <file:line or measurement, and the link>
       verified: yes | no   (whether the scout demonstrated it, not just asserted it)

  ... (blank line between entries)

READY FOR DEVELOPMENT: #<n> <title> — run /work <n>
IN PROGRESS: #<n> <title>
STALE: #<n> <title> — evidence no longer holds

next: /triage <n>   then:   /work <n>
```

Rules for the output:
- Read the "why" and evidence line from each issue's `## Problem` and `## Evidence` sections. Do not
  invent a summary; if an issue is missing them, say `evidence: missing — re-run scout` on that line.
- If there are no `proposed` issues, print `queue empty — run the issue-scout agent to refill` and
  stop. Do not fall back to listing anything else.
- Never suggest what Jim "should" work on, and never reorder by your own judgement of importance.
  The ranking above is the whole rule.
- If `$ARGUMENTS` names a filter (for example `type:ux` or `#94`), apply it and print the same
  shape for just those entries.
