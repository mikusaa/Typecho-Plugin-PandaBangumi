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
        $value = $query['cate'] ?? 'anime';
        if (!is_scalar($value)) {
            return '';
        }
        $category = strtolower((string)$value);
        return array_key_exists($category, $this->collectionSubjectTypes) ? $category : '';
    }

    public function collectionList(array $query): string
    {
        $category = $this->category($query);
        $value = $query['type'] ?? '';
        if (!is_scalar($value)) {
            return '';
        }
        $type = strtolower((string)$value);
        return $category !== '' && in_array($type, $this->collectionListTypes[$category] ?? array(), true)
            ? $type
            : '';
    }

    public function calendarFilter(array $query): string
    {
        $value = $query['filter'] ?? 'watching';
        if (!is_scalar($value)) {
            return '';
        }
        return strtolower((string)$value) === 'watching'
            ? 'watching'
            : 'all';
    }
}
