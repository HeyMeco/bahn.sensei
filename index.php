<?php

set_time_limit(0);

// GET https://www.bahn.de/web/api/reiseloesung/orte?suchbegriff=m%C3%BCn&typ=ALL&limit=10

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

	//$start_date = date_create($year."-".$month."-"."01");
	$start_date = date_create($_GET["abfahrtab"]);
	
	$year = $start_date->format("Y");
	$month = $start_date->format("m");
	
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

	// Liste

	//print_r($ergebnisse);

	print "<ul>";
	foreach ($ergebnisse as $datum => $data) {
		$farbe = ($data["preis"] == $guenstig ? "green" : ($data["preis"] == $teuer ? "red" : "orange"));
		
		$klasse = ($config["klasse"] == "KLASSE_2" ? "2" : "1");
		$bestpreis_anzeigen = "true";
		$direktverbindung = ($config["maxUmstiege"] == "0" ? "true" : "false");;
		
		$link = "";
		if ($data["preis"] > 0)
			$link = "https://www.bahn.de/buchung/fahrplan/suche#sts=true&kl=".$klasse."&hd=".$data["abfahrtsZeitpunkt"]."&soid=".$start."&zoid=".$ziel."&bp=".$bestpreis_anzeigen."&d=".$direktverbindung."";
		
		print "<li>".$datum.": <span style='font-weight:bold; color:".$farbe.";'>".$data["preis"]."€</span><br/>";
		if ($data["preis"] > 0)		
			print "(<a href='".$link."' target='_blank'>".$data["info"]."</a>)";
		print "</li>";
		
	}
	print "</ul>";
	
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
	</script>
</body>
</html>