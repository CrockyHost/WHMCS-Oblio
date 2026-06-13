# Changelog

## 1.5.2 - Correct replacement-invoice email when the original was partially paid (2026-06-13)

- When a late-fee amendment runs on an invoice that already had a partial payment, the replacement's "Invoice Created" email is now deferred until after the payment has been moved across, so the customer sees the real remaining balance instead of the full amount. Fully-unpaid invoices (the common case) are unchanged - their email still goes out at creation.
- Reordered the amendment so the replacement is synced to Oblio before payments are moved onto it, and the original is cancelled last. This guarantees the moved payments have a fiscal invoice to attach their Incasari to, and that nothing is detached before it has been copied across.
- `createReplacementInvoice()` gained a `$sendEmail` parameter to support the deferred-email path.

To be explicit about the question this answers: yes, payments on a partially-paid invoice are carried over to the replacement (moved via `UpdateTransaction` and re-recorded as Incasari against the new Oblio invoice), and the customer is emailed the new invoice rather than the stale modified original.

## 1.5.1 - Manual "Storno + Reissue" admin button (2026-06-13)

- Added a **Storno + Reissue (Late Fee)** panel to the addon's admin page. Enter an invoice ID to manually run the same storno-and-reissue flow the `AddInvoiceLateFee` cron hook uses: storno the original in Oblio, reissue it (current items plus any late fee) as a new fiscal invoice, and cancel the original in WHMCS. Useful for backfilling when the cron hook did not fire, or for reissuing an invoice you edited. Confirmation prompt before it runs.
- The manual action works regardless of the "Storno + Reissue on Late Fee" setting (that flag only gates the automatic hook and the email suppression).
- `oblio_handle_late_fee_amendment()` now returns a result array and refuses up front if "Enable Oblio Storno on Cancel/Refund" is off, so the amendment never cancels-and-reissues without actually reversing the original in Oblio. This precondition applies to both the automatic hook and the manual button.

## 1.5.0 - Storno and reissue when WHMCS adds a late fee (2026-06-13)

Romanian fiscal invoices cannot be edited once issued (and especially once submitted to SPV/e-Factura), but WHMCS's automated late-fee routine edits overdue invoices in place. This release adds an opt-in workaround.

### Added

- **`Storno + Reissue on Late Fee` setting (default OFF).** When enabled, the new `AddInvoiceLateFee` hook reacts to WHMCS adding a late fee to an Oblio-synced invoice by:
  1. Reissuing the whole invoice (original line items plus the new late fee) as a fresh WHMCS invoice, emailed to the customer and auto-synced to Oblio as a new fiscal document.
  2. Cancelling the original WHMCS invoice, which storno's it in Oblio (and optionally mirrors the storno in WHMCS, per the existing setting).
  3. Reattaching any payments from the original to the replacement and re-recording them as Incasari against the new Oblio invoice (covers the partially-paid-then-overdue edge case).
- **`EmailPreSend` suppression** for the now-defunct original invoice, active only when the setting is on:
  - "Invoice Modified" emails are never sent for an Oblio-synced invoice (an issued fiscal invoice is reissued, not modified). This rule is order-independent so it works even if WHMCS queues the email before the late-fee hook fires.
  - Overdue notices and payment reminders are suppressed for an invoice stornoed and replaced within the last 10 minutes.

### Notes

- The replacement invoice's due date is set to 7 days out so it is not itself immediately overdue (which would re-trigger the late-fee cron). If it does go overdue later, it is amended again the same way.
- The new invoice reaches SPV through your existing Oblio submission flow (the addon submits to SPV on payment when `Auto-send e-Factura to SPV` is enabled, or Oblio can auto-submit on issuance if configured in your Oblio account).
- Reuses the `transaction_id` column added in 1.4.0 to store the old to new invoice link (`oblio_type = 'amended'`); no schema change.

## 1.4.0 - Per-transaction Incasare tracking, storno transaction cleanup (2026-05-13)

Schema change: adds nullable `transaction_id` column to `mod_oblio_invoices`. Existing installs auto-migrate via `oblio_upgrade()`. Pre-existing `collect` rows stay at NULL and are treated as legacy whole-invoice records.

### Fixed

- **Partial payments now sync to Oblio.** `AddTransaction` no longer requires the invoice to be in `Paid` status before sending an Incasare. Every positive `tblaccounts` row produces one Oblio Incasare with that row's actual amount, gateway, and date.
- **Subsequent payments after a manual sync now sync automatically.** Collect state was previously keyed per-invoice (`isSynced($invoiceId, 'collect')`), so once any Incasare was recorded the addon refused to send more. Collect state is now keyed per-transaction (`isTransactionSynced($invoiceId, $transactionId)`), so each WHMCS transaction is tracked independently.
- **Stornoed invoices now release their WHMCS transactions.** After a successful Oblio storno, the addon detaches all attached transactions (`tblaccounts.invoiceid = 0`) and clears the addon's `collect` rows for that invoice. The transactions survive as free-floating credits the admin can reattach to a replacement invoice and re-collect via Manual Sync.

### Changed

- **SPV submission moved out of `oblio_collect_document` into the `InvoicePaid` hook.** Partial-payment Incasari no longer fire SPV repeatedly; SPV runs once when the invoice's status flips to `Paid`.
- **Manual Sync (collect) now sends all unsynced Incasari at once.** Previously sent only one Incasare derived from the most recent transaction; now iterates every positive transaction on the invoice and sends one Incasare each for those not already collected.
- **`InvoiceUnpaid` hook removed.** Per-transaction tracking made it obsolete: a deleted transaction's stale collect row no longer blocks re-collection because re-added transactions get a fresh `tblaccounts.id`.

### Notes for re-attaching detached transactions

WHMCS does not fire `AddTransaction` or `InvoicePaid` when an admin edits an existing transaction's `invoiceid`. After reattaching a detached transaction to a replacement invoice, click **Sync collect** in the addon's Manual Sync panel to send the Incasare to Oblio.

## 1.3.2 - Auto-recover from Oblio duplicate-product-name 400 (2026-05-13)

- When Oblio rejects invoice creation with "are cu aceeasi Denumire si Tip exista deja in baza de date. Oblio nu permite Denumiri duplicate", the addon now retries the same payload once with ` #INV-{whmcs_invoice_id}` appended to every line item name to force uniqueness. Clean descriptions stay clean for invoices that don't collide; the suffix only appears on the Oblio side when the collision actually occurred. Oblio's API does not expose a DELETE on `/api/nomenclature/products`, so this retry-with-suffix is the only path that doesn't require touching the Oblio web UI.
- The retry is logged to the WHMCS activity log so suffixed invoices can be located after the fact.

## 1.3.1 - Default VAT bumped to Romania's 21% (2026-05-11)

- Changed default `Default VAT %` setting from 19 to 21 to match Romania's current standard VAT rate. Existing installations are unaffected (the saved per-install value is preserved); only fresh activations and the unset fallback see the new default.
- Updated README example accordingly.

## 1.3.0 - Initial public release (2026-05-11)

First open-source release. The addon was developed and battle-tested in production at CrockyHost before being extracted and published.

### Features

- Auto-sync invoices to Oblio on creation or first payment
- Auto-record payments (Incasare) with WHMCS gateway -> Oblio collect-type mapping
- Storno (total reversal) creation in Oblio on cancellation or refund
- Optional WHMCS-side mirror storno invoice with the same Oblio fiscal number
- WHMCS Sequential Paid Invoice Number counter kept ahead of Oblio's assigned numbers
- Tax-exempt client detection (respects `tblclients.taxexempt`)
- Per-item tax handling (respects `tblinvoiceitems.taxed`)
- Configurable VAT name for 0% items (default `Scutita`; supports `SFDD`, `SDD`, etc.)
- WHMCS invoice notes propagated to Oblio's `mentions` field
- Optional automatic e-Factura SPV submission on payment
- Manual sync trigger from the admin panel (Invoice / Incasare / Storno)
- Sync log table with status and error tracking
- Romanian phone-number prefix normalization for ~200 countries
- Catches both `InvoiceCreated` (automated billing) and `InvoiceCreationPreEmail` (admin manual publish) so admin-created invoices sync reliably
- `AddTransaction` safety net for re-added payments after a deletion
- CSRF-protected admin actions
- Supports non-VAT-registered issuers: set `Default VAT %` to `0` and all items are sent at the configured Zero-Rate VAT Name
