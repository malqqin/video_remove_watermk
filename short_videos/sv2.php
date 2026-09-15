<?php
/**
 * Local short-video parser aggregator.
 *
 * This endpoint only dispatches to parsers shipped in this repository. It does
 * not forward requests to a hosted aggregation API.
 */

declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// Parser warnings must not corrupt JSON responses. They are still written to
// the PHP error log when log_errors is enabled.
ini_set('display_errors', '0');

const SV2_JSON_OPTIONS = JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_PRETTY_PRINT;

function sv2Main(): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    if ($method !== 'GET' && $method !== 'POST') {
        sv2Error(405, '仅支持 GET、POST 或 OPTIONS 请求', [], 405);
    }

    $input = sv2ReadInput($method);
    $url = sv2ExtractUrl($input);
    if ($url === null) {
        sv2Error(400, '请提供有效的 url 参数', [], 400);
    }

    $platform = sv2DetectPlatform($url);
    if ($platform === null) {
        sv2Error(400, '不支持该链接平台', [
            'supported_platforms' => array_keys(sv2PlatformConfig()),
        ], 400);
    }

    $config = sv2PlatformConfig()[$platform];

    try {
        $payload = call_user_func($config['handler'], $url);
        sv2OutputParserResult($payload, $platform);
    } catch (Throwable $error) {
        error_log(sprintf(
            'sv2 local parser error [%s]: %s',
            $platform,
            $error->getMessage()
        ));

        sv2Error(500, '本地解析器执行失败', [
            'platform' => $platform,
        ], 500);
    }
}

function sv2ReadInput(string $method): string
{
    if ($method === 'GET') {
        return trim((string)($_GET['url'] ?? ''));
    }

    if (isset($_POST['url'])) {
        return trim((string)$_POST['url']);
    }

    $body = file_get_contents('php://input');
    if ($body === false || trim($body) === '') {
        return '';
    }

    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $json = json_decode($body, true);
        return is_array($json) ? trim((string)($json['url'] ?? '')) : '';
    }

    return trim($body);
}

function sv2ExtractUrl(string $input): ?string
{
    $input = trim($input);
    if ($input === '') {
        return null;
    }

    if (filter_var($input, FILTER_VALIDATE_URL) !== false) {
        return sv2IsHttpUrl($input) ? $input : null;
    }

    // Also accept copied share text containing a single URL.
    if (!preg_match("~https?://[^\\s<>\"'\\]\\)]+~u", $input, $matches)) {
        return null;
    }

    $url = rtrim($matches[0], ".,;!?。，；！？");
    return filter_var($url, FILTER_VALIDATE_URL) !== false && sv2IsHttpUrl($url)
        ? $url
        : null;
}

function sv2IsHttpUrl(string $url): bool
{
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return $scheme === 'http' || $scheme === 'https';
}

function sv2PlatformConfig(): array
{
    return [
        'douyin' => [
            'domains' => ['douyin.com', 'iesdouyin.com'],
            'handler' => 'sv2ParseDouyin',
        ],
        'bilibili' => [
            'domains' => ['bilibili.com', 'b23.tv'],
            'handler' => 'sv2ParseBilibili',
        ],
        'kuaishou' => [
            'domains' => ['kuaishou.com', 'gifshow.com', 'kwai.com'],
            'handler' => 'sv2ParseKuaishou',
        ],
        'xiaohongshu' => [
            'domains' => ['xiaohongshu.com', 'xhslink.com', 'xhs.com'],
            'handler' => 'sv2ParseXiaohongshu',
        ],
        'weibo' => [
            'domains' => ['weibo.com', 'weibo.cn', 't.cn'],
            'handler' => 'sv2ParseWeibo',
        ],
        'toutiao' => [
            'domains' => ['toutiao.com'],
            'handler' => 'sv2ParseToutiao',
        ],
        'pipixia' => [
            'domains' => ['pipix.com', 'pipixia.com'],
            'handler' => 'sv2ParsePipixia',
        ],
        'pipigx' => [
            'domains' => ['pipigx.com', 'ippzone.com'],
            'handler' => 'sv2ParsePipigx',
        ],
        'zuiyou' => [
            'domains' => ['xiaochuankeji.cn', 'izuiyou.com'],
            'handler' => 'sv2ParseZuiyou',
        ],
        'jimeng' => [
            'domains' => ['jimeng.jianying.com', 'v.jimeng.aiseet.atry.com'],
            'handler' => 'sv2ParseJimeng',
        ],
        'doubao' => [
            'domains' => ['doubao.com'],
            'handler' => 'sv2ParseDoubao',
        ],
    ];
}

function sv2DetectPlatform(string $url): ?string
{
    $host = strtolower(rtrim((string)parse_url($url, PHP_URL_HOST), '.'));
    if ($host === '') {
        return null;
    }

    foreach (sv2PlatformConfig() as $platform => $config) {
        foreach ($config['domains'] as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $platform;
            }
        }
    }

    return null;
}

function sv2ParseDouyin(string $url)
{
    $workerResult = sv2RunNodeParser(
        'api/douyin new/cloudflare workers/local-runner.mjs',
        $url
    );
    if ($workerResult !== null) {
        return $workerResult;
    }

    require_once sv2ProjectPath('api/douyin/DouyinParser.php');

    $parser = new DouyinParser();
    $parser->setCookie(sv2Env('DOUYIN_COOKIE'));
    return $parser->parse($url);
}

function sv2ParseBilibili(string $url)
{
    require_once sv2ProjectPath('api/bilibili/BilibiliParser.php');

    $parser = new BilibiliParser(sv2Env('BILIBILI_COOKIE'));
    return $parser->parse($url);
}

function sv2ParseKuaishou(string $url): array
{
    require_once sv2ProjectPath('api/kuaishou/KuaishouSpider.php');

    $userAgent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) '
        . 'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 '
        . 'Mobile/15E148 Safari/604.1';
    $parser = new KuaishouSpider(sv2Env('KUAISHOU_COOKIE'), $userAgent, 15);
    return $parser->analyze($url);
}

function sv2ParseXiaohongshu(string $url)
{
    require_once sv2ProjectPath('api/xiaohongshu/XiaohongshuParser.php');

    $parser = new XiaohongshuParser();
    $parser->setCookie(sv2Env('XIAOHONGSHU_COOKIE'));
    return $parser->parse($url);
}

function sv2ParseJimeng(string $url)
{
    require_once sv2ProjectPath('api/jimengai/JimengParser.php');

    $parser = new JimengParser();
    return $parser->parseShareText($url);
}

function sv2ParseDoubao(string $url)
{
    require_once sv2ProjectPath('api/doubao/video/DoubaoParser.php');

    $parser = new DoubaoParser();
    return $parser->parse($url);
}

function sv2ParseWeibo(string $url): array
{
    return sv2RunLegacyParser('api/weibo.php', $url);
}

function sv2ParseToutiao(string $url): array
{
    return sv2RunLegacyParser('api/toutiao.php', $url);
}

function sv2ParsePipixia(string $url): array
{
    return sv2RunLegacyParser('api/ppxia.php', $url);
}

function sv2ParsePipigx(string $url): array
{
    return sv2RunLegacyParser('api/pipigx.php', $url);
}

function sv2ParseZuiyou(string $url): array
{
    return sv2RunLegacyParser('api/zuiyou.php', $url);
}

function sv2RunLegacyParser(string $relativePath, string $url): array
{
    $parsers = [
        'api/weibo.php' => 'parseWeibo',
        'api/toutiao.php' => 'toutiao',
        'api/ppxia.php' => 'pipixia',
        'api/pipigx.php' => 'parsePipigx',
        'api/zuiyou.php' => 'parseZuiyou',
    ];
    if (!isset($parsers[$relativePath])) {
        throw new InvalidArgumentException('未知的本地解析器');
    }
    $script = sv2ProjectPath($relativePath);
    if (!is_file($script)) {
        throw new RuntimeException('本地解析器文件不存在');
    }

    // Legacy entry points expose a returning function in library mode. This
    // avoids exit(), output capture, and rewriting request globals during POST.
    if (!defined('SV2_LIBRARY_ONLY')) {
        define('SV2_LIBRARY_ONLY', true);
    }
    require_once $script;
    return $parsers[$relativePath]($url);
}

function sv2ProjectPath(string $relativePath): string
{
    return dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
}

function sv2Env(string $name): string
{
    $value = getenv($name);
    return $value === false ? '' : trim($value);
}

function sv2RunNodeParser(string $relativePath, string $url): ?string
{
    if (!function_exists('proc_open')) {
        error_log('sv2: proc_open is unavailable; falling back to the PHP parser');
        return null;
    }

    $runner = sv2ProjectPath($relativePath);
    if (!is_file($runner)) {
        error_log('sv2: local Node parser runner is missing');
        return null;
    }

    $nodeBinary = sv2Env('NODE_BINARY');
    if ($nodeBinary === '') {
        $nodeBinary = 'node';
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $command = [
        $nodeBinary,
        '--experimental-default-type=module',
        $runner,
        $url,
    ];
    $pipes = [];
    $process = @proc_open(
        $command,
        $descriptors,
        $pipes,
        dirname($runner),
        null,
        ['bypass_shell' => true]
    );

    if (!is_resource($process)) {
        error_log('sv2: failed to start the local Node parser');
        return null;
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + 45;
    $timedOut = false;

    do {
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        $status = proc_get_status($process);

        if (!$status['running']) {
            break;
        }

        if (microtime(true) >= $deadline) {
            $timedOut = true;
            proc_terminate($process);
            break;
        }

        usleep(10000);
    } while (true);

    $stdout .= (string)stream_get_contents($pipes[1]);
    $stderr .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    if ($timedOut) {
        error_log('sv2: local Node parser timed out');
        return json_encode([
            'code' => 504,
            'msg' => '本地抖音解析超时',
            'data' => [],
        ], SV2_JSON_OPTIONS);
    }

    $decoded = json_decode(trim($stdout), true);
    if (!is_array($decoded)) {
        error_log('sv2: local Node parser failed: ' . trim($stderr));
        return null;
    }

    return trim($stdout);
}

function sv2OutputParserResult($payload, string $platform): void
{
    $payload = sv2NormalizeResult($payload, $platform);
    $code = (int)$payload['code'];
    http_response_code($code >= 400 && $code <= 599 ? $code : ($code === 200 ? 200 : 502));
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, SV2_JSON_OPTIONS | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function sv2NormalizeResult($payload, string $platform): array
{
    if (is_string($payload)) {
        $payload = json_decode(trim($payload), true);
    }

    if (!is_array($payload) || !isset($payload['code'])) {
        $payload = ['code' => 502, 'msg' => '本地解析器返回了无效响应', 'data' => []];
    }
    // Some legacy parsers call missing or empty media a success. A video
    // thumbnail alone must never make a video response pass validation.
    if ((int)$payload['code'] === 200) {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $mediaUrl = $data['url'] ?? $data['video'] ?? '';
        if (!is_string($mediaUrl) || !filter_var($mediaUrl, FILTER_VALIDATE_URL) || !sv2IsHttpUrl($mediaUrl)) {
            $imageUrls = $data['images'] ?? $data['imgurl'] ?? [];
            $hasImages = false;
            foreach (is_array($imageUrls) ? $imageUrls : [] as $imageUrl) {
                if (is_string($imageUrl) && filter_var($imageUrl, FILTER_VALIDATE_URL) && sv2IsHttpUrl($imageUrl)) {
                    $hasImages = true;
                    break;
                }
            }
            if (!$hasImages || ($data['type'] ?? '') === 'video') {
                $payload = ['code' => 502, 'msg' => '本地解析器未返回有效媒体地址', 'data' => []];
            }
        } elseif (empty($data['url'])) {
            $payload['data']['url'] = $mediaUrl;
        }
    }

    $payload['platform'] = $platform;
    $payload['source'] = 'local';

    return $payload;
}

function sv2Error(
    int $code,
    string $message,
    array $data = [],
    int $httpStatus = 400
): void {
    http_response_code($httpStatus);
    echo json_encode([
        'code' => $code,
        'msg' => $message,
        'data' => $data,
        'source' => 'local',
    ], SV2_JSON_OPTIONS);
    exit;
}

if (!defined('SV2_LIBRARY_ONLY')) {
    sv2Main();
}
