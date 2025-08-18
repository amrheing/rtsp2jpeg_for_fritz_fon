<?php
// Basic functional test for slice parameter crop filter generation
// Run: php tests/test_slicing.php

function buildFilterFromSlice($spec) {
  if (!preg_match('/^(\d+):(\d+)-(\d+)$/',$spec,$m)) return '';
  $parts=(int)$m[1];$from=(int)$m[2];$to=(int)$m[3];
  if ($parts<=0||$from<1||$to<$from||$to>$parts) return '';
  $widthParts = $to - $from + 1;
  $leftParts = $from - 1;
  return "crop=(iw*($widthParts)/$parts):(ih):(iw*($leftParts)/$parts):0";
}

function expectedFilter($parts,$from,$to) {
  $widthParts = $to - $from + 1;
  $leftParts = $from - 1;
  return "crop=(iw*($widthParts)/$parts):(ih):(iw*($leftParts)/$parts):0";
}

$cases = [
  '5:3-4' => [5,3,4],
  '3:1-1' => [3,1,1],
  '4:2-3' => [4,2,3],
];

$ok=true; $i=0;
foreach ($cases as $spec=>$triple) {
  list($p,$f,$t) = $triple;
  $expected = expectedFilter($p,$f,$t);
  $got = buildFilterFromSlice($spec);
  if ($got!==$expected) {
    echo "FAIL $spec\n expected=$expected\n got=$got\n"; $ok=false;
  }
  $i++;
}
if ($ok) { echo "All $i slice tests passed\n"; }
