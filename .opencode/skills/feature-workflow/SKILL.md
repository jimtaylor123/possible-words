---
name: feature-workflow
description: Process a new feature or GitHub issue through planning, implementation, code review, testing, and PR creation using dedicated sub-agents.
---

# Feature Workflow Orchestrator

When the user asks to work on a feature or GitHub issue, orchestrate the full pipeline.

## 1. Load the workflow
Read `.agents/workflow.yml` to get the step definitions. Follow the step order and `depends_on` relationships.

## 2. Setup (you do this directly)
Execute the `setup_workspace` step yourself:
- Create a git worktree from main as a sibling directory: `../possiblewords-<issue-number>/`
- Create `.agents/` scratch directory inside the worktree
- Create todo.md, plan.md, notes.md, browser_logs.md
- Find an available port (starting at 8000) and start the dev server
- Report the worktree path, port, and dev server URL

## 3. Dispatch subagents
For each subsequent step in the workflow, dispatch the appropriate subagent using the Task tool:

### Dispatch rules
| Workflow `agent` | Subagent type | Notes |
|---|---|---|
| `implementer` | `implementer` | Pass the step's prompt as your instruction |
| `reviewer` | `reviewer` | Pass the step's prompt as your instruction |
| `tester` | `tester` | Pass the step's prompt as your instruction |

### How to dispatch
For each workflow step:
```
task(
  description: "<step_id> - <short description>",
  subagent_type: "<agent field from YAML>",
  prompt: "<step's prompt from workflow.yml, interpolated with actual values>"
)
```

### Important
- After a subagent completes, **read their output files** to determine next actions
- For the `planning_approval_gate` step: pause and use the `question` tool to ask the user for explicit approval before proceeding
- The `fix_loop` step has `loop: 2` — after the reviewer outputs `.agents/review.md`, check if there are issues. If yes, dispatch implementer. Track iteration count. Repeat up to the loop limit.
- After `final_fix`, re-dispatch the tester to verify fixes pass

## 4. State files in .agents/
- `todo.md` — task tracking
- `plan.md` — implementation plan
- `notes.md` — decisions made
- `review.md` — review findings
- `browser_logs.md` — test results
- `port.txt` — dev server port
