#!/usr/bin/env php
<?php

$types=[ //linux/input.h:178
  0x00=>"EV_SYN",
  0x01=>"EV_KEY",
  0x02=>"EV_REL",
  0x03=>"EV_ABS",
  0x04=>"EV_MSC",
  0x05=>"EV_SW",
  0x11=>"EV_LED",
  0x12=>"EV_SND",
  0x14=>"EV_REP",
  0x15=>"EV_FF",
  0x16=>"EV_PWR",
  0x17=>"EV_FF_STATUS",
  0x1F=>"EV_MAX",
];

require(dirname(__FILE__)."/../shared/common.php");

#if(!is_file($config["remote_dev"]))
#  die("input event device does not exist\n");

// Initialize evdev input for the remote
$fp=fopen($config["remote_dev"],"rb");
$fifo=fifo_init();
$ctr=0;
while(!feof($fp)) {
  $data=fread($fp,16);
  $struct=unpack("Lsec/Lusec/Stype/Scode/Lvalue",$data);
  switch($struct["type"]) {
    case 0x01:
      $data=["type"=>"key","data"=>["keycode"=>$struct["code"],"pressed"=>($struct["value"]==1?true:false)]];
      $data=json_encode($data);
      fwrite($fifo,$data."\n");
      printf("%s: %s (code: %x, value: %x)\n",date("d.m.Y H:i:s",$struct["sec"]),$types[$struct["type"]],$struct["code"],$struct["value"]);
    break;
    default:
      printf("%s: %s (code: %x, value: %x)\n",date("d.m.Y H:i:s",$struct["sec"]),$types[$struct["type"]],$struct["code"],$struct["value"]);
  }
}
fclose($fifo);
fclose($fp);