<?php
declare(strict_types=1);

if (!function_exists('track_visitor_visit')) {
    /**
     * Tracks page visits and logs them in the database.
     */
    function track_visitor_visit(): void
    {
        // 1. Only track GET requests
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return;
        }

        // 2. Ignore command-line calls (CRON/CLI)
        if (PHP_SAPI === 'cli') {
            return;
        }

        // 3. Ignore AJAX/XMLHttpRequests
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax) {
            return;
        }

        // 4. Ignore specific paths/files (API endpoints, assets, cron jobs, etc.)
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

        $ignoredPatterns = [
            '#/api/#',
            '#/admin/api/#',
            '#/assets/#',
            '#/uploads/#',
            '#\.js$#i',
            '#\.css$#i',
            '#\.png$#i',
            '#\.jpg$#i',
            '#\.jpeg$#i',
            '#\.gif$#i',
            '#\.svg$#i',
            '#\.ico$#i',
            '#\.mp4$#i',
            '#api\.php$#i',
            '#cron\.php$#i',
            '#git-status\.php$#i',
            '#sse\.php$#i'
        ];

        foreach ($ignoredPatterns as $pattern) {
            if (preg_match($pattern, $path)) {
                return;
            }
        }

        // 5. Ensure we have a database connection
        $pdo = $GLOBALS['musabaqa_pdo'] ?? null;
        if (!$pdo) {
            return;
        }

        // 6. Ensure we have an active session to link visits
        $sessionId = session_id();
        if (empty($sessionId)) {
            return;
        }

        // 7. Get client details
        $rawIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $ipAddress = mask_visitor_ip($rawIp);
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $referrer = $_SERVER['HTTP_REFERER'] ?? '';

        // Truncate details if they are too long for DB
        if (strlen($userAgent) > 500) {
            $userAgent = substr($userAgent, 0, 497) . '...';
        }
        if (strlen($referrer) > 500) {
            $referrer = substr($referrer, 0, 497) . '...';
        }
        if (strlen($requestUri) > 255) {
            $requestUri = substr($requestUri, 0, 252) . '...';
        }

        // 8. Rate Limiting / F5 spam protection:
        // Skip inserting if the same session requested the SAME page in the last 5 seconds.
        try {
            $checkStmt = $pdo->prepare("
                SELECT id 
                FROM musabaqa_visitor_logs 
                WHERE session_id = ? 
                  AND page_url = ? 
                  AND visit_time > DATE_SUB(NOW(), INTERVAL 5 SECOND) 
                LIMIT 1
            ");
            $checkStmt->execute([$sessionId, $requestUri]);
            if ($checkStmt->fetchColumn()) {
                return; // Suppressed rapid refresh
            }
        } catch (Throwable $e) {
            // If the table doesn't exist or query fails, skip checking
        }

        // 9. Parse User-Agent
        $uaInfo = parse_visitor_user_agent($userAgent);

        // 10. Fetch event context
        $eventId = $_SESSION['active_event_id'] ?? $_SESSION['selected_event_id'] ?? null;
        if (!$eventId) {
            try {
                $stmt = $pdo->query("SELECT id FROM musabaqa_events WHERE status = 'active' LIMIT 1");
                $eventId = $stmt->fetchColumn() ?: null;
            } catch (Throwable $e) {}
        }
        $eventId = $eventId ? (int)$eventId : null;

        // 11. Write visitor log to Database
        try {
            $insertStmt = $pdo->prepare("
                INSERT INTO musabaqa_visitor_logs (
                    session_id, ip_address, user_agent, device_type, 
                    browser, platform, page_url, referrer, is_bot, event_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insertStmt->execute([
                $sessionId,
                $ipAddress,
                $userAgent,
                $uaInfo['device'],
                $uaInfo['browser'],
                $uaInfo['platform'],
                $requestUri,
                $referrer,
                $uaInfo['is_bot'] ? 1 : 0,
                $eventId
            ]);
        } catch (Throwable $e) {
            // Fail silently so visitor tracking errors never break pages
        }
    }
}

if (!function_exists('mask_visitor_ip')) {
    /**
     * Anonymizes/masks the client IP address.
     */
    function mask_visitor_ip(string $ip): string
    {
        if (empty($ip)) {
            return 'unknown';
        }

        // Clean IPv6 localhost or mapped IPv4 localhost
        if ($ip === '::1' || $ip === '127.0.0.1') {
            return '127.0.0.xxx';
        }

        // IPv4 Masking (192.168.1.15 -> 192.168.1.xxx)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.xxx';
            }
        }

        // IPv6 Masking (2001:db8:85a3:8d3:1319:8a2e:370:7348 -> 2001:db8:85a3:8d3::xxxx)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            if (count($parts) >= 4) {
                return $parts[0] . ':' . $parts[1] . ':' . $parts[2] . ':' . $parts[3] . '::xxxx';
            }
        }

        return 'unknown';
    }
}

if (!function_exists('parse_visitor_user_agent')) {
    /**
     * Parses the HTTP_USER_AGENT string into Device, OS, Browser, and Bot flags.
     */
    function parse_visitor_user_agent(string $ua): array
    {
        $device = 'Desktop';
        $platform = 'Unknown';
        $browser = 'Unknown';
        $isBot = false;

        if (empty($ua)) {
            return [
                'device' => $device,
                'platform' => $platform,
                'browser' => $browser,
                'is_bot' => false
            ];
        }

        // 1. Detect Bots
        $botRegex = '/(googlebot|bingbot|yandexbot|baidu|duckduck|facebot|facebookexternalhit|twitterbot|ia_archiver|slurp|crawler|spider|bot|curl|wget)/i';
        if (preg_match($botRegex, $ua, $matches)) {
            $isBot = true;
            $device = 'Bot';
            $browser = ucfirst(strtolower($matches[1]));
            
            // Refine bot names
            if (str_contains(strtolower($ua), 'googlebot')) {
                $browser = 'Googlebot';
            } elseif (str_contains(strtolower($ua), 'bingbot')) {
                $browser = 'Bingbot';
            } elseif (str_contains(strtolower($ua), 'yandexbot')) {
                $browser = 'YandexBot';
            } elseif (str_contains(strtolower($ua), 'facebookexternalhit') || str_contains(strtolower($ua), 'facebot')) {
                $browser = 'Facebook Bot';
            } elseif (str_contains(strtolower($ua), 'twitterbot')) {
                $browser = 'Twitter Bot';
            }
            $platform = 'Search Engine';
        }

        // 2. Detect Platform/OS (if not bot)
        if (!$isBot) {
            $osMap = [
                '/windows nt 10/i'      => 'Windows 10/11',
                '/windows nt 6.3/i'     => 'Windows 8.1',
                '/windows nt 6.2/i'     => 'Windows 8',
                '/windows nt 6.1/i'     => 'Windows 7',
                '/windows nt 6.0/i'     => 'Windows Vista',
                '/windows nt 5.1/i'     => 'Windows XP',
                '/windows xp/i'         => 'Windows XP',
                '/macintosh|mac os x/i' => 'macOS',
                '/android/i'            => 'Android',
                '/iphone/i'             => 'iOS (iPhone)',
                '/ipad/i'               => 'iOS (iPad)',
                '/linux/i'              => 'Linux',
                '/cros/i'               => 'ChromeOS'
            ];

            foreach ($osMap as $regex => $osName) {
                if (preg_match($regex, $ua)) {
                    $platform = $osName;
                    break;
                }
            }

            // 3. Detect Device Type
            if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobile))/i', $ua)) {
                $device = 'Tablet';
            } elseif (preg_match('/(mobi|ipod|iphone|opera mini|blackberry|iemobile|fennec)/i', $ua)) {
                $device = 'Mobile';
            }
        }

        // 4. Detect Browser (if not bot)
        if (!$isBot) {
            if (preg_match('/edg/i', $ua)) {
                $browser = 'Edge';
            } elseif (preg_match('/chrome/i', $ua) && !preg_match('/opr|opios/i', $ua)) {
                $browser = 'Chrome';
            } elseif (preg_match('/safari/i', $ua) && !preg_match('/chrome/i', $ua)) {
                $browser = 'Safari';
            } elseif (preg_match('/firefox/i', $ua)) {
                $browser = 'Firefox';
            } elseif (preg_match('/opera|opr/i', $ua)) {
                $browser = 'Opera';
            } elseif (preg_match('/samsungbrowser/i', $ua)) {
                $browser = 'Samsung Internet';
            } elseif (preg_match('/msie|trident/i', $ua)) {
                $browser = 'Internet Explorer';
            }
        }

        return [
            'device' => $device,
            'platform' => $platform,
            'browser' => $browser,
            'is_bot' => $isBot
        ];
    }
}

if (!function_exists('render_clarity_script')) {
    /**
     * Renders the Google Clarity tracking script if the project ID is configured.
     */
    function render_clarity_script(): void
    {
        $clarityId = env('CLARITY_PROJECT_ID');
        if (empty($clarityId)) {
            return;
        }

        echo "\n<!-- Google Clarity -->\n";
        echo "<script type=\"text/javascript\">\n";
        echo "    (function(c,l,a,r,i,t,y){\n";
        echo "        c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};\n";
        echo "        t=l.createElement(r);t.async=1;t.src=\"https://www.clarity.ms/tag/\"+i;\n";
        echo "        y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);\n";
        echo "    })(window,document,\"clarity\",\"script\",\"" . htmlspecialchars((string)$clarityId, ENT_QUOTES, 'UTF-8') . "\");\n";
        echo "</script>\n";
    }
}
