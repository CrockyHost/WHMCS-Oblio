# Changelog

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
