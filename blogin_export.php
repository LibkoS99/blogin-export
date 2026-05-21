<?php
$apiKey = getenv('BLOGIN_API_KEY') ?: (getenv('BLOGIN_API_KEY') ?: "TVOJ_API_KEY");
$baseUrl = "https://peliblog.blogin.co/api/rest";

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
        $err   = curl_error($ch);
        curl_close($ch);

        if ($response !== false && $httpCode >= 200 && $httpCode < 400) {
            $json = json_decode($response, true);
            if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
                // JSON parse error: treat as retryable once
                if ($attempt < $retries) {
                    usleep($sleepMs * 1000);
                    continue;
                }
                throw new RuntimeException("Invalid JSON from $url: " . json_last_error_msg());
            }
            return $json ?: [];
        }

        // Retry on network errors or 429/5xx
        $retryable = ($errNo !== 0) || ($httpCode == 429) || ($httpCode >= 500);
        if ($retryable && $attempt < $retries) {
            usleep($sleepMs * 1000);
            $sleepMs = min(2000, $sleepMs * 2); // simple backoff
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
        // If array of objects
        $isList = array_keys($response) === range(0, count($response) - 1);
        if ($isList) return $response;
    }
    return [];
}

function findNextPageUrl($response, $currentUrl) {
    // 1) Link-based pagination
    if (isset($response['links']['next']) && $response['links']['next']) {
        $next = $response['links']['next'];
        if (strpos($next, 'http') === 0) return $next;
        // relative next link
        $parsed = parse_url($currentUrl);
        $prefix = $parsed['scheme'] . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
        if (isset($parsed['path'])) {
            // If next is absolute path
            if (strpos($next, '/') === 0) return $prefix . $next;
            // Otherwise resolve relative to current directory
            $dir = rtrim(dirname($parsed['path']), '/');
            return $prefix . $dir . '/' . $next;
        }
        return $currentUrl; // fallback, should not happen
    }

    // 2) Meta-based (meta.pagination or meta.last_page/current_page)
    $meta = $response['meta'] ?? [];
    if (isset($meta['pagination'])) {
        $p = $meta['pagination'];
        $current = $p['current_page'] ?? null;
        $last = $p['total_pages'] ?? ($p['last_page'] ?? null);
        if ($current !== null && $last !== null && $current < $last) {
            // bump page query param
            $parts = parse_url($currentUrl);
            $query = [];
            if (!empty($parts['query'])) parse_str($parts['query'], $query);
            $query['page'] = ($query['page'] ?? 1) + 1;
            $newQuery = http_build_query($query);
            $base = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            $path = $parts['path'] ?? '';
            return $newQuery ? "$base$path?$newQuery" : "$base$path";
        }
    }
    if (isset($meta['current_page'], $meta['last_page']) && $meta['current_page'] < $meta['last_page']) {
        $parts = parse_url($currentUrl);
        $query = [];
        if (!empty($parts['query'])) parse_str($parts['query'], $query);
        $query['page'] = ($query['page'] ?? 1) + 1;
        $newQuery = http_build_query($query);
        $base = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = $parts['path'] ?? '';
        return $newQuery ? "$base$path?$newQuery" : "$base$path";
    }

    return null;
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

        // Fallback: if no explicit next link/meta, stop when we received less than perPage
        if (count($data) < $perPage) {
            break;
        }

        // Otherwise increment page heuristic
        $page++;
        $url = buildUrlWithParams($baseUrl, $path, array_merge($extraParams, [
            'page' => $page,
            'per_page' => $perPage,
        ]));
        usleep(150000);
    }

    return $items;
}

$allByYear = [];

// 1. načítaj kategórie cez robustné stránkovanie
$categories = fetchAllPaginated($baseUrl, '/categories', $apiKey);

foreach ($categories as $cat) {
    $catId = $cat['id'] ?? null;
    $catName = $cat['name'] ?? (string)($catId ?? 'unknown');
    echo "Spracovávam kategóriu: {$catName} (ID {$catId})\n";
    if (!$catId) { continue; }

    // 2. články v kategórii (stránkované) s vyšším per_page
    $posts = fetchAllPaginated($baseUrl, "/categories/{$catId}/posts", $apiKey);
    if (empty($posts)) { continue; }

    foreach ($posts as $p) {
        $postId = $p['id'] ?? null;
        if (!$postId) { continue; }
        // 3. detail článku
        try {
            $detail = fetchJson("$baseUrl/posts/{$postId}", $apiKey);
        } catch (Throwable $e) {
            fprintf(STDERR, "Detail error for post %s: %s\n", (string)$postId, $e->getMessage());
            continue;
        }
        if (!$detail) continue;

        // rok z date_published (fallback na created_at)
        $dateRaw = $detail['date_published'] ?? ($detail['created_at'] ?? null);
        $year = 'unknown';
        if ($dateRaw) {
            try {
                $year = (new DateTime($dateRaw))->format("Y");
            } catch (Throwable $e) {
                $year = 'unknown';
            }
        }

        // bezpečné extrakcie
        $authorName = $detail['author']['name'] ?? ($detail['author'] ?? null);
        $categoriesNames = [];
        if (!empty($detail['categories']) && is_array($detail['categories'])) {
            foreach ($detail['categories'] as $c) {
                $categoriesNames[] = is_array($c) ? ($c['name'] ?? '') : (string)$c;
            }
        }
        $tagsNames = [];
        if (!empty($detail['tags']) && is_array($detail['tags'])) {
            foreach ($detail['tags'] as $t) {
                $tagsNames[] = is_array($t) ? ($t['name'] ?? '') : (string)$t;
            }
        }

        $contentHtml = $detail['text'] ?? '';
        $introHtml   = $detail['intro_text'] ?? '';
        $contentText = strip_tags((string)$contentHtml);
        $introText   = strip_tags((string)$introHtml);

        // štruktúra článku
        $article = [
            "id" => $detail['id'] ?? $postId,
            "title" => $detail['title'] ?? '',
            "author" => $authorName,
            "published" => $detail['date_published'] ?? null,
            "created_at" => $detail['created_at'] ?? null,
            "categories" => $categoriesNames,
            "intro" => $introText,
            "content_html" => $contentHtml,
            "content_text" => $contentText,
            "tags" => $tagsNames
        ];

        // pridaj do správneho roka
        if (!isset($allByYear[$year])) {
            $allByYear[$year] = [];
        }
        $allByYear[$year][] = $article;

        echo "  -> Článok {$article['id']} zaradený do roku $year\n";
        usleep(120000); // šetrné volania
    }
}

// 4. ulož do samostatných JSON súborov podľa rokov
foreach ($allByYear as $year => $posts) {
    $safeYear = preg_replace('/[^0-9a-zA-Z_-]/', '_', (string)$year);
    $filename = "posts_{$safeYear}.json";
    file_put_contents(
        $filename,
        json_encode($posts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    echo "✅ Uložený súbor $filename (" . count($posts) . " článkov)\n";
}

echo "\nHotovo! Export dokončený.\n";

