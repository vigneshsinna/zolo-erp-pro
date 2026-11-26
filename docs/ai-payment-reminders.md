# Approved outbound payment reminders

`payment_reminder.prepare.v1` creates an internal collection plan and never sends a message. The separate `payment_reminder.send.v1` capability prepares an email preview for explicit human approval. It requires `ai.use`, `accounting.voucher.post`, and `documents-dispatch`; approval also requires `ai.action.approve`.

The send preview uses the current company date, an active customer master email, the authoritative invoice balance, and an active company-owned email channel. The recipient, subject, message, balance, and source-state hash are sealed with the proposal. A changed recipient, allocation, fiscal state, company sender, permission, membership, approval version, or expired proposal requires a new review. The worker revalidates the initiating actor, executor, and approver before delivery.

Delivery is disabled by default. Enable `AI_PAYMENT_REMINDERS_ENABLED=true` only after configuring the existing company email channel and application mail transport. The AI action foundation and execution switches must also be enabled. Use an asynchronous connection through `AI_COMMUNICATION_QUEUE_CONNECTION` (default `redis`) and run a worker for `ai-communications` with one attempt and a timeout below the connection's `retry_after`:

```sh
php artisan queue:work redis --queue=ai-communications --tries=1 --timeout=30
```

The encrypted `ai_reminder_outbox` is committed before a delivery job is queued. The execution receipt says `queued`; it does not claim that the email was sent. `php artisan ai:dispatch-reminders --limit=100` recovers untouched queued entries if dispatching the job was unavailable. Each job references only the outbox ID and company ID; it does not serialize credentials or permission grants.

Only an untouched `queued` row can be claimed. Successful transport acknowledgement records `sent`; a transport that suppresses delivery without acknowledgement records `not_sent`. Transport exceptions or a worker stopping during delivery record `delivery_unknown`; these outcomes are never automatically retried because SMTP may already have accepted the message. Reconcile an uncertain outcome with the provider before preparing another reviewed reminder. Revoked access, stale invoice information, and expired approvals record `requires_revalidation` without sending. Company daily creation caps default to 100 and are configurable with `AI_REMINDER_DAILY_COMPANY_LIMIT`.

Automated checks use a disposable database, a fake job bus, and a mocked mail transport. No customer message or paid model request is sent during verification.
