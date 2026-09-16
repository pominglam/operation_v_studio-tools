<?php

declare(strict_types=1);

namespace App\Support\Customers;

final class CustomerIdentityUnion
{
    /** @var array<string, string> */
    private array $parent = [];

    public function add(string $key): void
    {
        $this->parent[$key] ??= $key;
    }

    public function union(string $left, string $right): void
    {
        $rootLeft = $this->find($left);
        $rootRight = $this->find($right);
        if ($rootLeft !== $rootRight) {
            $this->parent[$rootRight] = $rootLeft;
        }
    }

    public function find(string $key): string
    {
        $this->add($key);
        if ($this->parent[$key] !== $key) {
            $this->parent[$key] = $this->find($this->parent[$key]);
        }

        return $this->parent[$key];
    }

    /**
     * @param  list<string>  $keys
     */
    public function unionAll(array $keys): void
    {
        $first = $keys[0] ?? null;
        if ($first === null) {
            return;
        }

        $this->add($first);
        foreach (array_slice($keys, 1) as $key) {
            $this->union($first, $key);
        }
    }
}
