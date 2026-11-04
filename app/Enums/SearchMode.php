<?php

namespace App\Enums;

enum SearchMode: string
{
    case Keyword = 'keyword';
    case Semantic = 'semantic';
    case Hybrid = 'hybrid';

    public function usesVector(): bool
    {
        return $this !== self::Keyword;
    }

    public function usesKeywords(): bool
    {
        return $this !== self::Semantic;
    }

    /**
     * Use the fusion of both rankings unless the request names a mode.
     */
    public static function default(): self
    {
        return self::Hybrid;
    }
}
