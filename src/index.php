<html>
<?php

require("init.php");

function traf_output($input) {
  $mb = round(($input/1024/1024),2);
  $gb = round(($input/1024/1024/1024),3);
  $tb = round(($input/1024/1024/1024/1024),4);
  return $tb." <b>TB</b> &nbsp;&nbsp; ".$gb." <b>GB</b> &nbsp;&nbsp; ".$mb." <b>MB</b>";
}

if (isset($_GET['id']) and is_numeric($_GET['id'])) {
  //get device info
  $getDevice = $db->prepare('SELECT * FROM devices WHERE id = ?');
  $getDevice->bindValue(1, $_GET['id']);
  $result = $getDevice->execute();
  #print_r($device->fetchArray(SQLITE3_ASSOC));
  $device = $result->fetchArray(SQLITE3_ASSOC);
  echo "<strong>Device Serial: ".$device['sn']."</strong> (".$device['comment'].")<br/>";
  echo "Last check time: ".$device['last_check']." <br/>";

  $last_tx = traf_output($device['last_tx']);
  $last_rx = traf_output($device['last_rx']);

  echo "Last results: <br/>&nbsp;&nbsp;
          TX: ".$last_tx."<br/>&nbsp;&nbsp;
          RX: ".$last_rx;
  echo "<br/><hr/>";


  $last_hours = 48;
  //get data for chart
  $getTraffic = $db->prepare("SELECT strftime('%Y-%m-%d %H:00:00', timestamp) AS hour,
                                     SUM(tx) AS total_tx,
                                     SUM(rx) AS total_rx
                              FROM traffic
                              WHERE device_id = ? AND timestamp >= ?
                              GROUP BY hour
                              ORDER by hour DESC");

  $getTraffic->bindValue(1, $_GET['id']);
  $date = new DateTime(); $date->modify("-".$last_hours." hours");
  $getTraffic->bindValue(2, $date->format("Y-m-d H:i:s"));
  $results = $getTraffic->execute();
  $chartData = '';
  while ($res = $results->fetchArray(SQLITE3_ASSOC)){
    #$res = $results->fetchArray(SQLITE3_ASSOC);
    #print_r($res);
    if(!isset($res['hour'])) continue;
      //set to Google Chart data format
      $chartData .= "['".date('d M H:i', strtotime($res['hour']))."',".round(($res['total_tx']/1024/1024/1024),2).",".round(($res['total_rx']/1024/1024/1024),2)."],";
  }
  $results->finalize();

  ?>
    <head>
      <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
      <script type="text/javascript">
        google.charts.load('current', {'packages':['bar']});
        google.charts.setOnLoadCallback(drawChart);

        function drawChart() {
          var data = google.visualization.arrayToDataTable([
            ['Date/Time', 'TX (GB)', 'RX (GB)'],
            <?php echo $chartData; ?>
          ]);

          var options = {
            chart: {
              title: 'Traffic Stats',
              subtitle: 'Last 48 hours',
            }
          };

          var chart = new google.charts.Bar(document.getElementById('columnchart_material'));

          chart.draw(data, google.charts.Bar.convertOptions(options));
        }
      </script>
    </head>
    <body>
      <div id="columnchart_material" style="width: 100%; height: 500px;"></div>
    </body>
    <hr/>
  <?php

  //get summary stats

  //get daily stats
  //query the db
  $daily = $db->prepare('SELECT sum(tx) as sumtx, sum(rx) as sumrx FROM traffic WHERE device_id = ? AND timestamp >= ? AND timestamp <= ?');
  $daily->bindValue(1, $_GET['id']);
  $daily->bindValue(2, date('Y-m-d 00:00:00'));
  $daily->bindValue(3, date('Y-m-d 23:59:59'));
  $result = $daily->execute();
  #print_r($result->fetchArray(SQLITE3_ASSOC));
  $dailyTraffic = $result->fetchArray(SQLITE3_ASSOC);
  //display results
  echo "<strong>Daily Stats</strong><br/>";
  echo "From: ".date('Y-m-d 00:00:00')." to ".date('Y-m-d 23:59:59')."<br/>";
  echo "TX: ".traf_output($dailyTraffic['sumtx']);
  echo "<br/>";
  echo "RX: ".traf_output($dailyTraffic['sumrx']);
  echo "<br/>";
  echo "Total: ".traf_output($dailyTraffic['sumtx']+$dailyTraffic['sumrx']);
  echo "<br/><br/>";

  //get weekly stats
  //getting sunday and saturday dates for current week
  $today = new DateTime();
  $currentWeekDay = $today->format('w');
  $firstdayofweek = clone $today;
  $lastdayofweek = clone $today;

  ($currentWeekDay != '0')?$firstdayofweek->modify('last Monday'):'';
  ($currentWeekDay != '6')?$lastdayofweek->modify('next Sunday'):'';

  #echo $firstdayofweek->format('Y-m-d 00:00:00').' to '.$lastdayofweek->format('Y-m-d 23:59:59');

  //query the db
  $weekly = $db->prepare('SELECT sum(tx) as sumtx, sum(rx) as sumrx FROM traffic WHERE device_id = ? AND timestamp >= ? AND timestamp <= ?');
  $weekly->bindValue(1, $_GET['id']);
  $weekly->bindValue(2, $firstdayofweek->format('Y-m-d 00:00:00'));
  $weekly->bindValue(3, $lastdayofweek->format('Y-m-d 23:59:59'));
  $result = $weekly->execute();
  #print_r($weeklyTraffic->fetchArray(SQLITE3_ASSOC));
  $weeklyTraffic = $result->fetchArray(SQLITE3_ASSOC);
  //display results
  echo "<strong>Weekly Stats</strong><br/>";
  echo "From: ".$firstdayofweek->format('Y-m-d 00:00:00')." to ".$lastdayofweek->format('Y-m-d 23:59:59')."<br/>";
  echo "TX: ".traf_output($weeklyTraffic['sumtx'])."<br/>";
  echo "RX: ".traf_output($weeklyTraffic['sumrx'])."<br/>";
  echo "Total: ".traf_output($weeklyTraffic['sumtx']+$weeklyTraffic['sumrx'])."</br>";
  echo "<br/>";

  //get monthly stats
  //query the db
  $monthly = $db->prepare('SELECT sum(tx) as sumtx, sum(rx) as sumrx FROM traffic WHERE device_id = ? AND timestamp >= ? AND timestamp <= ?');
  $monthly->bindValue(1, $_GET['id']);
  $monthly->bindValue(2, date('Y-m-01 00:00:00'));
  $monthly->bindValue(3, date('Y-m-t 23:59:59'));
  $result = $monthly->execute();
  #print_r($monthlyTraffic->fetchArray(SQLITE3_ASSOC));
  $monthlyTraffic = $result->fetchArray(SQLITE3_ASSOC);
  //display results
  echo "<strong>Monthly Stats</strong><br/>";
  echo "From: ".date('Y-m-01 00:00:00')." to ".date('Y-m-t 23:59:59')."<br/>";
  echo "TX: ".traf_output($monthlyTraffic['sumtx'])."<br/>";
  echo "RX: ".traf_output($monthlyTraffic['sumrx'])."<br/>";
  echo "Total: ".traf_output($monthlyTraffic['sumtx']+$monthlyTraffic['sumrx'])."</br>";
  echo "<br/>";

  $result->finalize();

  echo "<hr/>";

  //get totals
  $totals = $db->prepare('SELECT sum(tx) as sumtx, sum(rx) as sumrx FROM traffic WHERE device_id = ?');
  $totals->bindValue(1, $_GET['id']);
  $result = $totals->execute();
  $totalTraffic = $result->fetchArray(SQLITE3_ASSOC);
  echo "<strong>Total Stats</strong><br/>";
  echo "TX: ".traf_output($totalTraffic['sumtx'])."<br/>";
  echo "RX: ".traf_output($totalTraffic['sumrx'])."<br/>";
  echo "Total: ".traf_output($totalTraffic['sumtx']+$totalTraffic['sumrx'])."</br>";
  echo "<br/>";

  $result->finalize();
}
else {
  $result = $db->query('SELECT * FROM devices');
  if(empty($result->fetchArray(SQLITE3_ASSOC))) {
    echo "No devices found.<br/>";
  }
  else {
    $result = $db->query('SELECT * FROM devices');
    while ($device = $result->fetchArray(SQLITE3_ASSOC)){
      echo '<a href="?id='.$device['id'].'"><strong>'.$device['sn'].'</strong></a> ('.$device['comment'].') Last check: '.$device['last_check'].'<br/>';
    }
  }
  $result->finalize();
}
?>
</html>
