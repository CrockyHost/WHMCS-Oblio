# Changelog

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
