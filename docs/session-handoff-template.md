# Session Handoff Template

Use this template at the end of each session to keep continuity between agents.

## Metadata
- Date: YYYY-MM-DD
- Repo: `apis-hub`
- Branch: `<branch-name>`
- Related plan section: `<phase/task id>`

## 1) Completed in this session
- [ ] Item 1
- [ ] Item 2
- [ ] Item 3

## 2) Pending next actions
- [ ] Next step 1
- [ ] Next step 2
- [ ] Next step 3

## 3) Risks / assumptions
- Risk or assumption 1
- Risk or assumption 2

## 4) Validation executed
```powershell
# Add exact commands used during this session
php .\vendor\bin\phpunit --colors=never --filter "<suite-or-test>"
```
- Result summary: `OK (...)` or `FAILED (...)`

## 5) Files touched
- `path/to/file1`
- `path/to/file2`

## 6) Commits
- `<sha>` - `<message>`

## 7) Resume instructions for next agent
1. Read `AGENTS.md`.
2. Read `MEMORY.md`.
3. Read `docs/aggregation-implementation-plan.md`.
4. Read canonical plan in `_shared`.
5. Continue from "Pending next actions" section above.

