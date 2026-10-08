# 04 - Business Profile and Capability Engine

> **Repository:** `vigneshsinna/zolo-erp-pro`  
> **Target:** zoloERP Pro / Laravel 10  
> **Status:** Implementation specification  
> **Basis:** reviewed `main` branch + `optech_erp_modernization_project_plan.html`  
> **Date:** 2026-10-03

> **Product rule:** zoloERP is a general ERP. Textile, FMCG, timber, solar and other verticals must be configurable capabilities or industry packs around one shared sales, purchase, inventory, accounting, tax, document, security and API foundation.


## Purpose

Let a business owner choose a familiar profile while keeping every capability individually configurable. Profiles are presets, not code forks.

## Target rules

- Capability availability and user permission are separate checks.
- Capabilities have dependencies and optional configuration.
- Disabling a capability hides new operations but never deletes history.
- Existing `general_settings.modules` remains a compatibility input only during migration.

## Data model / contracts

Core tables:

```text
capabilities
  key, name, group_key, module_provider, is_core, configuration_schema

business_profiles
  key, name, description, is_system

business_profile_capabilities
  profile_id, capability_id, default_enabled, default_config_json

company_capabilities
  company_id, capability_id, enabled, config_json, enabled_at, enabled_by
```

Example keys:

```text
core.sales
core.purchases
core.inventory
core.accounting
core.gst
inventory.multi_uom
inventory.batch_expiry
inventory.serial_tracking
inventory.dimension_tracking
sales.route_distribution
manufacturing.production
operations.job_work
operations.projects
operations.installation
service.repair
service.warranty_amc
printing.dot_matrix
communications.whatsapp
```

## Services and ownership

`CapabilityService` must support:

```text
enabled(key, company)
config(key, default)
enable(key, config)
disable(key)
assertEnabled(key)
dependencies(key)
forNavigation()
```

Cache by company and invalidate on change.

## Implementation sequence

1. Create capability/profile migrations and seeders.
2. Seed General Trading, FMCG, Textile, Timber and Solar profiles.
3. Map legacy module-string values into capabilities.
4. Implement dependency validator.
5. Convert sidebar/menu checks to CapabilityService progressively.
6. Protect routes/services with capability middleware, not only UI hiding.

## Acceptance and verification

- FMCG profile enables batch/expiry and multi-UOM defaults.
- Textile profile enables job work and dot-matrix defaults.
- Solar profile enables project/serial/install defaults.
- Direct URL/API access to disabled capability is rejected.
