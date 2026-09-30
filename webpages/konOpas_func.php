<?php
// Copyright (c) 2015-2019 Peter Olszowka. All rights reserved. See copyright document for more details.

require_once('db_functions.php');

// Export formats and file layouts selectable via JSON_EXTRACT_FORMAT and JSON_EXTRACT_FILES in db_name.php.
define('KONOPAS_FORMAT_LEGACY', 'konopas'); // KonOpas compatible bare arrays (ConClár PROGRAM_DATA_URL/PEOPLE_DATA_URL)
define('KONOPAS_FORMAT_V2', 'v2');          // ConClár schemaVersion 2 objects (ConClár DATA_URLS)
define('KONOPAS_FILES_SEPARATE', 'separate');
define('KONOPAS_FILES_COMBINED', 'combined');
define('KONOPAS_FILES_BOTH', 'both');

function getJsonExtractFormat() {
    if (!defined('JSON_EXTRACT_FORMAT') || JSON_EXTRACT_FORMAT === '') {
        return KONOPAS_FORMAT_LEGACY;
    }
    $format = strtolower(JSON_EXTRACT_FORMAT);
    if (!in_array($format, array(KONOPAS_FORMAT_LEGACY, KONOPAS_FORMAT_V2), true)) {
        error_log("konOpas_func.php: Unknown JSON_EXTRACT_FORMAT '" . JSON_EXTRACT_FORMAT . "', using '" . KONOPAS_FORMAT_LEGACY . "'.");
        return KONOPAS_FORMAT_LEGACY;
    }
    return $format;
}

function getJsonExtractFiles() {
    if (!defined('JSON_EXTRACT_FILES') || JSON_EXTRACT_FILES === '') {
        return KONOPAS_FILES_BOTH;
    }
    $files = strtolower(JSON_EXTRACT_FILES);
    if (!in_array($files, array(KONOPAS_FILES_SEPARATE, KONOPAS_FILES_COMBINED, KONOPAS_FILES_BOTH), true)) {
        error_log("konOpas_func.php: Unknown JSON_EXTRACT_FILES '" . JSON_EXTRACT_FILES . "', using '" . KONOPAS_FILES_BOTH . "'.");
        return KONOPAS_FILES_BOTH;
    }
    return $files;
}

function getJsonExtractWriteTestCopy() {
    return !defined('JSON_EXTRACT_WRITE_TEST_COPY') || JSON_EXTRACT_WRITE_TEST_COPY;
}

// Encode as JSON, returning false (and logging) on failure rather than silently writing an empty file.
function konOpasJsonEncode($value) {
    $json = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        error_log("konOpas_func.php: json_encode failed: " . json_last_error_msg());
    }
    return $json;
}

// Gather the published schedule and participant data from the database in a format neutral form.
function retrieveKonOpasSourceData($showpubstatus, $showbio) {
    $ConStartDatim = CON_START_DATIM;

    // first query: which people are on which sessions
    $query = <<<EOD
SELECT
    SCH.sessionid,
    P.badgeid,
    P.pubsname,
    P.sortedpubsname,
    POS.moderator
FROM
         Schedule SCH
    JOIN Sessions S USING (sessionid)
    JOIN ParticipantOnSession POS USING (sessionid)
    JOIN Participants P USING (badgeid)
WHERE
    S.pubstatusid IN ($showpubstatus) /* Public */
ORDER BY
    SCH.sessionid,
    POS.moderator DESC,
    P.badgeid;
EOD;
    $result = mysqli_query_with_error_handling($query);

    $sessionHasParticipant = array();
    $participantOnSession = array();
    while($row = mysqli_fetch_assoc($result)) {
        $sessionHasParticipant[$row["sessionid"]][] = array(
            "id" => $row["badgeid"],
            "name" => $row["pubsname"],
            "moderator" => $row["moderator"] == "1"
            );
        $participantOnSession[$row["badgeid"]][] = $row["sessionid"];
    }


// query: active session information
    $query = <<<EOD
SELECT
    S.sessionid AS id,
    S.title,
    S.trackid,
    TR.trackname,
    S.typeid,
    TY.typename,
    R.roomname AS loc,
    R.floor,
    S.divisionid,
    D.divisionname,
    DATE_FORMAT(duration, '%k') * 60 + DATE_FORMAT(duration, '%i') AS mins,
    S.progguiddesc AS `desc`,
    S.progguidhtml AS `deschtml`,
    DATE_FORMAT(ADDTIME('$ConStartDatim',SCH.starttime),'%Y-%m-%d') as date,
    DATE_FORMAT(ADDTIME('$ConStartDatim',SCH.starttime),'%H:%i') as time,
    DATE_FORMAT(ADDTIME('$ConStartDatim',SCH.starttime),'%Y-%m-%d %H:%i:00') as datim,
    GROUP_CONCAT(TA.tagid ORDER BY TA.tagid SEPARATOR ',') AS tagidlist,
    GROUP_CONCAT(TA.tagname ORDER BY TA.tagid SEPARATOR ',') AS taglist,
    S.meetinglink
FROM
              Schedule SCH
         JOIN Sessions S USING (sessionid)
         JOIN Tracks TR USING (trackid)
         JOIN Types TY USING (typeid)
         JOIN Rooms R USING (roomid)
         JOIN Divisions D ON (S.divisionid = D.divisionid)
    LEFT JOIN SessionHasTag SHT USING (sessionid)
    LEFT JOIN Tags TA USING (tagid)
WHERE
    S.pubstatusid IN ($showpubstatus) /* Public */
GROUP BY
    S.sessionid
ORDER BY
    S.sessionid;
EOD;
    $result = mysqli_query_with_error_handling($query);

    $sessions = array();
    while($row = mysqli_fetch_assoc($result)) {
        $tags = array();
        if (!empty($row["taglist"])) {
            $tagids = explode(',', $row["tagidlist"]);
            foreach (explode(',', $row["taglist"]) as $index => $tagname) {
                $tags[] = array("id" => $tagids[$index], "name" => $tagname);
            }
        }
        $locfloor = '';
        if ($row["floor"] && $row["floor"] != "") {
            $locfloor = ' - ' . $row["floor"];
        }
        $desc = $row["desc"];
        if (!empty($row["deschtml"])) {
            $desc = $row["deschtml"];
        }
        $sessions[] = array(
            "id"           => $row["id"],
            "title"        => $row["title"],
            "trackid"      => $row["trackid"],
            "trackname"    => $row["trackname"],
            "divisionid"   => $row["divisionid"],
            "divisionname" => $row["divisionname"],
            "typeid"       => $row["typeid"],
            "typename"     => $row["typename"],
            "tags"         => $tags,
            "date"         => $row["date"],
            "time"         => $row["time"],
            "datim"        => $row["datim"],
            "mins"         => $row["mins"],
            "loc"          => $row["loc"] . $locfloor,
            "people"       => isset($sessionHasParticipant[$row["id"]]) ? $sessionHasParticipant[$row["id"]] : array(),
            "desc"         => $desc,
            "meetinglink"  => $row["meetinglink"]
            );
    }


// query: active participant information
    $query = <<<EOD
SELECT
    P.badgeid,
    P.pubsname,
    P.sortedpubsname,
    P.bio,
    P.htmlbio,
    CD.firstname,
    CD.lastname,
    P.approvedphotofilename
FROM
         Participants P
    JOIN CongoDump CD USING (badgeid)
WHERE
    P.badgeid IN (
        SELECT POS.badgeid
        FROM
                 ParticipantOnSession POS
            JOIN Sessions S USING (sessionid)
            JOIN Schedule SCH USING (sessionid)
        WHERE S.pubstatusid IN ($showpubstatus) /* Public */
        )
EOD;
    $result = mysqli_query_with_error_handling($query);

    $people = array();
    while($row = mysqli_fetch_assoc($result)) {
        if (empty($row["pubsname"])) {
            $name = $row["lastname"] . ', ' . $row["firstname"];
        } else {
            $name = $row["pubsname"];
        }
        if ($showbio==0) {
            $row["bio"] = '';
            $row["htmlbio"] = '';
        }
        $bio = $row["bio"];
        if (!empty($row["htmlbio"])) {
            $bio = $row["htmlbio"];
        }
        // Currently links only used for photo link, but plan to add other link types.
        $links = [];
        if (defined('PHOTO_EXTRACT_LINK_TYPE') && !empty(PHOTO_EXTRACT_LINK_TYPE) && !empty($row['approvedphotofilename'])) {
            // Construct link to photo image on current server.
            $links[PHOTO_EXTRACT_LINK_TYPE] = 'http'
                                            . (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 's' : '')
                                            . '://'
                                            . $_SERVER['SERVER_NAME']
                                            . PHOTO_PUBLIC_DIRECTORY
                                            . '/'
                                            . $row['approvedphotofilename'];
        }
        $people[] = array(
            "id" => $row["badgeid"],
            "name" => $name,
            "sortname" => $row["sortedpubsname"],
            "prog" => isset($participantOnSession[$row["badgeid"]]) ? $participantOnSession[$row["badgeid"]] : array(),
            "bio" => $bio,
            "links" => $links
            );
    }

    return array("sessions" => $sessions, "people" => $people);
}

// Program array in the KonOpas compatible format.
function buildKonOpasProgram($sessions) {
    $program = array();
    foreach ($sessions as $session) {
        $tagsArray = array("Track:".$session["trackname"], "Division:".$session["divisionname"]);
        if (!empty($session['typename'])) {
            $tagsArray[] = 'Type:'.$session['typename'];
        }
        foreach ($session["tags"] as $tag) {
            $tagsArray[] = "Tag:".$tag["name"];
        }
        $people = array();
        foreach ($session["people"] as $person) {
            $people[] = array("id" => $person["id"], "name" => $person["name"].($person["moderator"] ? " (moderator)" : ""));
        }
        $programRow = array(
            "id"     => $session["id"],
            "title"  => $session["title"],
            "tags"   => $tagsArray,
            "date"   => $session["date"],
            "time"   => $session["time"],
            "mins"   => $session["mins"],
            "loc"    => array($session["loc"]),
            "people" => $people,
            "desc"   => $session["desc"],
            "links"  => []
            );
        if (!empty($session["meetinglink"])) {
            $programRow["links"] = ["meeting" => $session["meetinglink"]];
        }
        $program[] = $programRow;
    }
    return $program;
}

// People array in the KonOpas compatible format.
function buildKonOpasPeople($people) {
    $peopleArray = array();
    foreach ($people as $person) {
        $peopleArray[] = array(
            "id" => $person["id"],
            "name" => array($person["name"]),
            "sortname" => $person["sortname"],
            "prog" => $person["prog"],
            "bio" => $person["bio"],
            "links" => $person["links"]
            );
    }
    return $peopleArray;
}

// Schedule array in the ConClár schemaVersion 2 format: ISO 8601 datetime with timezone offset,
// object tags with categories, and people referenced by id only (names come from the people array).
function buildConclarV2Schedule($sessions) {
    $timezone = new DateTimeZone(PHP_DEFAULT_TIMEZONE);
    $schedule = array();
    foreach ($sessions as $session) {
        $tagsArray = array(
            array("value" => "track-".$session["trackid"], "category" => "Track", "label" => $session["trackname"]),
            array("value" => "division-".$session["divisionid"], "category" => "Division", "label" => $session["divisionname"])
            );
        if (!empty($session['typename'])) {
            $tagsArray[] = array("value" => "type-".$session["typeid"], "category" => "Type", "label" => $session["typename"]);
        }
        foreach ($session["tags"] as $tag) {
            $tagsArray[] = array("value" => "tag-".$tag["id"], "category" => "Tag", "label" => $tag["name"]);
        }
        $people = array();
        foreach ($session["people"] as $person) {
            $personRef = array("id" => $person["id"]);
            if ($person["moderator"]) {
                $personRef["role"] = "moderator";
            }
            $people[] = $personRef;
        }
        $datetime = new DateTime($session["datim"], $timezone);
        $scheduleRow = array(
            "id"       => $session["id"],
            "title"    => $session["title"],
            "tags"     => $tagsArray,
            "datetime" => $datetime->format('c'),
            "mins"     => (int) $session["mins"],
            "loc"      => array($session["loc"]),
            "people"   => $people,
            "desc"     => $session["desc"],
            "links"    => []
            );
        if (!empty($session["meetinglink"])) {
            $scheduleRow["links"] = ["meeting" => $session["meetinglink"]];
        }
        $schedule[] = $scheduleRow;
    }
    return $schedule;
}

// Wrap schedule and/or people arrays in a ConClár schemaVersion 2 object.
function buildConclarV2File($schedule, $people) {
    $file = array(
        "schemaVersion" => 2,
        "info" => array("generator" => "PlanZ", "published" => date('c'))
        );
    if ($schedule !== null) {
        $file["schedule"] = $schedule;
    }
    if ($people !== null) {
        $file["people"] = $people;
    }
    return $file;
}

function buildKonOpasAppcache() {
    $appcache  = "CACHE MANIFEST\n";
    $appcache .= "# " . date("Y-m-d H:i:s") . "\n";
    $appcache .= "\n";
    $appcache .= "CACHE:\n";
    $appcache .= "cap/program.js\n";
    $appcache .= "cap/people.js\n";
    $appcache .= "cap/title.png\n";
    $appcache .= "konopas.min.js\n";
    $appcache .= "skin/konopas.css\n";
    $appcache .= "skin/icons.png\n";
    $appcache .= "skin/Roboto300.ttf\n";
    $appcache .= "skin/Roboto500.ttf\n";
    $appcache .= "skin/Oswald400.ttf\n";
    $appcache .= "favicon.ico\n";
    $appcache .= "\n";
    $appcache .= "NETWORK:\n";
    $appcache .= "*\n";
    return $appcache;
}

// Returns the export in the format selected by JSON_EXTRACT_FORMAT:
//   "format"  - KONOPAS_FORMAT_LEGACY or KONOPAS_FORMAT_V2
//   "program" - program/schedule only file contents
//   "people"  - people only file contents
//   "json"    - combined file contents (program/schedule and people)
//   "konopas" - KonOpas appcache manifest (legacy format only, otherwise empty)
function retrieveKonOpasData($showpubstatus = 2, $showbio = 1) {
    $results = array();
    if (prepare_db_and_more() === false) {
        $results["message_error"] = "Unable to connect to database.<br />No further execution possible.";
        return $results;
    };

    // $showpubstatus may come from the query string; only allow a comma separated list of ids.
    $showpubstatus = str_replace(' ', '', (string) $showpubstatus);
    if (!preg_match('/^\d+(,\d+)*$/', $showpubstatus)) {
        $showpubstatus = '2';
    }

    $sourceData = retrieveKonOpasSourceData($showpubstatus, $showbio);
    $results["program_num_rows"] = count($sourceData["sessions"]);     //used for reporting
    $results["people_num_rows"] = count($sourceData["people"]);        //used for reporting
    $results["format"] = getJsonExtractFormat();

    if ($results["format"] === KONOPAS_FORMAT_V2) {
        $schedule = buildConclarV2Schedule($sourceData["sessions"]);
        $people = buildKonOpasPeople($sourceData["people"]);
        $programJson = konOpasJsonEncode(buildConclarV2File($schedule, null));
        $peopleJson = konOpasJsonEncode(buildConclarV2File(null, $people));
        $combinedJson = konOpasJsonEncode(buildConclarV2File($schedule, $people));
        if ($programJson === false || $peopleJson === false || $combinedJson === false) {
            $results["message_error"] = "Unable to encode program data as JSON: " . json_last_error_msg();
            return $results;
        }
        $results["program"] = $programJson;
        $results["people"]  = $peopleJson;
        $results["json"]    = $combinedJson;
        $results["konopas"] = "";
        return $results;
    }

    $programJson = konOpasJsonEncode(buildKonOpasProgram($sourceData["sessions"]));
    $peopleJson = konOpasJsonEncode(buildKonOpasPeople($sourceData["people"]));
    if ($programJson === false || $peopleJson === false) {
        $results["message_error"] = "Unable to encode program data as JSON: " . json_last_error_msg();
        return $results;
    }

    // Encode program and people as JSON.
    if (defined('JSON_EXTRACT_ASSIGN_VARS') && JSON_EXTRACT_ASSIGN_VARS) {
        $programJson = "var program = " . $programJson . ";\n";
        $peopleJson = "var people = " . $peopleJson . ";\n";
    }

    //The json key is for general json output
    $results["json"]  = $programJson;
    $results["json"] .= $peopleJson;

    //The program, people and konopas keys are for KonOpas
    $results["program"]  = $programJson;
    $results["people"]   = $peopleJson;
    $results["konopas"]  = buildKonOpasAppcache();

    return $results;
}

// Write a single export file, rendering an error and exiting on failure.
function writeExportFile($fileName, $contents) {
    if (file_put_contents($fileName, $contents) === false) {
        $message_error = "Cannot write to " . $fileName . ".";
        error_log("konOpas_func.php: " . $message_error);
        RenderError($message_error);
        exit(1);
    }
}

// Write the data files selected by JSON_EXTRACT_FORMAT, JSON_EXTRACT_FILES and JSON_EXTRACT_WRITE_TEST_COPY,
// plus the ConClár info file. Returns the list of files written.
function writeKonOpasExportFiles($results, $infofile) {
    $files = getJsonExtractFiles();
    $testCopy = getJsonExtractWriteTestCopy();
    $toWrite = array();

    if ($results["format"] === KONOPAS_FORMAT_V2) {
        if ($files !== KONOPAS_FILES_COMBINED) {
            $toWrite["schedule.json"] = $results["program"];
            $toWrite["people.json"] = $results["people"];
            if ($testCopy) {
                $toWrite["scheduleTest.json"] = $results["program"];
                $toWrite["peopleTest.json"] = $results["people"];
            }
        }
        if ($files !== KONOPAS_FILES_SEPARATE) {
            $toWrite["conclarData.json"] = $results["json"];
            if ($testCopy) {
                $toWrite["conclarDataTest.json"] = $results["json"];
            }
        }
    } else {
        if ($files !== KONOPAS_FILES_COMBINED) {
            $toWrite["program.js"] = $results["program"];
            $toWrite["people.js"] = $results["people"];
            $toWrite["konopas.appcache"] = $results["konopas"];
        }
        if ($files !== KONOPAS_FILES_SEPARATE) {
            $toWrite["konOpasData.json"] = $results["json"];
            if ($testCopy) {
                $toWrite["konOpasDataTest.json"] = $results["json"];
            }
        }
    }

    if (!empty($infofile["output"])) {
        $toWrite["info.md"] = $infofile["output"];
        if ($testCopy) {
            $toWrite["info_test.md"] = $infofile["output"];
        }
    }

    $written = array();
    foreach ($toWrite as $fileName => $contents) {
        writeExportFile(JSON_EXTRACT_DIRECTORY . $fileName, $contents);
        $written[] = $fileName;
    }
    return $written;
}

function retrieveInfoData() {
    $infofile = array();
    if (prepare_db_and_more() === false) {
        $infofile["message_error"] = "Unable to connect to database.<br />No further execution possible.";
        return $infofile;
    };

    $ConStartDatim = CON_START_DATIM;
    $CON_NAME = CON_NAME;
    $CON_URL = CON_URL;

    // query to get locations data
    $query = <<<EOD
SELECT
    L.locationname,
    L.roomname,
    L.locationhours
FROM
    Locations L
ORDER BY display_order
EOD;
    $result = mysqli_query_with_error_handling($query);

    // Need to loop through locations and replace the html formatting with markdown tags
    // Assumes that the only html used is <br /> and <u></u> and <em></em>
    $locations = array();
    $locations["output"] = "# Department Hours and Locations" . "\n\n";
    while($row = mysqli_fetch_assoc($result)) {
        $locations["output"] .= "## " . $row["locationname"] . " - " . $row["roomname"] . "\n\n";
        $lochoursstr = $row["locationhours"];
        $lochoursstr = str_replace("<br />", "  ", $lochoursstr);
        $lochoursstr = str_replace(array("<em>", "</em>"), array("*", "*"), $lochoursstr);
        $lochoursstr = str_replace(array("<u>", "</u>"), array("**", "**"), $lochoursstr);
        $lochoursstr = str_replace(array("<strong>", "</strong>"), array("***", "***"), $lochoursstr);
        
        $locations["output"] .= $lochoursstr . "\n";
    }


    $infofile["output"]  = "\n";
    $infofile["output"] .= "Program and participant data were last updated " . date("F j, Y, g:i a T") . "\n\n";
    $infofile["output"] .= "---\n";

    $infofile["output"] .= "# Information" . "\n\n";
    $infofile["output"] .= "Use markdown to enter information here." . "\n\n";
    $infofile["output"] .= "All times listed are in CST unless otherwise noted." . "\n\n";
    $infofile["output"] .= "---\n";

    $infofile["output"] .= "# Links" . "\n\n";
    //$infofile["output"] .= "[Example Link](https://chicon.org/)" . "\n\n";
    //$infofile["output"] .= "[Example link to image](image.jpg)" . "\n\n";
    $infofile["output"] .= "---\n";

    $infofile["output"] .= $locations["output"];
    $infofile["output"] .= "---\n";

    $infofile["output"] .= "# About Conclár" . "\n\n";
    $infofile["output"] .= "Conclár is a browser based program guide used by [" . $CON_NAME . "](" . $CON_URL . ")." . "\n\n";

    return $infofile;
}
?>