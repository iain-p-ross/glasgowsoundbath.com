<?php

class AwstatsParser
{
    public static function parseFile($path)
    {
        $data = [
            'general' => [],
            'day' => [],
            'time' => [],
            'pages' => [],
            'externalref' => [],
            'country' => [],
            'os' => [],
            'browser' => [],
        ];

        $current = null;
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $data;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (preg_match('/^BEGIN_([A-Z0-9_]+)/', $line, $m)) {
                $current = $m[1];
                continue;
            }

            if (preg_match('/^END_([A-Z0-9_]+)/', $line, $m)) {
                $current = null;
                continue;
            }

            if ($current === null) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);

            switch ($current) {
                case 'GENERAL':
                    if (count($parts) >= 2) {
                        $key = $parts[0];
                        $value = $parts[1];
                        if (is_numeric($value)) {
                            $value = (int) $value;
                        }
                        $data['general'][$key] = $value;
                    }
                    break;
                case 'DAY':
                    if (count($parts) >= 5) {
                        $data['day'][$parts[0]] = [
                            'pages' => (int) $parts[1],
                            'hits' => (int) $parts[2],
                            'bandwidth_kb' => (int) $parts[3],
                            'visits' => (int) $parts[4],
                        ];
                    }
                    break;
                case 'TIME':
                    if (count($parts) >= 5) {
                        $data['time'][$parts[0]] = [
                            'pages' => (int) $parts[1],
                            'hits' => (int) $parts[2],
                            'bandwidth_kb' => (int) $parts[3],
                            'visits' => (int) $parts[4],
                        ];
                    }
                    break;
                case 'SIDER':
                case 'PAGES':
                    if (count($parts) >= 3) {
                        $pages = (int) $parts[1];
                        if ($current === 'SIDER') {
                            // SIDER format: URL - Pages - Bandwidth - Entry - Exit
                            $hits = $pages;
                            $bandwidth = (int) $parts[2];
                        } else {
                            // PAGES format: URL - Pages - Hits - Bandwidth
                            $hits = isset($parts[2]) ? (int) $parts[2] : $pages;
                            $bandwidth = isset($parts[3]) ? (int) $parts[3] : 0;
                        }
                        $data['pages'][$parts[0]] = [
                            'pages' => $pages,
                            'hits' => $hits,
                            'bandwidth_kb' => $bandwidth,
                        ];
                    }
                    break;
                case 'PAGEREFS':
                case 'EXTERNALREF':
                    if (count($parts) >= 3) {
                        $data['externalref'][$parts[0]] = [
                            'pages' => (int) $parts[1],
                            'hits' => (int) $parts[2],
                        ];
                    }
                    break;
                case 'DOMAIN':
                case 'COUNTRY':
                    if (count($parts) >= 4) {
                        $data['country'][$parts[0]] = [
                            'pages' => (int) $parts[1],
                            'hits' => (int) $parts[2],
                            'bandwidth_kb' => (int) $parts[3],
                        ];
                    } elseif (count($parts) >= 3) {
                        $data['country'][$parts[0]] = [
                            'pages' => (int) $parts[1],
                            'hits' => (int) $parts[2],
                            'bandwidth_kb' => 0,
                        ];
                    }
                    break;
                case 'OS':
                    if (count($parts) >= 3) {
                        $data['os'][$parts[0]] = [
                            'pages' => (int) $parts[1],
                            'hits' => (int) $parts[2],
                        ];
                    }
                    break;
                case 'BROWSER':
                    if (count($parts) >= 3) {
                        $data['browser'][$parts[0]] = [
                            'pages' => (int) $parts[1],
                            'hits' => (int) $parts[2],
                        ];
                    }
                    break;
            }
        }

        return $data;
    }
}
