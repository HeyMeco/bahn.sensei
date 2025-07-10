<?php

set_time_limit(0);

// GET https://www.bahn.de/web/api/reiseloesung/orte?suchbegriff=m%C3%BCn&typ=ALL&limit=10

function getDayOfWeek($day) {
	
	$weekdays = array(
		1 => "Mo",
		2 => "Di",
		3 => "Mi",
		4 => "Do",
		5 => "Fr",
		6 => "Sa",
		7 => "So",
	);

	return $weekdays[$day];
	
}

function searchBahnhof($search) {
	
	if ($search == "")
		return false;

	$search = urlencode($search);
	$url = "https://www.bahn.de/web/api/reiseloesung/orte?suchbegriff=".$search."&typ=ALL&limit=10";

	$result = file_get_contents($url);

	$data = json_decode($result);
	
	//print_r($data);
	
	$id = $data[0]->id;
	
	//"A=1@O=München Hbf@X=11558339@Y=48140229@U=80@L=8000261@B=1@p=1751918251@i=U×008020347@"
	// manchmal enhält hier der p-Parameter unterschiedliche nummern - wtf?? macht caching kaputt
	// wenn man p-parameter komplett mit nullen füllt gehts noch.
	
	$tmp = "";
	parse_str (str_replace("@", "&", $id), $tmp);
	$tmp["p"] = str_repeat("0", strlen($tmp["p"]));
	$id = str_replace("&", "@", urldecode(http_build_query($tmp)))."@";	
	
	return $id;
	
}

function getAllTrains($config) {

	$jetzt = $config["anfrageZeitpunkt"];
	$datum = substr(date('c', $jetzt), 0, 19); // bahn mag keine zeitzonen info
	
	$tag = date("Y-m-d", $jetzt);

	$url = "https://www.bahn.de/web/api/angebote/tagesbestpreis";

	$request = 
'{
  "abfahrtsHalt": "'.$config["abfahrtsHalt"].'",
  "anfrageZeitpunkt": "'.$datum.'",
  "ankunftsHalt": "'.$config["ankunftsHalt"].'",
  "ankunftSuche": "ABFAHRT",
  "klasse": "'.$config["klasse"].'",
  "maxUmstiege": '.$config["maxUmstiege"].',
  "produktgattungen": [
    "ICE",
    "EC_IC",
    "IR",
    "REGIONAL",
    "SBAHN",
    "BUS",
    "SCHIFF",
    "UBAHN",
    "TRAM",
    "ANRUFPFLICHTIG"
  ],
  "reisende": [
    {
      "typ": "ERWACHSENER",
      "ermaessigungen": [
        {
          "art": "KEINE_ERMAESSIGUNG",
          "klasse": "KLASSENLOS"
        }
      ],
      "alter": [],
      "anzahl": 1
    }
  ],
  "schnelleVerbindungen": '.$config["schnelleVerbindungen"].',
  "sitzplatzOnly": false,
  "bikeCarriage": false,
  "reservierungsKontingenteVorhanden": false,
  "nurDeutschlandTicketVerbindungen": '.$config["nurDeutschlandTicketVerbindungen"].',
  "deutschlandTicketVorhanden": false
}';

	// cache init
	$cache_dir = "./cache/";
	if (!is_dir($cache_dir))
		mkdir($cache_dir);
		
	$cache_hash = md5($request);
	$cache_filename = $cache_dir.$cache_hash;
	$max_age = 60 * 60; // In Sekunden, maximal 1 Stunde cachen
	
	if (file_exists($cache_filename))
		$cache_age = filemtime($cache_filename);	
	else
		$cache_age = 0;
	
	$current_age = (time()-$cache_age);
	
	if (file_exists($cache_filename) && $current_age <= $max_age) {
			
		//load cached data
		$data = file_get_contents($cache_filename);
			
	} else {

		$json = $request;
		$payload = $json;
		
		$options = [
			'http' => [
				'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:137.0) Gecko/20100101 Firefox/137.0',
				'header' => 
'Accept: application/json
content-type: application/json; charset=utf-8
Accept-Encoding: gzip
Origin: https://www.bahn.de
Referer: https://www.bahn.de/buchung/fahrplan/suche
Connection: close',
				'method' => 'POST',
				'content' => $payload,
			],
		];

		$context = stream_context_create($options);
		$result = @file_get_contents($url, false, $context);
			
		if ($result === false) {
			return false; // kein array zurückgeben falls api fehler
		}
		if (!strpos($result, "Preisauskunft nicht möglich"))
			$data = gzdecode($result); // dekomprimieren
		else
			return array(); // falls für tag keine bestpreise verfügbar sind, leeres array zurückgeben

		// caching
		file_put_contents($cache_filename, $data);

	}

	$arr = json_decode($data);
	
	$verbindungen = array();
	
	foreach ($arr->intervalle as $iv) {
		
		if (property_exists($iv, "preis") && property_exists($iv->preis, "betrag")) {
			
			foreach ($iv->verbindungen as $verbindung) {
				$conn = $verbindung->verbindung;
				$ersteFahrt = $conn->verbindungsAbschnitte[0];
				
				$abfahrt = new DateTime($ersteFahrt->abfahrtsZeitpunkt);
				$ankunft = new DateTime($ersteFahrt->ankunftsZeitpunkt);
				$duration = $abfahrt->diff($ankunft);
				$duration_str = $duration->h . "h";
				if ($duration->i > 0) {
					$duration_str .= " " . $duration->i . "m";
				}
				
				$verbindungen[] = array(
					'preis' => $iv->preis->betrag,
					'abfahrtsZeitpunkt' => $ersteFahrt->abfahrtsZeitpunkt,
					'ankunftsZeitpunkt' => $ersteFahrt->ankunftsZeitpunkt,
					'abfahrtsOrt' => $ersteFahrt->abfahrtsOrt,
					'ankunftsOrt' => $ersteFahrt->ankunftsOrt,
					'duration' => $duration_str,
					'umstiege' => count($conn->verbindungsAbschnitte) - 1
				);
			}
		}
	}
	
	// Sort by departure time
	usort($verbindungen, function($a, $b) {
		return strtotime($a['abfahrtsZeitpunkt']) - strtotime($b['abfahrtsZeitpunkt']);
	});
	
	return $verbindungen;
}

function getCheapestTrain($config) {

	$jetzt = $config["anfrageZeitpunkt"];
	$datum = substr(date('c', $jetzt), 0, 19); // bahn mag keine zeitzonen info
	
	$tag = date("Y-m-d", $jetzt);

	$url = "https://www.bahn.de/web/api/angebote/tagesbestpreis";

	//$json = '{"abfahrtsHalt":"A=1@O=München Hbf@X=11558339@Y=48140229@U=80@L=8000261@B=1@p=1749068087@i=U×008020347@","anfrageZeitpunkt":"[ZEITPUNKT]","ankunftsHalt":"A=1@O=Düsseldorf Hbf@X=6794317@Y=51219960@U=80@L=8000085@B=1@p=1749588501@i=U×008008094@","ankunftSuche":"ABFAHRT","klasse":"KLASSE_2","maxUmstiege":0,"produktgattungen":["ICE","EC_IC","IR","REGIONAL","SBAHN","BUS","SCHIFF","UBAHN","TRAM","ANRUFPFLICHTIG"],"reisende":[{"typ":"ERWACHSENER","ermaessigungen":[{"art":"KEINE_ERMAESSIGUNG","klasse":"KLASSENLOS"}],"alter":[],"anzahl":1}],"schnelleVerbindungen":true,"sitzplatzOnly":false,"bikeCarriage":false,"reservierungsKontingenteVorhanden":false,"nurDeutschlandTicketVerbindungen":false,"deutschlandTicketVorhanden":false}';
	
	$request = 
'{
  "abfahrtsHalt": "'.$config["abfahrtsHalt"].'",
  "anfrageZeitpunkt": "'.$datum.'",
  "ankunftsHalt": "'.$config["ankunftsHalt"].'",
  "ankunftSuche": "ABFAHRT",
  "klasse": "'.$config["klasse"].'",
  "maxUmstiege": '.$config["maxUmstiege"].',
  "produktgattungen": [
    "ICE",
    "EC_IC",
    "IR",
    "REGIONAL",
    "SBAHN",
    "BUS",
    "SCHIFF",
    "UBAHN",
    "TRAM",
    "ANRUFPFLICHTIG"
  ],
  "reisende": [
    {
      "typ": "ERWACHSENER",
      "ermaessigungen": [
        {
          "art": "KEINE_ERMAESSIGUNG",
          "klasse": "KLASSENLOS"
        }
      ],
      "alter": [],
      "anzahl": 1
    }
  ],
  "schnelleVerbindungen": '.$config["schnelleVerbindungen"].',
  "sitzplatzOnly": false,
  "bikeCarriage": false,
  "reservierungsKontingenteVorhanden": false,
  "nurDeutschlandTicketVerbindungen": '.$config["nurDeutschlandTicketVerbindungen"].',
  "deutschlandTicketVorhanden": false
}';

	// cache init
	$cache_dir = "./cache/";
	if (!is_dir($cache_dir))
		mkdir($cache_dir);
		
	$cache_hash = md5($request);
	$cache_filename = $cache_dir.$cache_hash;
	$max_age = 60 * 60; // In Sekunden, maximal 1 Stunde cachen
	
	if (file_exists($cache_filename))
		$cache_age = filemtime($cache_filename);	
	else
		$cache_age = 0;
	
	$current_age = (time()-$cache_age);
	
	if (file_exists($cache_filename) && $current_age <= $max_age) {
			
		//load cached data
		$data = file_get_contents($cache_filename);
			
	} else {

		$json = $request;
		$payload = $json;
		
		$options = [
			'http' => [
				'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:137.0) Gecko/20100101 Firefox/137.0',
				'header' => 
'Accept: application/json
content-type: application/json; charset=utf-8
Accept-Encoding: gzip
Origin: https://www.bahn.de
Referer: https://www.bahn.de/buchung/fahrplan/suche
Connection: close',
				'method' => 'POST',
				'content' => $payload,
			],
		];

		$context = stream_context_create($options);
		$result = @file_get_contents($url, false, $context);
			
		if ($result === false) {
			//print $tag.": fehler";
			return false; // kein array zurückgeben falls api fehler
		}
		if (!strpos($result, "Preisauskunft nicht möglich"))
			$data = gzdecode($result); // dekomprimieren
		else
			return array($tag => array('preis' => 0, 'info' => "Kein Bestpreis verfügbar!")); // falls für tag keine bestpreise verfügbar sind, 0€ zurückgeben

		// caching
		file_put_contents($cache_filename, $data);

	}

	$arr = json_decode($data);
	//print_r($arr);
	
	$preise = array();
	$infos = array();
	
	foreach ($arr->intervalle as $iv) {
		//print_r($iv);
		
		$newPreis = 0;
		
		if (property_exists($iv, "preis")) { //manchmal liefert die bahn keine preisinfo
			if (property_exists($iv->preis, "betrag")) {
				$newPreis = $iv->preis->betrag;
				
				$ersteFahrt = $iv->verbindungen[0]->verbindung->verbindungsAbschnitte[0];
				
				$abfahrt = date("d.m.Y H:i:s", strtotime($ersteFahrt->abfahrtsZeitpunkt));
				$ankunft = date("d.m.Y H:i:s", strtotime($ersteFahrt->ankunftsZeitpunkt));
				
				$info = $abfahrt." ".$ersteFahrt->abfahrtsOrt;				
				$info .= " -> ";
				$info .= $ankunft." ".$ersteFahrt->ankunftsOrt;				
				
			}
		}
				
		if ($newPreis != 0) {
			$preise[$info.$newPreis] = $newPreis;

		}


	}

	//print_r($preise);
	$minPreis = min($preise);
	$rv = array(
			$tag => array(
						'preis' => $minPreis, 
						'info' => str_replace($minPreis, "", array_search($minPreis, $preise)),
						'abfahrtsZeitpunkt' => $ersteFahrt->abfahrtsZeitpunkt,
						'ankunftsZeitpunkt' => $ersteFahrt->ankunftsZeitpunkt,
						)
				);
				
	//print_r($rv);

	return $rv;

}
?>
<html>
<head>
	<title>bahn.sensei</title>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width,initial-scale=1.0">		
	<link rel="stylesheet" href="https://cdn.simplecss.org/simple.min.css">
	<style>
		.calendar {
			display: grid;
			grid-template-columns: repeat(7, 1fr);
			gap: 1px;
			background-color: #ddd;
			margin: 20px 0;
			border-radius: 8px;
			overflow: hidden;
		}
		
		.calendar-header {
			background-color: #f5f5f5;
			padding: 15px 5px;
			text-align: center;
			font-weight: bold;
			color: #666;
		}
		
		.calendar-day {
			background-color: white;
			padding: 15px 10px;
			text-align: center;
			min-height: 80px;
			display: flex;
			flex-direction: column;
			justify-content: center;
			position: relative;
		}
		
		.calendar-day.weekend {
			background-color: #f9f9f9;
		}
		
		.calendar-day.other-month {
			color: #ccc;
			background-color: #f8f8f8;
		}
		
		.day-number {
			font-size: 0.9em;
			color: #666;
			margin-bottom: 5px;
		}
		
		.price {
			font-size: 1.4em;
			font-weight: bold;
			margin-bottom: 2px;
		}
		
		.price.cheap {
			color: #00a86b;
		}
		
		.price.expensive {
			color: #d32f2f;
		}
		
		.price.medium {
			color: #ff8f00;
		}
		
		.duration {
			font-size: 0.8em;
			color: #999;
		}
		
		.no-price {
			color: #ccc;
			font-size: 0.9em;
		}
		
		.calendar-day a {
			text-decoration: none;
			color: inherit;
		}
		
		.calendar-day a:hover {
			text-decoration: underline;
		}
		
		.nav-button {
			text-decoration: none;
			font-size: 1.8em;
			color: #666;
			padding: 10px 15px;
			border-radius: 50%;
			transition: all 0.2s ease;
			user-select: none;
		}
		
		.nav-button:hover {
			background-color: #f0f0f0;
			color: #333;
		}
		
		.calendar-nav {
			display: flex;
			align-items: center;
			justify-content: space-between;
			margin: 20px 0 10px 0;
			background: white;
			padding: 10px 15px;
			border-radius: 8px;
			box-shadow: 0 1px 3px rgba(0,0,0,0.1);
		}
		
		.calendar-nav h3 {
			margin: 0;
			text-align: center;
			flex-grow: 1;
			color: #333;
		}
		
		.day-number {
			cursor: pointer;
			border-radius: 3px;
			padding: 2px 4px;
			transition: background-color 0.2s ease;
		}
		
		.day-number:hover {
			background-color: #e3f2fd;
		}
		
		.day-details {
			margin-top: 20px;
			background: white;
			border-radius: 8px;
			box-shadow: 0 2px 10px rgba(0,0,0,0.1);
			overflow: hidden;
			display: none;
		}
		
		.day-details-header {
			background: #f5f5f5;
			padding: 15px 20px;
			border-bottom: 1px solid #e0e0e0;
		}
		
		.day-details-header h4 {
			margin: 0;
			color: #333;
		}
		
		.train-connection {
			padding: 15px 20px;
			border-bottom: 1px solid #f0f0f0;
			display: flex;
			justify-content: space-between;
			align-items: center;
			transition: background-color 0.2s ease;
		}
		
		.train-connection:hover {
			background-color: #fafafa;
		}
		
		.train-connection:last-child {
			border-bottom: none;
		}
		
		.train-time {
			display: flex;
			flex-direction: column;
			min-width: 120px;
		}
		
		.train-route {
			flex-grow: 1;
			margin: 0 20px;
		}
		
		.train-price {
			font-size: 1.2em;
			font-weight: bold;
			color: #2e7d32;
			min-width: 80px;
			text-align: right;
		}
		
		.train-departure {
			font-size: 1.1em;
			font-weight: bold;
			color: #333;
		}
		
		.train-arrival {
			font-size: 0.9em;
			color: #666;
		}
		
		.train-duration {
			font-size: 0.8em;
			color: #999;
		}
		
		.train-transfers {
			font-size: 0.8em;
			color: #666;
			margin-top: 2px;
		}
		
		.close-details {
			background: none;
			border: none;
			font-size: 1.5em;
			cursor: pointer;
			color: #666;
			padding: 5px;
			border-radius: 3px;
		}
		
		.close-details:hover {
			background-color: #f0f0f0;
		}
		
		@media (max-width: 768px) {
			.calendar {
				font-size: 0.8em;
			}
			.calendar-day {
				min-height: 60px;
				padding: 8px 5px;
			}
			.nav-button {
				font-size: 1.5em;
				padding: 8px 12px;
			}
			.calendar-nav {
				padding: 8px 10px;
			}
			.calendar-nav h3 {
				font-size: 1.2em;
			}
			.train-connection {
				flex-direction: column;
				align-items: stretch;
				padding: 12px 15px;
			}
			.train-time {
				min-width: auto;
				margin-bottom: 8px;
			}
			.train-route {
				margin: 8px 0;
			}
			.train-price {
				text-align: left;
				margin-top: 8px;
			}
			.day-details-header {
				padding: 12px 15px;
			}
		}
	</style>
</head>
<body>
	<h1 style="margin-bottom:0px;"><a href="?">bahn.sensei</a></h1>
	<i>Findet die günstigste Bahnreise für jeden Tag des Monats.</i>
	<section style="margin-top:0px;">
		<form action="" method="get">
		  <p>
			<label>Start</label>
			<input type="text" id="start" name="start" placeholder="München Hbf" value="<?php print (isset($_GET["start"]) ? $_GET["start"] : ""); ?>">
			<button type="button" onclick="javascript:SwitchOrt();">⇆</button>
			<label>Ziel</label>
			<input type="text" id="ziel" name="ziel" placeholder="Düsseldorf Hbf" value="<?php print (isset($_GET["ziel"]) ? $_GET["ziel"] : ""); ?>">			
		  </p>
		  
		  <p>
			<label>Hinfahrt ab</label>
			<input type="date" id="abfahrtab" name="abfahrtab" value="<?php print (isset($_GET["abfahrtab"]) ? $_GET["abfahrtab"] : date("Y-m-d")); ?>">
		  </p>
		
		
		  <p>
		  <label><input name="klasse" type="radio" value="KLASSE_1" <?php print (isset($_GET["klasse"]) && $_GET["klasse"] == "KLASSE_1" ? "checked" : ""); ?>/>1. Klasse</label> 
		  <label><input name="klasse" type="radio" value="KLASSE_2" <?php print ((isset($_GET["klasse"]) && $_GET["klasse"] == "KLASSE_2") || !isset($_GET["klasse"]) ? "checked" : ""); ?>/>2. Klasse</label> 
		  </p>


		  <p>
			  <label>
				  <input type="checkbox" name="schnelleVerbindungen" value="1" <?php print (isset($_GET["schnelleVerbindungen"]) && $_GET["schnelleVerbindungen"] == "1" ? "checked" : ""); ?>>
				  Schnellste Verbindungen anzeigen
			  </label>
			  
			  <label>
				  <input type="checkbox" name="nurDeutschlandTicketVerbindungen" value="1" <?php print (isset($_GET["nurDeutschlandTicketVerbindungen"]) && $_GET["nurDeutschlandTicketVerbindungen"] == "1" ? "checked" : ""); ?>>
				  Nur Deutschland-Ticket-Verbindungen
			  </label>
			  
			  <label>Maximale Umstiege</label>
			  <input type="number" name="maximaleUmstiege" value="<?php print (isset($_GET["maximaleUmstiege"]) ? $_GET["maximaleUmstiege"] : "0"); ?>">				  
			  
		  </p>

		  <button type="submit">Suchen</button>
		  <button type="reset">Zurücksetzen</button><br/>
		  (Verarbeitungszeit bis zu 60 Sekunden+)
		</form>	
	</section>
	<section>
<?php

if (isset($_GET["start"]) && $_GET["start"] != "" && isset($_GET["ziel"]) && $_GET["ziel"] != "") {

	$ergebnisse = array();

	$start = searchBahnhof($_GET["start"]);
	$ziel = searchBahnhof($_GET["ziel"]);

	// Liste aller Tage im Monat

	// Check if month navigation is used
	if (isset($_GET["nav_year"]) && isset($_GET["nav_month"])) {
		$year = $_GET["nav_year"];
		$month = str_pad($_GET["nav_month"], 2, "0", STR_PAD_LEFT);
		$start_date = date_create($year."-".$month."-01");
	} else {
		//$start_date = date_create($year."-".$month."-"."01");
		$start_date = date_create($_GET["abfahrtab"]);
		
		$year = $start_date->format("Y");
		$month = $start_date->format("m");
	}
	
	$end_date = date_create($year."-".($month+1)."-"."01");
	//$end_date = date_create($year."-".$month."-"."10");

	$interval = DateInterval::createFromDateString('1 day');
	$daterange = new DatePeriod($start_date, $interval ,$end_date);

	// Daten pro Tag abrufen

	$config = array(
		"abfahrtsHalt" => $start,
		"ankunftsHalt" => $ziel,
		"klasse" => $_GET["klasse"],
		"maxUmstiege" => $_GET["maximaleUmstiege"],
		"schnelleVerbindungen" => (isset($_GET["schnelleVerbindungen"]) && $_GET["schnelleVerbindungen"] == "1" ? "true" : "false"),
		"nurDeutschlandTicketVerbindungen" => (isset($_GET["nurDeutschlandTicketVerbindungen"]) &&  $_GET["nurDeutschlandTicketVerbindungen"] == "1" ? "true" : "false")
	);		

	$execution_start = time();

	foreach ($daterange as $day) {
		
		$config["anfrageZeitpunkt"] = $day->getTimestamp();

		do {
			$current_time = (time()-$execution_start);
			//print $day->format('Y-m-d').": ".$current_time."s<br/>\r\n";//flush();
			$tag = getCheapestTrain($config);
			//print_r($tag);
			if (!is_array($tag)) // api limit erreicht -> schlafen bis wieder möglich
				sleep(61-$current_time); // nach 30 requests 30 sekunden pause nötig (also 30requests/minute erlaubt?)
		} while(!is_array($tag));
		
		if (is_array($tag))
			$ergebnisse = array_merge($ergebnisse, $tag);

	}

	$execution_end = time();

	// Preis-Range ermitteln
	$preise = array();

	foreach ($ergebnisse as $datum => $data) {
		
		if ($data["preis"] > 0)
			$preise[] = $data["preis"];
		
	}

	$guenstig = min($preise);
	$teuer = max($preise);

	// Calendar display
	
	// Get first day of month and calculate calendar start
	$first_day = date_create($year."-".$month."-01");
	$first_weekday = (int)$first_day->format('N'); // 1 = Monday, 7 = Sunday
	
	// Calculate start date for calendar (might be from previous month)
	$calendar_start = clone $first_day;
	$calendar_start->modify('-' . ($first_weekday - 1) . ' days');
	
	// Get last day of month
	$last_day = date_create($year."-".$month."-01");
	$last_day->modify('last day of this month');
	$last_weekday = (int)$last_day->format('N');
	
	// Calculate end date for calendar (might be from next month)
	$calendar_end = clone $last_day;
	$calendar_end->modify('+' . (7 - $last_weekday) . ' days');
	
	// Calculate previous and next month
	$prev_date = date_create($year."-".$month."-01");
	$prev_date->modify('-1 month');
	$prev_year = $prev_date->format('Y');
	$prev_month = $prev_date->format('m');
	
	$next_date = date_create($year."-".$month."-01");
	$next_date->modify('+1 month');
	$next_year = $next_date->format('Y');
	$next_month = $next_date->format('m');
	
	// Build navigation URLs preserving all current parameters
	$current_params = $_GET;
	
	$prev_params = $current_params;
	$prev_params['nav_year'] = $prev_year;
	$prev_params['nav_month'] = $prev_month;
	$prev_url = '?' . http_build_query($prev_params);
	
	$next_params = $current_params;
	$next_params['nav_year'] = $next_year;
	$next_params['nav_month'] = $next_month;
	$next_url = '?' . http_build_query($next_params);
	
	print "<div class='calendar-nav'>";
	print "<a href='".$prev_url."' class='nav-button' title='Vorheriger Monat'>‹</a>";
	print "<h3>".date("F Y", strtotime($year."-".$month."-01"))."</h3>";
	print "<a href='".$next_url."' class='nav-button' title='Nächster Monat'>›</a>";
	print "</div>";
	
	print "<div class='calendar'>";
	
	// Header row
	$weekdays = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
	foreach ($weekdays as $weekday) {
		print "<div class='calendar-header'>".$weekday."</div>";
	}
	
	// Calendar days
	$current_date = clone $calendar_start;
	while ($current_date <= $calendar_end) {
		$date_str = $current_date->format('Y-m-d');
		$day_num = $current_date->format('j');
		$current_month = $current_date->format('m');
		$dayofweek = (int)$current_date->format('N');
		
		$classes = ['calendar-day'];
		if ($dayofweek >= 6) {
			$classes[] = 'weekend';
		}
		if ($current_month != $month) {
			$classes[] = 'other-month';
		}
		
		print "<div class='".implode(' ', $classes)."'>";
		if ($current_month == $month) {
			print "<div class='day-number' onclick='showDayDetails(\"".$date_str."\")'>".$day_num."</div>";
		} else {
			print "<div class='day-number'>".$day_num."</div>";
		}
		
		if (isset($ergebnisse[$date_str]) && $current_month == $month) {
			$data = $ergebnisse[$date_str];
			
			if ($data["preis"] > 0) {
				$price_class = 'medium';
				if ($data["preis"] == $guenstig) $price_class = 'cheap';
				if ($data["preis"] == $teuer) $price_class = 'expensive';
				
				$klasse = ($config["klasse"] == "KLASSE_2" ? "2" : "1");
				$bestpreis_anzeigen = "true";
				$direktverbindung = ($config["maxUmstiege"] == "0" ? "true" : "false");
				$link = "https://www.bahn.de/buchung/fahrplan/suche#sts=true&kl=".$klasse."&hd=".$data["abfahrtsZeitpunkt"]."&soid=".$start."&zoid=".$ziel."&bp=".$bestpreis_anzeigen."&d=".$direktverbindung."";
				
				// Calculate duration
				$abfahrt = new DateTime($data["abfahrtsZeitpunkt"]);
				$ankunft = new DateTime($data["ankunftsZeitpunkt"]);
				$duration = $abfahrt->diff($ankunft);
				$duration_str = $duration->h . "h";
				if ($duration->i > 0) {
					$duration_str .= " " . $duration->i . "m";
				}
				
				print "<a href='".$link."' target='_blank'>";
				print "<div class='price ".$price_class."'>".$data["preis"]."<sup>00</sup></div>";
				print "<div class='duration'>⌚ ".$duration_str."</div>";
				print "</a>";
			} else {
				print "<div class='no-price'>—</div>";
			}
		}
		
		print "</div>";
		
		$current_date->modify('+1 day');
	}
	
	print "</div>";
	
	// Day details section
	print "<div id='day-details' class='day-details'>";
	print "<div class='day-details-header'>";
	print "<div style='display: flex; justify-content: space-between; align-items: center;'>";
	print "<h4 id='day-details-title'>Verbindungen für den </h4>";
	print "<button class='close-details' onclick='hideDayDetails()'>×</button>";
	print "</div>";
	print "</div>";
	print "<div id='day-details-content'>";
	print "Lade Verbindungen...";
	print "</div>";
	print "</div>";
	
	// Hidden form for AJAX requests
	print "<form id='detail-form' style='display: none;'>";
	foreach ($_GET as $key => $value) {
		if ($key != 'detail_date') {
			print "<input type='hidden' name='".$key."' value='".$value."'>";
		}
	}
	print "<input type='hidden' name='detail_date' id='detail_date' value=''>";
	print "</form>";
	
	// Handle detail date request
	if (isset($_GET['detail_date'])) {
		$detail_date = $_GET['detail_date'];
		$detail_config = $config;
		$detail_config["anfrageZeitpunkt"] = strtotime($detail_date);
		
		$trains = getAllTrains($detail_config);
		
		print "<script>";
		print "document.addEventListener('DOMContentLoaded', function() {";
		print "showDayDetailsWithData('".$detail_date."', ".json_encode($trains).");";
		print "});";
		print "</script>";
	}
	
} else {
	print "<span style='font-weight:bold; color:red;'>Bitte Start + Ziel befüllen!</span>";
	$execution_start = time();
	$execution_end = time();
}
	
?>
	</section>
	<section>	
<?php
	print "Verarbeitungszeit: ".($execution_end-$execution_start)."s";
?>
	</section>
	<script>
		function SwitchOrt() {
			var start = document.getElementById('start');
			var ziel = document.getElementById('ziel');
			var tmp = start.value;
			start.value = ziel.value;
			ziel.value = tmp;
		}
		
		function showDayDetails(date) {
			// Set the date in the hidden form
			document.getElementById('detail_date').value = date;
			
			// Show loading state
			document.getElementById('day-details-title').innerHTML = 'Verbindungen für den ' + formatDate(date);
			document.getElementById('day-details-content').innerHTML = '<div style="padding: 20px; text-align: center; color: #666;">Lade Verbindungen...</div>';
			document.getElementById('day-details').style.display = 'block';
			
			// Scroll to details
			document.getElementById('day-details').scrollIntoView({ behavior: 'smooth', block: 'start' });
			
			// Load data by submitting form
			var form = document.getElementById('detail-form');
			var formData = new FormData(form);
			var params = new URLSearchParams(formData);
			
			// Reload page with detail_date parameter
			window.location.href = '?' + params.toString();
		}
		
		function showDayDetailsWithData(date, trains) {
			document.getElementById('day-details-title').innerHTML = 'Verbindungen für den ' + formatDate(date);
			
			var content = '';
			if (trains && trains.length > 0) {
				trains.forEach(function(train) {
					var departureTime = new Date(train.abfahrtsZeitpunkt).toLocaleTimeString('de-DE', {hour: '2-digit', minute: '2-digit'});
					var arrivalTime = new Date(train.ankunftsZeitpunkt).toLocaleTimeString('de-DE', {hour: '2-digit', minute: '2-digit'});
					
					var transferText = '';
					if (train.umstiege === 0) {
						transferText = 'Direktverbindung';
					} else if (train.umstiege === 1) {
						transferText = '1 Umstieg';
					} else {
						transferText = train.umstiege + ' Umstiege';
					}
					
					// Build booking link
					var klasse = '<?php echo ($config["klasse"] == "KLASSE_2" ? "2" : "1"); ?>';
					var bestpreis_anzeigen = "true";
					var direktverbindung = '<?php echo ($config["maxUmstiege"] == "0" ? "true" : "false"); ?>';
					var start = '<?php echo $start; ?>';
					var ziel = '<?php echo $ziel; ?>';
					var link = "https://www.bahn.de/buchung/fahrplan/suche#sts=true&kl=" + klasse + "&hd=" + train.abfahrtsZeitpunkt + "&soid=" + start + "&zoid=" + ziel + "&bp=" + bestpreis_anzeigen + "&d=" + direktverbindung;
					
					content += '<a href="' + link + '" target="_blank" style="text-decoration: none; color: inherit;">';
					content += '<div class="train-connection">';
					content += '<div class="train-time">';
					content += '<div class="train-departure">' + departureTime + '</div>';
					content += '<div class="train-arrival">' + arrivalTime + '</div>';
					content += '</div>';
					content += '<div class="train-route">';
					content += '<div>' + train.abfahrtsOrt + ' → ' + train.ankunftsOrt + '</div>';
					content += '<div class="train-duration">⌚ ' + train.duration + '</div>';
					content += '<div class="train-transfers">' + transferText + '</div>';
					content += '</div>';
					content += '<div class="train-price">' + train.preis + '€</div>';
					content += '</div>';
					content += '</a>';
				});
			} else {
				content = '<div style="padding: 20px; text-align: center; color: #666;">Keine Verbindungen für diesen Tag verfügbar.</div>';
			}
			
			document.getElementById('day-details-content').innerHTML = content;
			document.getElementById('day-details').style.display = 'block';
			
			// Scroll to details
			document.getElementById('day-details').scrollIntoView({ behavior: 'smooth', block: 'start' });
		}
		
		function hideDayDetails() {
			document.getElementById('day-details').style.display = 'none';
		}
		
		function formatDate(dateStr) {
			var date = new Date(dateStr);
			var options = { 
				weekday: 'long', 
				year: 'numeric', 
				month: 'long', 
				day: 'numeric' 
			};
			return date.toLocaleDateString('de-DE', options);
		}
	</script>
</body>
</html>