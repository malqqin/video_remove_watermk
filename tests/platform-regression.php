<?php
// Offline regression cases. Run each suite in a separate PHP process because
// the legacy platform scripts define some global helpers with the same names.
declare(strict_types=1);

define('SV2_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/short_videos/sv2.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$checks = 0;
function check($actual, $expected, string $label): void
{
    global $checks;
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': ' . var_export($actual, true));
    }
    $checks++;
}

$suite = $argv[1] ?? 'aggregator';
try {
    switch ($suite) {
        case 'aggregator':
            $url = 'https://www.xiaohongshu.com/explore/example?xsec_token=A_b-c=&xsec_source=pc_feed';
            check(sv2ExtractUrl('分享 [' . $url . '](' . $url . ')'), $url, 'share text preserves token');
            check(sv2DetectPlatform($url), 'xiaohongshu', 'platform routing');
            check(sv2DetectPlatform('https://douyin.com.example.org/video/123'), null, 'suffix spoof');
            check(sv2DetectPlatform('https://example.org/?url=https://douyin.com'), null, 'query spoof');
            check(sv2ExtractUrl('file:///tmp/video.mp4'), null, 'non-HTTP input');
            $result = sv2NormalizeResult(['code' => 200, 'data' => []], 'zuiyou');
            check($result['code'], 502, 'empty success');
            check($result['source'], 'local', 'source field');
            check($result['platform'], 'zuiyou', 'platform field');
            $result = sv2NormalizeResult(['code' => 200, 'data' => [
                'type' => 'video', 'images' => ['https://example.org/cover.jpg'],
            ]], 'douyin');
            check($result['code'], 502, 'video thumbnails cannot count as playable video');
            $result = sv2NormalizeResult(['code' => 200, 'data' => [
                'type' => 'image', 'images' => ['https://example.org/photo.jpg'],
            ]], 'xiaohongshu');
            check($result['code'], 200, 'image posts remain supported');
            $result = sv2NormalizeResult('{"code":200,"data":{"video":"https://example.org/video.mp4"}}', 'pipigx');
            check($result['data']['url'], 'https://example.org/video.mp4', 'legacy video field');
            check(sv2NormalizeResult('<html>upstream error</html>', 'weibo')['code'], 502, 'invalid JSON');
            check(sv2NormalizeResult(['code' => 429], 'weibo')['code'], 429, 'upstream failure preserved');
            break;

        case 'weibo':
            require dirname(__DIR__) . '/api/weibo.php';
            check(sv2ParseWeibo('https://weibo.com/')['code'], 400, 'invalid input returns without exit');
            check(WEIBO_CONNECT_TIMEOUT, 5, 'connection timeout defined');
            check(extractVideoId('https://weibo.com/tv/show/1034:123456789'), '1034:123456789', 'TV ID');
            check(extractVideoId('https://video.weibo.com/show?fid=1034%3A123456789'), '1034:123456789', 'encoded fid');
            $fixture = ['code' => 100000, 'data' => ['Component_Play_Playinfo' => [
                'title' => 'Test', 'urls' => ['480P' => '//example.org/480.mp4', '1080P' => 'https://example.org/1080.mp4'],
                'avatar' => '//example.org/avatar.jpg', 'cover_image' => 'https://example.org/cover.jpg',
            ]]];
            $result = formatWeiboResponse($fixture);
            check($result['data']['url'], 'https://example.org/1080.mp4', 'best direct URL, no third-party proxy');
            check($result['data']['author']['avatar'], 'https://example.org/avatar.jpg', 'scheme-relative avatar');
            $fixture['data']['Component_Play_Playinfo']['urls'] = [];
            check(formatWeiboResponse($fixture)['code'], 404, 'missing video');
            break;

        case 'pipigx':
            require dirname(__DIR__) . '/api/pipigx.php';
            check(sv2ParsePipigx('https://h5.pipigx.com/')['code'], 400, 'invalid input returns without exit');
            check(extractParamsFromUrl('https://h5.pipigx.com/?pid=123&mid=456'), ['pid' => '123', 'mid' => '456'], 'both IDs preserved');
            check(extractParamsFromUrl('https://h5.pipigx.com/?pid[]=123&mid=456'), false, 'array ID rejected');
            check(processApiResponse(['code' => 500, 'msg' => 'network error'])['code'], 500, 'network failure without response field');
            $fixture = ['data' => ['post' => ['content' => 'Test', 'videos' => []]]];
            check(processApiResponse(['code' => 200, 'response' => json_encode($fixture)])['code'], 404, 'empty video is failure');
            $fixture['data']['post']['videos'] = [['url' => 'https://example.org/video.mp4']];
            $result = processApiResponse(['code' => 200, 'response' => json_encode($fixture)]);
            check($result['data']['url'], 'https://example.org/video.mp4', 'standard media field');
            break;

        case 'pipixia':
            require dirname(__DIR__) . '/api/ppxia.php';
            foreach (['', '/', '?app_id=1319', '/?app_id=1319'] as $suffix) {
                check(pipixiaItemId('https://h5.pipix.com/item/123456789' . $suffix), '123456789', 'ID with suffix ' . $suffix);
            }
            check(pipixiaItemId('https://h5.pipix.com/item/invalid'), null, 'invalid item ID');
            break;

        case 'toutiao':
            require dirname(__DIR__) . '/api/toutiao.php';
            foreach (['video/123456789/', 'i123456789/', 'i/123456789/'] as $path) {
                check(extractId('https://www.toutiao.com/' . $path), '123456789', 'direct ID ' . $path);
            }
            break;

        case 'zuiyou':
            require dirname(__DIR__) . '/api/zuiyou.php';
            check(sv2ParseZuiyou('')['code'], 400, 'invalid input returns without output');
            check(getParamFromUrl('https://share.xiaochuankeji.cn/hybrid/share/post?pid=123&vid=456', 'pid'), '123', 'pid preserved');
            check(getParamFromUrl('https://share.xiaochuankeji.cn/?pid[]=123', 'pid'), null, 'array pid rejected');
            check(formatZuiyouResponse([])['code'], 404, 'empty upstream data');
            $fixture = ['ret' => 1, 'data' => ['post' => ['content' => 'Test', 'imgs' => [['id' => 456]], 'videos' => []]]];
            check(formatZuiyouResponse($fixture)['code'], 404, 'no video for image ID');
            $fixture['data']['post']['videos'][456] = ['url' => 'https://example.org/video.mp4'];
            check(formatZuiyouResponse($fixture)['data']['url'], 'https://example.org/video.mp4', 'video selected by image ID');
            $fixture['ret'] = -1;
            check(formatZuiyouResponse($fixture)['code'], 502, 'upstream error cannot become success');
            break;

        default:
            throw new InvalidArgumentException('Unknown suite: ' . $suite);
    }
    echo "$suite: $checks checks passed\n";
} catch (Throwable $error) {
    fwrite(STDERR, "$suite failed: {$error->getMessage()}\n");
    exit(1);
}
