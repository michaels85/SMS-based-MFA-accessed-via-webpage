<?php
    //  Author: User <user@domain.com>
    //  above line required to be retained.  Please send updates/improvements.

    // things to do
        // move from XmlHttpRequest to fetch()
        // add listeners

    /*  Your oganization is using Microsoft 365 and decides to use SMS-based MFA.  But this requires users to own a phone, it
        has power, signal, and can receive SMS messages.   Rather, each user's profile contains an SMS number from a provider
        that can execute a script upon an incoming SMS message. This is that script. We will receive the SMS message, extract
        the MFA code, store it in a database, and display the MFA codes that are currently valid.  A refresh option exists to
        update the list without refreshing the web page.
    */

    /*
        http://zetcode.com/php/sqlite3/
        https://stackoverflow.com/questions/6480756/php-sqlite3-error to test for errors and suggest try catch block
        https://www.dyn-web.com/tutorials/php-js/json/multidim-arrays.php
        https://www.w3schools.com/js/js_ajax_intro.asp
    */

    // global static variables
    $br = '<br />';
    $version = '0.1';

    // for PHP date operations
    date_default_timezone_set('American/Toronto');

    // in seconds, set by Microsoft
    $MFAcodeValidFor = 300;

    // extract this pattern as an MFA code
    $regexExpression = '/[0-9]{6}/';

    // sqlite3 database filename and encryption strinblueTableg
    $dbFileName = 'Microsoft365MFAcodes.db';
    $dbPhrase = 'Enter a pass phrase here.';

    // if necessary create, and open the database with RW access
    $dbHandle = new SQLite3($dbFileName, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE, $dbPhrase) or die('Unable to open database');
    if ($dbHandle->lastErrorCode() <> 0) { echo 'Error creating/opening database'; exit; }

    // create a table in the database if one doesn't exist already
    $dbHandle->query('CREATE TABLE IF NOT EXISTS MFAcodes (id INTEGER PRIMARY KEY, MFAcode INTEGER, ReceivedEpoch INTEGER)') or die('Create db failed');
    if ($dbHandle->lastErrorCode() <> 0) { echo 'Error creating table'; exit; }

    // if the URL has ?sample=true, delete all existing data, and add sample datum with upcoming expiry times
    if ($_GET['sample']) {
        $dbSamplesDeleteExistingData = true;
        $numberOfSamples = 10;
        $sampleExpiresEvery = 5;
        $sampleExpiresSpan = 3;
        if ($dbSamplesDeleteExistingData) {
            $queryString = 'DELETE FROM MFAcodes';
            $dbHandle->exec($queryString) or die('Error deleting data');
        }
        for ($i = 1; $i <= $numberOfSamples; $i++) {
            $secondsLeft = mt_rand($i * $sampleExpiresEvery, $i * $sampleExpiresEvery + $sampleExpiresSpan);
            $ReceivedEpoch = date("U") - $MFAcodeValidFor + $secondsLeft;
            $queryString = 'INSERT INTO MFAcodes (MFAcode, ReceivedEpoch) VALUES (' . mt_rand(1000, 9999) . ', ' . $ReceivedEpoch . ')';
            $dbHandle->exec($queryString) or die('Error adding data');
        }
    }

    // assign explicit POSIX permissions to be u=rw.  Send an e-mail upon failure.
    $myEmailAddress = '';
    if (!chmod($dbFileName, 0600)) mail($myEmailAddress, "Can't set POSIX on db", "Error in chmod command", "From: MFA Simulator");

    // save incoming MFA code
    if (isset($_GET['stamp'])) {
        // future consideration: switch to array_walk for the entire _GET array?
        $Stamp   = HTMLSpecialChars($_GET['stamp']);
        $Stamp = strtotime($Stamp);
        $Message = HTMLSpecialChars($_GET['message']);

        // extract the MFA code from the SMS message
        $matchFound = preg_match($regexExpression, $Message, $MFAcode);

        // save data to database
        if ($matchFound) {
            $queryString = 'INSERT INTO MFAcodes (MFAcode, ReceivedEpoch) VALUES (' . $MFAcode[0] . ', ' . $Stamp . ')';
            $dbHandle->exec($queryString) or die('Error adding data');
            if ($dbHandle->lastErrorCode() <> 0) { echo 'Error adding data'; exit; }
            $dbHandle->close();
        }
    }

    $stillValidMFA = date('U') - $MFAcodeValidFor;
    $queryString = 'SELECT MFAcode, time(ReceivedEpoch, "unixepoch", "localtime", "+1 hour") as ReceivedString, ReceivedEpoch FROM MFAcodes WHERE ReceivedEpoch > ' . $stillValidMFA . ' ORDER BY ReceivedEpoch DESC';
    $statement = $dbHandle->prepare($queryString);
    $result = $statement->execute();
    if ($dbHandle->lastErrorCode() <> 0) { echo 'Error reading data'; exit; }
    $assocArrayToSendToJS = array();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $assocArrayToSendToJS[] = $row; }
    $result->finalize();
    $dbHandle->close();

    // this line sends data if it is an XHR call
    if ($_SERVER['QUERY_STRING'] == 'XHR') { echo json_encode($assocArrayToSendToJS, JSON_PRETTY_PRINT); exit; }
?>
<!DOCTYPE HTML>
<html lang="en">
    <head>
        <title>MFA Codes for Office 365 Login</title>
        <style>
            .expired { text-decoration: line-through; }
            .expiredCleared { display: none; text-decoration: line-through; }
            .MFAcodesTable { border: 3px dashed grey; border-collapse: collapse; font-family: arial; margin-left: auto; margin-right: auto; padding: 15px; text-align: center; width: 40%; }
            .MFAcodesTable tr:nth-child(even) { background: #D0E4F5; }
            caption, th, td { padding: 10px; }
            thead { background-image: linear-gradient(to right, red, violet, blue, violet, red); border: 3px solid black; }
            tfoot { background: #1C6EA4; border: 3px solid black; text-align: right; }
        </style>
    </head>
    <body>
        <table class='MFAcodesTable' id='MFAcodesTable'>
            <caption>MFA codes received from Microsoft in the last 5 minutes<br /></caption>
            <colgroup><col style='width: 35px;'><col style='width: 35px;'><col style='width: 35px;'></colgroup>
            <thead><tr><th>MFA Code</th><th>Received</th><th>Expires In&emsp;<a href="javascript:populateTbody(allRows.reverse());" style="font-size: 28px; vertical-align: middle;">&#129093;</a></th></tr></thead>
            <tbody></tbody>
            <tfoot><tr><th></th><th></th><th></th></tr></tfoot>
        </table>
        <div style='padding-top: 30px; text-align: center;'>
            <button id='refresh' onClick='this.disabled=true; fetch("?XHR");'>Refresh Now&ensp;<span style='font-size: 18px;'>&#8635;</span></button>
            <br /><br />
            <input id='autoRemoveExpired' type='checkbox' onchange='handleChange(this);'>
            <label for='autoRemoveExpired'>Auto-remove expired</label> or 
            <button id='removeExpired' onClick='removeExpired();'>Remove Expired&ensp;<span style='font-size: 18px;'>&#9746;</span></button>
        </div>
        <script>
            // Javascript constants
            const MFAcodeValidFor = <?php echo $MFAcodeValidFor; ?>;
            const tableTbodyRef = document.getElementById('MFAcodesTable').getElementsByTagName('tbody')[0];
            var allRows = <?php echo json_encode($assocArrayToSendToJS, JSON_PRETTY_PRINT); ?>;
            populateTbody(allRows);
            var xhr = new XMLHttpRequest();
            var intervalHandle = setInterval(updateExpiresIn, 1000);

            function populateTbody(data) {
                tableTbodyRef.innerHTML = '';
                if (data.length == 0) {
                    var newRow = tableTbodyRef.insertRow(-1);
                    var newCell = newRow.insertCell(-1);
                    newCell.colSpan = "3";
                    newCell.style = 'line-height: 99px;';
                    newCell.innerHTML = 'no valid MFA codes exist at this time';
                    clearInterval(intervalHandle);
                } else {
                    // cycle through data and create a table row for each object row
                    for (i = 0; i < data.length; i++) {
                        var eachRowValues = Object.values(data[i]);
                        var newRow = tableTbodyRef.insertRow(-1);
                        // cycle through each object row and create table cell for each object cell
                        for (j = 0; j <= 2; j++) {
                            var newCell = newRow.insertCell(-1);
                            if (j <= 1) newCell.innerHTML = '&ensp;' + eachRowValues[j] + '&ensp;';
                        }
                    }
                    intervalHandle = setInterval(updateExpiresIn, 1000);
                }
            }

            function removeExpired() {
                for (i = allRows.length; i > 0; i--) {
                    if (Object.values(allRows[i-1])[2] < rightNow - MFAcodeValidFor) {
                        allRows.pop();
                    }
                }
                populateTbody(allRows);
            }

            function handleChange(checkbox) { document.getElementById('removeExpired').disabled = document.getElementById('autoRemoveExpired').checked; }

            // initiates the XHR sequence
            function refresh() {
                clearInterval(intervalHandle);
                xhr.open('GET', '?XHR', true);
                xhr.send();
                intervalHandle = setInterval(updateExpiresIn, 1000);
                document.getElementById('refresh').disabled = false;
            }
            // do this if XHR was successful
            xhr.onreadystatechange = function() {
                if (this.readyState == 4 && this.status == 200) { 
                    allRows = JSON.parse(xhr.responseText);
                    populateTbody(allRows);
                }
            };

            // every second in the browser do this:
            function updateExpiresIn() {
                if (document.getElementById('autoRemoveExpired').checked) removeExpired();

//                var now = new Date().toLocaleString("en-US", {timeZone: "America/Toronto"});
                rightNow = Math.round(Date.now() / 1000);
                // cycle through all object rows, if this timezone isn't America/Toronto, the app fails
                for (i = 0; i < allRows.length; i++) {
                    // calculate when the MFA code expires
                    expiresIn = (Number(Object.values(allRows[i])[2]) + MFAcodeValidFor) - rightNow;
                    // deal with MFA codes expiring soon
                    if (expiresIn >= 1 && expiresIn <= 20) tableTbodyRef.rows[i].style.color = 'red';
                    if (expiresIn >= 1 && expiresIn <=  9) tableTbodyRef.rows[i].cells[2].innerHTML = 'expires in ' + expiresIn;
                    if (expiresIn >  9)                    tableTbodyRef.rows[i].cells[2].innerHTML = Math.floor(expiresIn / 60) + ':' + ('0'+Math.floor(expiresIn % 60)).slice(-2);
                    if (expiresIn <= 0) {
                        if (document.getElementById('autoRemoveExpired').checked) {
                            tableTbodyRef.rows[i].className = 'expiredCleared';
                        } else {
                            tableTbodyRef.rows[i].className = 'expired';
                        }
                        tableTbodyRef.rows[i].style.color = 'slategrey';
                        tableTbodyRef.rows[i].cells[2].innerHTML = '&ensp;expired&ensp;';
                    }
                }
            }
        </script>
    </body>
</html>
