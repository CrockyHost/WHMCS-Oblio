# WHMCS Oblio Integration

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
![WHMCS 8.x](https://img.shields.io/badge/WHMCS-8.x-orange)
![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777BB4)

A free and open-source WHMCS addon that integrates WHMCS billing with the [Oblio.eu](https://www.oblio.eu) accounting platform. Auto-creates Romanian fiscal invoices, records payments (Incasare), handles cancellations and refunds via storno documents, and supports e-Factura (SPV) submission.

Built as a drop-in alternative to the commercial WHMCS-to-Oblio addons that charge hundreds of euros per year and ship as ionCube-encoded code you can't audit or modify.

## Why this exists

The commercial WHMCS modules for Romanian invoicing (Oblio, SmartBill, FGO, e-Factura) typically cost EUR 200-1000+ per year per installation and ship encoded. This addon does the same job in plain PHP, with no subscription, no license server, no encoded files.

Released under GPL v3: free forever, must remain readable source, modifications must stay GPL. Any hosting company can use it commercially as part of their service offering. Reselling the addon itself as a closed-source product is not permitted.

## Features

- Auto-sync invoices to Oblio when created in WHMCS (both automated billing and admin-created)
- Auto-record payments (Incasare) on the Oblio invoice when WHMCS marks paid
- Auto-create storno (total reversal) invoices in Oblio on cancellation or refund
- Optional WHMCS-side mirror storno invoice carrying the same Oblio fiscal number
- Tax-exempt client detection (respects `tblclients.taxexempt`)
- Per-line-item tax handling (respects `tblinvoiceitems.taxed`)
- Configurable VAT-rate name for 0% items (`Scutita`, `SFDD`, `SDD`, etc.)
- Issuer-side non-VAT-registered mode (set `Default VAT %` to 0)
- WHMCS invoice notes propagated to Oblio's `mentions` field
- Configurable payment gateway -> Oblio collect type mapping (admin UI)
- Optional automatic e-Factura SPV submission after payment
- Manual sync trigger from the admin panel
- Sync log table with status and error tracking
- WHMCS invoice-number counter (Tax-Compliant Invoicing or Sequential Paid Invoice Numbering, whichever is enabled) kept ahead of Oblio's assigned numbers so storno fiscal numbers never collide
- Romanian phone-number prefix normalization (~200 country codes)

## Requirements

- WHMCS 8.x (tested on 8.13)
- PHP 7.4 or higher
- An Oblio.eu account with API access enabled
- cURL extension
- A VAT rate configured in Oblio matching your `Default VAT %` setting (or a 0% rate if you're not VAT-registered)
- A 0% VAT rate configured in Oblio (typically `Scutita`, `SFDD`, or `SDD`) if you have tax-exempt clients or aren't VAT-registered yourself

## Installation

1. Clone or download this repo:

   ```bash
   git clone https://github.com/CrockyHost/WHMCS-Oblio.git
   ```

2. Copy the addon folder into your WHMCS installation:

   ```bash
   cp -r WHMCS-Oblio/modules/addons/oblio /path/to/whmcs/modules/addons/
   ```

3. In WHMCS admin: **Setup -> Addon Modules -> Oblio Integration -> Activate**.

   Activation creates two tables:
   - `mod_oblio_invoices` - sync log
   - `mod_oblio_gateway_map` - payment-gateway to collect-type overrides

4. Click **Configure** and fill in:
   - API Email and Secret (from Oblio: Settings -> API)
   - Company CIF (your VAT number in Oblio)
   - Invoice Series name
   - CUI/CIF custom client field (which WHMCS custom field stores the client's tax number)
   - Default VAT % (e.g., 19 for Romania, or 0 if you are not VAT-registered)
   - Zero-Rate VAT Name (must match one of your 0% rates in Oblio - default `Scutita`)
   - Toggle the sync features you want enabled

5. Click **Access Control** and grant access to the relevant admin roles.

## Configuration reference

| Setting | Purpose |
|---|---|
| API Email | Oblio account email (used as OAuth `client_id`) |
| API Secret | OAuth `client_secret` |
| Company CIF | Your fiscal code as registered in Oblio |
| Invoice Series | Series name for Oblio invoices (e.g., `CRK`) |
| CUI/CIF Client Field | WHMCS custom client field holding the client's VAT number |
| Document Language | RO / EN / FR / DE |
| Enable Invoice Sync | Auto-create invoice in Oblio when invoice is generated in WHMCS |
| Enable Incasare on Payment | Record payment in Oblio when WHMCS marks paid |
| Enable Oblio Storno | Create storno (total reversal) on cancellation or refund |
| Create WHMCS Storno Invoice | Create a mirror storno invoice inside WHMCS too |
| Default Payment Method | Fallback Oblio collect type when gateway not mapped |
| Default VAT % | VAT rate applied to taxed line items. Set to `0` if you are not VAT-registered. |
| Zero-Rate VAT Name | Oblio VAT-rate name used for any 0% item (must exist in Oblio settings) |
| Auto-send e-Factura to SPV | Submit to ANAF e-Factura after payment is recorded |

## How it works

The addon hooks into WHMCS's invoice lifecycle events:

| WHMCS event | Addon action |
|---|---|
| `InvoiceCreated` (auto invoicing) | Send invoice to Oblio |
| `InvoiceCreationPreEmail` (admin manual publish) | Send invoice to Oblio |
| `InvoicePaid` | Add Incasare to existing Oblio invoice; optionally send e-Factura SPV |
| `AddTransaction` (re-added payments) | Re-trigger Incasare if a previous one exists |
| `InvoiceUnpaid` (payment deleted) | Clear local Incasare state so re-add re-fires |
| `InvoiceCancelled` | Create storno in Oblio; optionally mirror inside WHMCS |
| `InvoiceRefunded` | Create storno in Oblio; optionally mirror inside WHMCS |

When the WHMCS-side mirror storno is enabled, the addon also:
- Sets the WHMCS storno's `invoicenum` to the Oblio storno number (e.g., `CRK-0026`)
- Advances whichever WHMCS invoice-number counter you have enabled so the next auto-issued invoice can't collide with the storno's fiscal number:
  - **Tax-Compliant Invoicing** (`TaxCustomInvoiceNumbering = on`) - bumps `TaxNextCustomInvoiceNumber` to `oblio_number + 1`, preserving zero-padding
  - **Sequential Paid Invoice Numbering** (`SequentialInvoiceNumbering = on`) - bumps `SequentialInvoiceNumberValue` so the next-issued number is greater than `oblio_number`
  - **Neither enabled** - silently no-op. The storno still gets its Oblio-derived `invoicenum` set for display; WHMCS isn't auto-assigning fiscal numbers to other invoices anyway, so no collision can happen.

## VAT handling

The addon resolves the VAT rate per line item using this precedence:

1. If the **issuer is not VAT-registered** (`Default VAT %` set to `0`) - every item is sent at 0% with the configured Zero-Rate VAT Name.
2. If the **client is tax-exempt** in WHMCS (`tblclients.taxexempt = 1`) - every item for that client is sent at 0%.
3. If the **individual WHMCS line item is untaxed** (`tblinvoiceitems.taxed = 0`) - that item is sent at 0%.
4. Otherwise - the item is sent with the configured `Default VAT %` and `vatName = Normala`.

Both the `Default VAT %` and the `Zero-Rate VAT Name` must correspond to rates that actually exist in your Oblio company settings, otherwise Oblio will reject the document.

## The "Storno" custom status

The WHMCS-side mirror storno uses a custom invoice status of `Storno`. WHMCS does not expose a configuration mechanism for invoice statuses (they're hardcoded in core), so this status:

- Stores and displays correctly in `tblinvoices.status` (it's a text column, not an enum)
- Shows as text in the invoice detail view in both admin and client area
- Does NOT appear in WHMCS's standard status filter dropdowns
- Is NOT color-styled by default

To list storno invoices, use the addon's **Recent Sync Log** panel (filter `Type = storno`), or query `mod_oblio_invoices` directly.

## Manual sync

The auto-sync occasionally fails (Oblio API outage, missing 0% rate, expired token, etc.). The addon's admin panel includes a **Manual Sync** widget: enter a WHMCS invoice ID, pick a type (Invoice / Incasare / Storno), and click Sync to Oblio. The result appears immediately.

The Manual Sync panel is also useful for backfilling: if you install the addon on an existing WHMCS with historical invoices, you can sync them one at a time without waiting for new events.

## Troubleshooting

**"Produsul ... are cota TVA 0%, dar aceasta nu este setata in Oblio"**
The "Zero-Rate VAT Name" setting points to a VAT name that doesn't exist in your Oblio company settings. Go to Oblio.eu -> Setari -> TVA and check which 0% rates you have configured. Set the addon's "Zero-Rate VAT Name" field to match one of them exactly (e.g., `Scutita`).

**"Nu exista document cu seria CRK si numarul 0XXX"**
The original invoice referenced by a storno call no longer exists in Oblio. This usually happens when an invoice was manually deleted in Oblio. Check the sync log to find the broken reference.

**`InvoiceCreated` never fires on admin-created invoices**
WHMCS's `InvoiceCreated` hook only fires reliably for automated billing (cron-generated invoices). For invoices created manually in the admin area, the addon also listens to `InvoiceCreationPreEmail`, which fires at publish time when the customer email is sent. If you publish without sending the email, the sync won't trigger automatically - use Manual Sync.

**Tax-exempt client still gets charged VAT in Oblio**
The addon detects `tblclients.taxexempt = 1` and sends `vatPercentage: 0` for that client's line items. If you're still seeing VAT applied, check the client's `taxexempt` flag in WHMCS admin: Clients -> [client] -> Edit -> Tax Exempt.

**I'm not VAT-registered. Does this addon work for me?**
Yes. Set `Default VAT %` to `0` in the addon configuration. All items will be sent to Oblio at 0% with the Zero-Rate VAT Name you configured. Make sure the matching 0% rate exists in Oblio (Setari -> TVA), and that your Oblio company profile itself is configured as non-VAT-payer there.

**Oblio rejects with "Ati emis deja 3 documente in aceasta luna"**
You're on Oblio's free tier (3 documents/month). Either upgrade your Oblio subscription or wait for the monthly reset.

## Roadmap

Pull requests welcome. Some directions we'd like to take:

- Multi-language UI strings (currently English only; addon docs are in Romanian context)
- Webhook receiver for Oblio-side changes (cancelled in Oblio -> cancelled in WHMCS)
- Per-WHMCS-product Oblio product mapping (currently 1 line item = 1 generic Oblio product)
- Optional admin CSS injection to color-style the `Storno` status badge

Please open an issue first for substantial changes so we can discuss approach.

## License

GPL v3 - see [LICENSE](LICENSE).

In plain language:
- Use it freely, in commercial WHMCS deployments included
- Modify it however you want
- If you distribute your modified version, it must also be GPL v3 with full readable source
- No ionCube, no obfuscation, no "encoded edition" forks
- Selling closed-source derivatives is not permitted

## Author

[CROCKY SRL](https://crocky.host) - Romanian hosting and infrastructure company.

Built by the CrockyHost team for our own WHMCS installation, released to the community because Romanian businesses shouldn't be locked into expensive proprietary integrations just to comply with their own tax law.

If this addon saves you money or hassle, a star on the repo is appreciated. If you find a bug or want a feature, open an issue.
