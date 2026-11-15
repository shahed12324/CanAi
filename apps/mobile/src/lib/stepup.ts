import { ApiError } from '@/api/client';

/**
 * Runs a sensitive action. If the server answers "step_up_required" the user is asked for the PIN, the PIN is
 * verified by the SERVER, and the action is retried once. The phone never decides on its own that a PIN was right.
 *
 * askPin(error) resolves with the PIN, or null if the user cancelled. `error` is the previous wrong-PIN error, if any,
 * so the screen can say "wrong PIN" and ask again.
 */
export async function withStepUp<T>(
  action: () => Promise<T>,
  verify: (pin: string) => Promise<unknown>,
  askPin: (previous: ApiError | null) => Promise<string | null>,
  maxPinTries = 5,
): Promise<T> {
  try {
    return await action();
  } catch (e) {
    if (!(e instanceof ApiError) || e.code !== 'step_up_required') throw e;
  }
  let previous: ApiError | null = null;
  for (let i = 0; i < maxPinTries; i++) {
    const pin = await askPin(previous);
    if (pin === null) throw new ApiError(0, 'cancelled', 'Cancelled.');
    try {
      await verify(pin);
      break;
    } catch (e) {
      if (e instanceof ApiError && e.code === 'bad_pin') { previous = e; if (i === maxPinTries - 1) throw e; continue; }
      throw e;                       // locked, no_pin, network...: stop asking
    }
  }
  return action();
}
