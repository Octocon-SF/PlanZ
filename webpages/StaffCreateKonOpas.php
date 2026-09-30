<?php

    $title="Staff - Create KonOpas File";
    require_once('db_functions.php');
    require_once('error_functions.php');
    require_once('render_functions.php');
    require_once('StaffCommonCode.php');
    require_once('konOpas_func.php');

    if (!empty($_GET['showpubstatus'])) {
        $showpubstatus = $_GET["showpubstatus"];
    }
    else {
        $showpubstatus = 2;
    }

    if (!empty($_GET['showbio'])) {
        $showbio = $_GET["showbio"];
    }
    else {
        $showbio = 1;
    }

    staff_header($title, true);

    $isLegacyFormat = getJsonExtractFormat() === KONOPAS_FORMAT_LEGACY;
?>

<div class="container">
    <div class="card">
        <div class="card-body">
<?php if ($isLegacyFormat) { ?>
            <p> This tool creates the KonOpas database files to update the KonOpas schedule.</p>
            <p class="mb-5"> It also creates the ConClár database files to update the ConClár schedule.</p>
<?php } else { ?>
            <p class="mb-5"> This tool creates the ConClár database files (schema version 2) to update the ConClár schedule.</p>
<?php } ?>

<?php


    $results = retrieveKonOpasData($showpubstatus, $showbio);
    if (empty($results)) {
        $message_error = "StaffCreateKonOpas.php: retrieveKonOpasData() did not return expected result or error indicator.";
        error_log($message_error);
        RenderError($message_error);
        exit();
    }
    if (!empty($results["message_error"])) {
        error_log("StaffCreateKonOpas.php: " . $results["message_error"]);
        RenderError($results["message_error"]);
        exit();
    }

    $infofile = retrieveInfoData();
    if (empty($infofile)) {
        $message_error = "StaffCreateKonOpas.php: retrieveInfoData() did not return expected result or error indicator.";
        error_log($message_error);
        RenderError($message_error);
        exit();
    }
    if (!empty($infofile["message_error"])) {
        error_log("StaffCreateKonOpas.php: " . $infofile["message_error"]);
        RenderError($infofile["message_error"]);
        exit();
    }

    $written = writeKonOpasExportFiles($results, $infofile);

    echo('<p>Format: ' . ($isLegacyFormat ? 'KonOpas compatible' : 'ConClár schema version 2') . "</p>\n");
    echo('<p>Number of program items: ' . $results["program_num_rows"] . "</p>\n");
    echo('<p>Number of participants: ' . $results["people_num_rows"] . "</p>\n");
    echo("<p>The following files were created:</p>\n<ul>\n");
    foreach ($written as $fileName) {
        echo('<li>' . htmlspecialchars($fileName) . "</li>\n");
    }
    echo("</ul>\n");

?>

<?php if ($isLegacyFormat) { ?>
            <p class="mt-5"> It will take roughly 5-10 minutes for the data to appear in the KonOpas app.</p>
            <p> Data shows up right away in the ConClár app, but the user will need to do a refresh.</p>
<?php } else { ?>
            <p class="mt-5"> Data shows up right away in the ConClár app, but the user will need to do a refresh.</p>
<?php } ?>

        </div>
    </div>
</div>

<?php


staff_footer();

?>
