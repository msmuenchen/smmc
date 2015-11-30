<?php
require(dirname(__FILE__)."/../shared/common.php");

function printChannelGroup($root=0,$path="0",$level=0,$strpath="All") {
  $stmt=db_init()->prepare("select * from groups where parent=?;");
  $stmt->execute([$root]);
  $rows=$stmt->fetchAll();
  foreach($rows as $group) {
?>
      <tbody class="grouphead">
      <tr class="group" data-path="<?=$path?>" data-gid="<?=$group["id"]?>"><th colspan="3"><?=$strpath." -> ".$group["name"]?></th></tr>
      </tbody>
      <tbody class="groupcontent">
<?php
    $inner=db_init()->prepare("select stations.* from stations_groups left join stations on stations_groups.station_id=stations.id where group_id=? order by type desc;");
    $inner->execute([$group["id"]]);
    $stations=$inner->fetchAll();
    if(sizeof($stations)!=0) {
      foreach($stations as $station) {
        $station["params"]=unserialize($station["params"]);
        $classes=["channel"];
        if($station["type"]=="tv")
          $symbol="&#128250; ";
        else if($station["type"]=="radio")
          $symbol="&#128251; ";
        else
          $symbol=" ";
        if($station["params"]["scrambled"]) {
          $classes[]="scrambled";
          $symbol.="&#128273; ";
        }
        
        $groups=getStationGroups($station["id"]);
?>
      <tr class="<?=implode(" ",$classes)?>" data-path="<?=$path?>" data-gid="<?=$group["id"]?>" data-sid="<?=$station["id"]?>">
        <td><?=$station["id"]?></td>
        <td><?=$station["name"]?><span class="symbol"><?=$symbol?></span></td>
        <td>
          <a href="act.php?act=play&amp;type=station&amp;id=<?=$station["id"]?>">play</a> &mdash;
<?php if(!in_array(8,$groups)) { ?>
          <a href="act.php?act=fav&amp;id=<?=$station["id"]?>">fav</a>
<?php } else { ?>
          <a href="act.php?act=unfav&amp;id=<?=$station["id"]?>">unfav</a>
<?php } ?>
        </td>
      </tr>
<?php
      }
    } else {
    }
?>
      </tbody>
<?php
    printChannelGroup($group["id"],"$path-".$group["id"],$level+1,$strpath." -> ".$group["name"]);
  }
}
?>
<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <title>simple media center</title>
    <meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no" />
    
    <style type="text/css">
.channellist {
  max-width:100%;
}
.channellist .channel td:nth-child(2) {
  position:relative;
  padding-right:40px;
}
.channellist .channel .symbol {
  position:absolute;
  right:0;
  top:0;
}
.channellist .channel.scrambled {
  background-color:red;
  color:#fff;
}
.channellist .channel:not(.scrambled):nth-child(odd) {
  background-color:#ccc;
}
.channellist .channel.scrambled:nth-child(odd) {
  background-color:#c00;
}
    </style>
  </head>
  <body>
    <h1>commands</h1>
    <ul>
      <li><a href="act.php?act=off">shutdown</a></li>
      <li><a href="act.php?act=terminate">terminate</a></li>
      <li><a href="act.php?act=voldn">vol dn</a></li>
      <li><a href="act.php?act=volup">vol up</a></li>
      <li>
        <form action="act.php" method="get">
          <input type="hidden" name="act" value="play" />
          <input type="hidden" name="type" value="stream" />
          <input type="text" name="stream" value="" placeholder="https://www.youtube.com/watch?v=wCkerYMffMo" />
          <input type="submit" value="play" />
        </form>
      </li>
    </ul>
    <h1>favorite channels</h1>
    <table class="channellist" id="favlist">
    <thead>
      <tr><th>id</th><th>name</th><th>action</th></tr>
    </thead>
<?php
  printChannelGroup(-1,"8");
?>
    </table>
    <h1>available stations</h1>
    <table class="channellist" id="channellist">
    <thead>
      <tr><th>id</th><th>name</th><th>action</th></tr>
    </thead>
<?php
  printChannelGroup();
?>
    </table>
  </body>
</html>