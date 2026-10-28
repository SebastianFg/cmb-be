<?php

namespace App\Services;

use Illuminate\Foundation\Http\FormRequest;

abstract class BaseService
{
    protected function formatRequest(
        FormRequest $request,
        int $page_size = 15,
        string $sort_by = 'id',
        string $order_by = 'desc',
    ): FormRequest {
        $request->mergeIfMissing([
            'page_size' => $page_size,
            'sort_by' => $sort_by,
            'order_by' => $order_by,
            'is_select' => false,
        ]);

        return $request;
    }
}
