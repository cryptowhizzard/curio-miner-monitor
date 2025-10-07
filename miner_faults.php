<?php

error_reporting(E_ERROR | E_PARSE);

$TELEGRAM_BOT_TOKEN = '';
$CHAT_ID = '';

$mysqli = new mysqli($host, $user, $pass, 'miner_data');

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

function sendMessageToTelegram($message) {
    global $TELEGRAM_BOT_TOKEN, $CHAT_ID;

    $maxLength = 4000; // Keeping some buffer from Telegram's 4096 limit
    $messages = str_split($message, $maxLength);

    foreach ($messages as $index => $chunk) {
        $url = "https://api.telegram.org/bot$TELEGRAM_BOT_TOKEN/sendMessage";
        $data = [
            'chat_id' => $CHAT_ID,
            'text' => $chunk,
            'parse_mode' => 'Markdown'
        ];

        $options = [
            'http' => [
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($data)
            ]
        ];

        $context = stream_context_create($options);
        file_get_contents($url, false, $context);

        if ($index < count($messages) - 1) {
            sleep(30); // Wait for 30 seconds before sending the next part
        }
    }
}

function getMinerBrief($minerID) {
    $url = "https://filfox.info/api/v1/miner/$minerID/brief";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $output = curl_exec($ch);
    curl_close($ch);

    return json_decode($output, true);
}

function getStoredFaults($mysqli, $minerID) {
    $stmt = $mysqli->prepare("SELECT faulty_sectors FROM miners WHERE miner_id = ? LIMIT 1");
    $stmt->bind_param("s", $minerID);
    $stmt->execute();
    $stmt->bind_result($faultySectors);
    $stmt->fetch();
    $stmt->close();
    
    return $faultySectors;
}

function updateFaults($mysqli, $minerID, $faultySectors) {
    $stmt = $mysqli->prepare("UPDATE miners SET faulty_sectors = ?, last_checked = NOW() WHERE miner_id = ?");
    $stmt->bind_param("is", $faultySectors, $minerID);
    $stmt->execute();
    $stmt->close();
}

$minerNames = [
    'f0000000' => 'MinerName',
    'f0111111' => 'MinerName2',
];

$alertMessages = [];

foreach ($minerNames as $minerID => $minerName) {
    $minerBriefData = getMinerBrief($minerID);
    if ($minerBriefData === null) {
        continue;
    }

    $currentFaults = $minerBriefData['faultySectors'] ?? 0;

    // Get the stored number of faulty sectors
    $previousFaults = getStoredFaults($mysqli, $minerID);

    if ($previousFaults !== null) {
        // Compare the difference in faulty sectors
        $faultDifference = $currentFaults - $previousFaults;

        if (abs($faultDifference) > 100) {
            // Create a Telegram message for this miner
            $message = "⚠️ *Alert for Miner $minerName ($minerID)* ⚠️\n";
            $message .= "Fault count changed by *" . abs($faultDifference) . "*.\n";
            $message .= "🔹 *Previous Faults:* $previousFaults\n";
            $message .= "🔹 *Current Faults:* $currentFaults\n";
            $message .= "------------------------\n";

            $alertMessages[] = $message;

            // Send immediately if the message is long
            if (strlen($message) > 3500) {
                sendMessageToTelegram($message);
            }
        }
    }

    // Update the stored faulty sectors in the database
    updateFaults($mysqli, $minerID, $currentFaults);
}

// Send accumulated messages in chunks
if (!empty($alertMessages)) {
    $combinedMessage = "";
    foreach ($alertMessages as $alert) {
        if (strlen($combinedMessage) + strlen($alert) > 4000) {
            sendMessageToTelegram($combinedMessage);
            $combinedMessage = "";
            sleep(30); // Prevent Telegram rate limits
        }
        $combinedMessage .= $alert;
    }

    if (!empty($combinedMessage)) {
        sendMessageToTelegram($combinedMessage);
    }
}

$mysqli->close();
?>

