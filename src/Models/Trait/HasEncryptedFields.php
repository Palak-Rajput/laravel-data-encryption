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

    // 1️⃣ Try Meilisearch for PARTIAL search
    if (config('data-encryption.meilisearch.enabled', false)) {
        $ids = app(MeilisearchService::class)->search(
            $model->getMeilisearchIndexName(),
            $query,
            array_map(fn ($f) => $f . '_hash', static::$searchableHashFields ?? [])
        );

        if (!empty($ids)) {
            return static::whereIn($model->getKeyName(), collect($ids)->pluck('id'));
        }
    }

    // 2️⃣ Fallback to EXACT hash search
    $hashService = app(HashService::class);

    return static::where(function ($q) use ($query, $hashService, $model) {
        foreach (static::$searchableHashFields ?? [] as $field) {
            $q->orWhere(
                $field . '_hash',
                $hashService->hash($query)
            );
        }
    });
}

    public function getMeilisearchIndexName(): string
{
    $prefix = config('data-encryption.meilisearch.index_prefix', 'encrypted_');

    return $prefix . str_replace(
        '\\',
        '_',
        strtolower(get_class($this))
    );
}

}
