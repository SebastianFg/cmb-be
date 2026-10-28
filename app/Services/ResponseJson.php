<?php

namespace App\Services;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\JsonResponse;

class ResponseJson
{
    public static function success($message, $data, $statusCode = StatusCode::HTTP_OK, array $additionalData = []): JsonResponse
    {
        $response = [
            'message' => $message,
            'statusCode' => $statusCode,
            'data' => $data,
        ];

        if (
            $data instanceof LengthAwarePaginator ||
            ($data instanceof AnonymousResourceCollection && $data->resource instanceof LengthAwarePaginator)
        ) {
            $response['pagination'] = [
                'total' => $data->total(),
                'per_page' => $data->perPage(),
                'current_page' => $data->currentPage(),
                'last_page' => $data->lastPage(),
                'from' => $data->firstItem() ?? 1,
                'to' => $data->lastItem() ?? 1,
            ];

            $response['data'] = $data->items();
        }

        $response = [...$response, ...$additionalData];

        return response()->json($response, $statusCode);
    }

    public static function successAuth($message, $token, $data): JsonResponse
    {
        $response = [
            'message' => $message,
            'statusCode' => StatusCode::HTTP_OK,
            'access_token' => $token,
            'data' => $data,
        ];

        return response()->json($response, StatusCode::HTTP_OK);
    }

    public static function unauthenticate($message = null, $data = null)
    {
        return response()->json([
            'message' => $message,
            'statusCode' => StatusCode::HTTP_UNAUTHORIZED,
            'data' => $data,
        ], StatusCode::HTTP_UNAUTHORIZED);
    }

    public static function unauthorized($message = null, $data = null)
    {
        return response()->json([
            'message' => $message,
            'statusCode' => StatusCode::HTTP_UNAUTHORIZED,
            'data' => $data,
        ], StatusCode::HTTP_UNAUTHORIZED);
    }

    public static function not_found($message = null, $data = null)
    {
        return response()->json([
            'message' => $message,
            'statusCode' => StatusCode::HTTP_NOT_FOUND,
            'data' => $data,
        ], StatusCode::HTTP_NOT_FOUND);
    }

    public static function not_content($message = null, $data = null)
    {
        return response()->json([
            'message' => $message,
            'statusCode' => StatusCode::HTTP_NO_CONTENT,
            'data' => $data,
        ], StatusCode::HTTP_NO_CONTENT);
    }

    public static function unprocessable($message = null, $data = null)
    {
        return response()->json([
            'message' => $message,
            'statusCode' => StatusCode::HTTP_UNPROCESSABLE_ENTITY,
            'data' => $data,
        ], StatusCode::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function error($message, $statusCode, $data = null)
    {
        return response()->json([
            'message' => $message,
            'statusCode' => $statusCode,
            'data' => $data,
        ], $statusCode);
    }
}
