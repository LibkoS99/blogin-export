<?php
$apiKey = getenv('BLOGIN_API_KEY') ?: '';
$baseUrl = "https://peliblog.blogin.co/api/rest";
$outputDir = __DIR__ . "/output";

date_default_timezone_set('Europe/Bratislava');

function usage(): void {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php blogin_export.php --previous-month\n");
    fwrite(STDERR, "  php blogin_export.php --month=YYYY-MM\n");
    fwrite(STDERR, "  php blogin_export.php --year=YYYY\n");
}

function parseArgs(array $argv): array {
    $mode = null;
    $value = null;

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--previous-month') {
            $mode = 'previous-month';
            $value = null;
        } elseif (preg_match('/^--month=(\d{4}-\d{2})$/', $arg, $m)) {
            $mode = 'month';
            $value = $m[1];
        } elseif (preg_match('/^--year=(\d{4})$/', $arg, $m)) {
            $mode = 'year';
            $value = $m[1];
        } elseif ($arg === '--help' || $arg === '-h') {
            usage();
            exit(0);
        } else {
            throw new InvalidArgumentException("Unknown argument: $arg");
        }
    }

    if ($mode === null) {
        $mode = 'previous-month';
    }

    return [$mode, $value];
}

function getExportWindow(string $mode, ?string $value): array {
    if ($mode === 'previous-month') {
        $start = new DateTimeImmutable('first day of previous month 00:00:00');
        $end = $start->modify('first day of next month');
        $label = $start->format('Y_m');
        $filename = "posts_{$label}.json";
        return [$start, $end, $filename, $start->format('Y-m')];
    }

    if ($mode === 'month') {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value . '-01 00:00:00');
        if (!$start || $start->format('Y-m') !== $value) {
            throw new InvalidArgumentException("Invalid month. Expected YYYY-MM, got: $value");
        }
        $end = $start->modify('first day of next month');
        $label = $start->format('Y_m');
        $filename = "posts_{$label}.json";
        return [$start, $end, $filename, $start->format('Y-m')];
    }

    if ($mode === 'year') {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value . '-01-01 00:00:00');
        if (!$start || $start->format('Y') !== $value) {
            throw new InvalidArgumentException("Invalid year. Expected YYYY, got: $value");
        }
        $end = $start->modify('first day of january next year');
        $filename = "posts_{$value}.json";
        return [$start, $end, $filename, $value];
    }

    throw new InvalidArgumentException("Invalid mode: $mode");
}

function fetchJson($url, $apiKey, $retries = 3, $sleepMs = 150) {
    $attempt = 0;
    while (true) {
        $attempt++;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Accept: application/json",
                "Authorization: Bearer $apiKey",
                "User-Agent: blogin-export/1.0"
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FAILONERROR => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errNo = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response !== false && $httpCode >= 200 && $httpCode < 400) {
            $json = json_decode($response, true);
            if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
                if ($attempt < $retries) {
                    usleep($sleepMs * 1000);
                    continue;
                }
                throw new RuntimeException("Invalid JSON from $url: " . json_last_error_msg());
            }
            return $json ?: [];
        }

        $retryable = ($errNo !== 0) || ($httpCode == 429) || ($httpCode >= 500);
        if ($retryable && $attempt < $retries) {
            usleep($sleepMs * 1000);
            $sleepMs = min(2000, $sleepMs * 2);
            continue;
        }
        throw new RuntimeException("HTTP $httpCode for $url; curl[$errNo] $err");
    }
}

function buildUrlWithParams($base, $path, $params = []) {
    $base = rtrim($base, '/');
    $path = ltrim($path, '/');
    $query = http_build_query($params);
    return $query ? "$base/$path?$query" : "$base/$path";
}

function extractDataArray($response) {
    if (isset($response['data']) && is_array($response['data'])) {
        return $response['data'];
    }
    if (is_array($response)) {
        $isList = array_keys($response) === range(0, count($response) - 1);
        if ($isList) return $response;
    }
    return [];
}

function findNextPageUrl($response, $currentUrl) {
    if (isset($response['links']['next']) && $response['links']['next']) {
        $next = $response['links']['next'];
        if (strpos($next, 'http') === 0) return $next;
        $parsed = parse_url($currentUrl);
        $prefix = $parsed['scheme'] . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
        if (isset($parsed['path'])) {
            if (strpos($next, '/') === 0) return $prefix . $next;
            $dir = rtrim(dirname($parsed['path']), '/');
            return $prefix . $dir . '/' . $next;
        }
        return $currentUrl;
    }

    $meta = $response['meta'] ?? [];
    if (isset($meta['pagination'])) {
        $p = $meta['pagination'];
        $current = $p['current_page'] ?? null;
        $last = $p['total_pages'] ?? ($p['last_page'] ?? null);
        if ($current !== null && $last !== null && $current < $last) {
            return bumpPage($currentUrl);
        }
    }
    if (isset($meta['current_page'], $meta['last_page']) && $meta['current_page'] < $meta['last_page']) {
        return bumpPage($currentUrl);
    }

    return null;
}

function bumpPage($currentUrl) {
    $parts = parse_url($currentUrl);
    $query = [];
    if (!empty($parts['query'])) parse_str($parts['query'], $query);
    $query['page'] = ($query['page'] ?? 1) + 1;
    $newQuery = http_build_query($query);
    $base = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $path = $parts['path'] ?? '';
    return $newQuery ? "$base$path?$newQuery" : "$base$path";
}

function fetchAllPaginated($baseUrl, $path, $apiKey, $extraParams = [], $perPage = 100) {
    $page = 1;
    $items = [];
    $url = buildUrlWithParams($baseUrl, $path, array_merge($extraParams, [
        'page' => $page,
        'per_page' => $perPage,
    ]));

    while (true) {
        $resp = fetchJson($url, $apiKey);
        $data = extractDataArray($resp);
        if (!empty($data)) {
            $items = array_merge($items, $data);
        }

        $next = findNextPageUrl($resp, $url);
        if ($next) {
            $url = $next;
            usleep(150000);
            continue;
        }

        if (count($data) < $perPage) {
            break;
        }

        $page++;
        $url = buildUrlWithParams($baseUrl, $path, array_merge($extraParams, [
            'page' => $page,
            'per_page' => $perPage,
        ]));
        usleep(150000);
    }

    return $items;
}

function postDate(?array $detail): ?DateTimeImmutable {
    $dateRaw = $detail['date_published'] ?? ($detail['created_at'] ?? null);
    if (!$dateRaw) return null;
    try {
        return new DateTimeImmutable($dateRaw);
    } catch (Throwable $e) {
        return null;
    }
}

function isInWindow(DateTimeImmutable $date, DateTimeImmutable $start, DateTimeImmutable $end): bool {
    return $date >= $start && $date < $end;
}

function namesFromArray($items): array {
    $names = [];
    if (!empty($items) && is_array($items)) {
        foreach ($items as $item) {
            $name = is_array($item) ? ($item['name'] ?? '') : (string)$item;
            if ($name !== '') $names[] = $name;
        }
    }
    return array_values(array_unique($names));
}

try {
    if ($apiKey === '' || $apiKey === 'TVOJ_API_KEY') {
        throw new RuntimeException('BLOGIN_API_KEY is not set.');
    }

    [$mode, $value] = parseArgs($argv);
    [$start, $end, $outputFilename, $label] = getExportWindow($mode, $value);

    if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
        throw new RuntimeException("Cannot create output directory: $outputDir");
    }

    echo "Export mode: $mode\n";
    echo "Export range: " . $start->format(DateTimeInterface::ATOM) . " to " . $end->format(DateTimeInterface::ATOM) . "\n";
    echo "Output: output/$outputFilename\n\n";

    $articlesById = [];
    $categories = fetchAllPaginated($baseUrl, '/categories', $apiKey);

    foreach ($categories as $cat) {
        $catId = $cat['id'] ?? null;
        $catName = $cat['name'] ?? (string)($catId ?? 'unknown');
        echo "Spracovávam kategóriu: {$catName} (ID {$catId})\n";
        if (!$catId) continue;

        $posts = fetchAllPaginated($baseUrl, "/categories/{$catId}/posts", $apiKey);
        if (empty($posts)) continue;

        foreach ($posts as $p) {
            $postId = $p['id'] ?? null;
            if (!$postId) continue;
            if (isset($articlesById[(string)$postId])) {
                continue;
            }

            try {
                $detail = fetchJson("$baseUrl/posts/{$postId}", $apiKey);
            } catch (Throwable $e) {
                fprintf(STDERR, "Detail error for post %s: %s\n", (string)$postId, $e->getMessage());
                continue;
            }
            if (!$detail) continue;

            $publishedDate = postDate($detail);
            if (!$publishedDate || !isInWindow($publishedDate, $start, $end)) {
                continue;
            }

            $authorName = $detail['author']['name'] ?? ($detail['author'] ?? null);
            $contentHtml = $detail['text'] ?? '';
            $introHtml = $detail['intro_text'] ?? '';

            $article = [
                'id' => $detail['id'] ?? $postId,
                'title' => $detail['title'] ?? '',
                'author' => $authorName,
                'published' => $detail['date_published'] ?? null,
                'created_at' => $detail['created_at'] ?? null,
                'categories' => namesFromArray($detail['categories'] ?? []),
                'intro' => strip_tags((string)$introHtml),
                'content_html' => $contentHtml,
                'content_text' => strip_tags((string)$contentHtml),
                'tags' => namesFromArray($detail['tags'] ?? []),
            ];

            $articlesById[(string)$article['id']] = $article;
            echo "  -> Článok {$article['id']} zaradený do exportu $label\n";
            usleep(120000);
        }
    }

    $articles = array_values($articlesById);
    usort($articles, function ($a, $b) {
        return strcmp((string)($a['published'] ?? ''), (string)($b['published'] ?? ''));
    });

    $outputPath = $outputDir . '/' . $outputFilename;
    file_put_contents(
        $outputPath,
        json_encode($articles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    echo "\nUložený súbor output/$outputFilename (" . count($articles) . " článkov)\n";
    echo "Hotovo! Export dokončený.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    usage();
    exit(1);
}
