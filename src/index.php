<html>
<?php

require(__DIR__ . "/init.php");
global $db;

// Function to format traffic with selected unit
function traf_output($input, $unit = null) {
  $mb = round(($input/1024/1024),2);
  $gb = round(($input/1024/1024/1024),3);
  $tb = round(($input/1024/1024/1024/1024),4);

  // If a specific unit is requested, return only that unit
  if ($unit) {
    switch ($unit) {
      case 'MB':
        return $mb." <b>MB</b>";
      case 'GB':
        return $gb." <b>GB</b>";
      case 'TB':
        return $tb." <b>TB</b>";
      default:
        return $mb." <b>MB</b>";
    }
  }

  // Otherwise return all units (for backward compatibility)
  return $tb." <b>TB</b> &nbsp;&nbsp; ".$gb." <b>GB</b> &nbsp;&nbsp; ".$mb." <b>MB</b>";
}

// Get the selected unit from GET parameter or use default
$selected_unit = isset($_GET['unit']) ? $_GET['unit'] : 'GB';

if (isset($_GET['id']) and is_numeric($_GET['id'])) {
  //get device info
  $getDevice = $db->prepare('SELECT * FROM devices WHERE id = ?');
  $getDevice->bindValue(1, $_GET['id']);
  $result = $getDevice->execute();
  #print_r($device->fetchArray(SQLITE3_ASSOC));
  $device = $result->fetchArray(SQLITE3_ASSOC);

  // Add CSS for better styling
  echo '<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .card { 
      border: 1px solid #ddd; 
      border-radius: 8px; 
      padding: 15px; 
      margin-bottom: 20px; 
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .card-title { 
      font-size: 18px; 
      font-weight: bold; 
      margin-bottom: 10px; 
      border-bottom: 1px solid #eee;
      padding-bottom: 5px;
    }
    .stats-row {
      display: flex;
      flex-wrap: wrap;
      gap: 20px;
    }
    .form-group {
      margin-bottom: 15px;
    }
    select {
      padding: 5px;
      border-radius: 4px;
      border: 1px solid #ccc;
    }
    label {
      margin-right: 10px;
    }
  </style>';

  echo '<div class="card">';
  echo "<div class='card-title'>Device Information</div>";
  echo "<strong>Device Serial: ".$device['sn']."</strong> (".$device['comment'].")<br/>";
  echo "Last check time: ".$device['last_check']." <br/>";

  // Unit selector form
  echo '<div class="form-group">';
  echo '<form method="GET" action="" id="unitForm">';
  echo '<input type="hidden" name="id" value="'.$_GET['id'].'">';
  if (isset($_GET['period'])) {
    echo '<input type="hidden" name="period" value="'.$_GET['period'].'">';
  }
  echo '<label for="unit">Display units as: </label>';
  echo '<select name="unit" id="unit" onchange="document.getElementById(\'unitForm\').submit()">';
  $units = ['MB', 'GB', 'TB'];
  foreach ($units as $unit) {
    $selected = ($unit == $selected_unit) ? 'selected' : '';
    echo '<option value="'.$unit.'" '.$selected.'>'.$unit.'</option>';
  }
  echo '</select>';
  echo '</form>';
  echo '</div>';

  $last_tx = traf_output($device['last_tx'], $selected_unit);
  $last_rx = traf_output($device['last_rx'], $selected_unit);

  echo "Last results: <br/>&nbsp;&nbsp;
          TX: ".$last_tx."<br/>&nbsp;&nbsp;
          RX: ".$last_rx;
  echo '</div>';


  // Default to 48 hours if not specified
  $last_hours = isset($_GET['period']) ? intval($_GET['period']) : 48;

  // Period selection form
  echo '<div class="form-group">';
  echo '<form method="GET" action="">';
  echo '<input type="hidden" name="id" value="'.$_GET['id'].'">';
  if (isset($_GET['unit'])) {
    echo '<input type="hidden" name="unit" value="'.$_GET['unit'].'">';
  }
  echo '<label for="period">Select time period (hours): </label>';
  echo '<select name="period" id="period" onchange="this.form.submit()">';
  $periods = [12, 24, 48, 72, 168]; // 12h, 24h, 48h, 72h, 7d (168h)
  foreach ($periods as $period) {
    $selected = ($period == $last_hours) ? 'selected' : '';
    echo '<option value="'.$period.'" '.$selected.'>'.$period.' hours</option>';
  }
  echo '</select>';
  echo '</form>';
  echo '</div>';

  //get data for chart
  $getTraffic = $db->prepare("SELECT strftime('%Y-%m-%d %H:00:00', timestamp) AS hour,
                                     SUM(tx) AS total_tx,
                                     SUM(rx) AS total_rx
                              FROM traffic
                              WHERE device_id = ? AND timestamp >= ?
                              GROUP BY hour
                              ORDER by hour ASC"); // Changed to ASC for chronological display

  $getTraffic->bindValue(1, $_GET['id']);
  $date = new DateTime(); $date->modify("-".$last_hours." hours");
  $getTraffic->bindValue(2, $date->format("Y-m-d H:i:s"));
  $results = $getTraffic->execute();
  $chartData = '';

  // Determine divisor based on selected unit
  $divisor = 1024 * 1024; // Default to MB
  if ($selected_unit == 'GB') {
    $divisor = 1024 * 1024 * 1024;
  } else if ($selected_unit == 'TB') {
    $divisor = 1024 * 1024 * 1024 * 1024;
  }

  while ($res = $results->fetchArray(SQLITE3_ASSOC)){
    #$res = $results->fetchArray(SQLITE3_ASSOC);
    #print_r($res);
    if(!isset($res['hour'])) continue;
      // Format date for better display in chart
      $dateObj = new DateTime($res['hour']);
      // Use JavaScript Date format for better time-based filtering
      $chartData .= "[new Date('".date('Y-m-d H:i:s', strtotime($res['hour']))."'),".
                    round(($res['total_tx']/$divisor),2).",".
                    round(($res['total_rx']/$divisor),2)."],";
  }
  $results->finalize();

  ?>
    <head>
      <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
      <script type="text/javascript">
        google.charts.load('current', {'packages':['corechart', 'controls']});
        google.charts.setOnLoadCallback(drawChart);

        function drawChart() {
          var data = google.visualization.arrayToDataTable([
            ['Date/Time', 'TX (<?php echo $selected_unit; ?>)', 'RX (<?php echo $selected_unit; ?>)'],
            <?php echo $chartData; ?>
          ]);

          // Create a dashboard
          var dashboard = new google.visualization.Dashboard(
            document.getElementById('dashboard_div')
          );

          // Create a range slider
          var rangeSlider = new google.visualization.ControlWrapper({
            'controlType': 'ChartRangeFilter',
            'containerId': 'filter_div',
            'options': {
              'filterColumnIndex': 0,
              'ui': {
                'chartType': 'LineChart',
                'chartOptions': {
                  'chartArea': {'width': '90%'},
                  'hAxis': {'baselineColor': 'none'}
                },
                'chartView': {
                  'columns': [0, 1, 2]
                },
                'minRangeSize': 86400000 // 1 day in milliseconds
              }
            }
          });

          // Create a chart
          var chart = new google.visualization.ChartWrapper({
            'chartType': 'ComboChart',
            'containerId': 'chart_div',
            'options': {
              'title': 'Traffic Stats',
              'subtitle': 'Last <?php echo $last_hours; ?> hours',
              'chartArea': {'width': '90%', 'height': '80%'},
              'legend': {'position': 'top'},
              'seriesType': 'bars',
              'series': {
                0: {color: '#4285F4'},
                1: {color: '#DB4437'}
              },
              'hAxis': {
                'title': 'Date/Time'
              },
              'vAxis': {
                'title': 'Traffic (<?php echo $selected_unit; ?>)'
              },
              'explorer': {
                'actions': ['dragToZoom', 'rightClickToReset'],
                'axis': 'horizontal',
                'keepInBounds': true,
                'maxZoomIn': 0.01
              }
            }
          });

          // Bind the chart and range slider
          dashboard.bind(rangeSlider, chart);

          // Draw the dashboard
          dashboard.draw(data);
        }
      </script>
    </head>
    <body>
      <div class="card">
        <div class="card-title">Traffic Chart</div>
        <div id="dashboard_div" style="width: 100%;">
          <div id="chart_div" style="width: 100%; height: 400px;"></div>
          <div id="filter_div" style="width: 100%; height: 100px;"></div>
        </div>
      </div>
    </body>
    <hr/>
  <?php

  // Stats section with improved layout
  echo '<div class="stats-row">';

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
  echo '<div class="card" style="flex: 1;">';
  echo "<div class='card-title'>Daily Stats</div>";
  echo "From: ".date('Y-m-d 00:00:00')." to ".date('Y-m-d 23:59:59')."<br/>";
  echo "TX: ".traf_output($dailyTraffic['sumtx'], $selected_unit);
  echo "<br/>";
  echo "RX: ".traf_output($dailyTraffic['sumrx'], $selected_unit);
  echo "<br/>";
  echo "Total: ".traf_output($dailyTraffic['sumtx']+$dailyTraffic['sumrx'], $selected_unit);
  echo "</div>";

  //get weekly stats
  //getting Monday and Sunday dates for current week
  $today = new DateTime();
  $firstdayofweek = clone $today;
  $lastdayofweek = clone $today;

  // Set to first day of week (Monday)
  $firstdayofweek->modify('Monday this week');

  // Set to last day of week (Sunday)
  $lastdayofweek->modify('Sunday this week');

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
  echo '<div class="card" style="flex: 1;">';
  echo "<div class='card-title'>Weekly Stats</div>";
  echo "From: ".$firstdayofweek->format('Y-m-d 00:00:00')." to ".$lastdayofweek->format('Y-m-d 23:59:59')."<br/>";
  echo "TX: ".traf_output($weeklyTraffic['sumtx'], $selected_unit)."<br/>";
  echo "RX: ".traf_output($weeklyTraffic['sumrx'], $selected_unit)."<br/>";
  echo "Total: ".traf_output($weeklyTraffic['sumtx']+$weeklyTraffic['sumrx'], $selected_unit)."</br>";
  echo "</div>";

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
  echo '<div class="card" style="flex: 1;">';
  echo "<div class='card-title'>Monthly Stats</div>";
  echo "From: ".date('Y-m-01 00:00:00')." to ".date('Y-m-t 23:59:59')."<br/>";
  echo "TX: ".traf_output($monthlyTraffic['sumtx'], $selected_unit)."<br/>";
  echo "RX: ".traf_output($monthlyTraffic['sumrx'], $selected_unit)."<br/>";
  echo "Total: ".traf_output($monthlyTraffic['sumtx']+$monthlyTraffic['sumrx'], $selected_unit)."</br>";
  echo "</div>";

  $result->finalize();

  echo "</div>"; // Close stats-row

  //get totals
  $totals = $db->prepare('SELECT sum(tx) as sumtx, sum(rx) as sumrx FROM traffic WHERE device_id = ?');
  $totals->bindValue(1, $_GET['id']);
  $result = $totals->execute();
  $totalTraffic = $result->fetchArray(SQLITE3_ASSOC);
  echo '<div class="card">';
  echo "<div class='card-title'>Total Stats</div>";
  echo "TX: ".traf_output($totalTraffic['sumtx'], $selected_unit)."<br/>";
  echo "RX: ".traf_output($totalTraffic['sumrx'], $selected_unit)."<br/>";
  echo "Total: ".traf_output($totalTraffic['sumtx']+$totalTraffic['sumrx'], $selected_unit)."</br>";
  echo "</div>";

  $result->finalize();
}
else {
  $result = $db->query('SELECT * FROM devices');
  if(empty($result->fetchArray(SQLITE3_ASSOC))) {
    echo "No devices found.<br/>";
  }
  else {
    echo '<style>
      body { font-family: Arial, sans-serif; margin: 20px; }
      .device-list { 
        border: 1px solid #ddd; 
        border-radius: 8px; 
        padding: 15px; 
        margin-bottom: 20px; 
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
      }
      .device-item {
        padding: 10px;
        border-bottom: 1px solid #eee;
      }
      .device-item:last-child {
        border-bottom: none;
      }
    </style>';

    echo '<div class="device-list">';
    echo '<h2>Available Devices</h2>';
    $result = $db->query('SELECT * FROM devices');
    while ($device = $result->fetchArray(SQLITE3_ASSOC)){
      echo '<div class="device-item">';
      echo '<a href="?id='.$device['id'].'"><strong>'.$device['sn'].'</strong></a> ('.$device['comment'].') Last check: '.$device['last_check'].'<br/>';
      echo '</div>';
    }
    echo '</div>';
  }
  $result->finalize();
}
?>
</html>
