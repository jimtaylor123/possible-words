---
description: Start the feature workflow for a GitHub issue (paste the issue URL)
agent: build
---

Start the feature-delivery workflow for the GitHub issue below.

Issue argument: $ARGUMENTS

Steps:
1. If the argument is empty or not a GitHub issue URL, stop and ask the user to provide one as `/work https://github.com/<owner>/<repo>/issues/<number>`.
2. Parse the owner, repository, and issue number from the URL.
3. If the repository is not this repository, tell the user the workflow is currently tuned for this repo and confirm before proceeding.
4. Fetch the issue: `gh issue view <owner>/<repo> <number>`
5. Load the `feature-workflow` skill via your skill tool and run its pipeline end-to-end for this issue. If the skill is unavailable, read `.agents/workflow.yml` and follow the steps directly.
6. Create the git worktree as a sibling directory (`../<repo-slug>-<issue-number>/`) from `origin/main`, set up the `<worktree>/.agents/` scratch files, and start the dev server on a free port (starting at 8000).
7. Dispatch the `implementer`, `reviewer`, and `tester` subagents in workflow order, pausing for explicit user approval at the planning gate.
8. End at PR creation. Do NOT self-request a review and do NOT merge anything.
9. Report the PR link and remind the user that the local PR maintenance pass is `/pr-manager`.