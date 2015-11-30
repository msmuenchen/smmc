#!/usr/bin/env php
<?php
require(dirname(__FILE__)."/../shared/common.php");
printf("Opening fifo\n");
$run=true;
$state=[
  "mode"=>"idle",
  "oldmode"=>"idle",
];
while($run) {
  $fp=fifo_init("r+");
  stream_set_timeout($fp,5);
  while(is_resource($fp) && !feof($fp)) {
    echo "waiting for input...\n";
    $line=trim(fgets($fp));
    pcntl_wait($void,WNOHANG);
    if($line=="" || $line==false)
      continue;
    $payload=json_decode($line);
    if($payload==null) {
      echo "Malformed command '$line'\n";
      continue;
    }
    $type=$payload->type;
    $data=$payload->data;
    switch($type) {
      case "command":
        switch($data->cmd) {
          case "play_station":
            echo "Got a play_station request for ".$data->id."\n";
            $station=getStation($data->id);
            if($station["type"]=="tv")
              exec("/opt/vc/bin/tvservice -p");
            else if($station["type"]=="radio")
              exec("/opt/vc/bin/tvservice -o");
            vlc_init()->launch($station["conndata"]);
            $state["mode"]="dvb";
            $state["station"]=$data->id;
            setAppState($state);
            tts("playing television");
          break;
          case "play_stream":
            echo "Got a play_stream request for ".$data->link."\n";
            $out=[];
            exec("youtube-dl -f best --youtube-skip-dash-manifest --get-url ".escapeshellarg($data->link),$out,$rc);
            if($rc!=0) {
              echo "Failed to download stream\n";
              tts("failed to play stream");
              var_dump($out);
              break;
            }
            $link=$out[0];
            echo "Playing real stream:\n$link\n";
            exec("/opt/vc/bin/tvservice -p");
            vlc_init()->launch("-vv --file-caching=30000 --network-caching=30000 ".escapeshellarg($link));
            $state["mode"]="stream";
            $state["stream"]=$link;
            setAppState($state);
            tts("playing stream");
          break;
          case "off":
            echo "Got a poweroff request\n";
            switch($state["mode"]) {
              case "dvb":
                vlc_init()->quit();
                $state["oldmode"]="dvb";
                $state["oldstation"]=$state["station"];
              break;
            }
            exec("/opt/vc/bin/tvservice -o");
            $state["mode"]="idle";
            setAppState($state);
            tts("shutting down");
          break;
          case "terminate":
            $run=false;
            vlc_init()->quit();
            $state["mode"]="off";
            setAppState($state);
            tts("shutting down");
            break 3;
          break;
          case "station_addgroup":
            stationAddToGroup($data->id,$data->gid);
          break;
          case "station_delgroup":
            stationRemoveFromGroup($data->id,$data->gid);
          break;
          case "volup":
            vlc_init()->volUp();
          break;
          case "voldn":
            vlc_init()->volDn();
          break;
          case "mute":
            vlc_init()->mute();
          break;
          default:
            echo "unknown cmd ".$data->cmd."\n";
        }
      break;
      case "key":
        var_dump($data->pressed);
        if($data->pressed==false)
          break;
        detached_exec("play -n synth 0.1 sin 1000 vol -12 db 2>/dev/null");
        var_dump($data->keycode);
        switch($data->keycode) {
          case 0x73: //Vol+
            writeFifoCmd(["type"=>"command","data"=>["cmd"=>"volup"]]);
          break;
          case 0x72: //Vol-
            writeFifoCmd(["type"=>"command","data"=>["cmd"=>"voldn"]]);
          break;
          case 0x74: //Pwr
            if($state["mode"]=="off" || $state["mode"]=="idle") {
              switch($state["oldmode"]) {
                case "dvb":
                  writeFifoCmd(["type"=>"command","data"=>["cmd"=>"play_station","id"=>$state["oldstation"]]]);
                  $state["mode"]="intermediate";
                break;
              }
            } else
              writeFifoCmd(["type"=>"command","data"=>["cmd"=>"off"]]);
          break;
          case 0x71: //Mute
            writeFifoCmd(["type"=>"command","data"=>["cmd"=>"mute"]]);
          break;
          default:
            printf("unknow key %d\n",$data->keycode);
        }
      break;

      default:
        echo "Unknown payload type ".$payload["type"]."\n";
    }
  }
  if(is_resource($fp))
    fclose($fp);
}
