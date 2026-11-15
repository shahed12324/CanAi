<?php

namespace Illuminate\Support { class Str {
  public static function lower($s){return mb_strtolower($s);} public static function upper($s){return mb_strtoupper($s);}
  public static function random($n=16){$c='abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';$o='';for($i=0;$i<$n;$i++)$o.=$c[random_int(0,61)];return $o;}
  public static function limit($s,$n=100,$e='...'){return mb_strlen($s)<=$n?$s:rtrim(mb_substr($s,0,$n)).$e;}
  public static function ulid(){return strtolower(substr(strtoupper(bin2hex(random_bytes(13))),0,26));}
  public static function uuid(){return sprintf('%s-%s-4%s-a%s-%s',bin2hex(random_bytes(4)),bin2hex(random_bytes(2)),substr(bin2hex(random_bytes(2)),1),substr(bin2hex(random_bytes(2)),1),bin2hex(random_bytes(6)));}
  public static function slug($s){return trim(preg_replace('/[^a-z0-9]+/','-',mb_strtolower($s)),'-');}
} }
namespace {
// Minimal Pest-like runner for pure-PHP tests (no Laravel needed).
// usage: php scripts/runpure.php apps/web-overlay/tests/Unit/AccTextTest.php [more files...]
// Not supported (need real Laravel/Mockery -> run with Pest): CredentialVaultTest, ProviderGatewayTest, TenantContextTest.
// Repo root = parent of scripts/. OVERLAY (optional) overrides apps/web-overlay.
$repo = dirname(__DIR__);
$root = rtrim(getenv('OVERLAY') ?: "$repo/apps/web-overlay", '/');
$pkg = "$repo/packages/jarvis-connector";
spl_autoload_register(function ($c) use ($root, $pkg) {
    foreach (['Jarvis\\Connector\\Tests\\' => "$pkg/tests/", 'Jarvis\\Connector\\' => "$pkg/src/", 'App\\' => "$root/app/", 'Tests\\Support\\' => "$root/tests/Support/", 'Tests\\' => "$root/tests/"] as $p => $d) {
        if (str_starts_with($c, $p)) { $f = $d . str_replace('\\', '/', substr($c, strlen($p))) . '.php'; if (is_file($f)) { require_once $f; return; } }
    }
});
foreach (glob("$root/tests/Support/*.php") as $f) { /* lazy via autoload; some files may hold helpers */ }
class Fail extends Exception {}
class Exp {
    public bool $neg = false;
    public function __construct(public mixed $v) {}
    public function __get($n) { if ($n === 'not') { $c = clone $this; $c->neg = !$this->neg; return $c; } throw new Fail("no prop $n"); }
    private function chk(bool $ok, string $m) { if ($ok === $this->neg) throw new Fail(($this->neg ? 'NOT ' : '') . $m . ' | got ' . substr(var_export($this->v, true), 0, 300)); return $this; }
    public function and(mixed $v) { return new Exp($v); }
    public function toBe($e) { return $this->chk($this->v === $e, 'toBe ' . substr(var_export($e, true), 0, 300)); }
    public function toEqual($e) { return $this->chk($this->v == $e, 'toEqual ' . substr(var_export($e, true), 0, 200)); }
    public function toBeTrue() { return $this->chk($this->v === true, 'true'); }
    public function toBeFalse() { return $this->chk($this->v === false, 'false'); }
    public function toBeNull() { return $this->chk($this->v === null, 'null'); }
    public function toBeEmpty() { return $this->chk(empty($this->v), 'empty'); }
    public function toBeTruthy() { return $this->chk((bool)$this->v, 'truthy'); }
    public function toBeInstanceOf($c) { return $this->chk($this->v instanceof $c, "instanceof $c"); }
    public function toBeLessThan($e) { return $this->chk($this->v < $e, "< $e"); }
    public function toBeGreaterThan($e) { return $this->chk($this->v > $e, "> $e"); }
    public function toBeLessThanOrEqual($e) { return $this->chk($this->v <= $e, "<= $e"); }
    public function toBeGreaterThanOrEqual($e) { return $this->chk($this->v >= $e, ">= $e"); }
    public function toContain(...$xs) { foreach ($xs as $x) { $ok = is_string($this->v) ? str_contains($this->v, (string)$x) : in_array($x, (array)$this->v, true); $this->chk($ok, 'contain ' . substr(var_export($x, true), 0, 200)); } return $this; }
    public function toHaveCount($n) { return $this->chk(count($this->v) === $n, "count $n (got " . count($this->v) . ')'); }
    public function toHaveLength($n) { return $this->chk((is_string($this->v) ? mb_strlen($this->v) : count($this->v)) === $n, "length $n"); }
    public function toHaveKey($k, $val = null) { $ok = is_array($this->v) && array_key_exists($k, $this->v); return $this->chk($ok, "key $k"); }
    public function toStartWith($s) { return $this->chk(str_starts_with($this->v, $s), "startsWith $s"); }
    public function toEndWith($s) { return $this->chk(str_ends_with($this->v, $s), "endsWith $s"); }
    public function toMatchArray($a) { $ok = true; foreach ($a as $k => $x) { if (!array_key_exists($k, (array)$this->v) || ((array)$this->v)[$k] != $x) $ok = false; } return $this->chk($ok, 'matchArray ' . substr(json_encode($a), 0, 200)); }
    public function toThrow($cls = null, $msg = null) {
        try { ($this->v)(); } catch (Throwable $t) {
            $ok = true; if (is_string($cls) && class_exists($cls)) $ok = $t instanceof $cls; elseif (is_string($cls)) $msg = $cls;
            if ($msg !== null && !str_contains($t->getMessage(), $msg)) $ok = false;
            return $this->chk($ok, 'throws ' . ($cls ?? '') . " (threw " . get_class($t) . ': ' . $t->getMessage() . ')');
        }
        return $this->chk(false, 'throws');
    }
    public function __call($n, $a) { throw new Fail("UNSUPPORTED matcher $n"); }
}
class Case_ { public $data = null; public $throws = null; public function throws($c=null){$this->throws=$c??true;return $this;} public function __construct(public string $d, public Closure $f) {} public function with($d) { $this->data = $d; return $this; } public function skip(...$a) { $this->d = '(skip)'; return $this; } }
$GLOBALS['cases'] = [];
function expect($v = null) { return new Exp($v); }
function it(string $d, Closure $f) { $c = new Case_($d, $f); $GLOBALS['cases'][] = $c; return $c; }
function test(string $d, Closure $f) { return it($d, $f); }
function beforeEach(...$a) {} function uses(...$a) { return new class { public function __call($n,$a){return $this;} }; }
$pass = 0; $fail = 0; $out = [];
foreach (array_slice($argv, 1) as $file) {
    $GLOBALS['cases'] = [];
    try { require $file; } catch (Throwable $t) { $fail++; $out[] = basename($file) . ' LOAD: ' . get_class($t) . ': ' . substr($t->getMessage(), 0, 200); continue; }
    foreach ($GLOBALS['cases'] as $c) {
        $sets = $c->data === null ? [[]] : (is_callable($c->data) ? ($c->data)() : $c->data);
        foreach ($sets as $args) {
            try { if ($c->throws !== null) { try { ($c->f)(...(is_array($args) ? array_values($args) : [$args])); throw new Fail('expected exception'); } catch (Fail $f) { throw $f; } catch (Throwable $t2) { if (is_string($c->throws) && class_exists($c->throws) && !($t2 instanceof $c->throws)) throw new Fail('wrong exception '.get_class($t2)); } $pass++; continue; } ($c->f)(...(is_array($args) ? array_values($args) : [$args])); $pass++; }
            catch (Throwable $t) { $fail++; $out[] = basename($file) . ' :: ' . substr($c->d, 0, 70) . ' -> ' . get_class($t) . ': ' . substr($t->getMessage(), 0, 260); }
        }
    }
}
foreach ($out as $l) echo $l, "\n";
echo "PASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);

}
