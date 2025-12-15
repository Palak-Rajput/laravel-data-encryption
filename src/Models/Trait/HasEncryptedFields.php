<?php

namespace PalakRajput\DataEncryption\Models\Trait;

use Illuminate\Database\Eloquent\Builder;
use PalakRajput\DataEncryption\Services\EncryptionService;
use PalakRajput\DataEncryption\Services\HashService;
use PalakRajput\DataEncryption\Services\MeilisearchService;

trait HasEncryptedFields
{
    /**
     * Boot the trait to encrypt/decrypt and index automatically.
     */
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

    /**
     * Encrypt model fields and generate searchable hashes.
     */
    public function encryptFields()
    {
        $encryptionService = app(EncryptionService::class);
        $hashService = app(HashService::class);

        $encryptedFields = static::$encryptedFields ?? [];
        $searchableHashFields = static::$searchableHashFields ?? [];

        foreach ($encryptedFields as $field) {
            if (!isset($this->attributes[$field]) || empty($this->attributes[$field])) {
                continue;
            }

            // Skip if already encrypted
            if ($this->isEncrypted($this->attributes[$field])) {
                continue;
            }

            // Encrypt in-place
            $this->attributes[$field] = $encryptionService->encrypt($this->attributes[$field]);

            // Hash for searchable fields
            if (in_array($field, $searchableHashFields)) {
                $hashField = $field . '_hash';
                $this->attributes[$hashField] = $hashService->hash($this->getOriginal($field) ?? $this->attributes[$field]);
            }
        }
    }

    /**
     * Decrypt model fields automatically on retrieval.
     */
    public function decryptFields()
    {
        $encryptionService = app(EncryptionService::class);
        $encryptedFields = static::$encryptedFields ?? [];

        foreach ($encryptedFields as $field) {
            if (!isset($this->attributes[$field]) || empty($this->attributes[$field])) {
                continue;
            }

            if ($this->isEncrypted($this->attributes[$field])) {
                $this->attributes[$field] = $encryptionService->decrypt($this->attributes[$field]);
            }
        }
    }

    /**
     * Detect if a value is encrypted (JSON + base64 format).
     */
    protected function isEncrypted($value): bool
    {
        if (!is_string($value)) return false;

        try {
            $decoded = base64_decode($value, true);
            if ($decoded === false) return false;

            $data = json_decode($decoded, true);
            return isset($data['iv'], $data['value'], $data['mac']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Index searchable hash fields to Meilisearch.
     */
    public function indexToMeilisearch()
    {
        $meilisearch = app(MeilisearchService::class);
        $searchableHashFields = static::$searchableHashFields ?? [];

        $data = [];
        foreach ($searchableHashFields as $field) {
            $hashField = $field . '_hash';
            if (isset($this->$hashField)) {
                $data[$hashField] = $this->$hashField;
            }
        }

        if (!empty($data)) {
            $data['id'] = $this->getKey();
            $data['model_type'] = get_class($this);
            $meilisearch->indexDocument($this->getMeilisearchIndexName(), $data);
        }
    }

    /**
     * Remove record from Meilisearch.
     */
    public function removeFromMeilisearch()
    {
        $meilisearch = app(MeilisearchService::class);
        $meilisearch->deleteDocument($this->getMeilisearchIndexName(), $this->getKey());
    }

    /**
     * Compute Meilisearch index name.
     */
    public function getMeilisearchIndexName(): string
    {
        $prefix = config('data-encryption.meilisearch.index_prefix', 'encrypted_');
        return $prefix . str_replace('\\', '_', strtolower(get_class($this)));
    }

    /**
     * Scope: search using hash fields automatically.
     */
    public function scopeSearchByHash(Builder $query, string $field, string $value)
    {
        $hashService = app(HashService::class);
        $hashField = $field . '_hash';

        return $query->where($hashField, $hashService->hash($value));
    }

    /**
     * Scope: search using Meilisearch index automatically.
     */
    public function scopeSearchInMeilisearch(Builder $query, string $searchTerm, array $fields = [])
    {
        $meilisearch = app(MeilisearchService::class);
        $indexName = $this->getMeilisearchIndexName();

        $results = $meilisearch->search($indexName, $searchTerm, $fields);

        if (empty($results)) {
            return $query->whereRaw('1 = 0'); // no results
        }

        $ids = collect($results)->pluck('id')->toArray();
        return $query->whereIn($this->getQualifiedKeyName(), $ids);
    }

    /**
     * Magic search method: tries Meilisearch first, falls back to hashed DB search.
     */
   public static function searchEncrypted(string $query)
{
    $model = new static();

    // Try Meilisearch first
    $builder = $model->newQuery();
    $ids = $model->scopeSearchInMeilisearch($builder, $query, $model::$searchableHashFields ?? [])
                 ->pluck($model->getKeyName())
                 ->toArray();

    if (!empty($ids)) {
        return static::whereIn($model->getKeyName(), $ids)->get();
    }

    // Fallback: search hashed fields in DB
    $builder = $model->newQuery();
    foreach ($model::$searchableHashFields ?? [] as $field) {
        $builder->orWhere($field . '_hash', app(HashService::class)->hash($query));
    }

    return $builder->get();
}

}
