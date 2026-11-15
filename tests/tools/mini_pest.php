<?php
// Usage: cd apps/web-overlay && php ../../tests/tools/mini_pest.php tests/Unit/SomeTest.php   (a minimal stand-in for Pest, NOT a replacement: unsupported matchers show as "Call to undefined method Ex::...")
// A tiny stand-in for Pest (only it/expect/and/not/toContain/toBe/toBeTrue/toBeFalse) so the new test file can run without vendor/.
spl_autoload_register(function ($c) {
    foreach (['App\\' => 'app/', 'Tests\\Support\\' => 'tests/Support/'] as $p => $d) {
        if (str_starts_with($c, $p)) { $f = $d . str_replace('\\', '/', substr($c, strlen($p))) . '.php'; if (is_file($f)) { require $f; } }
    }
});
if (!class_exists('Illuminate\\Support\\Str')) { eval('namespace Illuminate\\Support; final class Str { public static function ulid(): string { return strtoupper(substr(bin2hex(random_bytes(16)), 0, 26)); } }'); }  // stand-in so CreateWorkspace::newId() works without vendor/
class Ex { public bool $neg = false; function __construct(public $v) {}
  function and($v = null) { $this->neg = false; $this->v = $v; return $this; }
  function __get($n) { if ($n === 'not') { $this->neg = true; return $this; } }
  private function chk(bool $ok, string $m) { if ($this->neg ? $ok : !$ok) { throw new Exception("expect failed: $m (" . json_encode($this->v) . ")"); } $this->neg = false; return $this; }
  function toBe($x) { return $this->chk($this->v === $x, 'toBe ' . json_encode($x)); }
  function toContain($x) { return $this->chk(str_contains((string)$this->v, $x), 'toContain ' . $x); }
  function toHaveCount($n) { return $this->chk(count($this->v) === $n, 'count '.$n); }
  function toBeNull() { return $this->chk($this->v === null, 'null'); }
  function toBeGreaterThan($x) { return $this->chk($this->v > $x, '>'.$x); }
  function toBeLessThan($x) { return $this->chk($this->v < $x, '<'.$x); }
  function toBeLessThanOrEqual($x) { return $this->chk($this->v <= $x, '<='.$x); }
  function toBeGreaterThanOrEqual($x) { return $this->chk($this->v >= $x, '>='.$x); }
  function toEqual($x) { return $this->chk($this->v == $x, 'eq'); }
  function toBeEmpty() { return $this->chk(empty($this->v), 'empty'); }
  function toBeArray() { return $this->chk(is_array($this->v), 'array'); }
  function toBeString() { return $this->chk(is_string($this->v), 'string'); }
  function toBeInt() { return $this->chk(is_int($this->v), 'int'); }
  function toBeInstanceOf($c) { return $this->chk($this->v instanceof $c, 'instanceof'); }
  function toBeTruthy() { return $this->chk((bool)$this->v, 'truthy'); }
  function toBeFalsy() { return $this->chk(!$this->v, 'falsy'); }
  function toStartWith($x) { return $this->chk(str_starts_with((string)$this->v, $x), 'startsWith'); }
  function toEndWith($x) { return $this->chk(str_ends_with((string)$this->v, $x), 'endsWith'); }
  function toMatchArray($a) { foreach ($a as $k=>$x) { if (!is_array($this->v) || !array_key_exists($k,$this->v) || $this->v[$k] != $x) { return $this->chk(false, "matchArray $k"); } } return $this->chk(true,''); }
  function toHaveKey($k) { return $this->chk(is_array($this->v) && array_key_exists($k,$this->v), 'key '.$k); }
  function toMatch($rx) { return $this->chk(preg_match($rx, (string)$this->v) === 1, 'match'); }
  function toThrow($c = null, $m = null) { try { ($this->v)(); } catch (Throwable $e) { return $this->chk(true,''); } return $this->chk(false, 'toThrow'); }
  function toBeTrue() { return $this->chk($this->v === true, 'true'); }
  function toBeFalse() { return $this->chk($this->v === false, 'false'); } }
function expect($v) { return new Ex($v); }
$GLOBALS['fails'] = 0; $GLOBALS['n'] = 0;
function test($n,$f){ it($n,$f); }
function uses(...$a){ return new class { function __call($n,$a){return $this;} }; }
function beforeEach($f){}
function afterEach($f){}
function it($name, $fn) { $GLOBALS['n']++; try { $fn(); echo "ok   $name\n"; } catch (Throwable $e) { $GLOBALS['fails']++; echo "FAIL $name: " . $e->getMessage() . "\n"; } }
require $argv[1];
echo "{$GLOBALS['n']} tests, {$GLOBALS['fails']} failed\n";
