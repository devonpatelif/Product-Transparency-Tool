<?php
class Pwn {
    public $cmd = "calc.exe";
    public function __wakeup() { echo "BOOM __wakeup cmd=" . $this->cmd . "\n"; }
}
$payload = serialize(new Pwn());
echo "Payload: " . $payload . "\n\n";

echo "-- unsafe unserialize (no options) --\n";
$r1 = unserialize($payload);
echo "  type=" . gettype($r1) . " class=" . (is_object($r1) ? get_class($r1) : "n/a") . " is_array=" . var_export(is_array($r1), true) . "\n";

echo "\n-- hardened unserialize (allowed_classes => false) --\n";
$r2 = unserialize($payload, ["allowed_classes" => false]);
echo "  type=" . gettype($r2) . " class=" . (is_object($r2) ? get_class($r2) : "n/a") . " is_array=" . var_export(is_array($r2), true) . "\n";

echo "\n-- round-trip a plain array (loss-free check) --\n";
$arr = ["x" => 1, "rows" => [["a" => 1], ["b" => 2]]];
$r3 = unserialize(serialize($arr), ["allowed_classes" => false]);
echo "  is_array=" . var_export(is_array($r3), true) . " equal=" . var_export($r3 === $arr, true) . "\n";
