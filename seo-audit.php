#!/usr/bin/env php
<?php
/**
 * seo-audit.php — dependency-free CLI SEO auditor for a single URL.
 *
 * Usage:
 *   php seo-audit.php https://example.com/page [--json] [--no-color]
 *
 * Checks: status & redirect chain, load time, page size, gzip, title,
 * meta description, canonical, robots meta, viewport, H1/H2 structure,
 * Open Graph + Twitter tags, image alt coverage, robots.txt, sitemap.xml,
 * and body word count. Prints a Markdown report (or JSON with --json).
 *
 * Requires PHP 7.4+ (cURL preferred; falls back to streams).
 */

error_reporting(E_ALL & ~E_DEPRECATED);

const OK   = 'ok';
const WARN = 'warn';
const FAIL = 'fail';

function colorize($text, $color, $noColor) {
	if ($noColor) {
		return $text;
	}
	$codes = array(OK => "\033[32m", WARN => "\033[33m", FAIL => "\033[31m", 'reset' => "\033[0m");
	return ($codes[$color] ?? '') . $text . $codes['reset'];
}

/**
 * Fetch a URL, following redirects manually so the chain is visible.
 *
 * @return array{html:string, info:array, error:string|null}
 */
function fetch($url, $maxRedirects = 5) {
	$chain   = array();
	$current = $url;

	for ($i = 0; $i <= $maxRedirects; $i++) {
		if (function_exists('curl_init')) {
			$result = fetch_curl($current);
		} else {
			$result = fetch_stream($current);
		}
		if ($result['error'] !== null) {
			return $result;
		}
		$chain[] = array(
			'url'    => $current,
			'status' => $result['info']['status'],
			'time'   => $result['info']['time'],
			'bytes'  => $result['info']['bytes'],
		);
		$loc = $result['info']['location'];
		if ($result['info']['status'] >= 300 && $result['info']['status'] < 400 && $loc !== '') {
			$current = resolve_url($current, $loc);
			continue;
		}
		$result['chain'] = $chain;
		return $result;
	}
	return array('html' => '', 'info' => array('status' => 0), 'error' => "Too many redirects (>$maxRedirects)", 'chain' => $chain);
}

function fetch_curl($url) {
	$ch = curl_init($url);
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => false, // manual redirect walk
		CURLOPT_HEADER         => true,
		CURLOPT_TIMEOUT        => 20,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_USERAGENT      => 'Mozilla/5.0 (SEO-Auditor/1.0; +https://github.com/Azanumer/php-seo-auditor)',
		CURLOPT_ENCODING       => '', // accept gzip/deflate/br, decode automatically
		CURLOPT_SSL_VERIFYPEER => true,
	));
	$raw   = curl_exec($ch);
	$errno = curl_errno($ch);
	$err   = curl_error($ch);
	$info  = curl_getinfo($ch);
	curl_close($ch);

	if ($raw === false) {
		return array('html' => '', 'info' => array(), 'error' => "cURL error $errno: $err");
	}

	$headerSize = (int) $info['header_size'];
	$headers    = substr($raw, 0, $headerSize);
	$body       = substr($raw, $headerSize);
	$location   = '';
	if (preg_match('/^location:\s*(\S+)/mi', $headers, $m)) {
		$location = trim($m[1]);
	}
	return array(
		'html'  => $body,
		'info'  => array(
			'status'   => (int) $info['http_code'],
			'time'     => round((float) $info['total_time'], 2),
			'bytes'    => strlen($body),
			'location' => $location,
			// CURLOPT_ENCODING decodes the body, so a smaller wire size means compression was used.
			'gzip'     => ($info['size_download'] ?? 0) > 0 && $info['size_download'] < strlen($body),
		),
		'error' => null,
	);
}

function fetch_stream($url) {
	$start = microtime(true);
	$ctx   = stream_context_create(array(
		'http' => array(
			'method'          => 'GET',
			'timeout'         => 20,
			'follow_location' => 0,
			'header'          => "User-Agent: SEO-Auditor/1.0\r\nAccept-Encoding: gzip, deflate\r\n",
		),
		'ssl'  => array('verify_peer' => true, 'verify_peer_name' => true),
	));
	$body = @file_get_contents($url, false, $ctx);
	if ($body === false) {
		return array('html' => '', 'info' => array(), 'error' => 'HTTP fetch failed (streams)');
	}
	$status = 0; $location = '';
	foreach (($http_response_header ?? array()) as $h) {
		if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
			$status = (int) $m[1];
		} elseif (stripos($h, 'location:') === 0) {
			$location = trim(substr($h, 9));
		}
	}
	return array(
		'html'  => $body,
		'info'  => array(
			'status'   => $status,
			'time'     => round(microtime(true) - $start, 2),
			'bytes'    => strlen($body),
			'location' => $location,
			'gzip'     => false,
		),
		'error' => null,
	);
}

/** Resolve a possibly-relative Location header against the current URL. */
function resolve_url($base, $relative) {
	if (preg_match('#^https?://#i', $relative)) {
		return $relative;
	}
	$parts = parse_url($base);
	$schemeHost = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
	if (strpos($relative, '/') === 0) {
		return $schemeHost . $relative;
	}
	$dir = rtrim(dirname($parts['path'] ?? '/'), '/');
	return $schemeHost . $dir . '/' . $relative;
}

/** First match of a regex group, trimmed; '' when absent. */
function grab($pattern, $html) {
	return preg_match($pattern, $html, $m) ? trim($m[1]) : '';
}

/** Count all matches of a pattern. */
function count_matches($pattern, $html) {
	return preg_match_all($pattern, $html, $m) ? count($m[0]) : 0;
}

/** Cheap HEAD probe: does the URL return 2xx/3xx? */
function probe($url) {
	$headers = @get_headers($url, true);
	if ($headers === false || !isset($headers[0])) {
		return false;
	}
	return (bool) preg_match('#^HTTP/\S+\s+[23]\d\d#', (string) $headers[0]);
}

/* ------------------------------------------------------------------ */

function usage() {
	fwrite(STDERR, "Usage: php seo-audit.php https://example.com/page [--json] [--no-color]\n");
	exit(2);
}

$args    = array_values(array_filter($argv, function ($a, $i) { return $i > 0; }, ARRAY_FILTER_USE_BOTH));
$noColor = in_array('--no-color', $args, true);
$asJson  = in_array('--json', $args, true);
$targets = array_values(array_filter($args, function ($a) { return $a[0] !== '-'; }));
if (empty($targets)) {
	usage();
}
$target = $targets[0];
if (!preg_match('#^https?://#i', $target)) {
	$target = 'https://' . $target;
}

$result = fetch($target);
if ($result['error'] !== null) {
	fwrite(STDERR, "Fetch failed: {$result['error']}\n");
	exit(1);
}

$html  = $result['html'];
$info  = $result['info'];
$chain = $result['chain'] ?? array();
$checks = array();

function add_check(&$checks, $name, $status, $detail) {
	$checks[] = array('check' => $name, 'status' => $status, 'detail' => $detail);
}

/* --- Transport --- */
$finalStatus = $info['status'];
add_check($checks, 'HTTP status', ($finalStatus >= 200 && $finalStatus < 300) ? OK : FAIL, "Final status: $finalStatus");
$hops = count($chain);
add_check($checks, 'Redirect chain', $hops <= 2 ? OK : ($hops <= 4 ? WARN : FAIL), $hops > 1 ? ($hops - 1) . ' redirect hop(s): ' . implode(' → ', array_column($chain, 'status')) : 'no redirects');
add_check($checks, 'Load time', $info['time'] <= 1.5 ? OK : ($info['time'] <= 3 ? WARN : FAIL), $info['time'] . 's to first byte+body');
$kb = round($info['bytes'] / 1024, 1);
add_check($checks, 'Page size', $info['bytes'] <= 512 * 1024 ? OK : WARN, "$kb KB HTML");
add_check($checks, 'Compression', !empty($info['gzip']) ? OK : WARN, !empty($info['gzip']) ? 'gzip/deflate detected' : 'no compression detected (streams fallback cannot detect — re-run with cURL)');

/* --- Title & meta --- */
$title = grab('#<title[^>]*>(.*?)</title>#is', $html);
$tlen  = mb_strlen($title);
add_check($checks, 'Title tag', ($tlen >= 30 && $tlen <= 60) ? OK : ($tlen === 0 ? FAIL : WARN), $tlen === 0 ? 'missing' : "\"$title\" ($tlen chars; ideal 30–60)");

$desc = grab('#<meta\s[^>]*name=["\']description["\'][^>]*content=["\']([^"\']*)#i', $html);
if ($desc === '') {
	$desc = grab('#<meta\s[^>]*content=["\']([^"\']*)["\'][^>]*name=["\']description["\']#i', $html);
}
$dlen = mb_strlen($desc);
add_check($checks, 'Meta description', ($dlen >= 120 && $dlen <= 160) ? OK : ($dlen === 0 ? WARN : WARN), $dlen === 0 ? 'missing (recommended)' : "$dlen chars (ideal 120–160)");

$canonical = grab('#<link\s[^>]*rel=["\']canonical["\'][^>]*href=["\']([^"\']+)#i', $html);
if ($canonical === '') {
	$canonical = grab('#<link\s[^>]*href=["\']([^"\']+)["\'][^>]*rel=["\']canonical["\']#i', $html);
}
add_check($checks, 'Canonical', $canonical !== '' ? OK : WARN, $canonical !== '' ? $canonical : 'missing');

$robots = grab('#<meta\s[^>]*name=["\']robots["\'][^>]*content=["\']([^"\']*)#i', $html);
if ($robots === '') {
	$robots = grab('#<meta\s[^>]*content=["\']([^"\']*)["\'][^>]*name=["\']robots["\']#i', $html);
}
$robotsBlocked = stripos($robots, 'noindex') !== false || stripos($robots, 'none') !== false;
add_check($checks, 'Robots meta', $robotsBlocked ? FAIL : OK, $robots !== '' ? $robots : 'none set (index, follow assumed)');

$viewport = grab('#<meta\s[^>]*name=["\']viewport["\'][^>]*content=["\']([^"\']*)#i', $html);
add_check($checks, 'Viewport (mobile)', $viewport !== '' ? OK : FAIL, $viewport !== '' ? $viewport : 'missing — page is not mobile-friendly flagged');

/* --- Headings --- */
$h1 = count_matches('#<h1[\s>]#i', $html);
add_check($checks, 'H1 count', $h1 === 1 ? OK : ($h1 === 0 ? FAIL : WARN), "$h1 H1 tag(s) (exactly 1 recommended)");
$h2 = count_matches('#<h2[\s>]#i', $html);
add_check($checks, 'H2 count', $h2 >= 1 ? OK : WARN, "$h2 H2 tag(s)");

/* --- Social tags --- */
$og = count_matches('#<meta\s[^>]*property=["\']og:#i', $html);
$tw = count_matches('#<meta\s[^>]*name=["\']twitter:#i', $html);
add_check($checks, 'Open Graph', $og >= 4 ? OK : ($og > 0 ? WARN : WARN), "$og og:* tags (title, description, image, url recommended)");
add_check($checks, 'Twitter cards', $tw >= 2 ? OK : WARN, "$tw twitter:* tags");

/* --- Images --- */
$imgs    = count_matches('#<img[\s>]#i', $html);
$withAlt = count_matches('#<img(?=[^>]*\salt\s*=)[^>]*>#i', $html);
$altPct  = $imgs > 0 ? round($withAlt / $imgs * 100) : 100;
add_check($checks, 'Image alt text', ($altPct >= 90 || $imgs === 0) ? OK : WARN, "$withAlt/$imgs images have alt ($altPct%)");

/* --- Content depth --- */
$text  = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $html))));
$words = $text === '' ? 0 : count(preg_split('/\s+/', $text));
add_check($checks, 'Word count', $words >= 300 ? OK : WARN, "$words words of body text (300+ recommended)");

/* --- robots.txt / sitemap.xml --- */
$parts  = parse_url($target);
$origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
add_check($checks, 'robots.txt', probe($origin . '/robots.txt') ? OK : WARN, $origin . '/robots.txt');
add_check($checks, 'sitemap.xml', probe($origin . '/sitemap.xml') ? OK : WARN, $origin . '/sitemap.xml');

$score = array('ok' => 0, 'warn' => 0, 'fail' => 0);
foreach ($checks as $c) {
	$score[$c['status']]++;
}
$grade = $score['fail'] > 0 ? 'Needs work' : ($score['warn'] > 2 ? 'Fair' : 'Good');

$report = array(
	'url'    => $target,
	'final'  => end($chain)['url'] ?? $target,
	'grade'  => $grade,
	'score'  => $score,
	'checks' => $checks,
);

if ($asJson) {
	echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
	exit($score['fail'] > 0 ? 1 : 0);
}

echo "# SEO audit: $target\n\n";
echo "Final URL: " . ($report['final']) . "\n";
echo "Grade: **$grade** — {$score['ok']} pass, {$score['warn']} warnings, {$score['fail']} failures\n\n";
echo "| Check | Result | Detail |\n|---|---|---|\n";
foreach ($checks as $c) {
	$badge = $c['status'] === OK ? colorize('PASS', OK, $noColor) : ($c['status'] === WARN ? colorize('WARN', WARN, $noColor) : colorize('FAIL', FAIL, $noColor));
	echo "| {$c['check']} | $badge | {$c['detail']} |\n";
}
echo "\nGenerated by php-seo-auditor.\n";

exit($score['fail'] > 0 ? 1 : 0);
