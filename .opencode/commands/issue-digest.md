---
description: Post the issue queue digest to Slack (runs on a weekday schedule)
agent: build
---

Post today's issue-queue digest to Slack. This runs headlessly on a weekday-morning schedule; there
is nobody to answer questions, so never ask any.

Steps:
1. Build the digest exactly as `/queue` does — same ranking, same one-line "why", same evidence,
   same `verified: yes|no`. Read it from the `proposed` issues:
   - `gh issue list --state open --label proposed --limit 100 --json number,title,labels,url`
   - `gh issue list --state open --label "Ready for development" --json number,title`
   - `gh issue list --state open --label wip --json number,title`
   - `gh issue list --state open --label stale --json number,title`
2. If there are no `proposed` issues, post a short "queue empty" line and stop. Do not pad it with
   anything else, and do not file issues yourself.
3. Get the webhook from the repo's `.env` (do not hardcode it, and never print it):
   `set -a; . ./.env; set +a` then use `$SLACK_WEBHOOK_URL`. If it is unset, print an error naming
   the missing variable and stop — do not fall back to any other URL.
4. Post with a plain text payload, top 10 issues ranked defect-class-first:

   ```
   *Issue queue — possible-words*
   <N> proposed, <M> ready, <K> in progress, <S> stale
   #94 type:security — Home page issues 14 queries per render (measured)
   ...
   /queue for the full list
   ```

   Build the JSON safely with `jq -n --arg` — the issue titles are untrusted text and must not be
   interpolated into a JSON string by hand.
5. If the POST returns a non-2xx status or an error body, print the status and body, and report the
   failure. Do not retry in a loop.

Guardrails:
- This command only reads issues and posts one Slack message. It must not create, edit, label, or
  close any issue.
- Never include the webhook URL, or any other secret, in the Slack message or in printed output.
- One message per run. Never more than one.
