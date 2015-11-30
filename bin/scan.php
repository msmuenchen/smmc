<?php
require("common.php");

$cmd="w_scan -f c -c DE -Y";
echo "Initializing scan\n";
$proc=proc_open($cmd,[["pipe","r"],["file","channels.txt","w"],["pipe","w"]],$pipes);
if(!is_resource($proc))
  die("could not run w_scan\n");

$out="";
while(!feof($pipes[2])) {
  $packet=fgets($pipes[2],1024);
  $out.=$packet;
  parse_wscan(rtrim($packet));
}
echo "Scan done\n";

fclose($pipes[0]);
fclose($pipes[2]);

$rv=proc_close($proc);
if($rv!=0) {
  echo "An error occurred during scanning, please look at err.txt\n";
  $fp=fopen("err.txt","w");
  fwrite($fp,$out);
  fclose($fp);
  exit(1);
} else {
  echo "Scan successful.\n";
}
