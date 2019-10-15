<?php
require 'startup.php';

function sec_to_time($s, $off = 0) {
  $t = round($s) + $off*3600;
  return sprintf('%02d:%02d:%02d', (($t)/3600),(($t)/60%60), ($t)%60);
}

function get_json($link, $tasPk, $refPk=NULL) {
    #check if we request a specific json, or the visible one
    if ($refPk != NULL) {
        $query = "  SELECT `refJSON`
                    FROM `tblResultFile`
                    WHERE `refPk` = $refPk
                    LIMIT 1";
    }
    else {
        $query = "  SELECT `refJSON`
                    FROM `tblResultFile`
                    WHERE `tasPk` = $tasPk
                    AND `refVisible` = 1
                    LIMIT 1";
    }
    $result = mysqli_query($link, $query) or die('Error ' . mysqli_errno($link) . ' Cannot get JSON file: ' . mysqli_connect_error());
    $file = mysqli_result($result,0,0);
    return $file;
}

// Main Code Begins HERE //

$comPk = intval($_REQUEST['comPk']);
$tasPk = intval($_REQUEST['tasPk']);
$refPk = intval($_REQUEST['refPk']);
$link = db_connect();
$file = __FILE__;

$cval = (reqexists('class') ? reqsval('class') : '');

$html = '';

$file = get_json($link, $tasPk, $refPk);
//print "file: ".$file;

if ( isset($file) && !($file == NULL) ) {
    $json = file_get_contents($file);
    $array = json_decode($json, true);
    $rnd = 1;

    // print_r($array);
    $info       = $array['info'];
    $jsondata   = $array['data'];
    $formula    = $array['formula'];
    $rankings   = $array['rankings'];
    $stats      = $array['stats'];
    $task       = $array['task'];
    $results    = $array['results'];

    $date = date_format(date_create_from_format('Y-m-d', $info['task_date']), 'D d M Y');
    $offset     = $info['time_offset'];
    $ssDist     = $info['SS_distance'];
    $start      = $info['SS_time'];
    $deadline   = $info['task_deadline'];
    $stopped_time = $stats['task_stopped_time'];
    $altitude_bonus = $formula['height_bonus'];
    $created = gmdate('D d M Y H:i:s', ($jsondata['timestamp'] + $offset*3600));

    #order wpt array
    $order = [];
    foreach ($task as $key => $wpt) {
        $order[$key] = $wpt['number'];
    }
    array_multisort($order,SORT_NUMERIC,$task);

    #order results array
    $order = [];
    foreach ($results as $key => $row) {
        $order[$key] = $row['score'];
    }
    array_multisort($order,SORT_NUMERIC,SORT_DESC,$results);

    ## Title Table ##
    $html .= "<table class='result_header_table'>".PHP_EOL;
    $html .= "<tr>".PHP_EOL;
    $html .= "<td class='result_comp_name'>".$info['comp_name']."</td>".PHP_EOL;
    $html .= "</tr><tr>".PHP_EOL;
    $html .= "<td class='result_task_name'>".$info['task_name'].", ".$date."</td>".PHP_EOL;
    $html .= "</tr><tr>".PHP_EOL;
    $html .= "<td class='result_status'>".$jsondata['status']."</td>".PHP_EOL;
    $html .= "</tr>".PHP_EOL;
    $html .= "</table>".PHP_EOL;

    $html .= "<hr>";

    ## Task Table ##
    $html .= "<table class='result_task_table'>".PHP_EOL;
    $html .= "<tr>".PHP_EOL;
    $html .= "<td>ID</td><td>Type</td><td>Radius</td><td>Distance</td><td>Description</td>".PHP_EOL;
    $html .= "</tr><tr>".PHP_EOL;
    foreach ($task as $wpt) {
        $type = '';
        $description = $wpt['description'];
        #check if wpt description has XContest ID and removes it
        if ( is_numeric(substr($description, -4)) ){
            $description = substr($description, 0, -5);
        }
        switch($wpt['type']) {
            case 'launch':
                $type = 'TakeOff';
                break;
            case 'speed':
                $type = ($wpt['how']=='entry' ? 'SS' : 'SS (exit)');
                break;
            case 'endspeed':
                $type = ($wpt['how']=='entry' ? 'ESS' : 'ESS (exit)');
                break;
            case 'goal':
                $type = ($wpt['shape']=='circle' ? 'Goal' : 'Goal Line');
                break;
            default:
                ($wpt['how']=='entry' ? '' : '(exit)');
        }
        $html .= "      <td>".$wpt['ID']."</td>".PHP_EOL;
        $html .= "      <td>".$type."</td>".PHP_EOL;
        $html .= "      <td>".($type=='TakeOff' ? '' : ($wpt['radius'].' m.'))."</td>".PHP_EOL;
        $html .= "      <td>".($type=='TakeOff' ? '' : round($wpt['cumulative_dist']/1000, 2).' Km'). "</td>".PHP_EOL;
        $html .= "      <td>".$description. "</td>".PHP_EOL;
        $html .= "</tr><tr>".PHP_EOL;
    }

    $html .= "<td colspan='5'>Start Gate Time: ".$start;
    $html .= ($info['SS_close_time']>0 ? (" to ".$info['SS_close_time']) : '')."</td>".PHP_EOL;

    if ( $info['SS_interval'] > 0 ) {
        $html .= "</tr><tr>".PHP_EOL;
        $html .= "<td colspan='5'>Interval: ".$info['SS_interval']." min.</td>".PHP_EOL;
    }
    if ( isset($stopped_time) ) {
        $html .= "</tr><tr>".PHP_EOL;
        $html .= "<td colspan='5' style='color:red;'>Task Stopped at: ".$stopped_time." </td>".PHP_EOL;
    }
    elseif ( $deadline > 0 ) {
        $html .= "</tr><tr>".PHP_EOL;
        $html .= "<td colspan='5'>Task Deadline: ".$deadline." </td>".PHP_EOL;
    }

    $html .= "</tr>".PHP_EOL;
    $html .= "</table>".PHP_EOL;

    $html .= "<hr>";

    ## Rankings switch ##
    $html .= class_selector($link, $rankings, $cval);

    ## Results Table ##

    # create dep. points header
    if ($formula['departure'] == 'leadout')
    {
        $depcol = 'LO P';
    }
    elseif ($formula['departure'] == 'kmbonus')
    {
        $depcol = 'Lkm';
    }
    elseif ($formula['departure'] == 'on')
    {
        $depcol = 'Dep P';
    }
    else
    {
        $depcol = 'off';
    }

    $trtab = [];

    $header = array("Rank", "Name", "Nat", "Glider");
    $header[] = "Sponsor";
    $header[] = "Start";
    $header[] = "Finish";
    $header[] = "Time";
    $header[] = "Speed";
    if (isset($stopped_time))
    {
        $goalalt = $stats['goal_altitude'];
        $header[] = "Height";
    }
    if ($tasHeightBonus == 'on')
    {
        $header[] = "HBs";
    }
    $header[] = "Distance";

    $header[] = "Spd P";
    if ($depcol != 'off')
    {
        $header[] = $depcol;
    }
    if ($formula['arrival'] == 'on')
    {
        $header[] = "Arr P";
    }
    // $header[] = fb("Spd");
    $header[] = "Dst P";
    $header[] = "Score";
    $trtab[] = $header;
    $count = 1;

    foreach ($results as $row)
    {
        #check if we have filtered ranking
        #team ranking to be implemented
        if ( ($cval == '') || ($cval=='Female' && $row['sex']=='F') || ($cval != null && is_array($rankings[$cval]) && array_search($row['class'],$rankings[$cval]) != NULL) ) {
            $track_id = $row['track_id'];
            $name = str_replace('\' ', '\'', ucwords(str_replace('\'', '\' ', strtolower($row['name']))));
            $nation = $row['nat'];
            $glider = ( (stripos($row['glider'], 'Unknown') !== false) ? '' : htmlspecialchars(str_replace('\' ', '\'', ucwords(str_replace('\'', '\' ', strtolower(substr($row['glider'], 0, 25)))))) );
            $sponsor = isset($row['sponsor']) ? htmlspecialchars(str_replace('\' ', '\'', ucwords(str_replace('\'', '\' ', strtolower(substr($row['sponsor'], 0, 40)))))) : '';
            $class = $row['class'];
            $resulttype = strtoupper($row['type']);

            # Check if pilot did fly
            if ( $resulttype == 'ABS' || $resulttype == 'DNF' )
            {
            	$trrow = array($place, $name, $nation);
        		$trrow[] = $glider;
        		$trrow[] = $sponsor;
        		array_push($trrow, '', '', '', '');
        		$trrow[] = '';
        		array_push($trrow, '', '', '', $resulttype);
            }
            else
            {
        		$dist = round($row['dist_points'], $rnd);
        		$dep = round($row['dep_points'], $rnd);
        		$arr = round($row['arr_points'], $rnd);
        		$spd = round($row['speed_points'], $rnd);
        		$score = round($row['score'], $rnd);
        		$lastalt = round($row['altitude']);
        		$resulttype = $row['type'];
        		$comment = $row['comment'];
        		$start = $row['SS_time'];
        		$end = $row['ES_time'];
        		$goal = $row['goal_time'];
        		$endf = "";
        		$startf = "";
        		$timeinair = "";
                $startf = sec_to_time($start, $offset);
        		if ($end)
        		{
                    $timeinair = sec_to_time($end - $start);
                    $endf = sec_to_time($end, $offset);
        			$speed = round($ssDist * 3.6 / ($end - $start), 2);
        		}
        		else
        		{
        			$timeinair = "";
        			$speed = "";
        		}
        		$time = ($end - $start) / 60;
        		$tardist = round($row['distance']/1000,2);
        		$penalty = round($row['penalty']);
        		if (0 + $tardist == 0)
        		{
        			$tardist = $resulttype;
        		}

        		if ($lastscore != $score)
        		{
        			$place = "$count";
        		}
        		else
        		{
        			$place = '';
        		}
        		$lastscore = $score;

        		if ($count % 2 == 0)
        		{
        			$class = "d";
        		}
        		else
        		{
        			$class = "l";
        		}

        		if ( $extComp != 1 )
        		{
        		    $trrow = array(fb($place), "<a href=\"tracklog_map.php?trackid=$track_id&tasPk=$tasPk&comPk=$comPk\">$name</a>", $nation );
        		}
        		else
        		{
        		    $trrow = array(fb($place), $name, $nation );
        		}

        		$trrow[] = $glider;
        		$trrow[] = $sponsor;

        		$trrow[] = $startf;
                #strikesout if pilot did not make goal
        		$trrow[] = ( $goal != 0 ? $endf : "<del>".$endf."</del>" );
        		$trrow[] = ( $goal != 0 ? $timeinair : "<del>".$timeinair."</del>" );
        		$trrow[] = ( $speed != 0 ? number_format((float)$speed,2) : "" );
        		if ( isset($stopped_time) )
        		{
        			$alt = ( $lastalt - $goalalt >= 0 ? "+".($lastalt - $goalalt) : "");
        			#strikesout if pilot made ESS
        			$trrow[] = ( !$end ? $alt : "<del>".$alt."</del>" );
        		}
        		if ($altitude_bonus == 'on')
        		{
        			if ($lastalt > 0)
        			{
        				$habove = $lastalt - $goalalt;
        				if ($habove > 400)
        				{
        					$habove = 400;
        				}
        				if ($habove > 50)
        				{
        					$trrow[] = round(20.0*pow(($habove-50.0),0.40));
        				}
        				else
        				{
        					$trrow[] = 0;
        				}
        			}
        			else
        			{
        				$trrow[] = '';
        			}
        		}
        		$trrow[] = number_format((float)$tardist,2);
        		if ( $totalPenalty != 0 )
        		{
        			$trrow[] = $penalty;
        		}

        		$trrow[] = number_format($spd,1);
        		if ($depcol != 'off')
        		{
        			$trrow[] = number_format($dep,1);
        		}
        		if ($formula['arrival'] == 'on')
        		{
        			$trrow[] = $arr;
        		}
        	//     $trrow[] = number_format($spd,1);
        		$trrow[] = number_format($dist,1);
        		$trrow[] = round($score);
            }

            $trtab[] = $trrow;

            $count++;
        }
    }

    $html .= ftable($trtab, "class='format taskresult'", '' , '');

    ## STATS TABLE ##

    #create task statistic table
    $sttbl = '';
    $sttbl .= "<table class='result_stats'>".PHP_EOL;
    $sttbl .= "<th><td colspan='2'><h3>Task Statistics</h3></td></th>".PHP_EOL;
    $sttbl .= "<tr><td class='result key'>Partecipants:</td><td class='result value'>".$stats['pilots_present']."</td></tr>".PHP_EOL;
    $sttbl .= "<tr><td class='result key'>Pilots Flying:</td><td class='result value'>".$stats['pilots_flying']."</td></tr>".PHP_EOL;
    $sttbl .= "<tr><td class='result key'>Pilots in Goal:</td><td class='result value'>".$stats['pilots_goal']."</td></tr>".PHP_EOL;
    $sttbl .= "<tr><td class='result key'>Best Distance:</td><td class='result value'>".round($stats['max_distance']/1000, 2)." Km </td></tr>".PHP_EOL;
    $sttbl .= "<tr><td class='result key'>Total Dist. Flown:</td><td class='result value'>".round($stats['tot_dist_flown']/1000, 2)." Km </td></tr>".PHP_EOL;
    if ($stats['pilots_es'] > 0) {
        $sttbl .= "<tr><td class='result key'>Best Time:</td><td class='result value'>".sec_to_time($stats['fastest_time'])."</td></tr>".PHP_EOL;
    }
    $sttbl .= "</table>".PHP_EOL;

    #create task formula table
    $fortbl = '';
    $fortbl .= "<table class='result_stats'>".PHP_EOL;
    $fortbl .= "<th><td colspan='2'><h3>Task Parameters</h3></td></th>".PHP_EOL;
    $fortbl .= "<tr><td class='result key'>Formula:</td><td class='result value'>".$formula['formula_name']."</td></tr>".PHP_EOL;
    $fortbl .= "<tr><td class='result key'>Nom. Distance:</td><td class='result value'>".$formula['nominal_dist']." Km</td></tr>".PHP_EOL;
    $fortbl .= "<tr><td class='result key'>Nom. Time:</td><td class='result value'>".sec_to_time($formula['nominal_time']*60)."</td></tr>".PHP_EOL;
    $fortbl .= "<tr><td class='result key'>Nom. Launch:</td><td class='result value'>".($formula['nominal_launch']*100)."%</td></tr>".PHP_EOL;
    $fortbl .= "<tr><td class='result key'>Nom. Goal:</td><td class='result value'>".($formula['nominal_goal']*100)."%</td></tr>".PHP_EOL;
    $fortbl .= "<tr><td class='result key'>Min. Distance:</td><td class='result value'>".$formula['min_dist']." Km</td></tr>".PHP_EOL;
    $fortbl .= "<tr><td class='result key'>Tolerance:</td><td class='result value'>".$formula['tolerance']."%</td></tr>".PHP_EOL;
    if ($stopped_time != null) {
        $fortbl .= "<tr><td class='result key'>Score back Time:</td><td class='result value'>".$formula['score_back_time']." min.</td></tr>".PHP_EOL;
        $fortbl .= "<tr><td class='result key'>Glide Bonus:</td><td class='result value'>1:".round($formula['glide_bonus'],1)."</td></tr>".PHP_EOL;
    }
    $fortbl .= "</table>".PHP_EOL;

    #create task validity table
    $valtbl = '';
    $valtbl .= "<table class='result_stats'>".PHP_EOL;
    $valtbl .= "<th><td colspan='2'><h3>Task Validity</h3></td></th>".PHP_EOL;
    $valtbl .= "<tr><td class='result key'>Launch Validity:</td><td class='result value'>".round($stats['launch_validity'],3)."</td></tr>".PHP_EOL;
    $valtbl .= "<tr><td class='result key'>Distance Validity:</td><td class='result value'>".round($stats['dist_validity'],3)."</td></tr>".PHP_EOL;
    $valtbl .= "<tr><td class='result key'>Time Validity:</td><td class='result value'>".round($stats['time_validity'],3)."</td></tr>".PHP_EOL;
    if ($stopped_time != null) {
        $valtbl .= "<tr><td class='result key'>Stop Validity:</td><td class='result value'>".round($stats['stop_validity'],3)."</td></tr>".PHP_EOL;
    }
    $valtbl .= "<tr><td class='result key'>Task Quality:</td><td class='result value'>".round($stats['day_quality'],3)."</td></tr>".PHP_EOL;
    $valtbl .= "</table>".PHP_EOL;

    #create avail. points table
    $pttbl = '';
    $pttbl .= "<table class='result_stats'>".PHP_EOL;
    $pttbl .= "<th><td colspan='2'><h3>Available Points</h3></td></th>".PHP_EOL;
    $pttbl .= "<tr><td class='result key'>Distance Points:</td><td class='result value'>".round($stats['avail_dist_points'],1)."</td></tr>".PHP_EOL;
    $pttbl .= "<tr><td class='result key'>Time Point:</td><td class='result value'>".round($stats['avail_time_points'],1)."</td></tr>".PHP_EOL;
    $pttbl .= "<tr><td class='result key'>Leading Points:</td><td class='result value'>".round($stats['avail_lead_points'],1)."</td></tr>".PHP_EOL;
    if ($formula['arrival'] != 'off') {
        $pttbl .= "<tr><td class='result key'>Arrival Points:</td><td class='result value'>".round($stats['avail_arr_points'],1)."</td></tr>".PHP_EOL;
        $arr_points = $stats['avail_arr_points'];
    }
    else {
        $arr_points = 0;
    }
    $pttbl .= "<tr><td class='result key'>Max. Task points:</td><td class='result value'>".round($stats['avail_dist_points']+$stats['avail_time_points']+$stats['avail_lead_points']+$arr_points,3)."</td></tr>".PHP_EOL;
    $pttbl .= "<tr><td class='result key'>Best Score:</td><td class='result value'>".round($stats['max_score'],3)."</td></tr>".PHP_EOL;
    $pttbl .= "</table>".PHP_EOL;

    $html .= "<hr>".PHP_EOL;
    $html .= "<table>".PHP_EOL;
    $html .= "<tr><td>".PHP_EOL;
    $html .= $sttbl;
    $html .= "</td><td>".PHP_EOL;
    $html .= $fortbl;
    $html .= "</td><td>".PHP_EOL;
    $html .= $valtbl;
    $html .= "</td><td>".PHP_EOL;
    $html .= $pttbl;
    $html .= "</td></tr>".PHP_EOL;
    $html .= "</table>".PHP_EOL;

    $html .= "<hr>".PHP_EOL;
    $html .= "<p>created on ".$created."</p>";
}
else {
    $html .= "<p>Task has not been scored yet.<br /></p>";
}
echo $html;

echo "<p><a href='comp_result.php?comPk=$comPk'>Back to competition Result</a></p>";

?>
