<?php

namespace TypechoPlugin\PandaBangumi;

final class RequestParameters
{
    public function __construct(private array $collectionSubjectTypes)
    {
    }

    public function category(array $query): string
    {
        $category = strtolower((string)($query['cate'] ?? 'anime'));
        return array_key_exists($category, $this->collectionSubjectTypes) ? $category : '';
    }

    public function calendarFilter(array $query): string
    {
        return strtolower((string)($query['filter'] ?? 'watching')) === 'watching'
            ? 'watching'
            : 'all';
    }
}
