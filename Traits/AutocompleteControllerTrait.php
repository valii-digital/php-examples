<?php

namespace App\Traits;

use App\Facades\Language;
use App\Http\Resources\ApiJsonResource;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

trait AutocompleteControllerTrait
{
    protected $autocompleteTranslations = 1;

    protected $autocompleteLimit = 10;

    protected $autocompleteCache = 1;

    public function autocomplete(Request $request): JsonResponse
    {
        $search = $request->get('q', '');
        if (Str::length($search) >= 2) {
            $cacheKey = 'autocomplete_'.Str::slug(self::class).'_'.$search;
            if (Cache::has($cacheKey)) {
                $response = Cache::get($cacheKey);
            } else {
                [$entityTable, $translationTable, $tableKey] = $this->model->getTranslationKeys();
                $query = $this->model->query();
                if ($this->autocompleteTranslations) {
                    $result = $query->join(
                        $translationTable,
                        $translationTable.'.'.$tableKey,
                        '=',
                        $entityTable.'.id'
                    )
                        ->where($translationTable.'.language_id', Language::getLanguageId())
                        ->where($translationTable.'.name', 'LIKE', $search.'%')
                        ->offset(0)
                        ->limit($this->autocompleteLimit)
                        ->get(['id' => $entityTable.'.id', $translationTable.'.value' => 'name']);
                } else {
                    $result = $query->where('name', 'LIKE', $search.'%')->offset(0)->limit($this->autocompleteLimit)
                        ->get(['id', 'name']);
                }
                $response = response()->json([
                    'data' => ApiJsonResource::collection($result),
                    'success' => true,
                ]
                );
                Cache::put($cacheKey, $response, Carbon::now()->addMinutes($this->autocompleteCache));
            }

            return $response;
        }

        return response()->json([
            'success' => false,
        ]
        );
    }
}
