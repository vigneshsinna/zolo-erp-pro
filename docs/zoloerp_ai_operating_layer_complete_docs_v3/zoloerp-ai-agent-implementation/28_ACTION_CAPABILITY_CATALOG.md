# 28 — Action Capability Registry and ERP Command Ownership

> **Specification version:** 3.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `14195d8b5b195f65ca1283c96794c613de88eded` (2026-11-11). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Separation of Registries: Read, Setup, and Action
The existing `AiToolManifest` is strictly read-only. Action execution must not compromise this boundary. The system establishes two distinct write registries:
1. **`SetupCapabilityRegistry` (Mode A — Pre-Company Bootstrap):** Allowlisted capabilities accessible exclusively to verified installation administrators via `BootstrapSetupPrincipal`. Handles company group creation and atomic initial company creation.
2. **`ActionCapabilityRegistry` (Mode B — Company-Scoped Operations):** Allowlisted operational capabilities executed within an active `CompanyContext`.

---

## Action Definition Descriptor

Every executable action capability is explicitly registered in code via a typed descriptor:

```php
final readonly class ActionDefinition
{
    public function __construct(
        public string $key,                        // e.g. 'purchase_order.create.v1'
        public string $businessModule,             // e.g. 'commercial', 'platform', 'accounting'
        public string $riskTier,                   // 'prepare', 'controlled_write', 'financial_post', 'restricted'
        public array $requiredPermissions,         // Spatie permission strings
        public string $inputSchemaClass,           // Validates line items, quantities, UOM, decimals
        public string $previewAdapterClass,        // Calls domain service for deterministic preview
        public string $commandAdapterClass,        // Invokes authoritative ERP domain service
        public bool $domainOwnsTransaction = true, // Preserves ERP service's existing DB::transaction()
        public bool $requiresApproval = true,      // Requires explicit human sign-off
        public bool $requiresFreshSnapshot = true, // Re-verifies source row versions before commit
        public bool $enabledByDefault = false,     // Disabled until explicitly activated
    ) {}
}
```

### Transaction Ownership Guarantee
When `$domainOwnsTransaction` is `true`, the action executor delegates database transaction management directly to the underlying ERP domain service (e.g., `CompanyDirectoryService::createCompany` or `CommercialApplicationService`). The executor **must not** wrap the call in a redundant or long-lived outer transaction, preventing deadlocks and connection starvation.

---

## Mode A: Setup Capability Registry (Pre-Company Bootstrap)

Accessible exclusively via `/api/v1/ai/setup/bootstrap/*` under `BootstrapSetupPrincipal`:

| Capability Key | Domain Owner Service | Operation Scope | Approval & Invariant |
|---|---|---|---|
| `setup.group.create.v1` | `CompanyDirectoryService::createGroup` | Installation-level directory container | Installation admin sign-off; unique code check |
| `setup.company.create.v1` | `CompanyDirectoryService::createCompany` | Provisions company, MAIN branch, first FY, main warehouse, COA, industry defaults, starter masters | Installation admin sign-off; single atomic database transaction |

---

## Mode B: Action Capability Registry (Company-Scoped Operations)

Executed within verified `CompanyContext` after initial company provisioning:

| Capability Key | Domain Owner Service | Risk Tier | Approval & Invariant |
|---|---|---|---|
| `setup.branch.create.v1` | `CompanySetupService::branch` | Controlled write | Company admin sign-off; unique branch code |
| `setup.warehouse.create.v1` | `CompanySetupService::location` | Controlled write | Company admin sign-off; branch assignment |
| `master.product.create.v1` | `ProductMasterService::create` | Controlled write | Product admin review; UOM/tax validation |
| `master.party.create.v1` | `CustomerController` / `SupplierController` (Scoped) | Controlled write | Scoped party validation; GSTIN/PAN dedupe |
| `purchase_order.prepare.v1` | `CommercialApplicationService` | Prepare (A1) | Transient draft calculation; zero ERP database write |
| `purchase_order.create.v1` | `CommercialApplicationService` | Controlled write (A3) | Explicit hash-bound approval; live revalidation; idempotency key |
| `purchase_receipt.record.v1` | `OrderLifecycleService::receivePurchase` | Controlled write (A3) | Physical stock effect; warehouse authorization |
| `purchase_bill.post.v1` | `PurchasePostingService` | Financial post (A3) | AP ledger booking; tax reconciliation; dual check |
| `sales_order.create.v1` | `OrderLifecycleService::createSale` | Controlled write (A3) | Customer credit check; stock allocation |
| `sales_invoice.post.v1` | `SalePostingService` | Financial post (A3) | AR ledger booking; statutory invoice numbering |
| `inventory.transfer.v1` | Inventory Movement Service | Controlled write (A3) | Dual-warehouse authorization; double-entry stock |
| `accounting.journal.post.v1` | Journal Entry Service | Restricted (A3) | Balanced debit/credit; period lock check; finance approval |

---

## Execution Interface Pipeline

The action pipeline strictly decouples untrusted LLM generation from authoritative execution:

```text
ActionInputNormalizer (untrusted model slots)
  → ActionPreviewAdapter (calls domain service; computes taxes/totals/effects)
  → ActionProposalService (seals canonical SHA-256 hash, action version, TTL)
  → Human Review (AI Workspace preview / Action Review Center pending queue)
  → ActionApprovalService (validates session, permissions; creates HMAC approval signature)
  → ApprovedActionExecutor (brief row lock, fresh revalidation, live lifecycle check)
  → DomainCommandAdapter (calls authoritative ERP domain service)
  → ActionReceiptMapper (returns actual committed ERP record ID and audit reference)
```

---

## Lifecycle Enforcement on Existing Records
Every action targeting an existing record must declare a `DomainLifecyclePolicy`. Direct `UPDATE` queries on submitted, posted, paid, or closed records are rejected. The model must route modifications to supported domain workflows (e.g., Credit Note, Debit Note, Purchase Return, or Reversal Journal).
