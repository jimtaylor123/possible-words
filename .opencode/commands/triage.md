---
description: Refine a proposed issue with Jim and mark it approved or rejected
agent: build
---

Refine ONE proposed issue with Jim, then record the decision on the issue itself.

Issue argument: $ARGUMENTS

If the argument is empty, list the `proposed` issues (`gh issue list --state open --label proposed
--json number,title,labels`) and ask Jim which number to triage, then continue with that number.

## Boundary — read this carefully

You refine the **problem**, not the solution.

You may sharpen: what is actually wrong, who is affected and how often, the evidence, what a
correct outcome looks like, and what must NOT change. You may ask Jim questions to get there.

You must NOT design the implementation. Do not propose which files to edit, which functions to
add, what pattern to apply, or how to refactor. If Jim asks "how would you fix it", say that is
`/work`'s job at the planning gate, and offer the acceptance criteria instead. The moment this
command starts designing, it is duplicating the plan phase and it stops being useful.

## Steps

1. Read the issue and its evidence:
   - `gh issue view <N> --json number,title,body,labels,comments,url`
   - `gh api repos/jimtaylor123/possible-words/issues/<N>/comments`
2. **Verify the evidence still holds.** Open the cited `file:line` and confirm the described
   problem is really there. Report plainly if it is not — that is itself a finding, and the
   issue should be labelled `stale` rather than triaged.
3. Interview Jim. Ask only the questions that change the issue, and batch them. Useful ones:
   - Who hits this, and how often? Is this a real annoyance or a theoretical one?
   - Is the measured/observed cost still accurate, or should it be re-measured?
   - What would "fixed" look like concretely?
   - What must a fix deliberately leave alone?
   - Is this worth doing now, or is it real-but-not-urgent?
4. Rewrite the issue body into exactly this structure, preserving the existing evidence verbatim:

   ```markdown
   ## Problem
   <one or two sentences>

   ## Evidence
   - `path/to/file.php:42` — <what is there>
   <measurement or observed interaction, and how it was obtained>

   ## Impact
   <who is affected, how often. If unknown, say so — do not inflate.>

   ## Acceptance criteria
   - [ ] <testable and specific>

   ## Out of scope
   - <what a fix should not change>
   ```

   Update it with `gh issue edit <N> --body-file <file>`.
5. **Ask Jim to accept or reject, then act on the answer:**

   - **Accept** → add `Ready for development`, remove `proposed`, and (if the queue is backed up)
     remove `stale`. Reply with a one-line summary of what the issue now commits to.
   - **Reject** → add `rejected` if the label exists, otherwise add `wontfix`; remove `proposed` and
     any `type:` label. Then record WHY, generalized, in `.agents/scout/taste.md` under
     "Rejected patterns" — as a rule ("don't propose X", "prefer Y"), not an anecdote about this
     issue. Commit that change on a branch and open a PR, since the file is shared.
   - **Undecided** → change nothing. Leave it `proposed`. Never guess, and never apply
     `Ready for development` without an explicit yes.

## Guardrails
- One issue per invocation. Do not triage a second issue in the same run.
- Never apply `Ready for development` unless Jim has explicitly accepted this issue in this
  conversation. The label is the whole point of the gate — an unearned label sends work into
  `/work` that nobody approved.
- Do not close issues. Rejection is a label, not a closure; Jim may revisit.
- Do not start implementing, and do not open a pull request.
- Preserve the original evidence lines even when sharpening the wording. If evidence is wrong,
  say so and correct it rather than deleting it silently.
