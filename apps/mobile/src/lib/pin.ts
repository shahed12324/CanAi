/** Same rule as the server (PinPolicy.php) so the user is told immediately; the server still decides. */
const WEAK = new Set(['123456', '654321', '121212', '112233', '123123', '000000', '696969', '123321', '159753', '246810', '135790', '102030']);

export function pinProblem(pin: string): string | null {
  if (!/^[0-9]{6}$/.test(pin)) return 'The PIN must be exactly 6 digits (0-9).';
  const d = pin.split('').map(Number);
  const same = new Set(d).size === 1;
  let up = true, down = true;
  for (let i = 1; i < 6; i++) {
    up &&= d[i] === (d[i - 1]! + 1) % 10;
    down &&= d[i] === (d[i - 1]! + 9) % 10;
  }
  if (same || up || down || WEAK.has(pin)) return 'This PIN is easy to guess. Choose another PIN.';
  return null;
}
