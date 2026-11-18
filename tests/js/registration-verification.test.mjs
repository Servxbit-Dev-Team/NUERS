import { test } from "node:test";
import assert from "node:assert/strict";
import {
  secondsRemaining,
  deadlineFromSeconds,
  checkRegistrationResponse,
  verificationNeedsRenewal,
} from "../../src/lib/registration-verification.ts";
test("wall clock expiry and resend countdown remain correct after a suspended tab", () => {
  const sent = 100000,
    deadline = deadlineFromSeconds(600, sent);
  assert.equal(secondsRemaining(deadline, sent), 600);
  assert.equal(secondsRemaining(deadline, sent + 599001), 1);
  assert.equal(secondsRemaining(deadline, sent + 600000), 0);
  assert.equal(secondsRemaining(deadline, sent + 3600000), 0);
  assert.equal(secondsRemaining(deadlineFromSeconds(60, sent), sent + 61000), 0);
});
test("server-expired submission and upload proofs trigger renewal without masking other validation errors", async () => {
  const response = new Response(
    JSON.stringify({ code: "REGISTRATION_VERIFICATION_REQUIRED", message: "Send a new code." }),
    { status: 422 },
  );
  await assert.rejects(
    checkRegistrationResponse(response),
    (e) => verificationNeedsRenewal(e) && e.message === "Send a new code.",
  );
  const ordinary = new Response(JSON.stringify({ message: "TIN is already registered." }), {
    status: 422,
  });
  assert.equal(await checkRegistrationResponse(ordinary), ordinary);
  assert.equal(verificationNeedsRenewal(new Error("Unrelated issue")), false);
});
test("response inspection preserves successful bodies and tolerates non-JSON transport errors", async () => {
  const ok = new Response(JSON.stringify({ registration_id: "synthetic" }), { status: 201 });
  assert.equal(await checkRegistrationResponse(ok), ok);
  assert.equal((await ok.json()).registration_id, "synthetic");
  const failed = new Response("Service unavailable", { status: 503 });
  assert.equal(await checkRegistrationResponse(failed), failed);
});
