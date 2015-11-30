<?php
require(dirname(__FILE__)."/../shared/common.php");

if(!isset($_GET["act"]))
  die("unknown action");
$act=$_GET["act"];
if(!in_array($act,["play","off","terminate","fav","unfav","volup","voldn","mute"]))
  die("unknown action");

switch($act) {
  case "play":
    if(!isset($_GET["type"]))
      die("unknown type");
    $type=$_GET["type"];
    if(!in_array($type,["station","stream"]))
      die("unknown type");
    switch($type) {
      case "station":
        $station=getStation($_GET["id"]);
        writeFifoCmd(["type"=>"command","data"=>["cmd"=>"play_station","id"=>$station["id"]]]);
      break;
      case "stream":
        writeFifoCmd(["type"=>"command","data"=>["cmd"=>"play_stream","link"=>$_GET["stream"]]]);
      break;
      default:
        die("unknown type");
    }
  break;
  case "off":
    writeFifoCmd(["type"=>"command","data"=>["cmd"=>"off"]]);
  break;
  case "volup":
    writeFifoCmd(["type"=>"command","data"=>["cmd"=>"volup"]]);
  break;
  case "voldn":
    writeFifoCmd(["type"=>"command","data"=>["cmd"=>"voldn"]]);
  break;
  case "mute":
    writeFifoCmd(["type"=>"command","data"=>["cmd"=>"mute"]]);
  break;
  case "terminate":
    writeFifoCmd(["type"=>"command","data"=>["cmd"=>"terminate"]]);
  break;
  case "fav":
    $station=getStation($_GET["id"]);
    writeFifoCmd(["type"=>"command","data"=>["cmd"=>"station_addgroup","id"=>$_GET["id"],"gid"=>8]]);
  break;
  case "unfav":
    $station=getStation($_GET["id"]);
    writeFifoCmd(["type"=>"command","data"=>["cmd"=>"station_delgroup","id"=>$_GET["id"],"gid"=>8]]);
  break;
  default:
    die("unknown action");
}
header("HTTP/1.1 200 OK");
header("Location: index.php");
