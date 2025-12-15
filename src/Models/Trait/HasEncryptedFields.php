<?php

namespace PalakRajput\DataEncryption\Models\Trait;

use Illuminate\Database\Eloquent\Builder;
use PalakRajput\DataEncryption\Services\EncryptionService;
use PalakRajput\DataEncryption\Services\HashService;
use PalakRajput\DataEncryption\Services\MeilisearchService;

trait HasEncryptedFields
{
    protected static function bootHasEncryptedFields()
    {
        static::saving(function ($model) {
            $model->encryptFields();
        });

        static::retrieved(function ($model) {
            $model->decryptFields();
        });

        static::created(function ($model) {
            $model->indexToMeilisearch();
        });

        static::updated(function ($model) {
            $model->indexToMeilisearch();
        });

        static::deleted(function ($model) {
            $model->removeFromMeilisearch();
        });
    }

    public function encryptFields()
    {
        $encryptionService = app(EncryptionService::class);
        $hashService = app(HashService::class);

        foreach (static::$encryptedFields ?? [] as $field) {
            if (empty($this->attributes[$field])) continue;

            // Encrypt
            if (!$this->isEncrypted($this->attributes[$field])) {
                $this->attributes[$field] = $encryptionService->encrypt($this->attributes[$field]);
            }

            // Hash for search
            if (in_array($field, static::$searchableHashFields ?? [])) {
                $hashField = $field . '_hash';
                $this->attributes[$hashField] = $hashService->hash($this->getOriginal($field) ?? $this->attributes[$field]);
            }
        }
    }

    public function decryptFields()
    {
        $encryptionService = app(EncryptionService::class);

        foreach (static::$encryptedFields ?? [] as $field) {
            if (!empty($this->attributes[$field]) && $this->isEncrypted($this->attributes[$field])) {
                $this->attributes[$field] = $encryptionService->decrypt($this->attributes[$field]);
            }
        }
    }

    protected function isEncrypted($value): bool
    {
        if (!is_string($value)) return false;
        try {
            $decoded = base64_decode($value, true);
            $data = json_decode($decoded, true);
            return isset($data['iv'], $data['value'], $data['mac']);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function scopeSearchByHash(Builder $query, string $field, string $value)
    {
        $hashService = app(HashService::class);
        return $query->where($field . '_hash', $hashService->hash($value));
    }

    public function scopeSearchInMeilisearch(Builder $query, string $searchTerm, array $fields = [])
    {
        $meilisearch = app(MeilisearchService::class);
        $indexName = $this->getMeilisearchIndexName();

        $results = $meilisearch->search($indexName, $searchTerm, $fields);

        if (empty($results)) return $query->whereRaw('1 = 0');

        $ids = collect($results)->pluck('id')->toArray();
        return $query->whereIn($this->getQualifiedKeyName(), $ids);
    }

    public static function searchEncrypted(string $query)
    {
        $model = new static();

        // Meilisearch first (optional)
        $builder = $model->newQuery();
        $ids = $model->scopeSearchInMeilisearch(
            $builder,
            $query,
            array_map(fn($f) => $f.'_hash', $model::$searchableHashFields ?? [])
        )->pluck($model->getKeyName())->toArray();

        if (!empty($ids)) return static::whereIn($model->getKeyName(), $ids);

        // Fallback to DB hash search
        $builder = $model->newQuery();
        $hashService = app(HashService::class);
        foreach ($model::$searchableHashFields ?? [] as $field) {
            $builder->orWhere($field.'_hash', $hashService->hash($query));
        }

        return $builder;
    }
}
