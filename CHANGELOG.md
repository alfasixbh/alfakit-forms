# Changelog

All notable changes to `alfasix/alfakit-forms` are documented in this file.

## Unreleased

- Initial release: `FormsPlugin` with a Forms resource (visual builder with 17 block types, answer keys, per-field validation from a safe whitelist, conditional logic evaluated in PHP, multi-step forms from page breaks, publishing dates, submission limits, confirmation message or redirect, email recipients, honeypot and captcha switches), a submissions relation manager (answers, view modal with signed file downloads, spam flags, delete, CSV / XLSX export), `FormStatsWidget`, the public `/forms/{slug}` route and `AlfakitForms::embed()` rendering forms as standalone components that re-validate every submission against the stored definition, spam detection (honeypot, time check, rate limiting, `AlfakitForms::captcha()`), the queued `SubmissionReceived` mailable, the `FormSubmitted` event, polymorphic tenant scoping and the `alfakit-forms:page` generator.
