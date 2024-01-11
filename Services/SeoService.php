<?php


namespace App\Services;


use App\Models\SeoSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SeoService
{
    public function prepare(string $class, &$entity, $force = false): void
    {
        $seo = SeoSetting::where('class', $class)->first();
        $languages = \App\Facades\Language::getAllLanguages();
        if (!$seo) {
            $seo = new SeoSetting([
                'class' => $class
            ]);
            $seo->save();
            $defaultTitle = [];
            foreach ($languages as $language) {
                $defaultTitle[$language->slug] = "{name}";
            }
            $seo->saveTranslations([
                'seo_title' => $defaultTitle
            ]);
        }
        $seo->setAllTranslationData();
        $entity->setAllTranslationData();
        if (is_array($entity->seo_title) && $seo) {
            $seoTitle = $entity->seo_title;
            foreach ($languages as $language) {
                try {
                    if (!isset($entity->name[$language->slug]) || !$entity->name[$language->slug]) {
                        return;
                    }
                    if (!isset($seo->seo_title[$language->slug])) {
                        continue;
                    }

                    if (!isset($seoTitle[$language->slug])) {
                        $seoTitle[$language->slug] = '';
                    }
                    if (!$seoTitle[$language->slug] || $force) {
                        $seoTitle[$language->slug] = $this->replace(
                            $entity,
                            $seo->seo_title[$language->slug],
                            $language->slug);
                    }
                } catch (\Exception $e) {
                    Log::warning('seo service error', [
                        'message' => $e->getMessage(),
                        'trace' => $e->getTrace()
                    ]);
                }

            }
            $entity->saveTranslations(['seo_title' => $seoTitle]);
        }
    }

    private function replace($entity, $seoSetting, $slug)
    {
        return Str::replace(
            [
                '{name}',
                '{created_at}'
            ],
            [
                    $entity->name[$slug] ?? '',
                $entity->created_at ? Carbon::make($entity->created_at)->format('d.m.Y') : '-'
            ],
            $seoSetting
        );
    }
}
