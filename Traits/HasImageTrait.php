<?php

namespace App\Traits;

trait HasImageTrait
{
    public function getImager()
    {
        return $this->imager ?? [
            'list' => [
                'width' => 500,
                'height' => false,
            ],
            'single' => [
                'width' => 900,
                'height' => false,
            ],
        ];
    }

    public function dontResizeImage($key)
    {
        if (isset($this->dontResizeImages) && in_array($key, $this->dontResizeImages)) {
            return true;
        }

        return false;
    }
}
