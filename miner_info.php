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

    $maxLength = 4000;  // Telegram limit is 4096, but we keep some buffer
    $messages = str_split($message, $maxLength); // Split the message into chunks

    foreach ($messages as $index => $chunk) {
        $url = "https://api.telegram.org/bot$TELEGRAM_BOT_TOKEN/sendMessage";
        $data = [
            'chat_id' => $CHAT_ID,
            'text' => $chunk,
            'parse_mode' => 'Markdown'  // Enable Markdown mode
        ];

        $options = [
            'http' => [
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($data)
            ]
        ];

        $context = stream_context_create($options);
        file_get_contents($url, false, $context); // Send the chunk

        if ($index < count($messages) - 1) { // Don't sleep after the last message
            sleep(30);  // Wait for 30 seconds before sending the next part
        }
    }
}

function apiRequestWithRetry($url) {
    $retryInterval = 60;
    $success = false;
    $attempts = 0;
    $maxAttempts = 5;
    
    while (!$success && $attempts < $maxAttempts) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 1);

        $output = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode == 200) {
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $body = substr($output, $headerSize);
            $success = true;
        } else {
            echo "Failed to fetch data from URL: $url with HTTP Code: $httpCode\n";
            sleep($retryInterval);  // Wait before retrying
            $attempts++;
        }

        curl_close($ch);
    }

    if (!$success) {
        return null;
    }

    return $body;
}

function getMinerPower($minerID) {
    $url = "https://filfox.info/api/v1/address/$minerID";
    $output = apiRequestWithRetry($url);
    if ($output === null) {
        return null;
    }

    echo "Debug: Miner Power Data for $minerID: $output\n";
    return json_decode($output, true);
}

function getMinerBrief($minerID) {
    $url = "https://filfox.info/api/v1/miner/$minerID/brief";
    $output = apiRequestWithRetry($url);
    if ($output === null) {
        return null;
    }

    echo "Debug: Miner Brief Data for $minerID: $output\n";
    return json_decode($output, true);
}

function getMinerBlocks($minerID, $page) {
    $url = "https://filfox.info/api/v1/address/$minerID/blocks?pageSize=100&page=$page";
    $output = apiRequestWithRetry($url);
    if ($output === null) {
        return null;
    }

    echo "Debug: Miner Blocks Data for $minerID Page $page: $output\n";
    return json_decode($output, true);
}

function blockExists($mysqli, $minerID, $timestamp) {
    $stmt = $mysqli->prepare("SELECT COUNT(*) FROM blocks WHERE miner_id = ? AND timestamp = ?");
    $stmt->bind_param("si", $minerID, $timestamp);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count > 0;
}

function insertMinerData($mysqli, $minerID, $minerName, $adjustedPowerTiB, $available, $workerBalanceFIL, $faultySectors, $last24Hours, $luckRate24Hours, $last7Days, $luckRate7Days, $last30Days, $luckRate30Days) {
    $stmt = $mysqli->prepare("INSERT INTO miners (miner_id, miner_name, adjusted_power, available_balance, worker_balance, faulty_sectors, last24_hours_blocks, luck_rate_24_hours, last7_days_blocks, luck_rate_7_days, last30_days_blocks, luck_rate_30_days) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssdddddiddid", $minerID, $minerName, $adjustedPowerTiB, $available, $workerBalanceFIL, $faultySectors, $last24Hours, $luckRate24Hours, $last7Days, $luckRate7Days, $last30Days, $luckRate30Days);
    $stmt->execute();
    $stmt->close();
}

function insertBlockData($mysqli, $minerID, $timestamp, $blockData) {
    if (!blockExists($mysqli, $minerID, $timestamp)) {
        $stmt = $mysqli->prepare("INSERT INTO blocks (miner_id, timestamp, block_data) VALUES (?, ?, ?)");
        $stmt->bind_param("sis", $minerID, $timestamp, $blockData);
        if (!$stmt->execute()) {
            echo "Error inserting block data: (" . $stmt->errno . ") " . $stmt->error . "\n";
        }
        $stmt->close();
    }
}

function insertPowerData($mysqli, $minerID, $power, $networkQualityPower) {
    $stmt = $mysqli->prepare("INSERT INTO power (miner_id, power, network_quality_power, date) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("sdd", $minerID, $power, $networkQualityPower);
    $stmt->execute();
    $stmt->close();
}

function get24HourGrowth($mysqli, $minerID, $currentPower) {
    $stmt = $mysqli->prepare("SELECT power FROM power WHERE miner_id = ? AND date > NOW() - INTERVAL 1 DAY ORDER BY date ASC LIMIT 1");
    $stmt->bind_param("s", $minerID);
    $stmt->execute();
    $stmt->bind_result($previousPower);
    $stmt->fetch();
    $stmt->close();
    
    if (!isset($previousPower)) {
        return null;
    }
    
    return $currentPower - $previousPower;
}

function insertDailyLuckRate($mysqli, $minerID, $luckRate24Hours) {
    $stmt = $mysqli->prepare("INSERT INTO daily_luck (miner_id, date, luck_rate) VALUES (?, CURDATE(), ?) ON DUPLICATE KEY UPDATE luck_rate = ?");
    $stmt->bind_param("sdd", $minerID, $luckRate24Hours, $luckRate24Hours);
    $stmt->execute();
    $stmt->close();
}

function getAverageLuckRate($mysqli, $minerID, $days) {
    $stmt = $mysqli->prepare("SELECT AVG(luck_rate) FROM daily_luck WHERE miner_id = ? AND date >= CURDATE() - INTERVAL ? DAY");
    $stmt->bind_param("si", $minerID, $days);
    $stmt->execute();
    $stmt->bind_result($averageLuckRate);
    $stmt->fetch();
    $stmt->close();
    
    return $averageLuckRate;
}

$minerNames = [
    'f00000' => 'Miner1',
    'f01111' => 'Miner2',
];

$minerIDs = array_keys($minerNames);

$totalPower = 0;
$totalWeightedLuck = 0;
$totalBlocksMined = 0;
$totalPowerPiB = 0;
$outputMessage = "";

foreach ($minerIDs as $minerID) {
    sleep(1); // Pause for 1 second between requests
    $minerPowerData = getMinerPower($minerID);
    if ($minerPowerData === null) {
        continue;
    }

    sleep(1); // Pause for 1 second between requests
    $minerBriefData = getMinerBrief($minerID);
    if ($minerBriefData === null) {
        continue;
    }

    // Fetch and process block data
    $blocks = [];
    $page = 0;
    $currentTime = time();
    $last24Hours = 0;
    $last7Days = 0;
    $last30Days = 0;
    $days30InSeconds = 86400 * 30;
    
    while (true) {
        sleep(1); // Pause for 1 second between requests
        $minerBlocksData = getMinerBlocks($minerID, $page);
        if ($minerBlocksData === null || empty($minerBlocksData['blocks'])) {
            break;
        }

        foreach ($minerBlocksData['blocks'] as $block) {
            if ($block['timestamp'] < $currentTime - $days30InSeconds) {
                break 2; // Break out of both the foreach and while loop
            }

            $blocks[] = $block;
            if ($block['timestamp'] >= $currentTime - 86400) {
                $last24Hours++;
            }
            if ($block['timestamp'] >= $currentTime - (86400 * 7)) {
                $last7Days++;
            }
            if ($block['timestamp'] >= $currentTime - $days30InSeconds) {
                $last30Days++;
            }

            // Insert block data into the database
            insertBlockData($mysqli, $minerID, $block['timestamp'], json_encode($block));
        }

        $page++;
    }

    echo "Debug: Blocks fetched for $minerID: " . count($blocks) . "\n";

    // Fetch correct values from the JSON response
    $rawAdjustedPower = $minerPowerData['miner']['qualityAdjPower'] ?? '0';
    $rawNetworkQualityAdjPower = $minerPowerData['miner']['networkQualityAdjPower'] ?? '0';

    // Handle zero network quality adjusted power
    if ($rawNetworkQualityAdjPower == '0') {
        echo "Network quality adjusted power is zero or not available for Miner ID: $minerID\n";
        continue;
    }

    // Convert quality adjusted power from bytes to terabytes
    $adjustedPowerTiB = $rawAdjustedPower / (1024 ** 4);
    $networkQualityAdjPowerTiB = $rawNetworkQualityAdjPower / (1024 ** 4);

    // Convert available balance from attoFIL to FIL
    $availableBalance = $minerPowerData['miner']['availableBalance'] ?? '0';
    $available = bcdiv($availableBalance, '1000000000000000000', 18);

    // Get the worker balance and convert from attoFIL to FIL
    $workerBalance = $minerPowerData['miner']['worker']['balance'] ?? '0';
    $workerBalanceFIL = bcdiv($workerBalance, '1000000000000000000', 18);

    // Get the number of faulty sectors
    $faultySectors = $minerBriefData['faultySectors'] ?? '0';

    // Calculate the expected blocks mined by the miner in the last 24 hours
    $blocksPerEpoch = 5;
    $totalNetworkBlocks24Hours = 2880; // 2 blocks per minute, 1440 minutes in 24 hours
    $totalNetworkBlocks7Days = $totalNetworkBlocks24Hours * 7;
    $totalNetworkBlocks30Days = $totalNetworkBlocks24Hours * 30;

    $expectedBlocks24Hours = ($adjustedPowerTiB / $networkQualityAdjPowerTiB) * $totalNetworkBlocks24Hours * $blocksPerEpoch;

    // Calculate the luck rate
    $luckRate24Hours = $expectedBlocks24Hours == 0 ? 0 : ($last24Hours / $expectedBlocks24Hours) * 100;

    // Accumulate total power and weighted luck rate
    $totalPower += $adjustedPowerTiB;
    $totalWeightedLuck += $luckRate24Hours * $adjustedPowerTiB;
    $totalBlocksMined += $last24Hours;

    // Calculate total power in PiB
    $totalPowerPiB += $adjustedPowerTiB / 1024;

    // Calculate 24-hour growth
    $growth24Hours = get24HourGrowth($mysqli, $minerID, $adjustedPowerTiB);

    // Insert daily luck rate
    insertDailyLuckRate($mysqli, $minerID, $luckRate24Hours);

    // Get 7-day and 30-day average luck rates from the database
    $luckRate7Days = getAverageLuckRate($mysqli, $minerID, 7);
    $luckRate30Days = getAverageLuckRate($mysqli, $minerID, 30);

    // Get the miner name from the associative array
    $minerName = $minerNames[$minerID];

    // Create output message
    $outputMessage .= "Miner Name: $minerName\n";
    $outputMessage .= "Miner ID: $minerID\n";
    $outputMessage .= "Adjusted Power: " . number_format($adjustedPowerTiB, 2) . " TiB\n";
    $outputMessage .= "24-hour Growth: " . ($growth24Hours !== null ? number_format($growth24Hours, 2) . " TiB\n" : "N/A\n");
    $outputMessage .= "Available Balance: " . number_format($available, 2) . " FIL\n";
    $outputMessage .= "Worker Balance: " . number_format($workerBalanceFIL, 2) . " FIL\n";
    if ($workerBalanceFIL < 29) {
        $outputMessage .= "*Warning: Worker Balance is below 29 FIL!*\n";
    }
    $outputMessage .= "Faulty Sectors: $faultySectors\n";
    $outputMessage .= "Blocks Mined in Last 24 Hours: $last24Hours\n";
    $outputMessage .= "Luck Rate (24 Hours): " . number_format($luckRate24Hours, 2) . "%\n";
    $outputMessage .= "Luck Rate (7 Days): " . number_format($luckRate7Days, 2) . "%\n";
    $outputMessage .= "Luck Rate (30 Days): " . number_format($luckRate30Days, 2) . "%\n";
    $outputMessage .= "------------------------\n\n";

    // Insert miner data into the database
    insertMinerData($mysqli, $minerID, $minerName, $adjustedPowerTiB, $available, $workerBalanceFIL, $faultySectors, $last24Hours, $luckRate24Hours, $last7Days, $luckRate7Days, $last30Days, $luckRate30Days);

    // Insert power data into the database
    insertPowerData($mysqli, $minerID, $adjustedPowerTiB, $networkQualityAdjPowerTiB);

    echo "Debug: Output Message for $minerID:\n$outputMessage\n";
}

// Calculate and display the average luck rate based on power ratio
if ($totalPower > 0) {
    $averageLuckRate = $totalWeightedLuck / $totalPower;
    $outputMessage .= "Average Luck Rate based on Power Ratio: " . number_format($averageLuckRate, 2) . "%\n";
    $outputMessage .= "Total Blocks Mined by All Miners in Last 24 Hours: $totalBlocksMined\n";
    $outputMessage .= "Total Power of All Miners: " . number_format($totalPowerPiB, 2) . " PiB\n";
} else {
    $outputMessage .= "No valid miner data available to calculate the average luck rate.\n";
}

// Send the output message to Telegram
sendMessageToTelegram($outputMessage);

// Close the database connection
$mysqli->close();

?>
