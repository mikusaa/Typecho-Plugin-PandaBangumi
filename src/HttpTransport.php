<?php

namespace TypechoPlugin\PandaBangumi;

interface HttpTransport
{
    public function get(string $url): bool|string;
}
