<?php
require_once("include/benc.php");
require_once("include/bittorrent.php");
require_once "orlydb.php";

hit_start();
dbconn(false);
hit_count();
loggedinorreturn();

$GLOBALS["uploaderrors"] = Array();

function tr_msg($msg)
{
    echo "<tr><td class=\"tablea\" style=\"text-align:left;\">$msg</td>";
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

function abort($msg)
{
    end_table();
    end_frame();
    begin_frame("Torrent-Upload fehlgeschlagen!", FALSE, "650px");
    echo "<p>Beim Upload ist ein schwerwiegender Fehler aufgetreten:</p><p style=\"color:red\">$msg</p><p>Bitte korrigiere den angezeigten Fehler, und versuche es erneut!</p>";
    end_frame();
    die();
}

if (get_user_class() < UC_MFF_USER && $CURUSER['webseed'] == "no" && $CURUSER["dsl_speed"] < 20000 && $CURUSER["freeuploader"] == "no") {

  $count=@mysql_fetch_assoc(@mysql_query("SELECT count( id ) as cnt FROM torrents WHERE owner=".sqlesc($CURUSER["id"])." and (added >= ( NOW( ) - INTERVAL 3 DAY))"));

  if($count["cnt"] >= 3) {
    stderr("Fehler", "Dein Maximum an Gast-Upload ist bereits erreicht. Du darfst innerhalb von 3 Tagen nur max. 3 Torrents hochladen!");
  }

}

if ($CURUSER["allowupload"] != "yes") {
    stderr("Keine Uploadrechte!", "Du hast kein Recht, auf diesem Tracker Torrents hochzuladen, da diese Funktion für Deinen Account von einem Moderator deaktiviert wurde.");
}

foreach(explode(":", "name:type:descr") as $v) {
    if (!isset($_POST[$v]))
        stderr("Fehlende Formulardaten", "Die übergebenen Daten sind unvollständig. Bitte benutze das Upload-Formular, und fülle alle nötigen Felder aus! [<font color=red>".$v."</font>]");
}

if (get_user_class() <= UC_MFF_USER) {
// make sure user agrees to everything...
	if ($_POST["rulesaccept"] != "yes" || $_POST["pwdverify"] != "yes" || $_POST["seedrules"] != "yes")
		stderr("Upload Fehlgeschlagen", "Du hast nicht bestätigt, dass du die Regeln für (Gast-)Uploads gelesen und verstanden hast!");
}

$group=0+$_POST["group"];

$genre=0+$_POST["genre"];

$lang=0+$_POST["lang"];

if (get_user_class() < UC_SUPER_VIP && $CURUSER["freeuploader"] != "yes")
    $activated = "no";
else
    $activated = "yes";

if ($_POST["notifydeltorrents"] != "yes")
    $notifydeltorrents = "no";
else
    $notifydeltorrents = "yes";

if ($_POST["notifycomments"] != "yes")
    $notifycomments = "no";
else
    $notifycomments = "yes";

if ($_POST["kids"] != "yes")
    $kids = "no";
else
    $kids = "yes";

$dtcheck = "yes";
if(get_user_class() >= UC_UPLOADER) {

	if ($_POST["dtcheck"] != "yes")
    	$dtcheck = "no";
	else
    	$dtcheck = "yes";

}

if (!isset($_FILES["file"]))
    stderr("Fehlende Formulardaten", "Die übergebenen Daten sind unvollständig. Bitte benutze das Upload-Formular, und fülle alle nötigen Felder aus!");

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

tr_msg("Dateiname der Torrent-Metadatei");

$f = $_FILES["file"];

if(preg_match("/((http|https):\/\/[^\s]+)/",$_POST['infourl'])) {
	$infourl = unesc($_POST['infourl']);
}

$fname = unesc($f["name"]);

if (trim($fname) == "") {
    tr_status("err");
    abort("Torrent-Metadatei hat keinen Dateinamen bzw. es wurde kein Torrent hochgeladen!");
}
if (!validfilename($fname)) {
    tr_status("err");
    abort("Der Dateiname der Torrent-Datei enthält ungültige Zeichen!");
}
if (!preg_match('/^(.+)\.torrent$/si', $fname, $matches)) {
    tr_status("err");
    abort("Der Torrent-Dateiname muss mit \".torrent\" enden.");
}

$tmpname = $f["tmp_name"];

if (!is_uploaded_file($tmpname)) {
    tr_status("err");
    abort("Beim Upload der Torrent-Metadatei ist etwas schiefgegangen...");
}
tr_status("ok");

tr_msg("Max. Größe der Torrent-Metadatei");

if ($f["size"] > $GLOBALS["MAX_TORRENT_SIZE"]) {
    tr_status("err");
    abort("Torrent-Metadatei ist zu groß (max. ".$GLOBALS["MAX_TORRENT_SIZE"]." Bytes)!");
}
if (!filesize($tmpname)) {
    tr_status("err");
    abort("Leere Torrent-Metadatei hochgeladen!");
}
tr_status("ok");

if($_POST["nfofetch"] == "yes") {

	$nfofile['name'] = $matches[1].".nfo";
	$nfofile['tmp_name'] = fetch_nfo($matches[1]);
	$nfofile['size'] = strlen($nfofile['tmp_name']);
	$nfofile['error'] = false;

} else {
	$nfofile = $_FILES['nfo'];
}

if(!$nfofile['error']) {

	tr_msg("Dateiname der NFO-Datei");

	if ($nfofile['name'] == '') {
		tr_status("err");
		abort("Die NFO hat keinen Dateinamen oder es wurde keine NFO-Datei hochgeladen!");
	}

	if (!preg_match('/^(.+)\.(nfo|txt)$/si', $nfofile['name'])) {
	    tr_status("err");
	    abort("Der NFO-Dateiname muss mit \".nfo\" oder \".txt\" enden.");
	}
	tr_status("ok");

	tr_msg("Größe der NFO-Datei");

	if ($nfofile['size'] == 0) {
		tr_status("err");
		abort("0-byte NFO");
	}

	if ($nfofile['size'] > 65536) {
		tr_status("err");
		abort("NFO ist zu groß! Maximal 65536 Bytes (64 KB) sind erlaubt.");
	}
	tr_status("ok");

	$nfofilename = $nfofile['tmp_name'];

	if($_POST["nfofetch"] != "yes") {

		tr_msg("Uploadstatus der NFO-Datei");
		if (@!is_uploaded_file($nfofilename)) {
			tr_status("err");
			abort("NFO-Upload fehlgeschlagen");
		}
		tr_status("ok");

	}

	if($_POST["nfofetch"] != "yes") {
		$nfo_content = str_replace("\x0d\x0d\x0a", "\x0d\x0a", @file_get_contents($nfofilename));
	} else {
		$nfo_content = str_replace("\x0d\x0d\x0a", "\x0d\x0a", $nfofilename);
	}

	if(trim($_POST["imdb"]) == "") {

		preg_match('/(imdb.com|imdb.de)\/title\/([\w]*)/is',$nfo_content,$imdbid);

		$_POST["imdb"] = $imdbid[2];

	}

}

tr_msg("Torrent-Beschreibung");

$descr = unesc(trim($_POST["descr"]));

if (trim($descr) == "" && ($nfofile['name'] == '' || trim($nfo_content) == "")) {
	tr_status("err");
	abort("Du musst eine Beschreibung oder NFO angeben!");
}

if($_POST['strip'] == 'strip')
{
	$descr = StripNFO($descr);
}
tr_status("ok");

if(trim($_POST["ytube"]) != "") {
	tr_msg("YouTube-Link");
	$youtubelink = theyoutubelink($_POST["ytube"]);
	tr_status("ok");
}

$xxxgenre = 5;
$xxxcat = array();
$query_xxx = mysql_query("SELECT id FROM categories WHERE `group`='XXX' ORDER BY id ASC");
while($result_xxx = mysql_fetch_array($query_xxx)) {
	$xxxcat[] = $result_xxx["id"];
}

tr_msg("Kategorie-Zuordnung");
$catid = (0 + $_POST["type"]);
$res = mysql_query("SELECT name, `group` FROM categories WHERE id=".$catid." LIMIT 1");
if (!is_valid_id($catid) || mysql_num_rows($res) == 0) {
    tr_status("err");
    abort("Du musst eine Kategorie angeben, welcher der Torrent zugeordnet werden soll.");
}

$arr = mysql_fetch_assoc($res);
$catname = $arr["name"];
$catgroup = $arr["group"];

if($kids == "yes" && in_array($catid,$xxxcat)) {
	tr_status("err");
	abort("Du hast einen Kids-Torrent in eine XXX-Kategorie zugeordnet!?!?!...");
}
tr_status("ok");

if($genre == 0) {
	if(in_array($catid,$xxxcat)) {
		$genre = $xxxgenre;
	}
}

$shortfname = $torrent = $matches[1];
if (trim($_POST["name"]) != "")
    $torrent = unesc($_POST["name"]);

$torrent = str_replace("_", " ", $torrent);
$torrent = str_replace("Ä", "Ae", $torrent);
$torrent = str_replace("Ö", "Oe", $torrent);
$torrent = str_replace("Ü", "Ue", $torrent);
$torrent = str_replace("ä", "ae", $torrent);
$torrent = str_replace("ö", "oe", $torrent);
$torrent = str_replace("ü", "ue", $torrent);

tr_msg("Torrent-Metadatei dekodieren");
$dict = bdec_file($tmpname, $f["size"]);
if (!isset($dict)) {
    tr_status("err");
    abort("Was zum Teufel hast du da hochgeladen? Das ist jedenfalls keine gültige Torrent-Datei!");
}
tr_status("ok");

function dict_check($d, $s, $type = "")
{
    if ($type != "")
        tr_msg("Integritätsprüfung der Metadaten ($type)");
    if ($d["type"] != "dictionary") {
        tr_status("err");
        abort("Die Datei ist kein BEnc-Dictionary.");
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
            abort("Es fehlt ein benötigter Schlüssel im Dictionary!");
        }
        if (isset($t)) {
            if ($dd[$k]["type"] != $t) {
                tr_status("err");
                abort("Das Dictionary enthält einen ungültigen Eintrag (Tatsächlicher Datentyp entspricht nicht dem erwarteten)!");
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
        abort("Unerwarteter Fehler beim Dekodieren der Metadaten: Das ist kein Dictionary (".$d["type"].")!");
    }
    $dd = $d["value"];
    if (!isset($dd[$k]))
        return;
    $v = $dd[$k];
    if ($v["type"] != $t) {
    	tr_status("err");
        abort("Unerwarteter Fehler beim Dekodieren der Metadaten: Der Datentyp des Eintrags (".$v["type"].") enspricht nicht dem erwarteten Typ ($t)!");
    }
    return $v["value"];
}

list($ann, $info) = dict_check($dict, "announce(string):info", "Globales Dictionary");
list($dname, $plen, $pieces) = dict_check($info, "name(string):piece length(integer):pieces(string)", "Info-Dictionary");

tr_msg("Plausibilitätsprüfung und Einlesen der Dateiliste");

$totallen = dict_get($info, "length", "integer");
$filelist = array();
if ($totallen > 0) {
    $filelist[] = array($dname, $totallen);
    $type = "single";
} else {
    $flist = dict_get($info, "files", "list");
    if (!isset($flist)) {
        tr_status("err");
        abort("Es fehlen sowohl der \"length\"- als auch der \"files\"-Schlüssel im Info-Dictionary!");
    }
    if (!count($flist)) {
        tr_status("err");
        abort("Der Torrent enthält keine Dateien!");
    }
    $totallen = 0;
    foreach ($flist as $fn) {
        list($ll, $ff) = dict_check($fn, "length(integer):path(list)");
        $totallen += $ll;
        $ffa = array();
        foreach ($ff as $ffe) {
            if ($ffe["type"] != "string") {
                tr_status("err");
                abort("Ein Eintrag in der Dateinamen-Liste hat einen ungültigen Datentyp (".$ffe["type"].")!");
            }
            $ffa[] = $ffe["value"];
        }
        if (!count($ffa)) {
        	tr_status("err");
            abort("Ein Eintrag in der Dateinamen-Liste ist ungültig!");
        }
        $ffe = implode("/", $ffa);
        $filelist[] = array($ffe, $ll);
    }
    $type = "multi";
}
tr_status("ok");

if($dtcheck == "yes") {

	tr_msg("Doppeltorrentprüfung");

	$dateilist    = $filelist;
	shuffle($dateilist);
	$datei        = $dateilist[0];
	$num          = count($dateilist);

	$res_dt_check = mysql_query("SELECT torrent FROM files WHERE filename = ".sqlesc($datei[0])." AND size = ".$datei[1]." LIMIT 1");

	if(mysql_num_rows($res_dt_check) != 0)
	{

	  $arr_dt_check = mysql_fetch_assoc($res_dt_check);
	  $res_dt_check = mysql_query("SELECT id, name, numfiles FROM torrents WHERE id=".$arr_dt_check['torrent']);
	  $arr_dt_check = mysql_fetch_assoc($res_dt_check);

	  if ($arr_dt_check['numfiles'] == $num)
	  {
	    tr_status("err");
	    abort("Dieser Torrent wurde bereits hochgeladen! [".$datei[0]." | ".mksize($datei[1])."]<br><br><a target='_blank' href='details.php?id=".$arr_dt_check['id']."'>".$arr_dt_check['name']."</a>");
	  }

	}

	$dupload = mysql_query("SELECT `id`, `name` FROM `torrents` WHERE `filename` LIKE '%".$fname."%' OR `name` LIKE '%".$torrent."%' LIMIT 1");

	if (mysql_num_rows($dupload) > 0)
	{

	    while ($res = mysql_fetch_assoc($dupload))
	    {
	        $ts .= "<a target=\"_blank\" href=\"details.php?id=".$res['id'] . "\">" . $res['name'] . "</a> ";
	    }

	    tr_status("err");
	    abort("Dieses File existiert schon auf dem Tracker! ($ts)");

	}

	tr_status("ok");

}

tr_msg("Release-Gruppen Prüfung");

$releaser = explode("-", $dname);
$releaser_org = end($releaser);
$releaser = strtolower(end($releaser));

if(array_key_exists($releaser,$GLOBALS["RELEASE_FILTER"])) {
	tr_status("err");
    abort("Die Release-Gruppe \"".$releaser_org."\" ist hier verboten! (".$GLOBALS["RELEASE_FILTER"][$releaser].")");
}
tr_status("ok");

tr_msg("Plausibilitätsprüfung der Piece-Hashes");

if (strlen($pieces) % 20 != 0) {
    tr_status("err");
    abort("Die Länge der Piece-Hashes ist kein Vielfaches von 20!");
}

$numpieces = strlen($pieces)/20;

if ($numpieces != ceil($totallen/$plen)) {
    tr_status("err");
    abort("Die Anzahl Piecehashes stimmt nicht mit der Torrentlänge überein (".$numpieces." ungleich ".ceil($totallen/$plen).")!");
}
tr_status("ok");

tr_msg("Posterüberprüfung...");

if($_POST["serieninfo"] == 0) {

	if($_POST["nfoposter"] == "yes") {

		if($nfofile['error']) {
			tr_status("err");
        	abort("Die NFO enthält keine Daten");
		}

		$upload_nfoposter = imdb_poster($_POST["imdb"]);

		if(!$upload_nfoposter) {
			tr_status("err");
			abort("Das hochgeladene Bild konnte nicht verarbeitet werden.");
		}

		$poster = str_replace(" ", "%20", htmlspecialchars($upload_nfoposter));

		$serieninfo = 0;

	} else {

		$file = $_FILES["poster"];
		if ($file["size"] < 1)
		{
			tr_status("err");
			abort("Dein Bild hat eine ungültige Größe! <br> Achte darauf, keinen Link zu nehmen, sondern durch den <br>Durchsuchen-Button das Poster direkt von deinem Pc hochzuladen");
		}

    	$filename = md5_file($file["tmp_name"]);

    	$it = exif_imagetype($file["tmp_name"]);

    	$is_image = @getimagesize($file["tmp_name"]);

    	if (!$is_image || ($it != IMAGETYPE_GIF && $it != IMAGETYPE_JPEG && $it != IMAGETYPE_PNG)) {
    		tr_status("err");
        	abort("Die hochgeladene Datei konnte nicht als g&uuml;tige Bilddatei verifiziert werden.");
		}

    	$i = strrpos($file["name"], ".");
    	if ($i !== false)
    	{
        	$ext = strtolower(substr($file["name"], $i));
        	if (($it == IMAGETYPE_GIF && $ext != ".gif") || ($it == IMAGETYPE_JPEG && ($ext != ".jpg" && $ext != ".jpeg")) || ($it == IMAGETYPE_PNG && $ext != ".png") ) {
            	tr_status("err");
            	abort("Ung&uuml;tige Dateinamenerweiterung: <b>$ext</b>");
            }
        	$filename .= $ext;
    	}
    	else
    	{
    		tr_status("err");
        	abort("Die Datei muss eine Dateinamenerweiterung besitzen.");
		}

    	$tgtfile = $GLOBALS["BITBUCKET_DIR"]."/".$filename;

		if (!file_exists($tgtfile)) {

			if($is_image[0] > 300) {

				if(!resize_image($filename, $file["tmp_name"], $tgtfile, 300)) {
					tr_status("err");
					abort("Das hochgeladene Bild konnte nicht verarbeitet werden.");
				}

			} else {

				if(!@move_uploaded_file($file["tmp_name"], $tgtfile)) {
					tr_status("err");
					abort("Das hochgeladene Bild konnte nicht verarbeitet werden.");
				}

			}

		}

		$poster = str_replace(" ", "%20", htmlspecialchars($GLOBALS["BITBUCKET_DIR"]."/".$filename));

		$serieninfo = 0;

	}

} else {

	$res_poster = mysql_query("SELECT * FROM serieninfo WHERE id='".$_POST["serieninfo"]."' AND active='yes' LIMIT 1");

	if(mysql_num_rows($res_poster) == 0) {
		tr_status("err");
		abort("Ung&uuml;tiges Serien-Poster!");
	}

	$arr_poster = mysql_fetch_array($res_poster);

	$poster = str_replace(" ", "%20", htmlspecialchars($arr_poster["banner"]));
	$serienname = $arr_poster["name"];

	$serieninfo = $arr_poster["id"];
	if(trim($_POST["imdb"]) == "") $_POST["imdb"] = $arr_poster["plot"];
	if($genre == 0) $genre = $arr_poster["genre"];

}
tr_status("ok");

if($lang == 0 && preg_match("/german/", strtolower($dname))) {
	$lang = 1;
}

$kids_crew_id = 20;
if($group == 0) {

	$grpres=mysql_query("select teams.id,teams.kats from teams,teammembers where teams.id=teammembers.teamid AND teams.kats!='' AND teammembers.userid=".$CURUSER["id"]." order by teams.id DESC");

	if (mysql_num_rows($grpres) > 0) {

		while($grprow = mysql_fetch_array($grpres)) {

			$grpkats = explode(",",$grprow["kats"]);

			if($kids == "yes" && $kids_crew_id == $grprow["id"] && in_array($catid,$grpkats)) {
				$group = $grprow["id"];
				break;
			}
			elseif($kids == "no" && $grprow["id"] != $kids_crew_id && in_array($catid,$grpkats)) {
				$group = $grprow["id"];
				break;
			}
			elseif($kids == "yes" && $grprow["id"] != $kids_crew_id && in_array($catid,$grpkats)) {
				$group = $grprow["id"];
				break;
			}

		}

	}

}

//////////////////////////////////////////////////Poster Upload by Solstice /End/////////////////////////////////////

$allowed_keys = array("info");
$allowed_keys2 = array("files","name","piece length","pieces","length");

foreach($dict['value'] AS $key => $value) {

	if(!array_key_exists($key, $dict['value'])) continue;

	if(!in_array($key,$allowed_keys)) {
		unset($dict['value'][$key]);
	}

}

foreach($dict['value']['info']['value'] AS $key => $value) {

	if(!array_key_exists($key, $dict['value']['info']['value'])) continue;

	if(!in_array($key,$allowed_keys2)) {
		unset($dict['value']['info']['value'][$key]);
	}

}

$dict['value']['comment'] = array("type" => "string", "value" => mkprettytime(check_seedtime($totallen))." in ".check_seedtime2($totallen)." Tagen oder Ratio 1.0");
$dict['value']['created by'] = array("type" => "string", "value" => "Bond, James Bond");
$dict['value']['creation date'] = array("type" => "integer", "value" => time());
$dict["value"]["info"]["value"]["source"] = array("type" => "string", "value" => $GLOBALS["SECURE_DEFAULTBASEURL"]." ".$GLOBALS["SITENAME"]);
$dict["value"]["info"]["value"]["private"] = array("type" => "integer", "value" => "1");
$dict["value"]["info"]["value"]["unique id"] = array("type" => "string", "value" => mksecret());

$infohash = pack("H*", sha1(benc($dict["value"]["info"])));

$nfo = $nfo_content;
$added = get_date_time();

tr_msg("Torrent-Informationen in die Datenbank schreiben");
$ret = mysql_query("INSERT INTO torrents (search_text, filename, owner, visible, info_hash, name, size, numfiles, type, descr, ori_descr, category, save_as, added, last_action, nfo, activated, team, genre, lang, poster, infourl, youtubelink, notifydeltorrents, notifycomments, serieninfo, kids) VALUES (" .
    implode(",", array_map("sqlesc", array(
    searchfield("$shortfname $dname $torrent"),
    $fname,
    $CURUSER["id"],
    "no",
    $infohash,
    $torrent,
    $totallen,
    count($filelist),
    $type,
    $descr,
    $descr,
    $catid,
    $dname)
    )) . ", '" . $added . "', '" . $added . "', ".sqlesc(utf8_encode($nfo)).", '$activated', '$group', '$genre', '$lang', '".trim($poster)."','".$infourl."','".$youtubelink."','".$notifydeltorrents."','".$notifycomments."','".$serieninfo."','".$kids."')");

if (!$ret) {
    tr_status("err");
    if (mysql_errno() == 1062)
        abort("Dieser Torrent wurde bereits hochgeladen!");
    abort("MySQL hat einen Fehler ausgegeben: " . mysql_error() . " (".mysql_errno().")");
}
tr_status("ok");

$id = mysql_insert_id();

mysql_query("INSERT INTO poster_history (poster, torrent) VALUES ('".trim($poster)."', '".$id."')");

$isnow_freeleech = false;
if(intval(get_config_data("FREELEECH")) > 0 || (get_user_class() >= UC_MODERATOR && $_POST["bonus"] == "freeleech")) {

	tr_msg("No-Traffic (Es werden keine Stats gezählt und S&L erlaubt)");

	mysql_query("UPDATE torrents SET freeleech = 'yes' WHERE id = " . intval($id) . " LIMIT 1");

	$isnow_freeleech = true;

	tr_status("ok");

}

$isnow_onlyup = false;
if((intval(get_config_data("ONLYUP")) > 0 || $totallen >= $GLOBALS["AUTO_OU"] || (($CURUSER['makefree'] == "yes" || get_user_class() >= UC_MODERATOR) && $_POST["bonus"] == "makefree")) && !$isnow_freeleech) {

	tr_msg("Only Upload (Nur die Upload Stats werden gezählt)");

	mysql_query("UPDATE torrents SET free = 'yes' WHERE id = " . intval($id) . " LIMIT 1");

	$isnow_onlyup = true;

	tr_status("ok");

}

if ($_POST["bonus"] == "onlyuprequest" && !$isnow_freeleech && !$isnow_onlyup) {

	tr_msg("Only Upload Anfrage gesendet");

    mysql_query("INSERT INTO onlyup (torrentid, zaprosil) VALUES ('".$id."', '".$CURUSER["id"]."')");

	tr_status("ok");

}

if($_POST["nfodes"] == "1") {

	tr_msg("NFO-Bild als Beschreibung");

	$nfourl = "[center][img]".$GLOBALS["BITBUCKET_DIR"]."/nfo-".$id.".png[/img][/center]";
	mysql_query("UPDATE torrents SET descr = '".$nfourl."', ori_descr = '".$nfourl."' WHERE id = '".$id."' LIMIT 1");

	tr_status("ok");

}

@mysql_query("DELETE FROM files WHERE torrent = $id");
foreach ($filelist as $file) {
    @mysql_query("INSERT INTO files (torrent, filename, size) VALUES ($id, " . sqlesc($file[0]) . "," . $file[1] . ")");
}

tr_msg("Torrent-Datei auf dem Server speichern");

$fhandle = @fopen($GLOBALS["TORRENT_DIR"]."/$id.torrent", "w");

if ($fhandle) {
    @fwrite($fhandle, benc($dict));
    @fclose($fhandle);
} else {
    tr_status("err");
    abort("Fehler beim Öffnen der Torrent-Datei auf dem Server (Schreibzugriff verweigert) - bitte SysOp benachrichtigen!");
}
tr_status("ok");

write_log("torrentupload", "Der Torrent <a target=\"_blank\" href=\"details.php?id=$id\">$id ($torrent)</a> wurde von '<a target=\"_blank\" href=\"userdetails.php?id=$CURUSER[id]\">$CURUSER[username]</a>' hochgeladen.");

mysql_query("UPDATE users SET seedbonus = seedbonus+".($activated == "yes" ? $GLOBALS["SEEDBONUS_UPLOAD"] : $GLOBALS["SEEDBONUS_UPLOAD"]*2)." WHERE id = $CURUSER[id]");
mysql_query("INSERT INTO seedbonus_log (userid,seedbonus,date,torrent,part) VALUES('".$CURUSER[id]."','".($activated == "yes" ? $GLOBALS["SEEDBONUS_UPLOAD"] : $GLOBALS["SEEDBONUS_UPLOAD"]*2)."','".time()."','".$id."','addtorrent')") or sqlerr(__FILE__, __LINE__);

$picnum = 0;

if ($_FILES["pic1"]["name"] != "") {

    tr_msg("Vorschaubild ".($picnum+1)." verkleinern und ablegen");

    if (torrent_image_upload($_FILES["pic1"], $id, $picnum+1))
        $picnum++;

    tr_status("ok");

}

if ($_FILES["pic2"]["name"] != "") {

    tr_msg("Vorschaubild ".($picnum+1)." verkleinern und ablegen");

    if (torrent_image_upload($_FILES["pic2"], $id, $picnum+1))
        $picnum++;

    tr_status("ok");

}

if ($picnum) {
    @mysql_query("UPDATE torrents SET numpics=$picnum WHERE id=$id LIMIT 1");
}

// Create NFO image
tr_msg("NFO-Bild erzeugen");
if (gen_nfo_pic($nfo, $GLOBALS["BITBUCKET_DIR"]."/nfo-$id.png") == 0) {
    tr_status("err");
} else {
    tr_status("ok");
}

// IMDB-Nummer einfügen
if (trim($_POST["imdb"]) != "") {

  tr_msg("IMDB-Nummer einf&uuml;gen");

  $imdb_rating = imdb_rating($_POST["imdb"]);

  if(substr($_POST["imdb"],0,2) == "tt") $_POST["imdb"] = str_replace("tt", "", $_POST["imdb"]);

  mysql_query("UPDATE torrents SET imdb = " . sqlesc(trim($_POST["imdb"])) . ", imdb_rating = '".$imdb_rating."' WHERE id = " . intval($id) . " LIMIT 1");
  mysql_query("UPDATE torrents SET imdb_rating = '".$imdb_rating."' WHERE imdb = " . sqlesc(trim($_POST["imdb"])));

  tr_status("ok");

  if(trim($_POST["ytube"]) == "") {

	include_once("TMDb.php");

	$_POST["ytube"] = fetch_trailer("tt".$_POST["imdb"]);

	if(trim($_POST["ytube"]) != "") {

		tr_msg("YouTube-Link");
		$_POST["ytube"] = "http://www.youtube.com/watch?v=".$_POST["ytube"];
		$youtubelink = theyoutubelink($_POST["ytube"]);
		mysql_query("UPDATE torrents SET youtubelink = " . sqlesc(trim($youtubelink)) . " WHERE id = " . intval($id) . " LIMIT 1");
  		mysql_query("UPDATE torrents SET youtubelink = '".sqlesc(trim($youtubelink))."' WHERE imdb = " . sqlesc(trim($_POST["imdb"])));
		tr_status("ok");

	}

  }

  $query_recommended = mysql_query("SELECT id,name,size FROM torrents WHERE id != " . intval($id) . " AND imdb = " . sqlesc(trim($_POST["imdb"])) . " AND recommended='yes' ORDER BY added DESC");
  $row_recommended = mysql_num_rows($query_recommended);

  if($row_recommended > 0) {

  	$recommended_text = "Es wurde festgestellt, dass zum Torrent [url=" . $BASEURL . "/details.php?id=" . $id . "][b]" . $torrent . "[/b][/url] ".$row_recommended." ähnliche".($row_recommended == 1 ? "r" : "")." Torrent".($row_recommended != 1 ? "s" : "")." als \"Empfohlene Torrents\" markiert ".($row_recommended == 1 ? "ist" : "sind").".\n";

  	while($result_recommended = mysql_fetch_array($query_recommended)) {

  		$recommended_text .= "\n[url=" . $BASEURL . "/details.php?id=" . $result_recommended["id"] . "][b]" . $result_recommended["name"] . "[/b][/url] (".mksize($result_recommended["size"]).")";

  	}

  	$recommended_text .= "\n\nBitte prüfen und entsprechend reagieren.";

  	sendPersonalMessage(0, 0, "Empfohlene Torrents", $recommended_text, PM_FOLDERID_MOD, 0, "open");

  }

}

$dl_method = 0+$_POST["dl_method"];

if($_POST["https"] == 1) {
	$https = 1;
} else {
	$https = 0;
}

if($dl_method > 0) {

	tr_msg("Torrent in den Client einf&uuml;gen");

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
	$torrentname = $dname;

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

if (isset($_POST["request"]) && (intval($_POST["request"]) > 0)) {

	tr_msg("Request erfüllt");

	$query_request = mysql_query('SELECT id, gebot, gebot2, user, titel, edited, closed, kategorie, kategorie2 FROM requests WHERE id = ' . intval($_POST["request"]) . ' LIMIT 1');
    $request = mysql_fetch_array($query_request);

    if(mysql_num_rows($query_request) > 0 && $request["closed"] == 0 && (($request["edited"] > 0 && $request["edited"] == $CURUSER["id"]) || $request["edited"] == 0) && ($catid == $request["kategorie"] || in_array($catid, explode(",",$request["kategorie2"])))) {

    	mysql_query("UPDATE requests SET closed = ".sqlesc($id).", ruser = ".intval($CURUSER["id"]).", closedate = ".sqlesc(get_date_time(time())).", edited='0', editdate='0' WHERE id = " . intval($_POST["request"]));

		$requestid = $request["id"];
		$gebot = $request["gebot"];
		$gebot2 = $request["gebot2"];
		$requestowner = $request["user"];

		$result_requestowner = mysql_fetch_array(mysql_query("SELECT username FROM users WHERE id=".$requestowner." LIMIT 1"));
		$requestownername = $result_requestowner["username"];

		mysql_query("UPDATE users SET seedbonus = seedbonus+".$GLOBALS["SEEDBONUS_REQUEST"]." WHERE id = $CURUSER[id]");
		mysql_query("INSERT INTO seedbonus_log (userid,seedbonus,date,torrent,part) VALUES('".$CURUSER[id]."','".$GLOBALS["SEEDBONUS_UPLOAD"]."','".time()."','".$id."','addreqtorrent')") or sqlerr(__FILE__, __LINE__);
		mysql_query("UPDATE torrents SET request = 'yes' WHERE id = " . intval($id) . " LIMIT 1");

		if($gebot > 0) {
			mysql_query("UPDATE users SET uploaded = uploaded+$gebot WHERE id = $CURUSER[id]");
			write_modcomment($CURUSER[id], 0, "Request Bonus:\n".mksize($gebot)." von ".$requestownername." für ".$torrent." erhalten.");
			write_modcomment($requestowner, 0, "Request Bonus:\n".mksize($gebot)." an ".$CURUSER[username]." für ".$torrent." abgegeben.");
		}

		if($gebot2 > 0) {
			mysql_query("UPDATE users SET seedbonus = seedbonus+$gebot2 WHERE id = $CURUSER[id]");
			mysql_query("INSERT INTO seedbonus_log (userid,seedbonus,date,torrent,part) VALUES('".$CURUSER[id]."','".$gebot2."','".time()."','".$id."','addreqtorrent')") or sqlerr(__FILE__, __LINE__);
			write_modcomment($CURUSER[id], 0, "Request Bonus:\n".$gebot2." Seedpunkte von ".$requestownername." für ".$torrent." erhalten.");
			write_modcomment($requestowner, 0, "Request Bonus:\n".$gebot2." Seedpunkte an ".$CURUSER[username]." für ".$torrent." abgegeben.");
		}

		$titel = $request["titel"];

    	if($CURUSER["anon"] == "yes") {
    		$boxtext = "[color=".Lime."]Ein[/color] [color=".blue."][i]Anonymer[/i][/color] [color=".Lime."]User hat den Request[/color] [url=requests.php?action=info&id=".$requestid."][color=".red."][b]".htmlentities($titel)."[/b][/color][/url] [color=".Lime."]erfüllt.[/color][br][url=details.php?id=$id][color=".blue."][b]".$torrent."[/b][/color][/url]";
    		$user = "Ein [b][i]Anonymer[/i][/b] User";
    	} else {
    		$boxtext = "[color=".Lime."]Der User[/color] @".$CURUSER["username"]." [color=".Lime."]hat den Request[/color] [url=requests.php?action=info&id=".$requestid."][color=".red."][b]".htmlentities($titel)."[/b][/color][/url] [color=".Lime."]erfüllt.[/color][br][url=details.php?id=$id][color=".blue."][b]".$torrent."[/b][/color][/url]";
    		$user = "Der User @".$CURUSER[username];
    	}

    	$msg  = $user." hat Deinen Request [url=requests.php?action=info&id=".$requestid."][b]" . trim($titel) . "[/b][/url] mit dem Torrent [url=" . $BASEURL . "/details.php?id=" . $id . "][b]" . $torrent . "[/b][/url] erfüllt.";
    	$msg2  = $user." hat den Request [url=requests.php?action=info&id=".$requestid."][b]" . trim($titel) . "[/b][/url] mit dem Torrent [url=" . $BASEURL . "/details.php?id=" . $id . "][b]" . $torrent . "[/b][/url] erfüllt.";

    	sendPersonalMessage(0, $request["user"], "Request erfüllt", $msg, PM_FOLDERID_INBOX, 0);

    	$a = mysql_query("SELECT user FROM votes where what = 'requests' AND voteid = ".$requestid." AND user != ".$request["user"]." AND user != ".$CURUSER["id"]);

    	while($ar = mysql_fetch_array($a)) {

    		$votesid = $ar["user"];

    		sendPersonalMessage(0, $votesid, "Request erfüllt", $msg2);

    	}

    	$date = time();

    	mysql_query("INSERT INTO ajaxshoutbox (id, userid, username, date, text, text_c) VALUES ('id'," . sqlesc('0') . ", " . sqlesc('Request') . ", $date, " . sqlesc($boxtext) . ", " . sqlesc(format_comment($boxtext,false)) . ")");

    	tr_status("ok");

    } else {

    	tr_status("err");

    }

}

if($serieninfo > 0) {

	tr_msg("Abo PMs");

	$query_abos = mysql_query("SELECT * FROM abos WHERE serienid=".$serieninfo." AND userid!=".$CURUSER["id"]." AND lang IN ('0','".$lang."')");

	while($result_abos = mysql_fetch_array($query_abos)) {

		$cats = "";

		if(trim($result_abos["cats"]) != "") {

			$cats = explode(",",$result_abos["cats"]);

		}

		if(trim($result_abos["cats"]) == "" || in_array($catid,$cats)) {

    		$infomsg  = "Für dein Abo [b]".$serienname."[/b] wurde ein neuer Torrent hochgeladen:\n[url=" . $BASEURL . "/details.php?id=" . $id . "][b]" . $torrent . "[/b][/url]";

    		sendPersonalMessage(0, $result_abos["userid"], "Neuer Abo-Upload für ".$serienname, $infomsg, PM_FOLDERID_INBOX, 0);

		}

	}

	tr_status("ok");

}

$pretime = orlyread($dname);

if(trim($dname) != trim($pretime['release'])) unset($pretime);

if(isset($pretime['time'])) {

	mysql_query("UPDATE torrents SET pretime = " . sqlesc(intval($pretime['time'])) . " WHERE id = " . intval($id) . " LIMIT 1");

	$pretime_diff = strtotime($added)-$pretime['time'];
    $pretime_output = " (".mkprettytime($pretime_diff)." nach Pre)";
	//$pretime_output2 = "|  Pre ".$pretime['time'];

}

if(isset($pretime['nukeright'])) {
	mysql_query("UPDATE torrents SET nuked='yes', nukereason = " . sqlesc(trim($pretime['nukeright'])) . " WHERE id = " . intval($id) . " LIMIT 1");
}

if ($CURUSER["anon"] == 'yes') {
	$username = "*Anonym*";
} else {
	$username = $CURUSER["username"];
}

/*

$bot['ip'] = "http://www.screamlabs.at"; // your bot ip
$bot['port'] = 1239; // your script listen port

$bot['message'] = rawurlencode("$torrent | Size ".mksize($totallen)." | Category $catname |  Link $DEFAULTBASEURL/details.php?id=$id  Thanks to $username $pretime_output2");

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $bot['ip'].":".$bot['port']."/".$bot['message']);
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 2);
$output = curl_exec($ch);

curl_close($ch);

*/

if($CURUSER[anon] == "yes") {
	$text = "Ein [i]Anonymer[/i] User hat einen ".($kids == "yes" ? "Kids-" : (in_array($catid,$xxxcat) ? "XXX-" : ""))."Torrent in [b]".$catgroup."[/b] &raquo; ".$catname.($serieninfo > 0 ? " &raquo; [b]".$serienname."[/b]" : "")." hochgeladen.[br][url=".$DEFAULTBASEURL."/details.php?id=".$id."][color=#ff6600][b]".$torrent."[/b][/color][/url]".$pretime_output;
} else {
	$text = "Der User @".$CURUSER["username"]." hat einen ".($kids == "yes" ? "Kids-" : (in_array($catid,$xxxcat) ? "XXX-" : ""))."Torrent in [b]".$catgroup."[/b] &raquo; ".$catname.($serieninfo > 0 ? " &raquo; [b]".$serienname."[/b]" : "")." hochgeladen.[br][url=".$DEFAULTBASEURL."/details.php?id=".$id."][color=#ff6600][b]".$torrent."[/b][/color][/url]".$pretime_output;
}

$date = time();

mysql_query("INSERT INTO ajaxshoutbox (id, sbid, userid, username, date, text, text_c) VALUES ('id', '3', " . sqlesc('0') . ", " . sqlesc('System') . ", $date, " . sqlesc($text) . ", " . sqlesc(format_comment($text,false)) . ")");

end_table();
end_frame();

begin_frame("Torrent-Upload war erfolgreich!", FALSE, "650px");
?>
<p>Dein Torrent wurde erfolgreich hochgeladen. <b>Beachte</b> dass Dein Torrent erst
sichtbar wird, wenn der erste Seeder verfügbar ist!</p>
<?php

if (count($GLOBALS["uploaderrors"])) {
?>
<p>Beim Upload des Torrents ist mindestens ein unkritischer Fehler aufgetreten:</p>
<ul>
<?php
foreach($GLOBALS["uploaderrors"] as $error)
    echo "<li>$error</li>";
?>
</ul>
<?php
}

if ($activated == "no") {
?>
<p><b>Da Du kein Uploader bist, wurde Dein Torrent als Gastupload gewertet, und muss
zuerst von einem Moderator und freigeschaltet werden.
Dennoch kannst du den Torrent bereits herunterladen und seeden.</b> Bitte sende uns keine
Nachrichten mit der Bitte um Freischaltung. Das Team wurde bereits per PN &uuml;ber
Deinen Upload benachrichtigt, und wird sich baldm&ouml;glichst darum k&uuml;mmern.</p>
<?php
	if($dl_method == 0) {
		echo '<meta http-equiv="refresh" content="0; URL=download.php?torrent='.$id.'&https='.$https.'">';
	}
} else {
	mysql_query("UPDATE users SET uploads = uploads + 1 WHERE id = " .$CURUSER["id"]);
	if($dl_method == 0) {
		echo '<meta http-equiv="refresh" content="0; URL=download.php?torrent='.$id.'&https='.$https.'">';
	}
}
?>
<p><b>Wichtiger Hinweis:</b><br>Bevor Du den Torrent seeden kannst, musst Du den Torrent
erneut vom Tracker herunterladen, da beim Upload einige Änderungen an der Torrent-Datei
vorgenommen wurden. Dadurch hat der Torrent einen neuen Info-Hash erhalten, und beim
Download wird ebenfalls Dein PassKey in die Announce-URL eingefügt. <b>Das
&Auml;ndern der Announce-URL in Deiner soeben hochgeladenen Torrent-Metadatei gen&uuml;gt
nicht!</b></p>
<p style="text-align:center"><a target="_blank" href="details.php?id=<?=$id?>">Weiter zu den Details Deines Torrents</a></p>
<?php

end_table();
end_frame();

stdfoot();

hit_end();

?>
</center>