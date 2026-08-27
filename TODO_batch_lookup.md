# Batch Lookup Implementation Plan
## Status: In Progress

**Goal**: Refactor `findTaskByPlanfixId` → `findTasksByPlanfixIds(array $ids)` for 50-ID batching.

### Steps:
- [x] 1. Create TODO.md ✅ (done)
- [x] 2. Refactor function definition in handler.php (batch IN filter, return map) ✅
- [x] 3. Caller updates: wrapper provides batch for all calls (single compat), no code changes needed ✅
- [x] 4. Update res/comments/handler.php for consistency ✅
- [ ] 5. Test batch performance
- [ ] 6. Complete
- [ ] 4. Update res/comments/handler.php function for consistency  
- [ ] 5. Test: batch lookup + migration snippet (100 tasks → ~2 API calls)
- [ ] 6. Complete: attempt_completion

**Next**: Proceed to Step 2?

