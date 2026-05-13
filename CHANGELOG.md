# Changelog

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
