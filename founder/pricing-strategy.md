# zoloERP Pro — SaaS Pricing Strategy & Monetization Architecture

> **Document:** `founder/pricing-strategy.md`  
> **Product:** zoloERP Pro (Laravel 13 Multi-Tenant ERP + Governed AI Operating Layer)  
> **Target:** Pre-revenue to $10M ARR SMEs (Trading, Manufacturing, Opticals, Textile, FMCG, Timber, Solar)  
> **Markets:** India Primary (INR / GST) + Global Export / Emerging Markets (USD)

---

## 1. Pricing Model Analysis

| Model | Fit Score (1-5) | Pros | Cons |
|---|:---:|---|---|
| **Flat Subscription** | 2/5 | Predictable for buyers; simple billing. | High value leakage; 3-user traders pay the same as 50-user factories. |
| **Usage-Based (Pure)** | 2/5 | Aligns with AI token and compute costs. | Severe budget volatility for SME CFOs; disincentivizes daily staff usage. |
| **Per-Seat (Pure)** | 3/5 | Industry-standard Western SaaS expansion metric. | Encourages credential sharing in Indian SMEs; blocks shop-floor adoption. |
| **Freemium** | 1/5 | Rapid signups. | Heavy support burden; ERP migration friction kills free-to-paid conversion. |
| **Credits (Prepaid)** | 2/5 | Isolates variable AI compute consumption. | Transactional friction; workflow blocks when credit balance empties. |
| **Hybrid Tiered Base + Add-ons** | **5/5** | **Predictable core MRR; bundles vertical workflows; captures seat/AI expansion.** | Requires in-app entitlement gating and transparent usage counters. |

### Recommended Model: Hybrid Tiered Base + Add-ons
**Why:** ERP systems have high switching costs and daily operational reliance. A tiered base provides predictable MRR with realistic user, branch, and AI quotas. Expansion revenue is captured as clients add sister companies, warehouse seats, or multimodal OCR packs.

---

## 2. Tier Design

```
+-----------------------------------------------------------------------------------------------+
|                                      PRICING TIERS                                            |
+------------------------------+--------------------------------+-------------------------------+
|     STARTER (Retail/Trade)   |    GROWTH (Industry & BOM)     |   ENTERPRISE (AI Autonomous)  |
|   ₹1,599/mo (₹19,188/yr)     |     ₹3,999/mo (₹47,988/yr)     |     ₹9,599/mo (₹115,188/yr)   |
|   [$24/mo / $288/yr]         |     [$59/mo / $708/yr]         |     [$135/mo / $1,620/yr]     |
|   Save 20% on Annual         |     Save 20% on Annual         |     Save 20% on Annual        |
+------------------------------+--------------------------------+-------------------------------+
```

### Tier 1: Starter (Trading & Retail POS)
*Hook:* Affordable cloud replacement for desktop software with complete GST compliance.  
*Prices:* **₹1,999/mo** ($29) | **₹1,599/mo billed annually** ($24/mo = ₹19,188/yr). 20% annual savings.  
*Features & Limits:*
- 1 Legal Company, 2 Branches / Godowns.
- 3 User Seats included (Extra seats: ₹299/seat/mo).
- General Trading & Retail POS profiles.
- Double-entry accounting, GST invoicing, e-Way bills & e-Invoicing.
- Standard inventory with single UOM and barcode printing.
- AI Copilot: Read-only balance & stock queries (50 requests/day, text-only).
- Email support (24h SLA).

*Upgrade Trigger:* Exceeding 3 seats or 2 branches; requiring multi-level BOM, vertical attributes (textile rolls, optical powers, FMCG expiry), or action-executing AI.

### Tier 2: Growth (Specialized Industry & Manufacturing) — *TARGET TIER*
*Justification:* Deep domain engines (Textile, Opticals, FMCG, Timber, Solar) replacing expensive custom ERP setups.  
*Prices:* **₹4,999/mo** ($75) | **₹3,999/mo billed annually** ($59/mo = ₹47,988/yr). 20% annual savings.  
*Features & Limits:*
- 2 Legal Companies, 5 Branches / Warehouses.
- 10 User Seats included (Extra seats: ₹249/seat/mo).
- 1 Specialized Industry Profile unlocked (Textile Job Work, Opticals Rx Lab, FMCG FEFO, Timber CFT, or Solar EPC).
- Multi-level BOM, Production Runs, Subcontracting / Job Work GRN & DC.
- Multi-UOM conversions, batch & expiry tracking, serial numbers, lot tracking.
- Governed AI Operating Layer: Natural language PO/SO drafts, clarification triad, in-chat PDF receipts (100k tokens/day).
- Multimodal AI: 50 invoice/receipt OCR extractions/month included.
- WhatsApp invoice dispatch & priority chat support (4h SLA).

*Upgrade Trigger:* Managing >2 companies, needing >10 seats, exceeding 50 invoice scans/month, or requiring REST API access.

### Tier 3: Enterprise Pro (Multi-Company & Autonomous AI)
*Justification:* Complete corporate consolidation with high-volume multimodal document ingestion and custom workflow integrations.  
*Prices:* **₹11,999/mo** ($169) | **₹9,599/mo billed annually** ($135/mo = ₹115,188/yr). 20% annual savings.  
*Features & Limits:*
- 5 Legal Companies (Holding + Subsidiaries), Unlimited Branches.
- 25 User Seats included (Extra seats: ₹199/seat/mo).
- All 6 Industry Profiles active simultaneously.
- Advanced logistics: Fleet tanker dispatch, driver surrender, geofencing.
- Full Multimodal AI Suite: Bulk vendor bill OCR & speech transcription (500k tokens/day + 300 document scans/month).
- Full REST / Mobile API access with webhooks.
- Custom dot-matrix & stationary layout designer.
- Dedicated Account Manager & 1h critical response SLA.

*Sales Model:* Self-serve with optional white-glove onboarding (₹25,000 one-time).

---

## 3. Competitive Pricing Context

| Competitor & Link | Verified Public Price (March 2026) | Included Scope | Positioning & Strategic Rationale |
|---|---|---|---|
| **TallyPrime** <br>[tallysolutions.com/pricing](https://tallysolutions.com/pricing/) | Silver: ₹750/mo<br>Gold: ₹2,250/mo (+18% GST)<br>Cloud hosting: +₹600/user/mo | Single or LAN desktop accounting. No native mobile, cloud, or AI. | **Priced Above Desktop, Below Cloud+TDL:** Starter replaces Tally + AWS hosting + third-party TDL customizations with modern web access and AI. |
| **Zoho Books & Inventory** <br>[zoho.com/in/books/pricing](https://www.zoho.com/in/books/pricing/) | Premium: ₹2,999/mo (10 users)<br>Elite: ₹4,999/mo (10 users)<br>Ultimate: ₹7,999/mo (15 users) | Generic accounting & light inventory. Basic AI (Zia). | **Priced Parallel to Elite, Dominant on Depth:** Growth at ₹3,999/mo includes manufacturing (BOM/Job Work) and optical/textile verticals that Zoho cannot handle without custom Zoho Creator apps. |
| **Marg ERP 9+** <br>[margcompusoft.com](https://margcompusoft.com/) | Basic: ₹10,300 + ARC<br>Gold: ₹26,000 + ARC<br>Cloud: +₹7,500/user/yr | Windows desktop trade ERP. Rigid interface. | **Modern SaaS Migration Anchor:** Marg users pay ₹15,000–₹25,000/yr for desktop plus cloud hosting. zoloERP offers browser/mobile accessibility with zero desktop maintenance. |
| **Odoo Enterprise** <br>[odoo.com/pricing](https://www.odoo.com/pricing) | Standard: ₹580/user/mo<br>Custom: ₹1,080/user/mo (annual) | Broad modular ERP. 10 users on Custom = ₹10,800/mo + partner implementation fees. | **Priced at 60% Discount to Odoo:** zoloERP Growth bundles 10 users at ₹3,999/mo with Indian GST and industry presets working out of the box, avoiding ₹1,00,000+ implementation costs. |

---

## 4. Unit Economics Check

### Cost Arithmetic (Per Active Tenant Per Month)
1. **Server Compute & Database:**
   - 1 Cloud VPS (8 vCPU, 32GB RAM @ ₹5,000/mo) supports 50 active tenants running PHP 8.5-FPM + MySQL 8.4 + Redis.
   - Cost per tenant = **₹100/mo** ($1.25).
2. **Storage & Backups:**
   - Private media disk + S3 document storage (PDFs, images @ ₹1.2/GB) = **₹30/mo** ($0.37).
3. **AI Inference & Multimodal Perception:**
   - Text Copilot (Nemotron-3.5 / gpt-4o-mini @ ₹16/1M tokens): 50 daily requests (~1.5M tokens/mo) = **₹24/mo**.
   - Multimodal OCR (Nemotron-3-Nano-Omni @ ₹0.16/page): Starter (0 scans) = ₹0; Growth (50 scans) = **₹8/mo**; Enterprise (300 scans) = **₹48/mo**.
4. **Customer Support & Maintenance:**
   - Blended support cost per tenant: Starter = ₹150; Growth = ₹350; Enterprise = ₹750.

### Margin & Break-Even Matrix

| Metric | Starter (₹1,599/mo) | Growth (₹3,999/mo) | Enterprise (₹9,599/mo) |
|---|:---:|:---:|:---:|
| **Monthly Revenue** | ₹1,599 | ₹3,999 | ₹9,599 |
| **Compute & Storage** | ₹130 | ₹170 | ₹370 |
| **AI Inference & OCR** | ₹15 | ₹50 | ₹150 |
| **Support Allocation** | ₹150 | ₹350 | ₹750 |
| **Total Monthly COGS** | **₹295** | **₹570** | **₹1,270** |
| **Gross Margin (%)** | **81.5%** | **85.7%** | **86.8%** |
| **Gross Profit / Month** | **₹1,304** | **₹3,429** | **₹8,329** |

- **Fixed Overhead:** ₹1,50,000/month ($1,800) for base engineering and administrative software.
- **Customers Needed to Break Even:** Starter: **115**, Growth: **44**, or Enterprise: **18**.
- **Target Blended ARPU:**
  - Assuming 30% Starter / 55% Growth / 15% Enterprise split:  
    `ARPU = (0.30 × ₹1,599) + (0.55 × ₹3,999) + (0.15 × ₹9,599) =` **₹4,119/month (~$50/mo)**.

---

## 5. Pricing Psychology

1. **Anchoring:** Position Enterprise (₹9,599/mo) with "Autonomous Multi-Company AI" badges. This anchors enterprise value above ₹10,000/mo, making Growth at ₹3,999/mo feel like an exceptional mid-market bargain.
2. **Decoy (Starter Gating):** Starter is strictly gated to 3 seats, 1 company, and zero manufacturing BOM/job work. Any SME with factory operations, field distributors, or Rx optical labs immediately bypasses Starter to choose Growth.
3. **Annual Framing ("2 Months Free"):** Frame annual discount as **"Pay for 10 Months, Get 12 Months Access"** with explicit savings (*"Save ₹12,000/year"*). In SME cash-flow management, tangible free months convert 40% higher than abstract percentages.

---

## 6. Launch Pricing vs. Scale Pricing

### Launch Strategy (First 90 Days)
- **Founding Partner Program:** Cap at first 50 onboarding companies.
- **Offer:** Growth Plan at **₹2,999/mo** (billed annually at ₹29,990/year, saving 40% off list) with free chart of accounts and master data migration.
- **Why:** Early adoption in manufacturing clusters (Surat textiles, optical lab chains) requires reference clients to break Tally/Marg inertia.

### Grandfathering Policy
- Early adopters receive a **Permanent Base Subscription Price Lock** for the life of their active subscription.
- Add-ons (extra seats, AI OCR packs) are billed at prevailing rates. If a subscription lapses >30 days, renewal reverts to standard pricing.

### Price Increase Protocol
- **When:** Implement price increases only upon two milestones:
  1. Release of autonomous Phase 4+ features (direct e-Way bill API dispatch, automated bank feeds, multi-agent scheduling).
  2. Platform reaches 100 paying tenants with monthly churn <1.5% and NPS >50.
- **How:** Provide 45 days advance notice. Offer active tenants the option to lock in 1 or 2 years at legacy rates before the increase, generating an upfront cash surge.
