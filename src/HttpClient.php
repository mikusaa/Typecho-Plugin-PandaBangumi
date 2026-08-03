<?php

namespace TypechoPlugin\PandaBangumi;

require_once __DIR__ . '/HttpTransport.php';

final class HttpClient implements HttpTransport
{
    public function __construct(private PluginConfig $config)
    {
    }

    public function get(string $url): bool|string
    {
        $curl = curl_init($url);
        if ($curl === false) {
            return false;
        }

        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curl, CURLOPT_TIMEOUT, 12);
        curl_setopt($curl, CURLOPT_REFERER, 'https://bgm.tv/');
        curl_setopt($curl, CURLOPT_USERAGENT, $this->config->userAgent());
        $content = curl_exec($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($content === false || $httpCode < 200 || $httpCode >= 300) {
            error_log('PandaBangumi API request failed: ' . $url . ' HTTP ' . $httpCode . ' ' . curl_error($curl));
            curl_close($curl);
            return false;
        }

        curl_close($curl);
        return $content;
    }
}
