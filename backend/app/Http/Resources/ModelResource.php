<?php

namespace App\Http\Resources;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Generic output: every attribute except user_id; money stays a string, dates are Y-m-d. */
class ModelResource extends JsonResource
{
    private const TIMESTAMPS = ['created_at', 'updated_at', 'deleted_at'];

    public function toArray(Request $request): array
    {
        $model = $this->resource;
        $out = [];

        foreach (array_keys($model->getAttributes()) as $key) {
            if ($key === 'user_id') {
                continue;
            }
            $value = $model->getAttribute($key);
            if ($value instanceof DateTimeInterface) {
                $value = in_array($key, self::TIMESTAMPS, true) ? $value->format('c') : $value->format('Y-m-d');
            }
            $out[$key] = $value;
        }

        return $out;
    }
}