<?

require_once("include/benc.php");
require_once("include/bittorrent.php");
require_once "orlydb.php";

$GLOBALS["uploaderrors"] = Array();

function bark($msg)
{
    end_table();
    end_frame();
    begin_frame("Torrent-Upload fehlgeschlagen!", FALSE, "650px");
    echo "<p>Beim Upload ist ein schwerwiegender Fehler aufgetreten:</p><p style=\"color:red\">$msg</p><p>Bitte korrigiere den angezeigten Fehler, und versuche es erneut!</p>";
    end_frame();
    die();
}

function tr_msg($id,$msg)
{
    echo "<tr><td class=\"tableb\" style=\"text-align:left;\">#".$id."</td><td class=\"tablea\" style=\"text-align:left;\">$msg</td>";
}

function tr_status($status)
{
    echo "<td class=\"tableb\" style=\"text-align:center;\"><img src=\"".$GLOBALS["PIC_BASE_URL"];
    if ($status == "ok")
        echo "icon_success.gif";
    else
        echo "icon_cancel.gif";
    echo "\" width></td></tr>";
    @ob_flush();
    @flush();
}

hit_start();
dbconn(false);
hit_count();
loggedinorreturn();

if(get_user_class() < UC_UPLOADER && $CURUSER["freeuploader"] == "no") {
	stderr("Fehler", "Das Multi-Upload-Formular ist ein EXKLUSIVRECHT für unsere Uploader. Du willst dieses Privileg auch? Bewirb dich als Uploader!");
}

if ($CURUSER["allowupload"] != "yes") {
    stderr("Keine Uploadrechte!", "Du hast kein Recht, auf diesem Tracker Torrents hochzuladen, da diese Funktion für Deinen Account von einem Moderator deaktiviert wurde.");
}

$numforms = intval($_POST["numforms"]);

$min_forms = 2;
$max_forms = 10;

if($numforms == 0 || $numforms < $min_forms || $numforms > $max_forms) $numforms = $min_forms;

$nfofilename = array();
$nfofetch = array();
$matches = array();
$fname = array();
$pname = array();
$nname = array();
$poster = array();
$serieninfo = array();
$serieninfo2 = array();
$serienname = array();
$cat = array();
$catname = array();
$catgroup = array();
$genre = array();
$lang = array();
$kids = array();
$crew = array();
$imdb = array();
$imdb_rating = array();
$youtubelink = array();
$tmpname = array();

for ($k = 1; $k <= $numforms; $k++) {
    if (trim($_FILES["file".$k]["name"]) == "") {
		stderr("Fehlende Formulardaten", "Die übergebenen Daten sind unvollständig. Bitte benutze das Upload-Formular, und fülle alle nötigen Felder aus!");
  	}
}

if ($CURUSER) {
        $ss_a = @mysql_fetch_assoc(@mysql_query("SELECT `uri` FROM `stylesheets` WHERE `id`=" . $CURUSER["stylesheet"]));
        if ($ss_a) $GLOBALS["ss_uri"] = $ss_a["uri"];
    }

    if (!$GLOBALS["ss_uri"]) {
        ($r = mysql_query("SELECT `uri` FROM `stylesheets` WHERE `default`='yes'")) or die(mysql_error());
        ($a = mysql_fetch_assoc($r)) or die(mysql_error());
        $GLOBALS["ss_uri"] = $a["uri"];
    }

?>

<link rel="stylesheet" href="<?=$GLOBALS["PIC_BASE_URL"] . $GLOBALS["ss_uri"] . "/" . $GLOBALS["ss_uri"]?>.css" type="text/css">
<center>
<?

begin_frame("Überprüfe Upload...", FALSE, "650px");
begin_table(TRUE);

echo "<tr class=tableb><td colspan=3><center><b>Eingabe</b></center></td></tr>";

$descr = unesc($_POST["description"]);
$catid = (0 + $_POST["alltype"]);
$group = 0+$_POST["group"];
$dl_method = 0+$_POST["dl_method"];

if($_POST["https"] == 1) {
	$https = 1;
} else {
	$https = 0;
}

tr_msg("*","Kategorie-Zuordnung");
if (!is_valid_id($catid)) {
	tr_status("err");
	bark("Du musst eine gültige Kategorie angeben!");
}
tr_status("ok");

if ($_POST["notifydeltorrents"] != "yes")
    $notifydeltorrents = "no";
else
    $notifydeltorrents = "yes";

if ($_POST["notifycomments"] != "yes")
    $notifycomments = "no";
else
    $notifycomments = "yes";

$activated = "yes";

$xxxgenre = 5;
$xxxcat = array();
$query_xxx = mysql_query("SELECT id FROM categories WHERE `group`='XXX' ORDER BY id ASC");
while($result_xxx = mysql_fetch_array($query_xxx)) {
	$xxxcat[] = $result_xxx["id"];
}

$kids_crew_id = 20;
$crewdata = array();
$grpres = mysql_query("SELECT teams.id, teams.kats FROM teams, teammembers WHERE teams.id=teammembers.teamid AND teams.kats!='' AND teammembers.userid=".$CURUSER["id"]." ORDER BY teams.id DESC");
while($grprow = mysql_fetch_array($grpres)) {
	$crewdata[$grprow[id]] = $grprow["kats"];
}

$f = array();
$nfofile = array();
$nfoposter = array();
$p = array();

for($i=1; $i<=$numforms; $i++) {

	$a = $i-1;

	$f[$i] = $_FILES["file".$i];
	$p[$i] = $_FILES["poster".$i];
	$poster[] = $p[$i];
	$fname[] = unesc($f[$i]["name"]);
	$pname[] = unesc($p[$i]["name"]);
	$nfoposter[$a] = false;

	tr_msg($i,"Dateiname der Torrent-Metadatei");
	if (!validfilename($fname[$a])) {
		tr_status("err");
		bark("Der Dateinamen bei Torrent #".$i." ist ungültig.");
	}

	if (!preg_match('/^(.+)\.torrent$/si', $fname[$a], $matches[$a])) {
		tr_status("err");
		bark("Ungültige Dateiendung (nicht .torrent) bei Torrent #".$i.".");
	}
	tr_status("ok");

	if($_POST["nfofetch".$i] == "yes") {

		$nfofile[$i]['name'] = $matches[$a][1].".nfo";
		$nfofile[$i]['tmp_name'] = fetch_nfo($matches[$a][1]);
		$nfofile[$i]['size'] = strlen($nfofile[$i]['tmp_name']);
		$nfofile[$i]['error'] = false;
		$nfofetch[] = true;

	} else {
		$nfofile[$i] = $_FILES["nfo".$i];
		$nfofetch[] = false;
	}

	$nfofilename[] = $nfofile[$i]["tmp_name"];
	$nname[] = unesc($nfofile[$i]["name"]);

	if(isset($_POST["genre".$i])) {
		$genre[$a] = 0 + $_POST["genre".$i];
		if (!is_valid_id($genre[$a]))
			$genre[$a] = 0 + $_POST["allgenre"];
	}

	if ($_POST["kids".$i] != "yes")
    	$kids[$a] = "no";
	else
    	$kids[$a] = "yes";

	tr_msg($i,"Kategorie-Zuordnung");
	if(isset($_POST["type".$i])) {
		$cat[$a] = 0 + $_POST["type".$i];
		if (!is_valid_id($cat[$a]))
			$cat[$a] = 0 + $_POST["alltype"];
	}

	$res = mysql_query("SELECT name, `group` FROM categories WHERE id=".$cat[$a]." LIMIT 1");

	if(mysql_num_rows($res) == 0) {
		tr_status("err");
		bark("Du musst eine Kategorie angeben, welcher der Torrent zugeordnet werden soll.");
	}

	$arr = mysql_fetch_assoc($res);
	$catname[$a] = $arr["name"];
	$catgroup[$a] = $arr["group"];

	if($kids[$a] == "yes" && in_array($cat[$a],$xxxcat)) {
		tr_status("err");
		bark("Du hast einen Kids-Torrent in eine XXX-Kategorie zugeordnet!?!?!...");
	}
	tr_status("ok");

	if($genre[$a] == 0) {
		if(in_array($cat[$a],$xxxcat)) {
			$genre[$a] = $xxxgenre;
		}
	}

	if(isset($_POST["lang".$i])) {
		$lang[$a] = 0 + $_POST["lang".$i];
		if (!is_valid_id($lang[$a]))
			$lang[$a] = 0 + $_POST["alllang"];
	}

	tr_msg($i,"Posterüberprüfung...");
	if($p[$i]['error'] && $_POST["serieninfo".$i] == 0 && $_POST["nfoposter".$i] != "yes") {
		tr_status("err");
		bark("Kein Poster bei Torrent #".$i.".");
	}
	tr_status("ok");

	if(!$nfofile[$i]['error']) {

		tr_msg($i,"Dateiname und Größe der NFO-Datei");
		if ($nfofile[$i]['name'] == '' || $nfofile[$i]['size'] > 65535 || $nfofile[$i]['size'] == 0) {
			tr_status("err");
			bark("Keine NFO, oder die NFO ist grösser als 65535 Bytes bei Torrent #".$i.".");
		}

		if (!preg_match('/^(.+)\.(nfo|txt)$/si', $nfofile[$i]['name'])) {
		    tr_status("err");
		    abort("Der NFO-Dateiname bei Torrent #".$i." muss mit \".nfo\" oder \".txt\" enden.");
		}
		tr_status("ok");

	}

	tr_msg($i,"Dateiname der Poster-Datei");
	if($_POST["serieninfo".$i] == 0) {

		if($_POST["nfoposter".$i] == "yes") {

			if($nfofile[$i]['error']) {
				tr_status("err");
		        bark("Die NFO bei Torrent #".$i." enthält keine Daten");
			}

			$nfoposter[$a] = true;
			$serienname[$a] = "";
			$serieninfo[$a] = "";
			$serieninfo2[$a] = 0;
			$imdb[$a] = "";

		} else {

			if (!validfilename($pname[$a])) {
				tr_status("err");
				bark("Das Poster bei Torrent #".$i." ist ungültig.");
			}

			$serienname[$a] = "";
			$serieninfo[$a] = "";
			$serieninfo2[$a] = 0;
			$imdb[$a] = "";

		}

	} else {

		$res_poster = mysql_query("SELECT * FROM serieninfo WHERE id='".$_POST["serieninfo".$i]."' AND active='yes' LIMIT 1");

		if(mysql_num_rows($res_poster) == 0) {
			tr_status("err");
			bark("Das Poster bei Torrent #".$i." ist ungültig.");
		}

		$arr_poster = mysql_fetch_array($res_poster);

		$serienname[$a] = $arr_poster["name"];
		$serieninfo[$a] = str_replace(" ", "%20", htmlspecialchars($arr_poster["banner"]));
		$serieninfo2[$a] = $arr_poster["id"];
		$imdb[$a] = $arr_poster["plot"];
		if($genre[$a] == 0) $genre[$a] = $arr_poster["genre"];

	}
	tr_status("ok");

	if(!$nfofetch[$a]) {

		tr_msg($i,"Uploadstatus der NFO-Datei");

		if ($_POST["nonfo".$i] != "yes" && @!is_uploaded_file($nfofilename[$a])) {
			tr_status("err");
			bark("NFO-Upload bei Torrent #".$i." fehlgeschlagen.");
		}
		tr_status("ok");

	}

	tr_msg($i,"Torrent-Beschreibung");
	if ($_POST["nonfo".$i] == "yes" && trim($descr) == "") {
		tr_status("err");
		bark("Bitte gib eine Beschreibung an.");
	}
	tr_status("ok");

	$crew[$a] = $group;
	if (count($crewdata) > 0) {

		foreach($crewdata AS $crewid => $crewkats) {

			$grpkats = explode(",",$crewkats);

			if($kids[$a] == "yes" && $crewid == $kids_crew_id && in_array($cat[$a],$grpkats)) {
				$crew[$a] = $crewid;
				break;
			}
			elseif($kids[$a] == "no" && $crewid != $kids_crew_id && in_array($cat[$a],$grpkats)) {
				$crew[$a] = $crewid;
				break;
			}
			elseif($kids[$a] == "yes" && $crewid != $kids_crew_id && in_array($catid[$a],$grpkats)) {
				$crew[$a] = $crewid;
				break;
			}

		}

	}

	$tmpname[] = $f[$i]["tmp_name"];

}

echo "<tr class=tableb><td colspan=3><center><b>Upload</b></center></td></tr>";

$shortname = array();
$dict = array();
$ann = array();
$info = array();
$dbname = array();
$plen = array();
$pieces = array();

$totallen = array();
$infohash = array();
$torrent = array();
$nfo = array();
$ids = array();

$i = 0;
foreach($tmpname as $value) {

	$shortfname[$i] = $torrent[$i] = $matches[$i][1];

	$torrent[$i] = str_replace("_", " ", $torrent[$i]);
	$torrent[$i] = str_replace("_", " ", $torrent[$i]);
	$torrent[$i] = str_replace("Ä", "Ae", $torrent[$i]);
	$torrent[$i] = str_replace("Ö", "Oe", $torrent[$i]);
	$torrent[$i] = str_replace("Ü", "Ue", $torrent[$i]);
	$torrent[$i] = str_replace("ä", "ae", $torrent[$i]);
	$torrent[$i] = str_replace("ö", "oe", $torrent[$i]);
	$torrent[$i] = str_replace("ü", "ue", $torrent[$i]);

	tr_msg(($i+1),"Uploadstatus der Torrent-Metadatei");
	if(!is_uploaded_file($value)) {
		tr_status("err");
		bark("Beim Upload der Torrent-Metadatei von Torrent #".($i+1)." ist etwas schiefgegangen...");
	}
	tr_status("ok");

	tr_msg(($i+1),"Max. Größe der Torrent-Metadatei");
	if (!filesize($value)) {
		tr_status("err");
		bark("Leere Torrent-Metadatei hochgeladen bei Torrent #".($i+1).".");
	}
	tr_status("ok");

	$dict[] = bdec_file($value, $GLOBALS["MAX_TORRENT_SIZE"]);

	tr_msg(($i+1),"Torrent-Metadatei dekodieren");
	if (!isset($dict[$i])) {
		tr_status("err");
		bark("Was lädst du da bei Torrent #".($i+1)." hoch?!?!");
	}
	tr_status("ok");

	list($ann[$i], $info[$i]) = dict_check(($i+1),$dict[$i], "announce(string):info", "Globales Dictionary");
	list($dname[$i], $plen[$i], $pieces[$i]) = dict_check(($i+1),$info[$i], "name(string):piece length(integer):pieces(string)", "Info-Dictionary");

	tr_msg(($i+1),"Plausibilitätsprüfung und Einlesen der Dateiliste");

	$totallen[$i] = dict_get($info[$i], "length", "integer");
	$filelist[$i] = array();
	if ($totallen[$i] > 0) {
		$filelist[$i][] = array($dname[$i], $totallen[$i]);
		$type = "single";
	} else {
		$flist = dict_get($info[$i], "files", "list");
		if (!isset($flist)) {
			tr_status("err");
			bark("Es fehlen sowohl der \"length\"- als auch der \"files\"-Schlüssel im Info-Dictionary bei Torrent #".($i+1)."!");
		}
		if (!count($flist)) {
			tr_status("err");
			bark("Der Torrent #".($i+1)." enthält keine Dateien!");
		}
		$totallen[$i] = 0;
		foreach ($flist as $fn) {
			list($ll, $ff) = dict_check(($i+1),$fn, "length(integer):path(list)");
			$totallen[$i] += $ll;
			$ffa = array();
				foreach ($ff as $ffe) {
					if ($ffe["type"] != "string") {
						tr_status("err");
						bark("Ein Eintrag in der Dateinamen-Liste bei Torrent #".($i+1)." hat einen ungültigen Datentyp (".$ffe["type"].")!");
					}
					$ffa[] = $ffe["value"];
				}
			if (!count($ffa)) {
				tr_status("err");
				bark("Ein Eintrag in der Dateinamen-Liste bei Torrent #".($i+1)." ist ungültig!");
			}
			$ffe = implode("/", $ffa);
			$filelist[$i][] = array($ffe, $ll);
		}
		$type = "multi";
	}
	tr_status("ok");

	$dobble = false;
	tr_msg(($i+1),"Doppeltorrentprüfung");

	$dateilist    = $filelist[$i];
	shuffle($dateilist);
	$datei        = $dateilist[0];
	$num          = count($dateilist);

	$res_dt_check = mysql_query("SELECT torrent FROM files WHERE filename = ".sqlesc($datei[0])." AND size = '".$datei[1]."' LIMIT 1");

	if(mysql_num_rows($res_dt_check) != 0)
	{
		$arr_dt_check = mysql_fetch_assoc($res_dt_check);
		$res_dt_check = mysql_query("SELECT id, name, numfiles FROM torrents WHERE id=".$arr_dt_check['torrent']);
		$arr_dt_check = mysql_fetch_assoc($res_dt_check);

		if ($arr_dt_check['numfiles'] == $num)
  		{
  			$dobble = true;
  		}
	}

	$dupload = mysql_query("SELECT `id`, `name` FROM `torrents` WHERE `filename` LIKE '%".$fname[$i]."%' OR `name` LIKE '%".$torrent[$i]."%' LIMIT 1");

	if (mysql_num_rows($dupload) > 0) {
		$dobble = true;
	}
	if($dobble) {
		tr_status("err");
	} else {
		tr_status("ok");
	}

	tr_msg(($i+1),"Release-Gruppen Prüfung");

	$releaser = explode("-", $fname[$i]);
	$releaser_org = end($releaser);
	$releaser = strtolower(end($releaser));

	if(array_key_exists($releaser,$GLOBALS["RELEASE_FILTER"])) {
		tr_status("err");
	    bark("Die Release-Gruppe \"".$releaser_org."\" in File #".($i+1)." ist hier verboten! (".$GLOBALS["RELEASE_FILTER"][$releaser].")");
	}

	tr_status("ok");

	tr_msg(($i+1),"Plausibilitätsprüfung der Piece-Hashes");
	if (strlen($pieces[$i]) % 20 != 0) {
		tr_status("err");
		bark("Die Länge der Piece-Hashes in File #".($i+1)." ist kein Vielfaches von 20!");
	}

	$numpieces = strlen($pieces[$i])/20;

	if ($numpieces != ceil($totallen[$i]/$plen[$i])) {
		tr_status("err");
	    bark("Die Anzahl Piecehashes in File #".($i+1)." stimmt nicht mit der Torrentlänge überein (".$numpieces." ungleich ".ceil($totallen[$i]/$plen[$i]).")!");
	}
	tr_status("ok");

	if(!$nfofetch[$i]) {
		$nfo[$i] = str_replace("\x0d\x0d\x0a", "\x0d\x0a", @file_get_contents($nfofilename[$i]));
	} else {
		$nfo[$i] = str_replace("\x0d\x0d\x0a", "\x0d\x0a", $nfofilename[$i]);
	}

	if(trim($imdb[$i]) == "") {

		$imdbid = array();
		preg_match('/(imdb.com|imdb.de)\/title\/([\w]*)/is', $nfo[$i], $imdbid);

		$imdb[$i] = $imdbid[2];

	}

	tr_msg(($i+1),"Posterüberprüfung...");
	if($serieninfo2[$i] == 0) {

		if($nfoposter[$i] == true) {

			$upload_nfoposter = imdb_poster($imdb[$i]);

			if(!$upload_nfoposter) {
				tr_status("err");
				bark("Das hochgeladene Bild von File #".($i+1)." konnte nicht verarbeitet werden.");
			}

			$postername = str_replace(" ", "%20", htmlspecialchars($upload_nfoposter));

		} else {

			$pfile = $poster[$i];

			if ($pfile["size"] < 1) {
				tr_status("err");
				bark("Dein Bild von File #".($i+1)." hat eine ungültige Größe!");
			}

			$filename = md5_file($pfile["tmp_name"]);

			$it = exif_imagetype($pfile["tmp_name"]);

			$is_image = @getimagesize($pfile["tmp_name"]);

			if (!$is_image || ($it != IMAGETYPE_GIF && $it != IMAGETYPE_JPEG && $it != IMAGETYPE_PNG)) {
				tr_status("err");
				bark("Die hochgeladene Datei von File #".($i+1)." konnte nicht als g&uuml;tige Bilddatei verifiziert werden.");
			}

			$j = strrpos($pfile["name"], ".");

			if ($j !== false) {

				$ext = strtolower(substr($pfile["name"], $j));

				if (($it == IMAGETYPE_GIF && $ext != ".gif") || ($it == IMAGETYPE_JPEG && ($ext != ".jpg" && $ext != ".jpeg")) || ($it == IMAGETYPE_PNG && $ext != ".png")) {
					tr_status("err");
					bark("Ung&uuml;tige Dateinamenerweiterung: $ext");
				}

				$filename .= $ext;

			} else {
				tr_status("err");
        		bark("Die Datei von File #".($i+1)." muss eine Dateinamenerweiterung besitzen.");
			}

			$tgtfile = $GLOBALS["BITBUCKET_DIR"]."/".$filename;

			if (!file_exists($tgtfile)) {

				if($is_image[0] > 300) {
					resize_image($filename, $pfile["tmp_name"], $tgtfile, 300) or stderr("Fehler", "Das hochgeladene Bild konnte nicht verarbeitet werden.");
				} else {
					move_uploaded_file($pfile["tmp_name"], $tgtfile) or stderr("Fehler", "Das hochgeladene Bild konnte nicht verarbeitet werden.");
				}

			}

			$postername = str_replace(" ", "%20", htmlspecialchars($GLOBALS["BITBUCKET_DIR"]."/".$filename));

		}

	} else {
		$postername = $serieninfo[$i];
	}
	tr_status("ok");

	$allowed_keys = array("info");
	$allowed_keys2 = array("files","name","piece length","pieces","length");

	foreach($dict[$i]['value'] AS $key => $value) {

		if(!array_key_exists($key, $dict[$i]['value'])) continue;

		if(!in_array($key,$allowed_keys)) {
			unset($dict[$i]['value'][$key]);
		}

	}

	foreach($dict[$i]['value']['info']['value'] AS $key => $value) {

		if(!array_key_exists($key, $dict[$i]['value']['info']['value'])) continue;

		if(!in_array($key,$allowed_keys2)) {
			unset($dict[$i]['value']['info']['value'][$key]);
		}

	}

	$dict[$i]['value']['comment'] = array("type" => "string", "value" => mkprettytime(check_seedtime($totallen[$i]))." in ".check_seedtime2($totallen[$i])." Tagen oder Ratio 1.0");
	$dict[$i]['value']['created by'] = array("type" => "string", "value" => "Bond, James Bond");
	$dict[$i]['value']['creation date'] = array("type" => "integer", "value" => time());
	$dict[$i]["value"]["info"]["value"]["source"] = array("type" => "string", "value" => $GLOBALS["SECURE_DEFAULTBASEURL"]." ".$GLOBALS["SITENAME"]);
	$dict[$i]["value"]["info"]["value"]["private"] = array("type" => "integer", "value" => "1");
	$dict[$i]["value"]["info"]["value"]["unique id"] = array("type" => "string", "value" => mksecret());

	$infohash[$i] = pack("H*", sha1(benc($dict[$i]["value"]["info"])));

	if(trim($imdb[$i]) != "") {

		tr_msg(($i+1),"IMDB-Nummer einf&uuml;gen");

		$imdb_rating[$i] = imdb_rating($imdb[$i]);

		if(substr($imdb[$i],0,2) == "tt") $imdb[$i] = str_replace("tt", "", $imdb[$i]);

		tr_status("ok");

		include_once("TMDb.php");

		$youtubelink[$i] = fetch_trailer("tt".$imdb[$i]);

		if(trim($youtubelink[$i]) != "") {

			tr_msg(($i+1),"YouTube-Link");

			$youtubelink[$i] = "http://www.youtube.com/watch?v=".$youtubelink[$i];
			$youtubelink[$i] = theyoutubelink($youtubelink[$i]);

			tr_status("ok");

		}

	}

	$first = $shortfname[$i];
	$second = $dname[$i];
	$third = $torrent[$i];

	if($lang[$i] == 0 && preg_match("/german/", strtolower($dname[$i]))) {
		$lang[$i] = 1;
	}

	$descr2 = (trim($nfo[$i]) == "") ? $descr : NULL;
	$added = get_date_time();

	tr_msg(($i+1),"Torrent-Informationen in die Datenbank schreiben");
	$ret = mysql_query("INSERT INTO torrents (search_text, filename, owner, visible, info_hash, name, size, numfiles, type, descr, ori_descr, save_as, category, team, genre, lang, poster, added, last_action, nfo, imdb, imdb_rating, youtubelink, notifydeltorrents, notifycomments, serieninfo, kids) VALUES (" . @implode(",", array_map("sqlesc", array(searchfield("$first $second $third"), $fname[$i], $CURUSER["id"], "yes", $infohash[$i], $torrent[$i], $totallen[$i], count($filelist[$i]), $type, $descr2, $descr2, $dname[$i]))) . ", $cat[$i], $crew[$i], $genre[$i], '".$lang[$i]."', '".trim($postername)."', '" . $added . "', '" . $added . "', ".sqlesc(utf8_encode($nfo[$i])).", '".trim($imdb[$i])."', '".$imdb_rating[$i]."', '".$youtubelink[$i]."', '".$notifydeltorrents."', '".$notifycomments."', '".$serieninfo2[$i]."', '".$kids[$i]."')");

	if (!$ret) {
		tr_status("err");
		if (mysql_errno() == 1062) {
			bark("#".($i+1)." Torrent -- Den Torrent gibt es schon ;)");
		}
		bark("MySQL hat einen Fehler ausgegeben: " . mysql_error() . " (".mysql_errno().")");
	}
	tr_status("ok");

	$id = mysql_insert_id();

	mysql_query("INSERT INTO poster_history (poster, torrent) VALUES ('".trim($postername)."', '".$id."')");

	if(trim($imdb[$i]) != "") {
		mysql_query("UPDATE torrents SET imdb_rating = '".$imdb_rating[$i]."' WHERE imdb = '".$imdb[$i]."'");
	}

	if(trim($youtubelink[$i]) != "") {
		mysql_query("UPDATE torrents SET youtubelink = '".$youtubelink[$i]."' WHERE imdb = '".$imdb[$i]."'");
	}

	$isnow_freeleech = false;
	if(intval(get_config_data("FREELEECH")) > 0) {
		tr_msg(($i+1),"No-Traffic bei Torrent #".($i+1)." (Es werden keine Stats gezählt und S&L erlaubt)");
		mysql_query("UPDATE torrents SET freeleech = 'yes' WHERE id = " . intval($id) . " LIMIT 1");
		$isnow_freeleech = true;
		tr_status("ok");
	}

	if((intval(get_config_data("ONLYUP")) > 0 || $totallen[$i] >= $GLOBALS["AUTO_OU"]) && !$isnow_freeleech) {
		tr_msg(($i+1),"Only Upload bei Torrent #".($i+1)." (Nur die Upload Stats werden gezählt)");
		mysql_query("UPDATE torrents SET free = 'yes' WHERE id = " . intval($id) . " LIMIT 1");
		tr_status("ok");
	}

	if(trim($imdb[$i]) != "") {

		$query_recommended = mysql_query("SELECT id,name,size FROM torrents WHERE id != " . intval($id) . " AND imdb = " . sqlesc(trim($imdb[$i])) . " AND recommended='yes' ORDER BY added DESC");
  		$row_recommended = mysql_num_rows($query_recommended);


  		if($row_recommended > 0) {

  			$recommended_text = "Es wurde festgestellt, dass zum Torrent [url=" . $BASEURL . "/details.php?id=" . $id . "][b]" . $torrent[$i] . "[/b][/url] ".$row_recommended." ähnliche".($row_recommended == 1 ? "r" : "")." Torrent".($row_recommended != 1 ? "s" : "")." als \"Empfohlene Torrents\" markiert ".($row_recommended == 1 ? "ist" : "sind").".\n";

  			while($result_recommended = mysql_fetch_array($query_recommended)) {

  				$recommended_text .= "\n[url=" . $BASEURL . "/details.php?id=" . $result_recommended["id"] . "][b]" . $result_recommended["name"] . "[/b][/url] (".mksize($result_recommended["size"]).")";

  			}

  			$recommended_text .= "\n\nBitte prüfen und entsprechend reagieren.";

  			sendPersonalMessage(0, 0, "Empfohlene Torrents", $recommended_text, PM_FOLDERID_MOD, 0, "open");

  		}

  	}

	$ids[] = $id;

	@mysql_query("DELETE FROM files WHERE torrent = $id");

	foreach ($filelist[$i] as $file) {
		@mysql_query("INSERT INTO files (torrent, filename, size) VALUES ($id, ".sqlesc($file[0]).",".$file[1].")");
	}

	tr_msg(($i+1),"Torrent-Datei auf dem Server speichern");
	$fp = @fopen($GLOBALS["TORRENT_DIR"]."/$id.torrent", "w");

	if ($fp) {
		@fwrite($fp, benc($dict[$i]), strlen(benc($dict[$i])));
		@fclose($fp);
	} else {
		tr_status("err");
	    bark("Fehler beim Öffnen der Torrent-Datei von File #".($i+1)." auf dem Server (Schreibzugriff verweigert) - bitte SysOp benachrichtigen!");
	}
	tr_status("ok");

	tr_msg(($i+1),"NFO-Bild erzeugen");
	if (gen_nfo_pic($nfo[$i], $GLOBALS["BITBUCKET_DIR"]."/nfo-$id.png") == 0) {
		tr_status("err");
	    bark("Fehler beim erstellen des NFO Bild in File #".($i+1));
	}
	tr_status("ok");

	write_log("torrentupload", "Der Torrent <a target=\"_blank\" href=\"details.php?id=$id\">$id (".$torrent[$i].")</a> wurde von '<a target=\"_blank\" href=\"userdetails.php?id=$CURUSER[id]\">$CURUSER[username]</a>' hochgeladen.");

	mysql_query("UPDATE users SET seedbonus = seedbonus+".$GLOBALS["SEEDBONUS_UPLOAD"]." WHERE id = $CURUSER[id]");
	mysql_query("INSERT INTO seedbonus_log (userid,seedbonus,date,torrent,part) VALUES('".$CURUSER[id]."','".$GLOBALS["SEEDBONUS_UPLOAD"]."','".time()."','".$id."','addtorrent')") or sqlerr(__FILE__, __LINE__);

	if ($CURUSER["anon"] == 'yes') {
		$username = "*Anonym*";
	} else {
		$username = $CURUSER["username"];
	}

	$pretime = orlyread($dname[$i]);

	if(trim($dname[$i]) != trim($pretime['release'])) unset($pretime);

	if(isset($pretime['time'])) {

		mysql_query("UPDATE torrents SET pretime = " . sqlesc(intval($pretime['time'])) . " WHERE id = " . intval($id) . " LIMIT 1");

		$pretime_diff = strtotime($added)-$pretime['time'];
    	$pretime_output = " (".mkprettytime($pretime_diff)." nach Pre)";
		//$pretime_output2 = "|  Pre ".$pretime['time'];

	}

	if(isset($pretime['nukeright'])) {
		mysql_query("UPDATE torrents SET nuked='yes', nukereason = " . sqlesc(trim($pretime['nukeright'])) . " WHERE id = " . intval($id) . " LIMIT 1");
	}

	/*

	$bot['ip'] = "http://www.screamlabs.at"; // your bot ip
	$bot['port'] = 1239; // your script listen port

	$bot['message'] = rawurlencode($torrent[$i]." | Size ".mksize($totallen[$i])." | Category $catname |  Link $DEFAULTBASEURL/details.php?id=$id  Thanks to $username $pretime_output2");

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $bot['ip'].":".$bot['port']."/".$bot['message']);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 2);
	$output = curl_exec($ch);
	curl_close($ch);

	*/

	if($CURUSER["anon"] == "yes") {
		$text = "Ein [i]Anonymer[/i] User hat einen ".($kids[$i] == "yes" ? "Kids-" : (in_array($cat[$i],$xxxcat) ? "XXX-" : ""))."Torrent in [b]".$catgroup[$i]."[/b] &raquo; ".$catname[$i].($serieninfo2[$i] > 0 ? " &raquo; [b]".$serienname[$i]."[/b]" : "")." hochgeladen.[br][url=".$DEFAULTBASEURL."/details.php?id=".$id."][color=#ff6600][b]".$torrent[$i]."[/b][/color][/url]".$pretime_output;
	} else {
		$text = "Der User @".$CURUSER["username"]." hat einen ".($kids[$i] == "yes" ? "Kids-" : (in_array($cat[$i],$xxxcat) ? "XXX-" : ""))."Torrent in [b]".$catgroup[$i]."[/b] &raquo; ".$catname[$i].($serieninfo2[$i] > 0 ? " &raquo; [b]".$serienname[$i]."[/b]" : "")." hochgeladen.[br][url=".$DEFAULTBASEURL."/details.php?id=".$id."][color=#ff6600][b]".$torrent[$i]."[/b][/color][/url]".$pretime_output;
	}

	$date = time();

	mysql_query("INSERT INTO ajaxshoutbox (id, sbid, userid, username, date, text, text_c) VALUES ('id', '3', " . sqlesc('0') . ", " . sqlesc('System') . ", $date, " . sqlesc($text) . ", " . sqlesc(format_comment($text,false)) . ")");

	mysql_query("UPDATE users SET uploads = uploads + 1 WHERE id = " .$CURUSER["id"]);

	if($serieninfo2[$i] > 0) {

		$query_abos = mysql_query("SELECT * FROM abos WHERE serienid=".$serieninfo2[$i]." AND userid!=".$CURUSER["id"]." AND lang IN ('0','".$lang[$i]."')");

		while($result_abos = mysql_fetch_array($query_abos)) {

			$cats = "";

			if(trim($result_abos["cats"]) != "") {

				$cats = explode(",",$result_abos["cats"]);

			}

			if(trim($result_abos["cats"]) == "" || in_array($cat[$i],$cats)) {

    			$infomsg  = "Für dein Abo [b]".$serienname[$i]."[/b] wurde ein neuer Torrent hochgeladen:\n[url=" . $BASEURL . "/details.php?id=" . $id . "][b]" . $torrent[$i] . "[/b][/url]";

    			sendPersonalMessage(0, $result_abos["userid"], "Neuer Abo-Upload für ".$serienname[$i], $infomsg, PM_FOLDERID_INBOX, 0);

			}

		}

	}

	if($dl_method > 0) {

		tr_msg(($i+1),"Torrent in den Client einf&uuml;gen");

		$query_client = mysql_query("SELECT * FROM oneclickadder WHERE id='".$dl_method."' AND userid='".$CURUSER["id"]."' LIMIT 1");
		$row_client = mysql_num_rows($query_client);

		if($row_client == 0) {
			tr_status("err");
		}

		$result_client = mysql_fetch_array($query_client);

		$client = $result_client["client"];
		$uiurl = $result_client["url"];
		$uiuser = $result_client["username"];
		$uipass = $result_client["password"];
		$torrentname = $dname[$i];

		$psk = preg_replace_callback('/./s', "hex_esc", str_pad($CURUSER['passkey'], 8));
		$dlurl = $DEFAULTBASEURL."/download.php/".$psk."/" . $id . "/".$https;

		if($client == 1) {
			$success = add_utorrent($dlurl);
		}
		elseif($client == 2) {
			$success = add_rutorrent($dlurl,0);
		}
		elseif($client == 3) {
			$success = add_rutorrent($dlurl,1);
		}
		elseif($client == 4) {
			$success = add_torrentflux($dlurl,true);
		}
		elseif($client == 5) {
			$success = add_torrentflux($dlurl,false);
		}

		if($success) {
			tr_status("ok");
		} else {
	  		tr_status("err");
		}

	}

	$i++;

}

end_table();
end_frame();

begin_frame("Torrent-Upload war erfolgreich!", FALSE, "650px");
?>
<p>Es wurden <b><?=count($torrent)?></b> Torrents hochgeladen.<br>
<b>Beachte</b> dass Deine Torrents erst
sichtbar werden, wenn der erste Seeder verfügbar ist!</p>
<p><b>Wichtiger Hinweis:</b><br>Bevor Du die Torrents seeden kannst, musst Du die Torrents
erneut vom Tracker herunterladen, da beim Upload einige Änderungen an den Torrent-Dateien
vorgenommen wurden. Dadurch haben die Torrents einen neuen Info-Hash erhalten, und beim
Download wird ebenfalls Dein PassKey in die Announce-URL eingefügt. <b>Das
&Auml;ndern der Announce-URL in Deiner soeben hochgeladenen Torrent-Metadatei gen&uuml;gt
nicht!</b></p>
<?

$psk = preg_replace_callback('/./s', "hex_esc", str_pad($CURUSER['passkey'], 8));
foreach($torrent as $tids => $tnames) {
	$dlurl = $DEFAULTBASEURL."/download.php/".$psk."/" . $ids[$tids] . "/".$https;
	echo '<p style="text-align:center"><a target="_blank" href="details.php?id='.$ids[$tids].'" target="_blank"><b>'.$tnames.'</b></a><br><input type="text" readonly="readonly" size="60" value="'.$dlurl.'"> <a target="_blank" href="download.php?torrent='.$ids[$tids].'&https='.$https.'" target="_blank">Download</a></p>';
}

end_table();
end_frame();

stdfoot();

function dict_check($id, $d, $s, $type = "")
{
	if ($type != "")
        tr_msg($id,"Integritätsprüfung der Metadaten ($type)");
    if ($d["type"] != "dictionary") {
        tr_status("err");
        bark("Die Datei ist kein BEnc-Dictionary.");
    }
    $a = explode(":", $s);
    $dd = $d["value"];
    $ret = array();
    foreach ($a as $k) {
        unset($t);
        if (preg_match('/^(.*)\((.*)\)$/', $k, $m)) {
            $k = $m[1];
            $t = $m[2];
        }
        if (!isset($dd[$k])) {
        	tr_status("err");
            bark("Es fehlt ein benötigter Schlüssel im Dictionary!");
        }
        if (isset($t)) {
            if ($dd[$k]["type"] != $t) {
            	tr_status("err");
                bark("Das Dictionary enthält einen ungültigen Eintrag (Tatsächlicher Datentyp entspricht nicht dem erwarteten)!");
            }
            $ret[] = $dd[$k]["value"];
        } else
            $ret[] = $dd[$k];
    }
    if ($type != "")
        tr_status("ok");
    return $ret;
}

function dict_get($d, $k, $t)
{
    if ($d["type"] != "dictionary") {
    	tr_status("err");
        bark("Unerwarteter Fehler beim Dekodieren der Metadaten: Das ist kein Dictionary (".$d["type"].")!");
    }
    $dd = $d["value"];
    if (!isset($dd[$k]))
        return;
    $v = $dd[$k];
    if ($v["type"] != $t) {
    	tr_status("err");
        bark("Unerwarteter Fehler beim Dekodieren der Metadaten: Der Datentyp des Eintrags (".$v["type"].") enspricht nicht dem erwarteten Typ ($t)!");
    }
    return $v["value"];
}

hit_end();

?>
</center>