# 28 — Action Capability Registry and ERP Command Ownership

> **Specification version:** 2.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `6ded3715275e7ad8bd60eb7410428af03e4fc304` (2026-11-10). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Why separate from current read manifest
Current `AiToolManifest` is deliberately read-only. The future write registry **must not** remove that constraint. Create `ActionCapabilityRegistry` with an explicit code-defined allowlist. The LLM may choose an intent to *prepare*; it cannot directly invoke `execute`. Every action has two independently reviewed interfaces: `prepare/preview` and `executeApproved`. No arbitrary service class, method name, HTTP route, SQL or reflection from model-controlled strings.

## Example descriptor (proposed design, not SDK API)
```php
final readonly class ActionDefinition
{
    public function __construct(
        public string $key, // purchase_order.create.v1
        public string $businessModule,
        public string $risk, // controlled_write
        public array $requiredPermissions,
        public string $inputSchemaVersion,
        public string $previewAdapter, // registered class only
        public string $commandAdapter, // registered class only
        public bool $requiresApproval = true,
        public bool $requiresFreshSnapshot = true,
        public bool $enabledByDefault = false,
    ) {}
}
```
Only code review can register an adapter; DB config may **disable** allowed action keys but may not add arbitrary PHP methods. Typed input schemas define quantity/decimal/currency/date/UOM/variant/IDs plus per-field authorization and object ownership. Format errors are safe and actionable, never raw stack traces.

## Action registry inventory: first-wave candidates
| Capability key (proposed) | Domain owner candidate | Stage/risk | Approval gate |
|---|---|---|---|
| `master.customer.create.v1` | Existing customer master application layer (to verify) | Master create | Review, company permission, dedupe |
| `master.product.create.v1` | Product service/API (to verify) | Master create | Review, UOM/tax/catalog validation |
| `purchase_order.prepare.v1` | `CommercialDraftService`/purchase command mapping | Read/prepare | No ERP posting |
| `purchase_order.create.v1` | `PurchaseApplicationService` / `CommercialApplicationService` | Controlled order create | Explicit approve + idempotency |
| `purchase_receipt.record.v1` | `OrderLifecycleService::receivePurchase` and receipt services | Stock effect | Actual receipt verification and approval |
| `purchase_bill.post.v1` | `PurchasePostingService` / purchase command | Financial post | Finance approval, GST and duplication checks |
| `sales_order.create.v1` | `OrderLifecycleService::createSale` | Controlled order create | Credit/stock rules and approval |
| `sales_invoice.post.v1` | `SalePostingService` / sale command | Financial/stock effect | Explicit approval and reconciliation |
| `inventory.transfer.v1` | Existing inventory movement command (verify) | Stock movement | Warehouse permission and double-entry stock identity |
| `accounting.journal.post.v1` | Existing journal posting service (verify) | Restricted financial | Dual control if policy, balanced entries, locks |
| `payment.receipt.allocate.v1` | Accounting allocation service (verify) | Financial settlement | Source invoice, amount/currency and approval |
| `manufacturing.production.plan.v1` | `ProductionService::plan` | Operations | Industry/BOM/material authorization |

**Candidate** does not mean a method can be called as-is. Validate real accepted `kind`, document stage, command DTO, required fields, stock/accounting effects and idempotency before implementation. For unknown master/finance services, explicitly mark to inspect.

## Interface boundaries
`ActionInputNormalizer` (untrusted parsed slots) → `ActionPreviewAdapter` (reads authoritative prices, GST, constraints; returns immutable preview) → `ActionProposalService` (seal state/TTL/version) → `ActionApprovalService` (authenticated approve exact hash) → `ApprovedActionExecutor` (fresh ACL + source-state + idempotency) → `DomainCommandAdapter` (invokes reviewed ERP service) → `ActionReceiptMapper` (returns actual ERP type/id/status). These general components are shared; domain command adapters contain necessary business-specific mapping.

## Impact and effect model
Preview defines `effects`: proposed ERP document, expected stock delta (if any), planned GL/tax effects, external communication (if any), and dependency on prior documents. Effects are **estimates from reviewed ERP services** and not a guarantee until posting. Execution response includes actual receipt and downstream record references reported by the authoritative domain layer.

## Permissions and safety
Each capability has `ai.use` + exact business permission(s), risk tier, company/industry feature, allowed document status, allowed currencies, record scope, approval policy, idempotency semantics and maximum batch size. Do not allow multiple general permissions to imply a sensitive write. Audit denied attempts with redacted inputs and stable error IDs.

## Acceptance for a new capability
A fully typed preview, ambiguous input rejection, golden domain comparison, positive and negative tenancy cases, record version/period checks, hash-bound approval, duplicate/retry protection, receipt lookup, proper ERP reversal path, and feature-flagged manual pilot. A capability is *not ready* simply because its PHP class compiles.
