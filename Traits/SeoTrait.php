<?php

namespace App\Traits;

use App\Facades\SeoService;

trait SeoTrait
{
    public static function bootSeoTrait(): void
    {
        static::saved(function ($model) {
            $model->updateSeoData();
        });
    }

    public function updateSeoData(): void
    {
        SeoService::prepare(self::class, $this, true);
    }
}
