<?php

declare(strict_types=1);

namespace App\Support\Customers;

final class ShopifyRfmQuintileScorer
{
    /**
     * Higher value → higher score (5 = best fifth of this store).
     * Tied values share the score of the best rank in the tied group.
     *
     * @param  list<int|float|string>  $values
     * @return list<int>
     */
    public static function scoresDescending(array $values): array
    {
        $count = count($values);
        if ($count === 0) {
            return [];
        }

        $indexed = [];
        foreach ($values as $index => $value) {
            $indexed[] = ['i' => $index, 'v' => $value];
        }

        usort($indexed, static function (array $left, array $right): int {
            return self::compare($right['v'], $left['v']);
        });

        $scores = array_fill(0, $count, 1);
        $rank = 0;
        while ($rank < $count) {
            $value = $indexed[$rank]['v'];
            $end = $rank;
            while ($end + 1 < $count && self::compare($indexed[$end + 1]['v'], $value) === 0) {
                $end++;
            }
            $score = 5 - min(4, intdiv($rank * 5, $count));
            for ($cursor = $rank; $cursor <= $end; $cursor++) {
                $scores[$indexed[$cursor]['i']] = $score;
            }
            $rank = $end + 1;
        }

        return $scores;
    }

    private static function compare(int|float|string $left, int|float|string $right): int
    {
        if (is_string($left) && is_string($right) && is_numeric($left) && is_numeric($right)) {
            return ShopMoney::compare($left, $right);
        }

        return $left <=> $right;
    }
}
