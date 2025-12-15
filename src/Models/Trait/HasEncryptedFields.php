<?php

namespace PalakRajput\DataEncryption\Models\Trait;

use Illuminate\Database\Eloquent\Builder;
use PalakRajput\DataEncryption\Services\EncryptionService;
use PalakRajput\DataEncryption\Services\HashService;
use PalakRajput\DataEncryption\Services\MeilisearchService;

trait HasEncryptedFields
{
    protected static $encryptedFields = [];
    protected static $searchableHashFields = [];
    
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
    
    // In src/Models/Trait/HasEncryptedFields.php
public function encryptFields()
{
    $encryptionService = app(EncryptionService::class);
    $hashService = app(HashService::class);
    
    foreach (static::$encryptedFields as $field) {
        if (isset($this->attributes[$field]) && !empty($this->attributes[$field])) {
            // Skip if already encrypted
            if ($this->isEncrypted($this->attributes[$field])) {
                continue;
            }
            
            // Encrypt INTO THE ORIGINAL COLUMN
            $this->attributes[$field] = $encryptionService->encrypt($this->attributes[$field]);
            
            // Create hash for searching
            $hashField = $field . '_hash';
            if (in_array($field, static::$searchableHashFields)) {
                $this->attributes[$hashField] = $hashService->hash(
                    $this->getOriginal($field) ?? $this->attributes[$field]
                );
            }
        }
    }
}
    
    public function decryptFields()
    {
        $encryptionService = app(EncryptionService::class);
        
        foreach (static::$encryptedFields as $field) {
            if (isset($this->attributes[$field]) && !empty($this->attributes[$field])) {
                $this->attributes[$field] = $encryptionService->decrypt($this->attributes[$field]);
            }
        }
    }
    
    public function indexToMeilisearch()
    {
        $meilisearch = app(MeilisearchService::class);
        $hashService = app(HashService::class);
        
        $data = [];
        foreach (static::$searchableHashFields as $field) {
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
    
    public function removeFromMeilisearch()
    {
        $meilisearch = app(MeilisearchService::class);
        $meilisearch->deleteDocument($this->getMeilisearchIndexName(), $this->getKey());
    }
    
    public function getMeilisearchIndexName()
    {
        $prefix = config('data-encryption.meilisearch.index_prefix', 'encrypted_');
        return $prefix . str_replace('\\', '_', strtolower(get_class($this)));
    }
    
    public function scopeSearchByHash(Builder $query, string $field, string $value)
    {
        $hashService = app(HashService::class);
        $hashField = $field . '_hash';
        
        return $query->where($hashField, $hashService->hash($value));
    }
    
    public function scopeSearchInMeilisearch(Builder $query, string $searchTerm, array $fields = [])
    {
        $meilisearch = app(MeilisearchService::class);
        $indexName = $this->getMeilisearchIndexName();
        
        $results = $meilisearch->search($indexName, $searchTerm, $fields);
        
        if (empty($results)) {
            return $query->whereRaw('1 = 0'); // Return empty result
        }
        
        $ids = collect($results)->pluck('id')->toArray();
        
        return $query->whereIn($this->getQualifiedKeyName(), $ids);
    }
}