<?php

declare(strict_types=1);

namespace Fp\Streams;

use Error;
use Fp\Collections\ArrayList;
use Fp\Collections\HashMap;
use Fp\Collections\HashSet;
use Fp\Collections\LinkedList;
use Fp\Collections\Seq;
use Fp\Functional\Option\Option;
use Fp\Functional\Unit;
use Fp\Operations\AppendedAllOperation;
use Fp\Operations\AppendedOperation;
use Fp\Operations\ChunksOperation;
use Fp\Operations\DropOperation;
use Fp\Operations\DropWhileOperation;
use Fp\Operations\EveryOfOperation;
use Fp\Operations\EveryOperation;
use Fp\Operations\ExistsOfOperation;
use Fp\Operations\ExistsOperation;
use Fp\Operations\FilterMapOperation;
use Fp\Operations\FilterNotNullOperation;
use Fp\Operations\FilterOfOperation;
use Fp\Operations\FilterOperation;
use Fp\Operations\FirstOfOperation;
use Fp\Operations\FirstOperation;
use Fp\Operations\FlatMapOperation;
use Fp\Operations\FoldOperation;
use Fp\Operations\GroupAdjacentByOperationOperation;
use Fp\Operations\HeadOperation;
use Fp\Operations\InterleaveOperation;
use Fp\Operations\IntersperseOperation;
use Fp\Operations\LastOperation;
use Fp\Operations\MapValuesOperation;
use Fp\Operations\PrependedAllOperation;
use Fp\Operations\PrependedOperation;
use Fp\Operations\ReduceOperation;
use Fp\Operations\RepeatNOperation;
use Fp\Operations\RepeatOperation;
use Fp\Operations\TailOperation;
use Fp\Operations\TakeOperation;
use Fp\Operations\TakeWhileOperation;
use Fp\Operations\TapOperation;
use Fp\Operations\ZipOperation;
use Generator;
use IteratorAggregate;
use LogicException;
use SplFileObject;

use function Fp\Callable\asGenerator;
use function Fp\Cast\asList;

/**
 * Note: stream iteration via foreach is terminal operation
 *
 * @psalm-immutable
 * @template-covariant TV
 * @implements StreamOps<TV>
 * @implements StreamEmitter<TV>
 * @implements IteratorAggregate<TV>
 */
final class Stream implements StreamOps, StreamEmitter, IteratorAggregate
{
    /**
     * @var Generator<int, TV>
     */
    private Generator $emitter;

    /**
     * @psalm-readonly-allow-private-mutation $forked
     */
    private bool $forked = false;

    /**
     * @psalm-readonly-allow-private-mutation $drained
     */
    private bool $drained = false;

    /**
     * @param iterable<TV> $emitter
     */
    private function __construct(iterable $emitter)
    {
        $gen = function() use ($emitter): Generator {
            foreach ($emitter as $elem) {
                yield $elem;
            }
        };

        $this->emitter = $gen();
    }

    /**
     * Note: You can not iterate the stream second time
     *
     * @inheritDoc
     * @return Generator<int, TV>
     */
    public function getIterator(): Generator
    {
        return $this->iter();
    }

    /**
     * @return Generator<int, TV>
     */
    public function iter(): Generator
    {
        $this->drained = !$this->drained
            ? true
            : throw new Error('Can not drain already drained stream');

        return $this->emitter;
    }

    /**
     * @inheritDoc
     * @template TVI
     * @param TVI $elem
     * @return self<TVI>
     */
    public static function emit(mixed $elem): self
    {
        return self::emits(asGenerator(function () use ($elem) {
            yield $elem;
        }));
    }

    /**
     * @inheritDoc
     * @template TVI
     * @param iterable<TVI> $source
     * @return self<TVI>
     */
    public static function emits(iterable $source): self
    {
        return new self($source);
    }

    /**
     * @inheritDoc
     * @return self<int>
     */
    public static function awakeEvery(int $seconds): self
    {
        return self::emits(asGenerator(function () use ($seconds) {
            $elapsed = 0;
            $prevTime = time();

            while (true) {
                sleep($seconds);

                $curTime = time();
                $elapsed += $curTime - $prevTime;
                $prevTime = $curTime;

                yield $elapsed;
            }
        }));
    }

    /**
     * @inheritDoc
     * @template TVI
     * @param TVI $const
     * @return self<TVI>
     */
    public static function constant(mixed $const): self
    {
        return self::emits(asGenerator(function () use ($const) {
            while (true) {
                yield $const;
            }
        }));
    }

    /**
     * @inheritDoc
     * @return Stream<Unit>
     */
    public static function infinite(): Stream
    {
        return self::constant(Unit::getInstance());
    }

    /**
     * @inheritDoc
     * @param 0|positive-int $start
     * @param 0|positive-int $stopExclusive
     * @param positive-int $by
     * @return self<int>
     */
    public static function range(int $start, int $stopExclusive, int $by = 1): self
    {
        return self::emits(asGenerator(function () use ($start, $stopExclusive, $by) {
            for ($i = $start; $i < $stopExclusive; $i += $by) {
                yield $i;
            }
        }));
    }

    /**
     * @psalm-template TKO
     * @psalm-template TVO
     * @psalm-param Generator<TVO> $gen
     * @psalm-return self<TVO>
     */
    private function fork(Generator $gen): self
    {
        $this->forked = !$this->forked
            ? $this->forked = true
            : throw new LogicException('multiple stream forks detected');

        return self::emits($gen);
    }

    /**
     * @template TVO
     * @psalm-param callable(TV): TVO $callback
     * @psalm-return self<TVO>
     */
    public function map(callable $callback): self
    {
        return $this->fork(MapValuesOperation::of($this->iter())($callback));
    }

    /**
     * @inheritDoc
     * @template TVI
     * @psalm-param TVI $elem
     * @psalm-return self<TV|TVI>
     */
    public function appended(mixed $elem): self
    {
        return $this->fork(AppendedOperation::of($this->iter())($elem));
    }

    /**
     * @inheritDoc
     * @template TVI
     * @psalm-param iterable<TVI> $suffix
     * @psalm-return self<TV|TVI>
     */
    public function appendedAll(iterable $suffix): self
    {
        return $this->fork(AppendedAllOperation::of($this->iter())($suffix));
    }

    /**
     * @inheritDoc
     * @template TVI
     * @psalm-param TVI $elem
     * @psalm-return self<TV|TVI>
     */
    public function prepended(mixed $elem): self
    {
        return $this->fork(PrependedOperation::of($this->iter())($elem));
    }

    /**
     * @inheritDoc
     * @template TVI
     * @psalm-param iterable<TVI> $prefix
     * @psalm-return self<TV|TVI>
     */
    public function prependedAll(iterable $prefix): self
    {
        return $this->fork(PrependedAllOperation::of($this->iter())($prefix));
    }

    /**
     * @inheritDoc
     * @psalm-param callable(TV): bool $predicate
     * @psalm-return self<TV>
     */
    public function filter(callable $predicate): self
    {
        return $this->fork(FilterOperation::of($this->iter())($predicate));
    }

    /**
     * @inheritDoc
     * @psalm-template TVO
     * @psalm-param callable(TV): Option<TVO> $callback
     * @psalm-return self<TVO>
     */
    public function filterMap(callable $callback): self
    {
        return $this->fork(FilterMapOperation::of($this->iter())($callback));
    }

    /**
     * @inheritDoc
     * @psalm-return self<TV>
     */
    public function filterNotNull(): self
    {
        return $this->fork(FilterNotNullOperation::of($this->iter())());
    }

    /**
     * @inheritDoc
     * @psalm-template TVO
     * @psalm-param class-string<TVO> $fqcn fully qualified class name
     * @psalm-param bool $invariant if turned on then subclasses are not allowed
     * @psalm-return self<TVO>
     */
    public function filterOf(string $fqcn, bool $invariant = false): self
    {
        return $this->fork(FilterOfOperation::of($this->iter())($fqcn, $invariant));
    }

    /**
     * @inheritDoc
     * @psalm-template TVO
     * @psalm-param callable(TV): iterable<TVO> $callback
     * @psalm-return self<TVO>
     */
    public function flatMap(callable $callback): self
    {
        return $this->fork(FlatMapOperation::of($this->iter())($callback));
    }

    /**
     * @inheritDoc
     * @psalm-return self<TV>
     */
    public function tail(): self
    {
        return $this->fork(TailOperation::of($this->iter())());
    }

    /**
     * @inheritDoc
     * @psalm-param callable(TV): bool $predicate
     * @psalm-return self<TV>
     */
    public function takeWhile(callable $predicate): self
    {
        return $this->fork(TakeWhileOperation::of($this->iter())($predicate));
    }

    /**
     * @inheritDoc
     * @psalm-param callable(TV): bool $predicate
     * @psalm-return self<TV>
     */
    public function dropWhile(callable $predicate): self
    {
        return $this->fork(DropWhileOperation::of($this->iter())($predicate));
    }

    /**
     * @inheritDoc
     * @psalm-return self<TV>
     */
    public function take(int $length): self
    {
        return $this->fork(TakeOperation::of($this->iter())($length));
    }

    /**
     * @inheritDoc
     * @psalm-return self<TV>
     */
    public function drop(int $length): self
    {
        return $this->fork(DropOperation::of($this->iter())($length));
    }

    /**
     * @inheritDoc
     * @param callable(TV): void $callback
     * @psalm-return self<TV>
     */
    public function tap(callable $callback): self
    {
        return $this->fork(TapOperation::of($this->iter())($callback));
    }

    /**
     * @inheritDoc
     * @return self<TV>
     */
    public function repeat(): self
    {
        return $this->fork(RepeatOperation::of($this->iter())());
    }

    /**
     * @inheritDoc
     * @return self<TV>
     */
    public function repeatN(int $times): self
    {
        return $this->fork(RepeatNOperation::of($this->iter())($times));
    }

    /**
     * @inheritDoc
     * @template TVI
     * @param TVI $separator
     * @psalm-return self<TV|TVI>
     */
    public function intersperse(mixed $separator): self
    {
        return $this->fork(IntersperseOperation::of($this->iter())($separator));
    }

    /**
     * @inheritDoc
     * @psalm-return self<TV>
     */
    public function lines(): self
    {
        return $this->fork(TapOperation::of($this->iter())(function ($elem) {
            print_r($elem) . PHP_EOL;
        }));
    }

    /**
     * @inheritDoc
     * @template TVI
     * @param iterable<TVI> $that
     * @return self<TV|TVI>
     */
    public function interleave(iterable $that): self
    {
        return $this->fork(InterleaveOperation::of($this->iter())($that));
    }

    /**
     * @inheritDoc
     * @template TVI
     * @param iterable<TVI> $that
     * @return self<array{TV, TVI}>
     */
    public function zip(iterable $that): self
    {
        return $this->fork(ZipOperation::of($this)($that));
    }

    /**
     * @inheritDoc
     * @param positive-int $size
     * @return self<Seq<TV>>
     */
    public function chunks(int $size): self
    {
        $chunks = ChunksOperation::of($this->iter())($size);

        return $this->fork(MapValuesOperation::of($chunks)(function (array $chunk) {
            return new ArrayList($chunk);
        }));
    }

    /**
     * @inheritDoc
     * @template D
     * @param callable(TV): D $discriminator
     * @return Stream<array{D, Seq<TV>}>
     */
    public function groupAdjacentBy(callable $discriminator): Stream
    {
        $adjacent = GroupAdjacentByOperationOperation::of($this->iter())($discriminator);

        return $this->fork(MapValuesOperation::of($adjacent)(function (array $pair) {
            $pair[1] = new ArrayList($pair[1]);
            return $pair;
        }));
    }

    /**
     * @inheritDoc
     * @psalm-param callable(TV): bool $predicate
     */
    public function every(callable $predicate): bool
    {
        return EveryOperation::of($this->iter())($predicate);
    }

    /**
     * @inheritDoc
     * @psalm-template TVO
     * @psalm-param class-string<TVO> $fqcn fully qualified class name
     * @psalm-param bool $invariant if turned on then subclasses are not allowed
     */
    public function everyOf(string $fqcn, bool $invariant = false): bool
    {
        return EveryOfOperation::of($this->iter())($fqcn, $invariant);
    }

    /**
     * @inheritDoc
     * @psalm-param callable(TV): bool $predicate
     */
    public function exists(callable $predicate): bool
    {
        return ExistsOperation::of($this->iter())($predicate);
    }

    /**
     * @inheritDoc
     * @psalm-template TVO
     * @psalm-param class-string<TVO> $fqcn fully qualified class name
     * @psalm-param bool $invariant if turned on then subclasses are not allowed
     */
    public function existsOf(string $fqcn, bool $invariant = false): bool
    {
        return ExistsOfOperation::of($this->iter())($fqcn, $invariant);
    }

    /**
     * @inheritDoc
     * @psalm-param callable(TV): bool $predicate
     * @psalm-return Option<TV>
     */
    public function first(callable $predicate): Option
    {
        return FirstOperation::of($this->iter())($predicate);
    }

    /**
     * @inheritDoc
     * @psalm-template TVO
     * @psalm-param class-string<TVO> $fqcn fully qualified class name
     * @psalm-param bool $invariant if turned on then subclasses are not allowed
     * @psalm-return Option<TVO>
     */
    public function firstOf(string $fqcn, bool $invariant = false): Option
    {
        return FirstOfOperation::of($this->iter())($fqcn, $invariant);
    }

    /**
     * @inheritDoc
     * @template TA
     * @psalm-param TA $init initial accumulator value
     * @psalm-param callable(TA, TV): TA $callback (accumulator, current element): new accumulator
     * @psalm-return TA
     */
    public function fold(mixed $init, callable $callback): mixed
    {
        return FoldOperation::of($this->iter())($init, $callback);
    }

    /**
     * @inheritDoc
     * @template TA
     * @psalm-param callable(TV|TA, TV): (TV|TA) $callback
     * @psalm-return Option<TV|TA>
     */
    public function reduce(callable $callback): Option
    {
        return ReduceOperation::of($this->iter())($callback);
    }

    /**
     * @inheritDoc
     * @psalm-return Option<TV>
     */
    public function head(): Option
    {
        return HeadOperation::of($this->iter())();
    }

    /**
     * @inheritDoc
     * @psalm-param callable(TV): bool $predicate
     * @psalm-return Option<TV>
     */
    public function last(callable $predicate): Option
    {
        return LastOperation::of($this->iter())($predicate);
    }

    /**
     * @inheritDoc
     * @psalm-return Option<TV>
     */
    public function firstElement(): Option
    {
        return FirstOperation::of($this->iter())();
    }

    /**
     * @inheritDoc
     * @psalm-return Option<TV>
     */
    public function lastElement(): Option
    {
        return LastOperation::of($this->iter())();
    }

    /**
     * @inheritDoc
     */
    public function drain(): void
    {
        foreach ($this as $ignored) { }
    }

    /**
     * @inheritDoc
     * @return list<TV>
     */
    public function toArray(): array
    {
        return asList($this->iter());
    }

    /**
     * @inheritDoc
     * @return LinkedList<TV>
     */
    public function toLinkedList(): LinkedList
    {
        return LinkedList::collect($this->iter());
    }

    /**
     * @inheritDoc
     * @return ArrayList<TV>
     */
    public function toArrayList(): ArrayList
    {
        return ArrayList::collect($this->iter());
    }

    /**
     * @inheritDoc
     * @return HashSet<TV>
     */
    public function toHashSet(): HashSet
    {
        return HashSet::collect($this->iter());
    }

    /**
     * @inheritDoc
     * @template TKI
     * @template TVI
     * @param callable(TV): array{TKI, TVI} $callback
     * @return HashMap<TKI, TVI>
     */
    public function toHashMap(callable $callback): HashMap
    {
        return HashMap::collectPairs(asGenerator(function () use ($callback) {
            foreach ($this as $elem) {
                yield $callback($elem);
            }
        }));
    }

    /**
     * @inheritDoc
     */
    public function toFile(string $path, bool $append = false): void
    {
        $file = new SplFileObject($path, $append ? 'a' : 'w');

        foreach ($this as $elem) {
            /** @psalm-suppress ImpureMethodCall */
            $file->fwrite((string) $elem);
        }

        $file = null;
    }
}
