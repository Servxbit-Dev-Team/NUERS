import contract from "../../../resources/data/merchant-api-receipts.json";
export function IntegrationReceiptGuide() {
  return (
    <section className="mi-note" aria-label="NUERS status and customer receipts">
      <div>
        <h3>{contract.title}</h3>
        <p>{contract.dashboard_requirement}</p>
        <p>
          <strong>Checkout email:</strong> {contract.customer_email}
        </p>
        <p>{contract.receipt_delivery}</p>
        <p>{contract.branding}</p>
        <p>{contract.delivery_status}</p>
        <p>{contract.errors}</p>
        <p>{contract.bir_fields}</p>
        <h3>CWT and manual recovery</h3>
        <p>{contract.withholding_details}</p>
        <p>{contract.withholding_corrections}</p>
        <p>{contract.manual_recovery}</p>
        <p>{contract.corrections}</p>
        <p>{contract.source_intake_policy}</p>
        <a className="mi-outline" href="/merchant/compliance-operations">
          Open Compliance Operations
        </a>
        <a className="mi-outline" href="/guides/NUERS-Transaction-Status.mjs" download>
          Download sales status adapter
        </a>
      </div>
    </section>
  );
}
