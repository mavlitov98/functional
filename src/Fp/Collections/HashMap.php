<?php

declare(strict_types=1);

namespace Fp\Collections;

use ArrayIterator;
use Fp\Functional\Option\Option;
use Generator;

/**
 * @template TK of (object|scalar)
 * @template-covariant TV
 * @psalm-immutable
 * @implements Map<TK, TV>
 * @psalm-type hash = string
 */
final class HashMap implements Map
{
    /**
     * @var array<hash, Seq<array{TK, TV}>>
     */
    private array $hashTable = [];

    /**
     * @param iterable<array{TK, TV}> $source
     */
    private function __construct(iterable $source)
    {
        foreach ($source as $pair) {

            $hash = (string) $this->computeKeyHash($pair[0]);

            if (!isset($this->hashTable[$hash])) {
                $this->hashTable[$hash] = Nil::getInstance();
            }

            $this->hashTable[$hash] = $this->hashTable[$hash]
                ->filter(fn(array $p) => !$this->keyEquals($pair[0], $p[0]))
                ->prepended($pair);
        }
    }

    /**
     * @param TK $key
     * @return Option<TV>
     */
    public function __invoke(mixed $key): Option
    {
        return $this->get($key);
    }

    /**
     * @psalm-pure
     * @template TKI of (object|scalar)
     * @template TVI
     * @param iterable<array{TKI, TVI}> $source
     * @return self<TKI, TVI>
     */
    public static function collect(iterable $source): self
    {
        return new self($source);
    }

    /**
     * @psalm-pure
     * @template TKI of array-key
     * @template TVI
     * @param iterable<TKI, TVI> $source
     * @return self<TKI, TVI>
     */
    public static function collectIterable(iterable $source): self
    {
        $pairSource = function() use ($source): Generator {
            foreach ($source as $idx => $elem) {
                yield [$idx, $elem];
            }
        };

        /** @psalm-suppress ImpureFunctionCall */
        return self::collect($pairSource());
    }

    /**
     * @return ArrayIterator<int, array{TK, TV}>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->toArray());
    }

    /**
     * @return list<array{TK, TV}>
     */
    public function toArray(): array
    {
        $buffer = [];

        foreach ($this->generatePairs() as $pair) {
            $buffer[] = $pair;
        }

        return $buffer;
    }

    /**
     * @param TK $key
     * @return Option<TV>
     */
    public function get(mixed $key): Option
    {
        return $this->findBucketByKey($key)
            ->flatMap(fn(Seq $bucket) => $bucket->first(fn($pair) => $this->keyEquals($pair[0], $key)))
            ->map(fn($pair) => $pair[1]);
    }

    /**
     * @template TKI of (object|scalar)
     * @template TVI
     * @param TKI $key
     * @param TVI $value
     * @return self<TK|TKI, TV|TVI>
     */
    public function updated(mixed $key, mixed $value): self
    {
        return self::collect([...$this->toArray(), [$key, $value]]);
    }

    /**
     * @param TK $key
     * @return self<TK, TV>
     */
    public function removed(mixed $key): self
    {
        return $this->filter(fn($v, $k) => $k !== $key);
    }

    /**
     * Filter collection by condition
     *
     * @psalm-param callable(TV, TK): bool $predicate
     * @psalm-return self<TK, TV>
     */
    public function filter(callable $predicate): self
    {
        $source = function () use ($predicate):Generator {
            foreach ($this->generatePairs() as $pair) {
                if ($predicate($pair[1], $pair[0])) {
                    yield $pair;
                }
            }
        };

        return self::collect($source());
    }

    /**
     * @param object|scalar $lhs
     * @param object|scalar $rhs
     * @psalm-suppress ImpureMethodCall
     */
    private function keyEquals(mixed $lhs, mixed $rhs): bool
    {
        return $lhs instanceof HashContract && $rhs instanceof HashContract
            ? $lhs->equals($rhs)
            : $this->keyHashEquals($lhs, $rhs);
    }

    /**
     * @param object|scalar $lhs
     * @param object|scalar $rhs
     */
    private function keyHashEquals(mixed $lhs, mixed $rhs): bool
    {
        return $this->computeKeyHash($lhs) === $this->computeKeyHash($rhs);
    }

    /**
     * @param object|scalar $key
     * @return string|int|float|bool
     * @psalm-suppress ImpureMethodCall
     */
    private function computeKeyHash(object|string|int|float|bool $key): string|int|float|bool
    {
        return match (true) {
            $key instanceof HashContract => $key->hashCode(),
            is_object($key) => spl_object_hash($key),
            default => $key,
        };
    }

    /**
     * @param TK $key
     * @return Option<Seq<array{TK, TV}>>
     */
    private function findBucketByKey(mixed $key): Option
    {
        $hash = (string) $this->computeKeyHash($key);
        return Option::fromNullable($this->hashTable[$hash] ?? null);
    }

    /**
     * @return Generator<array{TK, TV}>
     */
    private function generatePairs(): Generator
    {
        foreach ($this->hashTable as $bucket) {
            foreach ($bucket as $pair) {
                yield $pair;
            }
        }
    }
}