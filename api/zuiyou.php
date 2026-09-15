<?php
/**
 * @Author: JH-Ahua
 * @CreateTime: 2026/3/31 上午11:25
 * @email: admin@bugpk.com
 * @blog: www.jiuhunwl.cn
 * @Api: api.bugpk.com
 * @tip: 最右解析
 */
header("Access-Control-Allow-Origin: *");
header('Content-Type: application/json; charset=utf-8');

/**
 * 构建统一格式的响应数组
 * @param int $code 状态码
 * @param string $msg 消息描述
 * @param array $data 附加数据
 * @return array 响应数组
 */
function outputJson(int $code, string $msg, array $data = []): array
{
    return [
        'code' => $code,
        'msg' => $msg,
        'data' => $data
    ];
}

/**
 * 从URL中提取指定参数
 * @param string $url 原始URL
 * @param string $param 要提取的参数名
 * @return string|null 参数值或null
 */
function getParamFromUrl(string $url, string $param): ?string
{
    $parsedUrl = parse_url($url);
    if (!isset($parsedUrl['query'])) {
        return null;
    }

    parse_str($parsedUrl['query'], $queryParams);
    $value = $queryParams[$param] ?? null;
    return is_string($value) ? $value : null;
}

/**
 * 获取URL重定向后的最终地址
 * @param string $url 原始URL
 * @return string|null 重定向后的URL或null
 */
function getRedirectUrl(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_NOSIGNAL => 1,
        CURLOPT_LOW_SPEED_LIMIT => 256,
        CURLOPT_LOW_SPEED_TIME => 6,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 处理3xx重定向状态码
    if ($httpCode >= 300 && $httpCode < 400) {
        if (preg_match('/Location: (.*?)\r\n/', $response, $matches)) {
            $redirectUrl = trim($matches[1]);
            // 处理相对路径
            if (strpos($redirectUrl, 'http') !== 0) {
                $parsedOriginal = parse_url($url);
                $redirectUrl = $parsedOriginal['scheme'] . '://' . $parsedOriginal['host'] . $redirectUrl;
            }
            return $redirectUrl;
        }
    }

    // 无重定向或获取失败，返回原URL
    return $url;
}

/**
 * 从URL或其重定向地址中提取指定参数
 * @param string $url 原始URL
 * @param string $param 要提取的参数名
 * @return string|null 提取到的参数值或null
 */
function getParamFromUrlWithRedirect(string $url, string $param): ?string
{
    // 先尝试从原始URL提取参数
    $value = getParamFromUrl($url, $param);
    if (!empty($value)) {
        return $value;
    }

    // 原始URL无参数，获取重定向后的URL再尝试提取
    $redirectUrl = getRedirectUrl($url);
    if ($redirectUrl && $redirectUrl !== $url) {
        $value = getParamFromUrl($redirectUrl, $param);
        if (!empty($value)) {
            return $value;
        }
    }

    return null;
}

/**
 * curl请求处理函数
 * @param string $url 请求URL
 * @param array|null $headers 请求头
 * @param mixed|null $data POST数据
 * @return string|false 响应结果或false
 */
function curlRequest(string $url, ?array $headers = null, $data = null)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HEADER => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_AUTOREFERER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_NOSIGNAL => 1,
        CURLOPT_LOW_SPEED_LIMIT => 256,
        CURLOPT_LOW_SPEED_TIME => 8,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    ]);

    if (isset($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    if (isset($data)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }

    $result = curl_exec($ch);

    if ($result === false) {
        $error = curl_error($ch);
        curl_close($ch);
        trigger_error("cURL error: $error", E_USER_WARNING);
        return false;
    }

    curl_close($ch);
    return $result;
}

function parseZuiyou(string $url): array
{
    $url = trim($url);
    if (!filter_var($url, FILTER_VALIDATE_URL)
        || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
        return outputJson(400, '请输入有效的URL');
    }

    $pid = getParamFromUrlWithRedirect($url, 'pid');
    if ($pid === null || !ctype_digit($pid) || (int)$pid <= 0) {
        return outputJson(400, '找不到有效的pid参数（包括重定向后）');
    }

    $apiUrl = 'https://share.xiaochuankeji.cn/planck/share/post/detail_h5';
    $requestData = json_encode([
        'pid' => (int)$pid,
        'h_av' => '5.2.13.011'
    ]);

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];

    $apiResponse = curlRequest($apiUrl, $headers, $requestData);
    if ($apiResponse === false) {
        return outputJson(502, '最右接口请求失败');
    }
    $data = json_decode($apiResponse, true);
    if (!is_array($data)) {
        return outputJson(502, '最右接口未返回有效的 JSON');
    }
    return formatZuiyouResponse($data);
}

function formatZuiyouResponse(array $data): array
{
    if (isset($data['ret']) && (int)$data['ret'] !== 1) {
        return outputJson(502, '最右接口返回错误');
    }
    $postData = $data['data']['post'] ?? [];
    $memberData = $postData['member'] ?? [];
    $videosData = $postData['videos'] ?? [];
    $imgsData = $postData['imgs'][0] ?? [];
    $vid = $imgsData['id'] ?? null;
    $videoUrl = $vid === null ? null : ($videosData[$vid]['url'] ?? null);
    if (!is_string($videoUrl) || !filter_var($videoUrl, FILTER_VALIDATE_URL)
        || !in_array(parse_url($videoUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
        return outputJson(404, '最右未返回可播放的视频');
    }
    return outputJson(200, '请求成功', [
        'type' => 'video',
        'author' => $memberData['name'] ?? null,
        'avatar' => $memberData['avatar_urls']['origin']['urls'][0] ?? null,
        'title' => $postData['content'] ?? null,
        'cover' => $imgsData['urls']['540_webp']['urls'][0] ?? null,
        'url' => $videoUrl,
    ]);
}

if (!defined('SV2_LIBRARY_ONLY')) {
    $url = $_POST['url'] ?? $_GET['url'] ?? '';
    $responseData = parseZuiyou(is_string($url) ? $url : '');
    http_response_code($responseData['code']);
    echo json_encode($responseData, 480);
}
