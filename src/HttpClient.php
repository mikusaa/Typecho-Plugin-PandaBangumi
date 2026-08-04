<?php

namespace TypechoPlugin\PandaBangumi;

require_once __DIR__ . '/HttpTransport.php';

final class HttpClient implements HttpTransport
{
    private const MAX_BYTES = 4194304;

    public function __construct(
        private PluginConfig $config,
        private UpstreamGate $upstreamGate,
        private ?RefreshBudget $refreshBudget = null
    )
    {
        $this->refreshBudget ??= new RefreshBudget();
    }

    public static function appendResponseChunk(string &$content, string $chunk, int $maxBytes = self::MAX_BYTES): int
    {
        $length = strlen($chunk);
        if (strlen($content) + $length > max(1, $maxBytes)) {
            return 0;
        }
        $content .= $chunk;
        return $length;
    }

    public function get(string $url): bool|string
    {
        $this->refreshBudget->requireTime();
        return $this->upstreamGate->api(function () use ($url): bool|string {
            $requestTimeout = $this->refreshBudget->requestTimeoutMilliseconds();
            $connectTimeout = $this->refreshBudget->connectTimeoutMilliseconds();
            $curl = curl_init($url);
            if ($curl === false) {
                throw RefreshFailure::transient('PandaBangumi could not initialize cURL');
            }

            $content = '';
            $overflow = false;
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($curl, CURLOPT_HEADER, false);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT_MS, $connectTimeout);
            curl_setopt($curl, CURLOPT_TIMEOUT_MS, $requestTimeout);
            curl_setopt($curl, CURLOPT_REFERER, 'https://bgm.tv/');
            curl_setopt($curl, CURLOPT_USERAGENT, $this->config->userAgent());
            curl_setopt($curl, CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use (&$content, &$overflow): int {
                $written = self::appendResponseChunk($content, $chunk);
                if ($written === 0 && $chunk !== '') {
                    $overflow = true;
                }
                return $written;
            });
            $result = curl_exec($curl);
            $httpCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($result === false || $overflow || $httpCode < 200 || $httpCode >= 300) {
                $reason = $overflow
                    ? 'response exceeded 4 MiB'
                    : ($result === false ? curl_error($curl) : 'upstream returned a non-2xx response');
                error_log('PandaBangumi API request failed: ' . $url . ' HTTP ' . $httpCode . ' ' . $reason);
                curl_close($curl);
                throw RefreshFailure::transient(
                    'PandaBangumi upstream request failed: ' . $reason,
                    $httpCode
                );
            }

            curl_close($curl);
            return $content;
        });
    }
}
