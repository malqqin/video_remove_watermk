import worker from "./workers.js";

const input = (process.argv[2] || "").trim();

if (!input) {
    process.stdout.write(JSON.stringify({
        code: 400,
        msg: "请输入抖音链接",
        data: [],
    }));
    process.exit(0);
}

// Bound each upstream request so a parser call cannot occupy the PHP worker
// indefinitely when Douyin is slow or unavailable.
const nativeFetch = globalThis.fetch;
globalThis.fetch = (resource, options = {}) => nativeFetch(resource, {
    ...options,
    signal: options.signal || AbortSignal.timeout(15000),
});

try {
    const requestUrl = new URL("http://localhost/");
    requestUrl.searchParams.set("url", input);

    const response = await worker.fetch(new Request(requestUrl));
    process.stdout.write(await response.text());
} catch (error) {
    process.stdout.write(JSON.stringify({
        code: 500,
        msg: `本地抖音解析器异常：${error instanceof Error ? error.message : String(error)}`,
        data: [],
    }));
}
