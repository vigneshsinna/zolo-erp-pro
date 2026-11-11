# 14 — Async Workers, Scheduling, Resumption and Recovery

> **Specification version:** 3.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `14195d8b5b195f65ca1283c96794c613de88eded` (2026-11-11). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## When queues are required
Current short read requests may run synchronously. Action workflow orchestration becomes asynchronous for OCR/documents, multi-step approvals spanning days, scheduled stock checks, reminders and report generation. Use existing Laravel jobs + Redis/Horizon after operations review; don't introduce a second queue platform for simple workflows.

## Proposed queue categories
| Queue | Job | Isolation |
|---|---|---|
| `ai-interactive` | Short read, clarification enrichment, task events | Low concurrency, short timeout |
| `ai-workflow` | Resume reviewed task/after-approval execution | Idempotent, strict worker ACL and retry |
| `ai-documents` | Virus scan/OCR/mapping/batch validation | Separate CPU/memory quotas |
| `ai-scheduled` | Opt-in monitored reads/report preparation | Daily/weekly caps, company scope |
| Existing ERP business queues | Actual commercial/finance dispatch when domain requires | Never starve or bypass their established workers |

## Job contract
Persist `task_id`, action ID/version, actor ID and scope *references*; never serialize authentication session or permission grants. On execution reload task and principal, check kill switches, status/version, due time, approval hash, current source record state, user membership, domain permissions and budget. Acquire idempotent claim, execute only the domain command and persist receipt. Cancel/skip a job if task changed or permission was revoked.

## Retry semantics
- Safe read jobs may be retried with bounded backoff and deduped result.
- Mutation jobs must look up execution receipt **before** retry; do not depend on “exactly once” delivery from queue.
- Provider retries never cause domain write retry without an already approved, sealed command.
- Uncertain external side effect (email/webhook/payment provider) requires service status inquiry before retry.
- Worker crash after transaction commit must be recoverable from ERP receipt and idempotency key.

## Operations
Secure Redis, isolate app prefix/ACL, protect Horizon dashboard with admin policy, configure timeout shorter than retry_after, bounded attempts and dead-letter/manual review. Run under PHP 8.5 supervisor/systemd only after VPS capacity test. Monitor queue delay, stuck tasks, token spend, failed jobs, FPM saturation and customer starvation. ERP transactional queues retain their priority.

## Scheduling autonomy
Only run opt-in tasks that the company explicitly enabled and users understand; a scheduled rule can automatically **read or prepare** items, but financial posting, bank action, external sending and stock corrections remain behind defined approval policy. Audit each execution, maximum frequency, next scheduled time, cancellation and ownership.


---

## V3: bootstrap and lifecycle recovery
A queued onboarding step is never allowed to create the company twice. Claim task/version atomically, store the exact server-issued company code/idempotency key, reconcile the committed `CompanyDirectoryService` return and company setup audit after a crash, then resume **only the incomplete subordinate setup steps**. Do not retry the whole company-create call after a partial response failure without checking durable receipt. Follow-up group/branch/FY/warehouse/unit/product/party imports are individually idempotent and permission checked.

Before any delayed amend/reverse/post job, re-fetch the ERP document state and linked downstream effects. If state differs from approved snapshot, fail `requires_revalidation` with no business mutation, not an automatic resubmission of the previous command.
