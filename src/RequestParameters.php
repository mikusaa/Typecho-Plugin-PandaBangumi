<?php

namespace TypechoPlugin\PandaBangumi;

final class RequestParameters
{
    public function __construct(
        private array $collectionSubjectTypes,
        private array $collectionListTypes
    ) {
    }

    public function category(array $query): string
    {
        $category = strtolower((string)($query['cate'] ?? 'anime'));
        return array_key_exists($category, $this->collectionSubjectTypes) ? $category : '';
    }

    public function collectionList(array $query): string
    {
        $category = $this->category($query);
        $type = strtolower((string)($query['type'] ?? ''));
        return $category !== '' && in_array($type, $this->collectionListTypes[$category] ?? array(), true)
            ? $type
            : '';
    }

    public function calendarFilter(array $query): string
    {
        return strtolower((string)($query['filter'] ?? 'watching')) === 'watching'
            ? 'watching'
            : 'all';
    }
}
