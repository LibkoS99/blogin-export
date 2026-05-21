<?php

$apiKey = getenv('BLOGIN_API_KEY') ?: '';
$baseUrl = 'https://peliblog.blogin.co/api/rest';
$outputDir = __DIR__ . '/output';

date_default_timezone_set('Europe/Bratislava');

function usage(): void {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php blogin_export.php --previous-quarter\n");
    fwrite(STDERR, "  php blogin_export.php --quarter=YYYY-QN\n");
    fwrite(STDERR, "  php blogin_export.php --year=YYYY\n");
}

function parseArgs(array $argv): array {
    $mode = 'previous-quarter';
    $value = null;

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--previous-quarter') {
            return ['previous-quarter', null];
        }

        if (preg_match('/^--quarter=(\d{4}-Q[1-4])$/', $arg, $m)) {
            return ['quarter', $m[1]];
        }

        if (preg_match('/^--year=(\d{4})$/', $arg, $m)) {
            return ['year', $m[1]];
        }

        if ($arg === '--help' || $arg === '-h') {
            usage();
            exit(0);
        }

        throw new InvalidArgumentException("Unknown argument: $arg");
    }

    return [$mode, $value];
}

function exportWindow(string $mode, ?string $value): array {
    if ($mode === 'previous-quarter') {
        $now = new DateTimeImmutable('now');
        $currentQuarter = intdiv(((int)$now->format('n')) - 1, 3) + 1;
        $quarter = $currentQuarter - 1;
        $year = (int)$now->format('Y');

        if ($quarter === 0) {
            $quarter = 4;
            $year--;
        }

        return quarterWindow($year, $quarter, 'previous-quarter');
    }

    if ($mode === 'quarter') {
        if (!preg_match('/^(\d{4})-Q([1-4])$/', (string)$value, $m)) {
            throw new InvalidArgumentException("Invalid quarter. Use YYYY-QN, e.g. 2026-Q1.");
        }

        return quarterWindow((int)$m[1], (int)$m[2], 'quarter');
    }

    if ($mode === 'year') {
        $start = new DateTimeImmutable("$value-01-01 00:00:00");
        $end = $start->modify('+1 year');

        return [
            'mode' => 'year',
            'label' => $value,
            'start' => $start,
            'end' => $end,
            'filename' => "posts_$value.json",
        ];
    }

    throw new InvalidArgumentException("Invalid mode: $mode");
}

function quarterWindow(int $year, int $quarter, string $mode): array {
    $startMonth = (($quarter - 1) * 3) + 1;
    $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $startMonth));
    $end = $start->modify('+3 months');
    $label = sprintf('%04d_Q%d', $year, $quarter);

    return [
        'mode' => $mode,
        'label' => $label,
        'start' => $start,
        'end' => $end,
        'filename' => "posts_$label.json",
    ];
}

function fetchJson(string $url, string $apiKey): array {
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            "Authorization: Bearer $apiKey",
            'User-Agent: blogin-export/1.0',
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 400) {
        throw new RuntimeException("HTTP $httpCode for $url $error");
    }

    $json = json_decode($response, true);

    if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Invalid JSON: ' . json_last_error_msg());
    }

    return $json ?: [];
}

function apiUrl(string $baseUrl, string $path, array $params = []): string {
    $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    return $params ? $url . '?' . http_build_query($params) : $url;
}

function dataItems(array $response): array {
    if (isset($response['data']) && is_array($response['data'])) {
        return $response['data'];
    }

    return array_is_list($response) ? $response : [];
}

function fetchAll(string $baseUrl, string $path, string $apiKey): array {
    $items = [];
    $page = 1;
    $perPage = 100;

    while (true) {
        $response = fetchJson(apiUrl($baseUrl, $path, [
            'page' => $page,
            'per_page' => $perPage,
        ]), $apiKey);

        $batch = dataItems($response);
        $items = array_merge($items, $batch);

        if (count($batch) < $perPage) {
            break;
        }

        $page++;
        usleep(150000);
    }

    return $items;
}

function postDate(array $post): ?DateTimeImmutable {
    $raw = $post['date_published'] ?? $post['created_at'] ?? null;

    if (!$raw) {
        return null;
    }

    try {
        return new DateTimeImmutable($raw);
    } catch (Throwable) {
        return null;
    }
}

function inWindow(DateTimeImmutable $date, DateTimeImmutable $start, DateTimeImmutable $end): bool {
    return $date >= $start && $date < $end;
}

function names(array $items): array {
    $names = [];

    foreach ($items as $item) {
        $name = is_array($item) ? ($item['name'] ?? '') : (string)$item;
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return array_values(array_unique($names));
}

try {
    if ($apiKey === '') {
        throw new RuntimeException('BLOGIN_API_KEY is not set.');
    }

    [$mode, $value] = parseArgs($argv);
    $window = exportWindow($mode, $value);

    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0775, true);
    }

    echo "Export mode: {$window['mode']}\n";
    echo "Export range: {$window['start']->format(DateTimeInterface::ATOM)} to {$window['end']->format(DateTimeInterface::ATOM)}\n";
    echo "Output: output/{$window['filename']}\n\n";

    $articles = [];
    $categories = fetchAll($baseUrl, '/categories', $apiKey);

    foreach ($categories as $category) {
        $categoryId = $category['id'] ?? null;
        $categoryName = $category['name'] ?? 'unknown';

        if (!$categoryId) {
            continue;
        }

        echo "Spracovávam kategóriu: $categoryName (ID $categoryId)\n";

        foreach (fetchAll($baseUrl, "/categories/$categoryId/posts", $apiKey) as $post) {
            $postId = $post['id'] ?? null;

            if (!$postId || isset($articles[(string)$postId])) {
                continue;
            }

            $detail = fetchJson("$baseUrl/posts/$postId", $apiKey);
            $date = postDate($detail);

            if (!$date || !inWindow($date, $window['start'], $window['end'])) {
                continue;
            }

            $articles[(string)$postId] = [
                'id' => $detail['id'] ?? $postId,
                'title' => $detail['title'] ?? '',
                'author' => $detail['author']['name'] ?? ($detail['author'] ?? null),
                'published' => $detail['date_published'] ?? null,
                'created_at' => $detail['created_at'] ?? null,
                'categories' => names($detail['categories'] ?? []),
                'intro' => strip_tags((string)($detail['intro_text'] ?? '')),
                'content_html' => $detail['text'] ?? '',
                'content_text' => strip_tags((string)($detail['text'] ?? '')),
                'tags' => names($detail['tags'] ?? []),
            ];

            echo "  -> Článok $postId zaradený do exportu {$window['label']}\n";
            usleep(120000);
        }
    }

    $articles = array_values($articles);

    usort($articles, fn($a, $b) =>
        strcmp((string)($a['published'] ?? ''), (string)($b['published'] ?? ''))
    );

    file_put_contents(
        $outputDir . '/' . $window['filename'],
        json_encode($articles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    echo "\nUložený súbor output/{$window['filename']} (" . count($articles) . " článkov)\n";
    echo "Hotovo! Export dokončený.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    usage();
    exit(1);
}
