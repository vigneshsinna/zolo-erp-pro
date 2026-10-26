# 01 — Company Creation & Textile Profile Setup

**System:** zoloERP Pro  
**Repository:** `vigneshsinna/zolo-erp-pro`  
**Verified branch:** `ui`  
**Verified commit:** `6aeab06888106127c4dabf058ede975f86e0eeaa`  
**Documentation date:** 2026-10-26  
**Purpose:** Create an Indian company, select the Textile business profile/subtype, understand the automatically created records, and complete the minimum setup before transactions.

> This guide documents behavior supported by the inspected remote `ui` branch. It is an operator/UAT reference, not a claim that every optional customer-specific configuration is enabled. Where a value is configuration-dependent, the guide says so explicitly.

---


## 1. Navigation

Use the post-login company picker / company management screen.

Relevant routes on the verified branch:

- `GET /select-company`
- `POST /company-picker/companies`
- `GET /settings/company`
- `POST /settings/company`
- Financial-year setup: `/company/financial-years/setup`

## 2. Who can create a company?

A company can be created by an installation administrator. For a company inside a company group, the actor must be allowed to manage that group.

## 3. Company creation inputs

| Input | Required | Example | Rule / meaning |
|---|---:|---|---|
| Legal Name | Yes | `Sri Vignesh Textiles Pvt Ltd` | Maximum 255 characters |
| Code | Yes, or auto-generated | `SVT` | Uppercase letters/numbers/`_`/`-`; unique |
| Industry | Yes | `textile` | Must be one of the configured profiles |
| Business subtype | Yes in practice; defaults if blank | `manufacturing` | Must belong to Textile subtype list |
| Timezone | Yes | `Asia/Kolkata` | Must be a valid timezone |
| Financial Year Start | Yes | `2026-04-01` | Date |
| Financial Year End | Yes | `2027-03-31` | Must be after start |
| Trade Name | No | `Vignesh Textiles` | Display/trading name |
| Company Group | No | `Zolo Group` | Optional grouping |
| State Code | No | `33` | Tamil Nadu example |
| Base Currency | No | `INR` | INR default when available |
| Address | Conditional | `12, Textile Street` | Required when GSTIN is supplied |
| City | No | `Erode` | Company profile |
| PIN Code | No | `638001` | Six digits |
| PAN | No | `ABCDE1234F` | Must match GSTIN PAN section if both supplied |
| GSTIN | No | valid GSTIN | GST setup when configured |
| GST Registration Type | No | `regular` | regular/composition/unregistered |
| Logo | No | PNG/JPG/JPEG | Max 1 MB in company profile |

## 4. Textile subtypes

| Subtype | Intended use | Added capabilities |
|---|---|---|
| `wholesale_distributor` | Wholesale + distribution | Route distribution |
| `wholesale` | Wholesale textile trading | No Job Work/manufacturing by default |
| `distributor` | Distribution | Route distribution |
| `manufacturing` | Textile processing/manufacturing | Job Work + BOM + Production |

**Important:** If the company needs Job Work Material DC / Job Work GRN, choose Textile `manufacturing` or explicitly enable Job Work later.

## 5. What the system creates automatically

When a company is created successfully, zoloERP creates/configures:

1. Legal company.
2. `MAIN` branch named **Main branch**.
3. First open financial year.
4. User/company and branch access memberships.
5. Main warehouse, e.g. `Vignesh Textiles - Main`.
6. Chart of Accounts.
7. Selected industry profile/subtype.
8. Starter masters when empty: customer group, product category, biller, Cash/Bank settlement accounts, plus configured starter sale/purchase types, sundries and document series.
9. Address/PAN/GSTIN/logo when supplied.
10. GST defaults when GST/compliance is configured.
11. Company setup audit event.

## 6. Textile defaults

### Quantity and units

- Quantity scale: **3 decimals**
- Units: `MTR`, `KGS`, `DOZ`, `PCS`
- Default UQC: `MTR`
- Warehouse label: **Godown**

### Textile product attributes

- Fabric Type
- Construction
- Width
- GSM
- Design
- Color
- Brand
- Roll / Lot
- Rack / Godown

### Textile document fields

- Transporter
- LR No
- LR Date
- Bale Count
- Bundle Count

### Textile entry aids

- Previous rates
- Outstanding balance
- Clone invoice
- Inline party creation
- Inline item creation
- Tracking

### Textile reports

Base Textile:
- Stock by Lot
- Previous Rates

Textile manufacturing additionally:
- Job Work Pending
- Job Work Shrinkage
- Stock by Lot
- Previous Rates

## 7. Textile process presets

| Code | Process | Default max loss | Conversion? |
|---|---|---:|---:|
| `BLEACHING` | Bleaching | 0% | No |
| `GREY-DYE` | Dyeing - grey to dyed fabric | 5% | Yes |
| `DYEING` | Dyeing | 4% | No |
| `PRINTING` | Printing | 4% | No |
| `FINISHING` | Finishing | 4% | No |

Used process policy is protected from silent policy changes.

## 8. Company profile validation

If GSTIN is entered:
- Registered address is required.
- PAN must match GSTIN.
- State code follows GSTIN.
- GST registration is maintained for the active branch context.

If Registration Type is `unregistered`, GSTIN must be blank.

## 9. Expected outputs after setup

| Check | Expected output |
|---|---|
| Company picker | New company appears |
| Branch | `MAIN / Main branch` |
| Financial Year | Correct FY and status `open` |
| Warehouse | Main Godown/Warehouse exists |
| Currency | Correct base currency |
| Chart of Accounts | Installed |
| Textile units | MTR/KGS/DOZ/PCS |
| Textile attributes | Available according to subtype |
| Job Work | Available only when enabled |
| GST | Registration/default taxes when configured |
| Audit | Company creation/profile changes recorded |

## 10. Example

```text
Legal Name: Vignesh Textile Processors Pvt Ltd
Trade Name: Vignesh Textiles
Code: VTP
Industry: Textile
Subtype: Manufacturing
State: Tamil Nadu (33)
Currency: INR
Timezone: Asia/Kolkata
FY: 01-Apr-2026 to 31-Mar-2027
GST Type: Regular
```

Expected structure:

```text
Company VTP
  ├── MAIN branch
  ├── Open FY 2026-27
  ├── Main Godown
  ├── Chart of Accounts
  ├── Textile profile
  ├── MTR/KGS/DOZ/PCS
  ├── Job Work capability
  └── GST setup when configured
```

## Source map

- `app/Http/Controllers/CompanySelectorController.php`
- `app/Services/Platform/CompanyDirectoryService.php`
- `app/Services/Platform/CompanyProfileService.php`
- `app/Services/Industry/IndustryCatalog.php`
