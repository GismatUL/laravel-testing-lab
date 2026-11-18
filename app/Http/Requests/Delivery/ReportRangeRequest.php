<?php

namespace App\Http\Requests\Delivery;

use Illuminate\Foundation\Http\FormRequest;

class ReportRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from'  => ['sometimes', 'date'],
            'to'    => ['sometimes', 'date', 'after:from'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Half-open range [from, to): "to" is exclusive, which avoids the classic
     * BETWEEN '...' AND '... 23:59:59' bug that loses the last second's rows.
     *
     * @param  string  $defaultLookback  e.g. '30 days', '12 months'
     * @return array{0: string, 1: string}
     */
    public function range(string $defaultLookback): array
    {
        return [
            $this->input('from', now()->sub($defaultLookback)->startOfDay()->toDateTimeString()),
            $this->input('to', now()->addDay()->startOfDay()->toDateTimeString()),
        ];
    }
}
