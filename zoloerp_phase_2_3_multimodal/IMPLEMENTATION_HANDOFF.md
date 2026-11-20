# Implementation handoff — zoloERP Phase 2.3

## Goal

Implement multimodal ingestion without disturbing the existing ERP Copilot. Existing Nemotron 3.5 Lightning remains the main task/reasoning model. New Nemotron 3 Nano Omni Reasoning handles **image bill extraction and voice-to-text only**. All output enters a validated normalized-input layer and the existing governed Action Foundation.

## Required implementation order

1. **Audit actual current code/branch and API entitlement**. Verify whether Phase 2.1 clarification and Phase 2.2 dependent-master workflows have since landed. Inspect actual Laravel 13 routes/migrations rather than assuming names.
2. **2.3A** — Add media config, private upload/validation, purpose-scoped Omni client, telemetry and fake-provider tests. Feature remains disabled.
3. **2.3B** — Add image/PDF extraction, strict schema and source preview/corrections; still no ERP posting.
4. **2.3C** — Add microphone upload and WebM→WAV transcode, English transcription and editable text.
5. **2.3D** — Integrate NormalizedInput with existing Phase 2.1 task API and master-creation subworkflows. No duplicate approval engine.
6. **2.3E** — Run functional, model-quality, privacy, concurrency and commercial-regression gates on disposable MySQL; pilot in one company.

## Mandatory source documents

Read all Markdown files in `docs/` in numeric order. Use `10_REPOSITORY_IMPLEMENTATION_PLAN.md` for `[Create]`/`[Modify]` paths; `11_TEST_MATRIX_AND_RELEASE_GATES.md` for tests; and `13_SOURCES_AND_OPEN_DECISIONS.md` for unresolved provider/document-policy choices.

## Constraints

- Neither Omni nor attachments can call ERP write tools, raw SQL or arbitrary URLs.
- Never trust model-calculated GST, inventory or amount totals.
- No new unscoped company/branch/FY queries.
- New media persistence is additive and private; current text chat works without Omni.
- Human approval remains required for each master creation and each business document separately.
- Live financial actions and preview snapshots remain controlled by authoritative ERP domain services.
- Keep all new features disabled by default until tests and signoff pass.

## Outputs requested from implementation agent

Implementation branch + PR, code diff, migrations, payload samples, secure configuration example, test report with passing/failing results, provider compatibility smoke-test evidence, security/retention report, screenshots of UI, rollback plan, and list of unsupported formats/languages.
