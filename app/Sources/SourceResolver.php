<?php

namespace App\Sources;

use App\Novel;

class SourceResolver
{
    /**
     * Ordered list of sources. The first whose matches() returns true wins;
     * NovelBinSource matches everything, so it's the default and must be last.
     */
    protected static array $sources = [
        EmpireNovelSource::class,
        NovelFullSource::class,
        NovelBinSource::class,
    ];

    public static function for(Novel $novel): Source
    {
        foreach (static::$sources as $class) {
            $source = new $class();
            if ($source->matches($novel)) {
                return $source;
            }
        }

        return new NovelBinSource();
    }
}
