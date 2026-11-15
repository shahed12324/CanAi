import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/api/client';
import { withStepUp } from '@/lib/stepup';

const need = () => new ApiError(403, 'step_up_required', 'pin');
const bad = () => new ApiError(422, 'bad_pin', 'wrong');

describe('withStepUp', () => {
  it('does not ask for a PIN when the server does not require one', async () => {
    const ask = vi.fn(); const verify = vi.fn();
    expect(await withStepUp(async () => 'done', verify, ask)).toBe('done');
    expect(ask).not.toHaveBeenCalled(); expect(verify).not.toHaveBeenCalled();
  });
  it('asks, lets the server verify, then retries once', async () => {
    const action = vi.fn().mockRejectedValueOnce(need()).mockResolvedValueOnce('approved');
    const verify = vi.fn().mockResolvedValue({}); const ask = vi.fn().mockResolvedValue('482915');
    expect(await withStepUp(action, verify, ask)).toBe('approved');
    expect(verify).toHaveBeenCalledWith('482915'); expect(action).toHaveBeenCalledTimes(2);
  });
  it('asks again after a wrong PIN and passes the error so the screen can say so', async () => {
    const action = vi.fn().mockRejectedValueOnce(need()).mockResolvedValueOnce('ok');
    const verify = vi.fn().mockRejectedValueOnce(bad()).mockResolvedValueOnce({});
    const ask = vi.fn().mockResolvedValueOnce('000001').mockResolvedValueOnce('482915');
    await withStepUp(action, verify, ask);
    expect(ask.mock.calls[0]![0]).toBeNull();
    expect((ask.mock.calls[1]![0] as ApiError).code).toBe('bad_pin');
  });
  it('stops when the user cancels and never retries the action', async () => {
    const action = vi.fn().mockRejectedValue(need());
    await expect(withStepUp(action, vi.fn(), vi.fn().mockResolvedValue(null))).rejects.toMatchObject({ code: 'cancelled' });
    expect(action).toHaveBeenCalledTimes(1);
  });
  it('stops asking when the device is locked', async () => {
    const action = vi.fn().mockRejectedValue(need());
    const ask = vi.fn().mockResolvedValue('482915');
    const verify = vi.fn().mockRejectedValue(new ApiError(423, 'pin_locked', 'locked'));
    await expect(withStepUp(action, verify, ask)).rejects.toMatchObject({ code: 'pin_locked' });
    expect(ask).toHaveBeenCalledTimes(1);
  });
  it('gives up after the allowed number of wrong PINs', async () => {
    const action = vi.fn().mockRejectedValue(need());
    const ask = vi.fn().mockResolvedValue('000001');
    await expect(withStepUp(action, vi.fn().mockRejectedValue(bad()), ask, 3)).rejects.toMatchObject({ code: 'bad_pin' });
    expect(ask).toHaveBeenCalledTimes(3); expect(action).toHaveBeenCalledTimes(1);
  });
  it('does not turn other errors into a PIN prompt', async () => {
    const ask = vi.fn();
    await expect(withStepUp(async () => { throw new ApiError(409, 'not_pending', 'x'); }, vi.fn(), ask)).rejects.toMatchObject({ code: 'not_pending' });
    expect(ask).not.toHaveBeenCalled();
  });
  it('does not loop forever if the server still wants a step-up after a good PIN', async () => {
    const action = vi.fn().mockRejectedValue(need());
    await expect(withStepUp(action, vi.fn().mockResolvedValue({}), vi.fn().mockResolvedValue('482915'))).rejects.toMatchObject({ code: 'step_up_required' });
    expect(action).toHaveBeenCalledTimes(2);
  });
});
