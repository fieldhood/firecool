<?php
$logfile = "/tmp/test1.log";
$posfile = "/tmp/file.pos";
#@unlink($logfile);
#@unlink($posfile);
$a = [
    'SQL', 'DEBUG', 'ERROR', 'INFO', 'WARING', 'TRACK',
    'AAA', 'BBB', 'CCC'
];

$index = 0;
while(true) {
    $rand = rand(0, 8);
    sleep($rand);
    file_put_contents($logfile, "[". $index ."] [".date("Y-m-d H:i:s")."] [". $a[$rand]. "] ".$rand."\n", FILE_APPEND);
    $index++;
}
