# Receipt Template Reference

Source: `RMC No. 77-2024 Annex A1-B6.pdf`

Required NUERS receipt download format: B6 Payment Receipt.

The client receipt PDF generator follows the B6 sample layout:

- A4 portrait page with `B6` marker and centered `PAYMENT RECEIPT` title.
- Seller identity block with business name, registered TIN, and registered address/RDO.
- Payment receipt number displayed as `No. ...`.
- Payment method checkboxes for cash and credit card.
- Payment date and account/reference number fields.
- `RECEIVED FROM` block with registered name, TIN, and address.
- Transaction/nature of service table with description and amount.
- Red notice: document is not valid for claim of input tax.
- Total paid amount and invoice reference number block.
- Footer for loose-leaf permit, date issued, authority/series, and receipt status.
- Supplementary invoice purpose note: records collection of payment or cash receipt.

Implementation: `src/lib/receipt-pdf.ts`.
