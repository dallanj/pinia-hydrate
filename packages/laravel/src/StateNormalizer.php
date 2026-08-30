<?php

namespace Dallanj\PiniaHydrate;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use JsonSerializable;
use RuntimeException;

/** Converts Laravel response values into JSON-compatible Pinia state. */
final class StateNormalizer
{
    /**
     * Normalize a complete module state object.
     *
     * @return array<string|int, mixed>
     *
     * @throws RuntimeException when the value cannot represent store state
     */
    public function state(mixed $value): array
    {
        $value = $this->value($value);

        if (! is_array($value)) {
            throw new RuntimeException('Hydrated store state must normalize to an array.');
        }

        return $value;
    }

    /**
     * Recursively normalize resources, paginators, Arrayable objects, and
     * JsonSerializable values while preserving scalar values.
     */
    public function value(mixed $value): mixed
    {
        if ($value instanceof ResourceCollection) {
            $items = $value->resolve(request());
            $paginator = $value->resource;

            if ($paginator instanceof LengthAwarePaginator || $paginator instanceof Paginator) {
                $value = [
                    'data' => $items,
                    'current_page' => $paginator->currentPage(),
                    'last_page' => method_exists($paginator, 'lastPage') ? $paginator->lastPage() : null,
                    'per_page' => $paginator->perPage(),
                    'total' => method_exists($paginator, 'total') ? $paginator->total() : null,
                ];
            } else {
                $value = $items;
            }
        } elseif ($value instanceof JsonResource) {
            $value = $value->resolve(request());
        } elseif ($value instanceof Arrayable) {
            $value = $value->toArray();
        } elseif ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->value($item), $value);
        }

        return $value;
    }
}
