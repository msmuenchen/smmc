<?php
define("SMMC_ROOT",realpath(dirname(__FILE__)."/../"));
require(SMMC_ROOT."/config/config.php");

//If there is no valid dbus session address, create a dbus daemon
//In any case, set the dbus environment variable
function dbus_init($tries=1) {
  global $config;
  $file=$config["dbus_file"];
  $info=file($file);
  if(sizeof($info)!=3) {
    printf("dbus daemon info not present, launching new dbus daemon\n");
    exec("dbus-launch --sh-syntax > ".$config["dbus_file"]);
    $info=file($file);
    if(sizeof($info)!=3)
      throw new Exception("failed to initialize dbus");
  }
  foreach($info as $i=>$line)
    $info[$i]=str_replace("'","",substr(trim($line),0,-1));
  list(,$pid)=explode("=",$info[2]);
  exec("pgrep dbus-daemon",$out,$rv);
  if(!in_array($pid,$out)) {
    if($tries==1) {
      $fp=fopen($config["dbus_file"],"w");
      fclose($fp);
      return dbus_init(0);
    } else {
      throw new Exception("Repeatedly failed to initialize dbus daemon");
    }
  }
  putenv($info[0]);
  putenv($info[2]);
  
  static $dbus=null;
  static $interfaces=[];
  if($dbus==null) {
    echo "Creating new dbus if\n";
    $dbus=new Dbus();
    $interfaces["vlc"] = $dbus->createProxy( "org.mpris.MediaPlayer2.vlc", "/org/mpris/MediaPlayer2", "org.mpris.MediaPlayer2");//destination path interface
    $interfaces["vlcPlayer"]=$dbus->createProxy( "org.mpris.MediaPlayer2.vlc", "/org/mpris/MediaPlayer2", "org.mpris.MediaPlayer2.Player");
    $interfaces["vlcPlayerProperties"]=$dbus->createProxy( "org.mpris.MediaPlayer2.vlc", "/org/mpris/MediaPlayer2","org.freedesktop.DBus.Properties");
  }
  return $interfaces;
}

//If the fifo does not exist, create it with 0777
//Return a file handle opened with the given mode
function fifo_init($mode="w") {
  global $config;
  if(filetype($config["fifo"])=="unknown") {
    echo "Initializing fifo ".$config["fifo"]."\n";
    posix_mkfifo($config["fifo"],0777);
    chmod($config["fifo"],0777);
  }
  $fp=fopen($config["fifo"],$mode);
  if(!is_resource($fp))
    throw new Exception("failed to open fifo");
  return $fp;
}

//Initialize and return database
function db_init() {
  global $config;
  static $db=null;
  if(is_object($db))
    return $db;
  $db=new PDO("sqlite:".$config["dbpath"]);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  return $db;
}

function parse_wscan($data) {
  static $state=0; //Wait for beginning of scan data
  static $currentMode=""; //QAM...
  static $currentFreq=""; //Current frequency
  static $currentTime=""; //Current time
  static $currentSymR=""; //Symbol rate
  static $stations=[];

  if($data===null) //return the stations array
    return $stations;
  
  if($data=="")
    return;
  if($data=="-_-_-_-_-_-_-_-_-_-_-_-_-_-_-_-_-_-_-_-_-_-_-_" && $state==0) {
    echo "Beginning to scan\n";
    $state=1;
    return;
  }

  if($state==1) {
    if(preg_match("@searching QAM([\\d]*)...\$@",$data,$hit)) {
      $currentMode=intval($hit[1]);
      echo "Scanning for QAM_$currentMode stations\n";
      return;
    }
    if(preg_match("@^tune to@",$data)) {
      echo "Scanning done, now scanning for stations\n";
      $state=2;
      goto state_2; //We want to parse this line too
    }
    if(preg_match("@^([\\d]*): @",$data,$hit)) {
      $currentFreq=intval($hit[1])*1000;
      if(preg_match("@sr([\\d]*)@",$data,$hit))
        $currentSymR=intval($hit[1])*1000;
      if(preg_match("@\\(time: ([\\d]{2}:[\\d]{2}.[\\d]{3})\\)@",$data,$hit))
        $currentTime=$hit[1];
    } else
      return;
    printf("At QAM %d, searching at frequency %d with symbol rate %d, %s elapsed\n",$currentMode,$currentFreq,$currentSymR,$currentTime);
  } else if($state==2) {
state_2:
    if(preg_match("@tune to: QAM_([\\d]*)[\s]*f = ([\\d]*) kHz S(.*)C(.*)  \(.*\) \\(time: ([\\d]{2}:[\\d]{2}.[\\d]{3})\\)\$@",$data,$hit)) {
      $currentMode=intval($hit[1]);
      $currentFreq=intval($hit[2])*1000;
      $currentSymR=intval($hit[3])*1000;
      $currentTime=$hit[5];
      printf("At QAM %d, searching at frequency %d with symbol rate %d, %s elapsed\n",$currentMode,$currentFreq,$currentSymR,$currentTime);
      return;
    } else if(preg_match("@service = (.*) \\((.*)\\)\$@",$data,$hit)) {
      $name=$hit[1];
      $group=$hit[2];
      if(!isset($stations[$group]))
        $stations[$group]=[];
      $stations[$group][]=["name"=>$name,"mode"=>$currentMode,"freq"=>$currentFreq,"symr"=>$currentSymR];
      printf("Found station '%s' in group '%s'\n",$name,$group);
    }
  }
}
function getAppState() {
  global $config;
  $ret=["mode"=>"idle"];
  if(!is_file($config["state_file"]))
    return $ret;
  $file=file_get_contents($config["state_file"]);
  if($file===false)
    return $ret;
  $state=json_decode($file);
  if($state===false || $state===null)
    return $ret;
  return $state;
}
function setAppState($state) {
  global $config;
  $fp=fopen($config["state_file"],"w");
  fwrite($fp,json_encode($state));
  fclose($fp);
}
function getStation($id) {
  $stmt=db_init()->prepare("select * from stations where id=?");
  $stmt->execute([$id]);
  $rows=$stmt->fetchAll();
  if(sizeof($rows)!=1)
    throw new Exception("Unknown station");
  return $rows[0];
}
function getGroup($id) {
  $stmt=db_init()->prepare("select * from groups where id=?");
  $stmt->execute([$id]);
  $rows=$stmt->fetchAll();
  if(sizeof($rows)!=1)
    throw new Exception("Unknown group");
  return $rows[0];
}
function getStationGroups($id) {
  $ret=[];
  $stmt=db_init()->prepare("select * from stations_groups where station_id=?;");
  $stmt->execute([$id]);
  $rows=$stmt->fetchAll();
  foreach($rows as $row)
    $ret[]=intval($row["group_id"]);
  return $ret;
}
function writeFifoCmd($data) {
  $data=json_encode($data);
  $fp=fifo_init();
  fwrite($fp,$data."\n");
  fclose($fp);
}
function stationAddToGroup($station,$group) {
  getStation($station);
  getGroup($group);
  $stmt=db_init()->prepare("select * from stations_groups where station_id=? and group_id=?;");
  $stmt->execute([$station,$group]);
  if(sizeof($stmt->fetchAll())==1)
    return;
  $stmt=db_init()->prepare("insert into stations_groups(station_id,group_id) values(?,?);");
  $stmt->execute([$station,$group]);
}
function stationRemoveFromGroup($station,$group) {
  getStation($station);
  getGroup($group);
  $stmt=db_init()->prepare("select * from stations_groups where station_id=? and group_id=?;");
  $stmt->execute([$station,$group]);
  if(sizeof($stmt->fetchAll())==0)
    return;
  $stmt=db_init()->prepare("delete from stations_groups where station_id=? and group_id=?;");
  $stmt->execute([$station,$group]);
}

// http://stackoverflow.com/a/19553986/1933738
//$childPids=[];
function detached_exec($cmd) {
  global $childPids;
  $pid = pcntl_fork();
  switch($pid) {
    case -1 : return false;
    case 0 :
      posix_setsid();
      passthru($cmd);
      exit();
    default:
//      $childPids[]=$pid;
      return $pid;
  }
}

function isChannelInGroup($id,$group) {
  $groups=getStationGroups($id);
  return in_array($group,$groups);
}

//Re-order channels inside group
function reorderChannels($map,$group) {
  $stmt=$db->prepare("update stations_groups set channel_num=? where station_id=? and group_id=?");
  foreach($map as $idx=>$id) {
    $stmt->execute([$idx+1,$id,$group]);
  }
}

class VLCPlayer {
  private $dbus=[];
  private $muteVol=0.0; //for mute, backup the old volume
  
  function __construct($dbus) {
    echo "vlcplayer ctor\n";
    var_dump($dbus);
    $this->dbus=$dbus;
  }
  function getVolume() {
    try {
      $vol=$this->dbus["vlcPlayerProperties"]->Get("org.mpris.MediaPlayer2.Player","Volume")->getData();
    } catch(Exception $e) {
      printf("Warning, VLC does not react to DBus\n");
      return -1.0;
    }
//    printf("Current volume: %.2f\n",$vol);
    return $vol;
  }
  function setVolume($newVol) {
//    printf("New volume: %.2f\n",$newVol);
    try {
      $this->dbus["vlcPlayerProperties"]->Set("org.mpris.MediaPlayer2.Player","Volume",new DbusVariant($newVol));
    } catch(Exception $e) {
      printf("Warning, VLC does not react to DBus\n");
    }
  }
  function mute() {
    $oldVol=$this->getVolume();
    $newVol=$this->muteVol;
    $this->muteVol=$oldVol;
    $this->setVolume($newVol);
    return $newVol;
  }
  function changeVolume($delta) {
    $oldVol=$this->getVolume();
    $newVol=$oldVol+$delta;
    if($newVol<0.0)
      $newVol=0;
    if($newVol>2.0)
      $newVol=2.0;
    $this->setVolume($newVol);
    return $newVol;
  }
  function volUp($delta=0.05) {
    return $this->changeVolume($delta);
  }
  function volDn($delta=-0.05) {
    return $this->changeVolume($delta);
  }
  function quit() {
    printf("Attempting to quit vlc... ");
    try {
      $this->dbus["vlc"]->Quit();
      printf("done\n");
    } catch(Exception $e) {
      printf("not needed\n");
    }
  }
  function launch($conndata) {
    $this->quit();
    $cmd="vlc -I http --play-and-exit --no-video-title-show --http-password=penis --vout omxil_vout $conndata";// > /dev/null 2>/dev/null &";
    echo "Launching VLC:\n$cmd\n";
    detached_exec($cmd);
  }
}

function vlc_init() {
  static $vlc=null;
  if($vlc===null)
    $vlc=new VLCPlayer(dbus_init());
  return $vlc;
}

function tmp_init() {
  global $config;
  exec("mountpoint -q ".$config["tmp_dir"],$out,$rc);
  if($rc==1)
    exec("mount -t tmpfs none ".$config["tmp_dir"]." -o size=100M");
}

function tts($text) {
  detached_exec("espeak -a 6 -ven+f3 -k5 -s150 ".escapeshellarg($text));
}
