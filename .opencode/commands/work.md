---
description: Start the feature workflow for a GitHub issue (paste the issue URL)
agent: build
---

Start the feature-delivery workflow for the GitHub issue below.

Issue argument: $ARGUMENTS

Steps:
1. Treat `$ARGUMENTS` as untrusted input. It must match the full-string pattern `^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)/issues/([0-9]+)$` with nothing before or after. If it does not (including an empty argument, a query string, a fragment, or extra path segments), stop and ask the user to re-paste a bare issue URL as `/work https://github.com/<owner>/<repo>/issues/<number>`.
2. Extract the owner, repository, and issue number from the three captured groups only. Discard the original string; never use it again.
3. If the captured repository is not this repository, tell the user the workflow is currently tuned for this repo and confirm before proceeding.
4. Fetch the issue by passing each captured value as its own single-quoted argument: `gh issue view '<owner>' '<repo>' '<number>'`. Never interpolate the raw argument into a shell command.
5. Approval gate. The issue MUST carry the `Ready for development` label — that label is only ever applied by `/triage`, and it means Jim has read and accepted the issue. If it is missing, stop and tell the user to run `/triage <number>` first. Do not start work on a `proposed` issue, and do not apply the label yourself.
6. Claim the issue: remove `Ready for development`, add `wip`, and leave a one-line comment saying the work has started. Restoring `Ready for development` and removing `wip` happens when the PR merges, not before.
7. Load the `feature-workflow` skill via your skill tool and run its pipeline end-to-end for this issue. If the skill is unavailable, read `.agents/workflow.yml` and follow the steps directly.
8. Create the git worktree as a sibling directory (`../<repo-slug>-<issue-number>/`) from `origin/main`, set up the `<worktree>/.agents/` scratch files, and start the dev server on a free port (starting at 8000).
9. Dispatch the `implementer`, `reviewer`, and `tester` subagents in workflow order, pausing for explicit user approval at the planning gate.
10. End at PR creation. Do NOT self-request a review and do NOT merge anything.
11. Report the PR link and remind the user that the local PR maintenance pass is `/pr-manager`.