# 05 — Audio, Voice Transcription, and Language Routing

## Objective

A user can speak an ERP instruction and receive a **verbatim, editable transcript** before the existing reasoning model processes it. Speech-to-text is input processing, not a command authorization. No purchase, invoice, stock update or journal can be posted by saying “yes” without separate authenticated approval in the ERP UI.

## Audio flow

```text
Microphone permission -> MediaRecorder in browser -> upload audio/webm
-> server MIME, signature, duration and security validation -> private storage
-> transcode to WAV mono / 16kHz if needed -> Omni transcription
-> transcript + numbers/names warnings -> user confirmation/correction
-> NormalizedInput(kind=audio_transcript) -> Phase 2.1 task engine
-> all-slot clarification -> proposal -> approval -> domain command
```

The client must visibly indicate when recording is active. `Stop`, `Discard`, `Replay`, and `Use transcript` are separate controls. Never automatically keep microphone recording active or send media without user action. If the user blocks microphone permission, offer typed text.

## Supported format strategy

- Browser may use WebM/Opus. Use `MediaRecorder.isTypeSupported()` and prefer the best supported recording format; do not rely on WebM acceptance at the NVIDIA endpoint.
- Convert to WAV/PCM in a bounded server worker before submission to Omni. Validate frame headers, sample rate, duration, channel count and content, not only extension.
- NVIDIA's NIM examples list `audio_url` with `audio/wav` as supported. This can be supplied as server-generated `data:audio/wav;base64,...`.
- Short recordings (default <=120 seconds) minimize cost and error accumulation. Split recordings longer than policy only in a later reviewed phase.

## Transcription rules

1. Preserve spoken product codes, serial numbers, GSTIN, invoice numbers, dates, negations and units. Do not paraphrase business-critical amounts.
2. Explicitly distinguish “fifteen” from “fifty” when uncertain and flag the transcript segment for human correction.
3. Keep all dictated line items and change instructions, including phrases like “not ten, make it twelve.” Mark the final user-intended correction as pending confirmation, not an unverified calculator result.
4. Unknown or noisy segments must be `[unintelligible]` or structured `null`, not invented supplier names.
5. The transcription model cannot make final decisions about the target ERP command or approve a proposal.
6. Time stamps are optional only if the provider returns trustworthy segment timing. Do not fabricate millisecond word alignments.
7. User-edited transcript version is immutable on handoff and linked to the original audio hash and actor.

## Language support and fallback

The **Nemotron 3 Nano Omni model card currently identifies English-only language support**. For Tamil, Hindi, code-switched Tamil-English, and regional commercial terms, treat Omni as **unproven**, not guaranteed multilingual. A language check can be done locally/on metadata or as a non-authoritative indicator. Present a clear warning when audio may be unsupported.

Add a `MultilingualSpeechProviderInterface` only as a **future optional extension**, selected by a server-side approved model routing policy. A fallback provider must meet data-residency, privacy, price, reliability and permission review before sending real customer financial voice notes. Benchmark Tamil and Tamil-English with common terms (kilo, dozen, GST, supplier names, district names, currency and Indian numbering). If no approved fallback exists, ask the user to type/correct the transcript instead of guessing.

## Voice example

Spoken audio: “Create a purchase order for fifty metres of blue cotton from ABC Traders at three hundred rupees per metre.”

Example response (not model output):

```json
{
  "schema_version": "media.transcript.v1",
  "language": "en",
  "transcript": "Create a purchase order for 50 metres of blue cotton from ABC Traders at 300 rupees per metre.",
  "segments": [
    {"text": "50 metres", "start_ms": null, "end_ms": null, "requires_confirmation": false},
    {"text": "ABC Traders", "start_ms": null, "end_ms": null, "requires_confirmation": true}
  ],
  "user_confirmed": false,
  "warnings": ["Supplier identity must be resolved against ERP records"]
}
```

Following confirmation, the Copilot must still resolve supplier, product, unit and warehouse and collect any missing information. If “ABC Traders” is absent, offer existing / correct / create dependent master with its own approval. Do not use voice alone to choose a substitute supplier.

## UI requirements

- Microphone button with visible consent and recording duration.
- Waveform/playback optional, but accessible stop/cancel required.
- Transcript card (editable textarea) with uncertain terms highlighted.
- `Confirm & Continue` must be a human interaction, not a tool call.
- User can enter additional typed context (“actually quantity 60”) without rerecording; task revision logic merges sources safely.
- Report limited/unsupported languages transparently; do not use the term “verified transcription” before review.

## Test fixtures

Quiet English; noisy warehouse; rapid numbers; 15 vs 50; Indian English accent; fake company name; 3-line item order; silence; 0-second audio; long audio cap; WebM conversion; missing microphone permission; user transcript edit; concurrent transcript confirmation; Tamil fallback unavailable; unsupported language rejected gracefully; mixed user text + voice conflict; no unauthorised ERP mutation.
