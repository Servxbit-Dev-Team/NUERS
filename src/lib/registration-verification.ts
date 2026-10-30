/** Server-issued durations are converted to wall-clock deadlines so suspended tabs recover correctly. */
export function secondsRemaining(deadline: number, now = Date.now()) {
  return Math.max(0, Math.ceil((deadline - now) / 1000));
}
export function deadlineFromSeconds(seconds: number, now = Date.now()) {
  return now + Math.max(0, seconds) * 1000;
}
export function verificationNeedsRenewal(error: unknown) {
  return error instanceof RegistrationVerificationError;
}
export class RegistrationVerificationError extends Error {}
export async function checkRegistrationResponse(response: Response) {
  if (!response.ok) {
    const payload = await response
      .clone()
      .json()
      .catch(() => null);
    if (payload?.code === "REGISTRATION_VERIFICATION_REQUIRED") {
      throw new RegistrationVerificationError(payload.message);
    }
  }
  return response;
}
