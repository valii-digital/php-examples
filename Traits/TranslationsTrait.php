<?php

namespace App\Traits;

use App\Facades\Language;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

trait TranslationsTrait
{
    public function scopeTranslated(Builder $query, $languageId = null)
    {
        [$entityTable, $translationTable, $tableKey] = $this->getTranslationKeys();
        if (!$languageId) {
            $languageId = Language::getLanguageId();
        }
        $query->join(
            $translationTable,
            $translationTable . '.' . $tableKey,
            $entityTable . '.id'
        )->where($translationTable . '.language_id', $languageId);
    }

    public static function findWithTranslation($id, $columns = ['*'])
    {

        if (is_array($id) || $id instanceof Arrayable) {
            return self::findManyWithTranslation($id, $columns);
        }

        return self::whereKey($id)->translated()->first($columns);
    }

    public static function findWithTranslationOrFail($id, $columns = ['*'])
    {

        if (is_array($id) || $id instanceof Arrayable) {
            $ret = self::findManyWithTranslation($id, $columns);
        }

        $ret = self::whereKey($id)->translated()->first($columns);
        if (!$ret) {
            abort(404);
        }

        return $ret;
    }

    public function findManyWithTranslation($ids, $columns = ['*'])
    {
        $ids = $ids instanceof Arrayable ? $ids->toArray() : $ids;

        if (empty($ids)) {
            return $this->model->newCollection();
        }

        return $this->whereKey($ids)->translated()->get($columns);
    }

    private array $dataToSaving = [];

    private bool $deleting = false;

    public function getTranslationKeys(): array
    {
        $entityTable = $this->getTable();
        $translationTable = $this->getTranslationTable();
        $tableKey = Str::singular($entityTable) . '_id';

        return [$entityTable, $translationTable, $tableKey];
    }

    public function getTranslationTable()
    {
        $obj = new $this->translationModel;

        return $obj->getTable();
    }

    public function getTranslationName(): mixed
    {
        $languageId = Language::getLanguageId();
        $transData = $this->getTranslationDataByLanguage($languageId);
        if ($transData) {
            return $transData->name;
        }

        return null;
    }

    public function getTranslationModel()
    {
        return $this->translationModel;
    }

    public function setTranslationData(): void
    {
        $languageId = Language::getLanguageId();
        $this->setTranslationDataByLanguage($languageId);
    }

    public function getTranslationDataByLanguage(int $languageId): mixed
    {
        [$entityTable, $translationTable, $tableKey] = $this->getTranslationKeys();

        return $this->translationModel::where($translationTable . '.' . $tableKey, $this->id)
            ->where($translationTable . '.language_id', $languageId)
            ->first($this->translations);
    }

    public function setTranslationDataByLanguage(int $languageId): void
    {
        $entityTranslation = $this->getTranslationDataByLanguage($languageId);
        foreach ($this->translations as $translationKey) {
            $this->setAttribute($translationKey, $entityTranslation?->$translationKey);
        }
    }

    /**
     * set all translations as array slug => value.
     */
    public function setAllTranslationData(): void
    {
        [$entityTable, $translationTable, $tableKey] = $this->getTranslationKeys();
        $keysArray = [];
        foreach ($this->translations as $translation) {
            $keysArray[] = $translationTable . '.' . $translation;
        }
        $keysArray[] = 'languages.slug as language_slug';
        $entityTranslations = $this->translationModel::join(
            'languages',
            $translationTable . '.language_id',
            'languages.id'
        )
            ->where($translationTable . '.' . $tableKey, $this->id)
            ->get($keysArray)->keyBy('language_slug');
        foreach ($entityTranslations as $slug => $entityTranslation) {
            foreach ($this->translations as $translationKey) {
                if (!$this->$translationKey) {
                    $value = [];
                } else {
                    $value = $this->$translationKey;
                }

                $value[$slug] = $entityTranslation->$translationKey;

                $this->setAttribute($translationKey, $value);
            }
        }
    }

    //    public function prepareModelToSave(): void
    //    {
    //        if (empty($this->translations)) {
    //            return;
    //        }
    //        foreach ($this->translations as $translationKey) {
    //            $this->dataToSaving[$translationKey] = $this->$translationKey;
    //            unset($this->$translationKey);
    //        }
    //    }

    public function saveTranslations($dataToSaving): void
    {
        $languages = Language::getAllLanguages();
        foreach ($languages as $slug => $language) {
            $languageData = [];
            foreach ($this->translations as $translationKey) {
                if (array_key_exists($translationKey, $dataToSaving)) {

                    Log::info('data to saving  ' . $translationKey, [
                        'language' => $slug,
                        'key' => $translationKey,
                        'full' => $dataToSaving[$translationKey],
                        'value' => $dataToSaving[$translationKey][$slug]
                    ]);

                    $languageData[$translationKey] = $dataToSaving[$translationKey][$slug];
                }
            }
            $this->saveTranslationForLanguage($language->id, $languageData);
        }
    }

    public function saveTranslationForLanguage(int $languageId, array $dataToSaving)
    {
        [$entityTable, $translationTable, $tableKey] = $this->getTranslationKeys();
        $translation = $this->translationModel::where($translationTable . '.' . $tableKey, $this->id)
            ->where('language_id', $languageId)->first();
        if (!$translation) {
            $translation = new $this->translationModel(['language_id' => $languageId, $tableKey => $this->id]);
        }

        Log::info(' saveTranslationForLanguage  ' . $languageId, $dataToSaving);

        foreach ($this->translations as $translationKey) {
            if (array_key_exists($translationKey, $dataToSaving)) {
                if (!isset($dataToSaving[$translationKey]) || !$dataToSaving[$translationKey]) {
                    $dataToSaving[$translationKey] = '';
                }
                Log::info($translationTable . ' setting  ' . $translationKey, [
                    'language_id' => $languageId,
                    'key' => $translationKey,
                    'value' => $dataToSaving[$translationKey]
                ]);
                $translation->setAttribute($translationKey, $dataToSaving[$translationKey]);
            }
        }
        $translation->save();
    }

    public function isTranslationField($key): bool
    {
        return in_array($key, $this->translations);
    }

    public function getTranslationsFields(): array
    {
        return $this->translations;
    }
}
