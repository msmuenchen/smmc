<?php
require("common.php");
//Ensure that for a station (tv/radio, scrambled/fta) the group for its provider exists
//Create appropriate group if it doesn't already exist, always return the group id
function createProviderGroup($station) {
  global $db;
  if($station["type"]=="tv" && $station["scrambled"]==false)
    $parent_group=3;
  else if($station["type"]=="tv" && $station["scrambled"]==true)
    $parent_group=4;
  else if($station["type"]=="radio" && $station["scrambled"]==false)
    $parent_group=5;
  else if($station["type"]=="radio" && $station["scrambled"]==true)
    $parent_group=6;
  else
    $parent_group=7;
  $stmt=$db->prepare("select * from groups where parent=? and name=?");
  $stmt->execute([$parent_group,$station["group"]]);
  $rows=$stmt->fetchAll();
  if(sizeof($rows)>0)
    return $rows[0]["id"];
  $stmt=$db->prepare("insert into groups(parent,name) values(?,?)");
  $stmt->execute([$parent_group,$station["group"]]);
  return $db->lastInsertId();
}
$lines=file("channels.txt");
$groups=["tv"=>["fta"=>[],"encrypted"=>[]],"radio"=>["fta"=>[],"encrypted"=>[]]];
$modulations=["QPSK", "16QAM", "32QAM", "64QAM", "128QAM", "256QAM", "QAM", "8VSB", "16VSB", "8PSK", "16APSK", "32APSK", "DQPSK"]; //dump-vlc-m3u.c:75
$total=0;
$new=0;
$updated=0;
$skipped=0;
foreach($lines as $line) {
// //provider:service:freq:srate:ts:modulation:program:vpid:apid:ac3pid:scrambled
  $line=trim($line);
  if($line=="")
    continue;
  $total++;
  echo "At line $total of ".sizeof($lines)."\n";
  list($group,$title,$freq,$srate,$ts,$modulation,$program,$vpid,$apid,$ac3pid,$scrambled)=explode(":",$line);
  $station=[
    "group"=>$group,
    "title"=>$title,
    "freq"=>intval($freq),
    "srate"=>intval($srate),
    "modulation"=>$modulations[intval($modulation)],
    "pids"=>[
      "ts"=>intval($ts),
      "pid"=>intval($program),
      "vid"=>intval($vpid),
      "aid"=>intval($apid),
      "ac3id"=>intval($ac3pid),
    ],
    "scrambled"=>intval($scrambled)==1?true:false,
  ];
  if($station["pids"]["vid"]!=0)
    $station["type"]="tv";
  else if($station["pids"]["aid"]!=0 || $station["pids"]["ac3id"]!=0)
    $station["type"]="radio";
  else
    $station["type"]="other";
  
  $vlc_string=sprintf("\"dvb-c://frequency=%d\" --dvb-srate=%d --dvb-modulation=%s --dvb-ts-id=%d --program=%d",$station["freq"],$station["srate"],$station["modulation"],$station["pids"]["ts"],$station["pids"]["pid"]);
  
  if($station["type"]!="other" && $station["scrambled"]==false) {
    if(!isset($groups[$station["type"]]["fta"][$station["group"]]))
      $groups[$station["type"]]["fta"][$station["group"]]=[];
    $groups[$station["type"]]["fta"][$station["group"]][]=$station;
  } else if($station["type"]!="other" && $station["scrambled"]==true) {
    if(!isset($groups[$station["type"]]["encrypted"][$station["group"]]))
      $groups[$station["type"]]["encrypted"][$station["group"]]=[];
    $groups[$station["type"]]["encrypted"][$station["group"]][]=$station;
  } else {
    echo "Station $title has neither VID, AID nor AC3ID. Unknown station type\n";
    $skipped++;
    continue;
  }
  $stmt=$db->prepare("select * from stations where name=?");
  $stmt->execute([$station["title"]]);
  $rows=$stmt->fetchAll();
  $params=serialize($station);
  if(sizeof($rows)==0) {
    $stmt=$db->prepare("insert into stations(name,conndata,type,params) values (?,?,?,?);");
    $stmt->execute([$station["title"],$vlc_string,$station["type"],$params]);
    $station_id=$db->lastInsertId();
    $group_id=createProviderGroup($station);
    $stmt=$db->prepare("insert into stations_groups(group_id,station_id) values (?,?);");
    $stmt->execute([$group_id,$station_id]);
    $new++;
  } else if(sizeof($rows)==1) {
    if($rows[0]["conndata"]==$vlc_string && $rows[0]["params"]==$params) {
      $skipped++;
      continue;
    }
    var_dump($rows[0]["conndata"],$vlc_string);
    var_dump($rows[0]["params"],$params);
    $stmt=$db->prepare("update stations set conndata=?,type=?,params=? where id=?;");
    $stmt->execute([$vlc_string,$station["type"],$params,$rows[0]["id"]]);
    $updated++;
  } else {
    $skipped++;
    echo "WARNING: Multiple stations with same name, DB broken\n";
  }
}
printf("%d stations total, %d new, %d skipped, %d updated\n",$total,$new,$skipped,$updated);
