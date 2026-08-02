# SDD ledger — plan: /Users/michaeltawiahsowah/Sites/glueful/extensions/subscriptions/docs/superpowers/plans/2026-08-02-subscriptions-v2-subject-model.md
Task 1: fix round 1/5 (1 addressed, 0 open — test brief-form deviation; commits 8677bed..89e134a)
Task 1: complete (commits 97fd12a..89e134a, review clean)
Task 2: minor (deferred): PrepareV2Command uses raw <error>/<info> writeln tags instead of BaseCommand error()/info() helpers
Task 2: minor (deferred): final-verification loop in PrepareV2Command is effectively unreachable (plan-mandated pseudocode)
Task 2: minor (deferred): catalog_signature is snapshot-at-insert, not batch-bound (inherited from spec)
Task 2: complete (commits 89e134a..790a2cf, review clean)
Task 3: complete (commits 790a2cf..ead9232, review clean; tag v1.4.0 local) — PHASE A (1.4.0) DONE
Task 4: fix round 1/5 (1 addressed, 0 open — unrequested phpcs.xml removed; commits c9ff529..05d1016)
Task 4: complete (commits ead9232..05d1016, review clean)
Task 5: review found C1 (PG DROP INDEX vs constraint-backed uniques), I2 (up() not re-runnable), I3 (down() preflight hole subject_uuid<>tenant_uuid), I4 (no tests for abort path + receipts down-refusal). Fix round 1 dispatched.
Task 5: minor (deferred): receipts UNIQUE is anonymous sqlite_autoindex on SQLite (name not addressable)
Task 5: minor (deferred): expectException(\Throwable) too broad in 4 SubjectMigrationTest sites
Task 5: minor (deferred): V2SubscriptionsTestCase plan_uuid-override hint unreachable
Task 5: minor (deferred): COALESCE(tenant_uuid,'') missing in subject_uuid backfill (NULL tenant_uuid theoretical)
Task 5: minor (deferred): guard test does not assert no-DDL-before-throw property
Task 5: fix round 1/5 (4 addressed, 0 open — PG DROP CONSTRAINT, ensureColumn idempotency, preflight subject_uuid check, 4 safety tests; commits 77fbdd8..97740b7)
Task 5: minor (deferred): PG down() restores 1.x uniques as standalone indexes not table constraints (metadata asymmetry vs fresh install)
Task 5: complete (commits 05d1016..97740b7, review clean)
Task 6: fix round 1/5 (2 addressed, 0 open — atomic-pair derivation + transitional comment; commits 7cc1db6..b0fc1cb)
Task 6: note: ProviderEventReceiptRepository intentionally NOT wired into services() yet (Task 14 wires)
Task 6: complete (commits 97740b7..b0fc1cb, review clean)
Task 7: minor (deferred, Task-9 pointer): transitional isScoped() guards scattered across 8 methods, not one branch — Task 9 must sweep the whole class
Task 7: minor (deferred): uuid-first catalog methods are scope-blind by design; cross-scope case untested
Task 7: complete (commits b0fc1cb..2eacfc8, review clean)
Task 8: complete (commits 2eacfc8..585e7dd, review clean)
Task 8: Task-9 pointer (Important handoff): pre-cutover PlanController write-side clobber — unscoped updateByKey() can mutate a same-keyed workspace row; Task 9's platform-scope delegation of legacy methods MUST close this (verify with a test)
Task 9: complete (commits 585e7dd..20f1143, review clean — activation boundary)
Task 9: minor (deferred): PrepareV2CommandTest audit assertion now vacuous (coverage preserved elsewhere); stale PlanManagementService binding in its setUp
Task 9: minor (deferred): six stale Task-9/TRANSITIONAL docblocks (PlanController.php:23 production + SubjectMigrationTest:17-20 now false + three repo-test notes)
Task 9: minor (deferred, Task-16 pointer): current('') now throws (stricter facade edge) — MUST be documented in CHANGELOG/upgrade notes
Task 9: minor (deferred): SubscriptionEventRepository::append() now dead in src/ (swallow-wrapper invites misuse)
Task 9: minor (deferred): PrepareV2Command docblock should state the lost plan_changed audit emission explicitly
Task 9: minor (deferred): LegacySchemaTestCase 'Reserved for' wording is the only guard against new tests parking on 1.x schema
Task 10: review found I1 (partial subject coercion), I2 (empty-metadata mismatch), I3 (unmapped burn — ESCALATED), I4 (events.data raw — ESCALATED) + 7 minors.
Task 10: fix round 1/5 (I1+I2 addressed + 4 regression tests; commits 7c51e37..a16af69)
Task 10: OWNER RULINGS: (A) unmapped_subscription = retryable rollback with typed exception, other 4 codes stay committed; race-retry test required. (B) ProviderReceiptData→ProviderEventData, sanitize provider-sourced events.data too, 006 amended to sanitize historical provider events. planForUuid throwable-swallow + rejectionCode-null suppression elevated into round 2 by ruling A's determinism principle.
Task 10: fix round 2/5 dispatched (rulings A+B).
Task 10: minor (deferred): candidate_* unbounded lengths vs narrow columns (MySQL/PG strict overflow; SQLite harness blind)
Task 10: minor (deferred): markAccepted/markRejected don't verify affected-row count
Task 10: minor (deferred): relink unique-collision logged as duplicate_claim_skipped (wrong log label, safe outcome)
Task 10: minor (deferred): final dropped from two classes for test doubles (extension surface widened)
Task 10: minor (deferred): no test asserting candidate_* diagnostic columns populated (partially fixed round 1)
Task 10: fix round 2/5 (rulings A+B implemented; commits a16af69..499f361; combined re-review: 6/6 ADDRESSED, no new breakage)
Task 10: complete (commits 20f1143..499f361, review clean after 2 rounds + owner rulings)
Task 10: CROSS-REPO FLAG: payvia webhook controller dispatch() vs dispatchOrFail() determines whether the unmapped retry signal reaches the wire — needs verification in glueful/payvia (outside this repo)
Task 11: review found C1 (unguarded cross-workspace catalog scope in resolveMap — the spec-§1 leak), I1 (case-sensitive rate.tier strip). Fix round 1 dispatched.
Task 11: minor (deferred): MemberEntitlementResolver cache path shipped without a dedicated cache test
Task 11: minor (resolved by ruling): strip log level pinned at warning
Task 11: fix round 1/5 (2 addressed, 0 open — catalog scope guard + normalized strip; commits 17826f6..5f84da6)
Task 11: complete (commits 499f361..5f84da6, review clean)
Task 12: review found C1 (composer dev-dev-as-1.5.0 branch-alias + path-repo hack unresolvable off-machine) + 1 minor (hasContainer pre-check). Fix round 1 dispatched (plain ^1.5.0).
Task 12: fix round 1/5 (2 addressed — plain ^1.5.0 + canonical:false path repo + hasContainer pre-check; commits b3ace51..619f6f3)
Task 12: complete (commits 5f84da6..619f6f3, review clean)
Task 13: review found I1 (inconsistent mismatched --subject-uuid handling; SetPlan silently discards uuid on a write path) + minor (resolveSubject copy-paste drift). Fix round 1 dispatched.
Task 13: minor (deferred): setUserSubjectPlan double catalog lookup (isAssignable + planUuidForKey)
Task 13: fix round 1/5 (2 addressed — ResolvesSubjectOption trait + consistent mismatch rejection; commits 7870a15..3a30664)
Task 13: complete (commits 619f6f3..3a30664, review clean)
Task 14: review found C1 (pre-pass tenant read bakes stale catalog scope into MemberEntitlementResolver — 500 not 403 on the standard ['tenant','require_member_entitlement'] stack; traced against real Router + tenancy extension). Fix round 1 dispatched (handle()-time catalog construction via factory).
Task 14: fix round 1/5 (1 addressed — MemberEntitlementResolverFactory, handle()-time scoping, skew reproduction test; commits a0a13d9..536b02f)
Task 14: complete (commits 3a30664..536b02f, review clean)
Task 15: complete (commits 536b02f..ded400e, review clean; PG path proven against real local PostgreSQL 17)
Task 15: minor (deferred): CI runs PostgresSavepointTest twice (full suite + isolated --fail-on-skipped step) — deliberate redundancy
Task 16: implemented (commits ded400e..96517bb, tag v2.0.0 local). Review next.
Task 16: complete (commits ded400e..96517bb, review clean; tag v2.0.0 local) — ALL 16 TASKS DONE
FINAL REVIEW: 2 Critical (fresh-install silent failure; post-upgrade override inertness) + 3 Important (PG down/up round-trip; provider-string overflow; tenancy read seam) — ALL FIXED in one wave (96517bb..3a655d0), re-review clean. Residual minors: TenantIntegration docblock caller list stale; upsertForSubject TOCTOU (package convention). Tag v2.0.0 → 3a655d0 (local). PROGRAM COMPLETE.
