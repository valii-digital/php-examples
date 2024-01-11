<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait SlugTrait
{
    public function updateSlug(): void
    {
        if (! $this->slug) {
            $name = null;
            if (
                method_exists($this, 'hasTranslations')
                && $this->hasTranslations()
                && $this->isTranslationField('name')
            ) {
                $name = $this->getTranslationName();
            } elseif (isset($this->name)) {
                $name = $this->name;
            }

            if (! $name) {
                $name = Str::random(16);
            }
            $this->slug = Str::slug($name);
            $this->save();
        }
    }
}
