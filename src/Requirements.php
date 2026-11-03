<?php

namespace Omaresmael\Thread;

use Omaresmael\Thread\Exceptions\ThreadUnavailableException;

final class Requirements
{
    /**
     * Returns a human-readable entry for each unmet requirement. Nulls mean "detect".
     *
     * @return array<int, string>
     */
    public static function missing(?bool $zts = null, ?bool $parallel = null): array
    {
        $zts ??= (bool) PHP_ZTS;
        $parallel ??= extension_loaded('parallel');

        $missing = [];

        if (! $zts) {
            $missing[] = 'PHP is not thread-safe (ZTS)';
        }

        if (! $parallel) {
            $missing[] = 'ext-parallel is not loaded';
        }

        return $missing;
    }

    /**
     * @throws ThreadUnavailableException
     */
    public static function ensure(): void
    {
        $missing = self::missing();

        if ($missing !== []) {
            throw ThreadUnavailableException::missing($missing);
        }
    }
}
