<?php
require_once __DIR__ . '/facebook-config.php';
if (!function_exists('t')) { require_once __DIR__ . '/lang.php'; }

/**
 * True once the placeholders in facebook-config.php have actually been
 * replaced with a real Page ID and token. Every function below checks
 * this first and fails quietly (returns false/empty) if not — the
 * Events page should keep working even if Facebook isn't set up yet.
 */
function fbIsConfigured() {
    return FB_PAGE_ID !== 'PASTE_YOUR_PAGE_ID_HERE'
        && FB_PAGE_ACCESS_TOKEN !== 'PASTE_YOUR_LONG_LIVED_PAGE_ACCESS_TOKEN_HERE'
        && FB_PAGE_ID !== '' && FB_PAGE_ACCESS_TOKEN !== '';
}

/**
 * Low-level helper: call the Graph API and decode the JSON response.
 * Returns ['ok' => bool, 'data' => array|null, 'error' => string|null].
 */
function fbGraphRequest($method, $path, $params = [], $isMultipart = false) {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'data' => null, 'error' => 'cURL extension not available on this host'];
    }

    $url = 'https://graph.facebook.com/' . FB_API_VERSION . '/' . ltrim($path, '/');
    $params['access_token'] = FB_PAGE_ACCESS_TOKEN;

    $ch = curl_init();
    if (strtoupper($method) === 'GET') {
        curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    } else {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        // Multipart requests (file uploads via CURLFile) must be passed as an
        // array so cURL builds a multipart/form-data body instead of a plain
        // urlencoded string, which would just send the filename as text.
        curl_setopt($ch, CURLOPT_POSTFIELDS, $isMultipart ? $params : http_build_query($params));
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'data' => null, 'error' => $curlErr ?: 'Network error contacting Facebook'];
    }

    $decoded = json_decode($response, true);
    if (isset($decoded['error'])) {
        return ['ok' => false, 'data' => null, 'error' => $decoded['error']['message'] ?? 'Unknown Facebook API error'];
    }

    return ['ok' => true, 'data' => $decoded, 'error' => null];
}

/**
 * Publish a post to the configured Facebook Page.
 *
 * @param string      $message   The post text.
 * @param string|null $link      Optional URL to attach (e.g. a link back to the event).
 * @param string|null $imagePath Optional absolute path to a local image file to attach.
 *                               When given, posts via the /photos endpoint so the picture
 *                               itself shows up on the Page (not just text).
 * @return array ['ok' => bool, 'post_id' => string|null, 'error' => string|null]
 */
function fbPostToPage($message, $link = null, $imagePath = null) {
    if (!fbIsConfigured()) {
        return ['ok' => false, 'post_id' => null, 'error' => 'Facebook Page is not configured yet (see includes/facebook-config.php).'];
    }

    if ($imagePath && is_file($imagePath)) {
        $mime = mime_content_type($imagePath) ?: 'image/jpeg';
        $params = [
            'caption'    => $message,
            'source'     => new CURLFile($imagePath, $mime, basename($imagePath)),
            'published'  => 'true',
        ];
        $result = fbGraphRequest('POST', FB_PAGE_ID . '/photos', $params, true);
        if (!$result['ok']) {
            return ['ok' => false, 'post_id' => null, 'error' => $result['error']];
        }
        // /photos returns {id, post_id}; post_id is the wall-post id (what fb_post_id expects).
        return ['ok' => true, 'post_id' => $result['data']['post_id'] ?? ($result['data']['id'] ?? null), 'error' => null];
    }

    $params = ['message' => $message];
    if ($link) { $params['link'] = $link; }

    $result = fbGraphRequest('POST', FB_PAGE_ID . '/feed', $params);
    if (!$result['ok']) {
        return ['ok' => false, 'post_id' => null, 'error' => $result['error']];
    }

    return ['ok' => true, 'post_id' => $result['data']['id'] ?? null, 'error' => null];
}

/**
 * Download a remote image (used for Facebook post thumbnails, whose URLs
 * are often short-lived/signed and get blocked when hotlinked directly
 * from a browser). Saves it under uploads/fb_cache/ and returns the local
 * relative path (e.g. "fb_cache/abc123.jpg"), or null on failure.
 */
function fbCacheImage($url, $postId) {
    if (!$url) return null;

    $dir = __DIR__ . '/../uploads/fb_cache';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

    $ext = 'jpg';
    $path = parse_url($url, PHP_URL_PATH);
    if ($path && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $path, $m)) {
        $ext = strtolower($m[1]);
    }
    $filename = 'fbpost_' . preg_replace('/[^A-Za-z0-9_]/', '_', $postId) . '.' . $ext;
    $fullPath = $dir . '/' . $filename;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; GNC-OSA-Portal/1.0)');
    $data = curl_exec($ch);
    $ok = ($data !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200);
    curl_close($ch);

    if (!$ok || empty($data)) return null;

    if (@file_put_contents($fullPath, $data) === false) return null;
    return 'fb_cache/' . $filename;
}

/**
 * Get the Page's recent posts, using a short-lived DB cache
 * (fb_posts table) so we don't hit the Graph API on every page view.
 *
 * @param PDO $pdo
 * @param int $limit
 * @param bool $forceRefresh Bypass the cache and fetch fresh from Facebook.
 * @return array ['posts' => array, 'error' => string|null, 'from_cache' => bool]
 */
function fbGetPagePosts($pdo, $limit = 6, $forceRefresh = false, $allowNetwork = true) {
    // Check cache freshness first (based on the most recently fetched row).
    if (!$forceRefresh) {
        $stmt = $pdo->query("SELECT MAX(fetched_at) AS last_fetch FROM fb_posts");
        $lastFetch = $stmt->fetch()['last_fetch'] ?? null;
        if ($lastFetch && (time() - strtotime($lastFetch)) < FB_FEED_CACHE_MINUTES * 60) {
            $rows = $pdo->prepare("SELECT * FROM fb_posts ORDER BY created_time DESC LIMIT ?");
            $rows->bindValue(1, $limit, PDO::PARAM_INT);
            $rows->execute();
            return ['posts' => $rows->fetchAll(), 'error' => null, 'from_cache' => true];
        }
    }

    // Never make a page wait on Facebook: cache-only callers, a recent failure, or another request
    // already refreshing all fall back to whatever is cached.
    $failFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gnc_fb_fail.flag';
    $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gnc_fb_fetch.lock';
    $skipNetwork = !$allowNetwork;
    if (!$skipNetwork && !$forceRefresh && is_file($failFile) && (time() - filemtime($failFile)) < 300) {
        $skipNetwork = true;                       // Facebook failed in the last 5 min: don't retry on every click
    }
    $lockHandle = null;
    if (!$skipNetwork) {
        $lockHandle = @fopen($lockFile, 'c');
        if ($lockHandle && !flock($lockHandle, LOCK_EX | LOCK_NB)) { $skipNetwork = true; }   // someone else is fetching
    }
    if ($skipNetwork) {
        $rows = $pdo->prepare("SELECT * FROM fb_posts ORDER BY created_time DESC LIMIT ?");
        $rows->bindValue(1, $limit, PDO::PARAM_INT);
        $rows->execute();
        return ['posts' => $rows->fetchAll(), 'error' => null, 'from_cache' => true];
    }

    if (!fbIsConfigured()) {
        // Not set up yet — fall back to whatever is cached (probably nothing).
        $rows = $pdo->prepare("SELECT * FROM fb_posts ORDER BY created_time DESC LIMIT ?");
        $rows->bindValue(1, $limit, PDO::PARAM_INT);
        $rows->execute();
        return ['posts' => $rows->fetchAll(), 'error' => t('msg.facebook_page_is_not_configured_yet_c869', 'Facebook Page is not configured yet.'), 'from_cache' => true];
    }

    $result = fbGraphRequest('GET', FB_PAGE_ID . '/posts', [
        'fields' => 'message,full_picture,permalink_url,created_time,attachments{media,type,url,subattachments}',
        'limit'  => $limit,
    ]);

    if (!$result['ok']) { @touch($failFile); } else { @unlink($failFile); }
    if (!$result['ok']) {
        // Fetch failed (expired token, rate limit, etc.) — serve stale cache instead of breaking the page.
        $rows = $pdo->prepare("SELECT * FROM fb_posts ORDER BY created_time DESC LIMIT ?");
        $rows->bindValue(1, $limit, PDO::PARAM_INT);
        $rows->execute();
        return ['posts' => $rows->fetchAll(), 'error' => $result['error'], 'from_cache' => true];
    }

    $posts = $result['data']['data'] ?? [];
    $upsert = $pdo->prepare(
        "INSERT INTO fb_posts (fb_post_id, message, full_picture, permalink_url, created_time, fetched_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE message=VALUES(message), full_picture=VALUES(full_picture),
             permalink_url=VALUES(permalink_url), fetched_at=NOW()"
    );
    $liveIds = [];
    foreach ($posts as $p) {
        if (empty($p['id'])) continue;

        // full_picture isn't always populated by Facebook (especially for
        // directly-uploaded photos). Fall back to the attachments payload,
        // which carries the image even when full_picture comes back empty.
        $remotePicture = $p['full_picture'] ?? null;
        if (!$remotePicture && !empty($p['attachments']['data'][0])) {
            $att = $p['attachments']['data'][0];
            if (!empty($att['media']['image']['src'])) {
                $remotePicture = $att['media']['image']['src'];
            } elseif (!empty($att['subattachments']['data'][0]['media']['image']['src'])) {
                $remotePicture = $att['subattachments']['data'][0]['media']['image']['src'];
            }
        }

        // Facebook's image URLs (especially for Reels/videos) are often
        // signed and expire or block hotlinking from other domains, so we
        // download a local copy and store that path instead.
        $picture = $remotePicture ? fbCacheImage($remotePicture, $p['id']) : null;

        $upsert->execute([
            $p['id'],
            $p['message'] ?? '',
            $picture,
            $p['permalink_url'] ?? null,
            !empty($p['created_time']) ? date('Y-m-d H:i:s', strtotime($p['created_time'])) : date('Y-m-d H:i:s'),
        ]);
    }

    // Remove cached rows for posts that no longer exist on the Page (e.g.
    // deleted directly on Facebook). We check against a larger, separate
    // "still alive" list (not just this fetch's $limit) so we don't wrongly
    // wipe out older-but-still-real posts that simply fell outside $limit.
    $aliveCheck = fbGraphRequest('GET', FB_PAGE_ID . '/posts', ['fields' => 'id', 'limit' => 50]);
    if ($aliveCheck['ok']) {
        $liveIds = array_filter(array_column($aliveCheck['data']['data'] ?? [], 'id'));
        if (!empty($liveIds)) {
            $placeholders = implode(',', array_fill(0, count($liveIds), '?'));
            $pdo->prepare("DELETE FROM fb_posts WHERE fb_post_id NOT IN ($placeholders)")->execute($liveIds);
        }
        // If the Page genuinely has zero posts, clear the cache entirely.
        elseif (empty($aliveCheck['data']['data'])) {
            $pdo->exec("DELETE FROM fb_posts");
        }
    }

    $rows = $pdo->prepare("SELECT * FROM fb_posts ORDER BY created_time DESC LIMIT ?");
    $rows->bindValue(1, $limit, PDO::PARAM_INT);
    $rows->execute();
    return ['posts' => $rows->fetchAll(), 'error' => null, 'from_cache' => false];
}
