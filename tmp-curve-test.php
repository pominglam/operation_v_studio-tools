<?php

foreach (['straight', 'stedi ultra-precision tweezers (straight)', 'curved', 'curved arc'] as $s) {
    echo $s.' => curve? '.(str_contains($s, 'curve') ? 'YES' : 'no')."\n";
}
