<?php

require_once __DIR__ . '/app/awstats_parser.php';

header('Content-Type: application/json');

$configFile = __DIR__ . '/app/config.php';
if (!file_exists($configFile)) {
    $configFile = __DIR__ . '/app/config.sample.php';
}
$config = require $configFile;

$timezone = isset($config['timezone']) ? $config['timezone'] : 'UTC';
date_default_timezone_set($timezone);

$dataDir = rtrim($config['data_dir'], '/');
$site = $config['site'];

$pattern = $dataDir . '/awstats[0-9][0-9][0-9][0-9][0-9][0-9].' . $site . '.txt';
$files = glob($pattern);
if (!$files) {
    echo json_encode([
        'error' => 'No awstats files found for the configured site.',
        'pattern' => $pattern,
    ]);
    exit;
}

sort($files);

$daily = [];
$hourly = [];
$lists = [
    'pages' => [],
    'externalref' => [],
    'country' => [],
    'os' => [],
    'browser' => [],
];
$lastUpdate = null;

foreach ($files as $file) {
    $parsed = AwstatsParser::parseFile($file);

    if (isset($parsed['general']['LastUpdate'])) {
        $lastUpdate = $parsed['general']['LastUpdate'];
    }

    foreach ($parsed['day'] as $date => $row) {
        if (!isset($daily[$date])) {
            $daily[$date] = $row;
        } else {
            $daily[$date]['pages'] += $row['pages'];
            $daily[$date]['hits'] += $row['hits'];
            $daily[$date]['bandwidth_kb'] += $row['bandwidth_kb'];
            $daily[$date]['visits'] += $row['visits'];
        }
    }

    foreach ($parsed['time'] as $hour => $row) {
        if (!isset($hourly[$hour])) {
            $hourly[$hour] = $row;
        } else {
            $hourly[$hour]['pages'] += $row['pages'];
            $hourly[$hour]['hits'] += $row['hits'];
            $hourly[$hour]['bandwidth_kb'] += $row['bandwidth_kb'];
            $hourly[$hour]['visits'] += $row['visits'];
        }
    }

}

if (empty($daily)) {
    echo json_encode(['error' => 'No daily data found in awstats files.']);
    exit;
}

ksort($daily);
$allDates = array_keys($daily);
$lastDate = $allDates[count($allDates) - 1];

$defaultTo = date('Y-m-d', strtotime($lastDate));
$defaultFrom = date('Y-m-d', strtotime($lastDate . ' -29 days'));

$from = isset($_GET['from']) ? $_GET['from'] : $defaultFrom;
$to = isset($_GET['to']) ? $_GET['to'] : $defaultTo;

$fromTs = strtotime($from);
$toTs = strtotime($to);
if ($fromTs === false || $toTs === false || $fromTs > $toTs) {
    $from = $defaultFrom;
    $to = $defaultTo;
    $fromTs = strtotime($from);
    $toTs = strtotime($to);
}

$fromMonthIndex = ((int) date('Y', $fromTs)) * 12 + (int) date('n', $fromTs);
$toMonthIndex = ((int) date('Y', $toTs)) * 12 + (int) date('n', $toTs);

$series = [];
$totals = [
    'visits' => 0,
    'pages' => 0,
    'hits' => 0,
    'bandwidth_bytes' => 0,
];

foreach ($daily as $dateKey => $row) {
    $date = date('Y-m-d', strtotime($dateKey));
    $dateTs = strtotime($date);
    if ($dateTs < $fromTs || $dateTs > $toTs) {
        continue;
    }

    $bandwidthBytes = $row['bandwidth_kb'] * 1024;

    $series[] = [
        'date' => $date,
        'visits' => $row['visits'],
        'pages' => $row['pages'],
        'hits' => $row['hits'],
        'bandwidth_bytes' => $bandwidthBytes,
    ];

    $totals['visits'] += $row['visits'];
    $totals['pages'] += $row['pages'];
    $totals['hits'] += $row['hits'];
    $totals['bandwidth_bytes'] += $bandwidthBytes;
}

$lists = [
    'pages' => [],
    'externalref' => [],
    'country' => [],
    'os' => [],
    'browser' => [],
];

foreach ($files as $file) {
    if (!preg_match('/awstats(\\d{2})(\\d{4})\\./', basename($file), $match)) {
        continue;
    }
    $month = (int) $match[1];
    $year = (int) $match[2];
    $monthIndex = $year * 12 + $month;
    if ($monthIndex < $fromMonthIndex || $monthIndex > $toMonthIndex) {
        continue;
    }

    $parsed = AwstatsParser::parseFile($file);
    foreach ($lists as $key => $bucket) {
        foreach ($parsed[$key] as $label => $row) {
            if (!isset($lists[$key][$label])) {
                $lists[$key][$label] = $row;
            } else {
                foreach ($row as $metric => $value) {
                    $lists[$key][$label][$metric] += $value;
                }
            }
        }
    }
}

$top = [];
foreach ($lists as $key => $bucket) {
    uasort($bucket, function ($a, $b) {
        $aCount = isset($a['pages']) ? $a['pages'] : (isset($a['hits']) ? $a['hits'] : 0);
        $bCount = isset($b['pages']) ? $b['pages'] : (isset($b['hits']) ? $b['hits'] : 0);
        return $bCount <=> $aCount;
    });
    $items = [];
    foreach ($bucket as $label => $row) {
        $items[] = array_merge(['label' => $label], $row);
        if (count($items) >= 12) {
            break;
        }
    }
    $top[$key] = $items;
}

$response = [
    'meta' => [
        'site' => $site,
        'last_update' => $lastUpdate,
        'range' => [
            'from' => $from,
            'to' => $to,
        ],
        'latest_date' => date('Y-m-d', strtotime($lastDate)),
    ],
    'totals' => $totals,
    'series' => $series,
    'top' => $top,
];

echo json_encode($response);
