<?php

namespace PalakRajput\DataEncryption\Models\Trait;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

trait HasEncryptedFields
{
    protected static function bootHasEncryptedFields()
    {
        static::saving(function ($model) {
            $model->encryptFields();
        });

        static::retrieved(function ($model) {
            // Don't automatically decrypt on model retrieval
            // This causes issues with authentication
            // Decryption should be handled on-demand
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
        
        // Add global scope to handle encrypted email queries
        static::addGlobalScope('encrypted_email', function (Builder $builder) {
            // We'll handle this in the resolveRouteBinding and specific query methods
        });
    }
    
    /**
     * Method to find user by email for authentication
     */
    public static function findByEmailForAuth($email)
    {
        $hashedEmail = hash('sha256', 'laravel-data-encryption' . $email);
        return static::where('email_hash', $hashedEmail)->first();
    }
    
    /**
     * Override the default resolveRouteBinding to handle encrypted email
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field === 'email') {
            $hashedEmail = hash('sha256', 'laravel-data-encryption' . $value);
            return $this->where('email_hash', $hashedEmail)->first();
        }
        
        return parent::resolveRouteBinding($value, $field);
    }
    
    /**
     * Get decrypted email value
     */
    public function getDecryptedEmailAttribute()
    {
        if (!empty($this->attributes['email']) && $this->isEncrypted($this->attributes['email'])) {
            try {
                return Crypt::decryptString($this->attributes['email']);
            } catch (\Exception $e) {
                return null;
            }
        }
        return $this->attributes['email'] ?? null;
    }
    
    /**
     * Scope for finding by email
     */
    public function scopeWhereEmail($query, $email)
    {
        if (in_array('email', static::$encryptedFields ?? [])) {
            $hashedEmail = hash('sha256', 'laravel-data-encryption' . $email);
            return $query->where('email_hash', $hashedEmail);
        }
        
        return $query->where('email', $email);
    }

    public function indexToMeilisearch()
    {
        if (!config('data-encryption.meilisearch.enabled', true)) {
            return;
        }

        try {
            $meilisearch = new \Meilisearch\Client('http://localhost:7700');
            $indexName = $this->getMeilisearchIndexName();
            $document = $this->getSearchableDocument();
            
            $meilisearch->index($indexName)->addDocuments([$document]);
        } catch (\Exception $e) {
            Log::info('Meilisearch indexing failed: ' . $e->getMessage());
        }
    }

    public function getSearchableDocument(): array
    {
        // Get decrypted email for search indexing
        $decryptedEmail = $this->decrypted_email;
        
        $document = [
            'id' => (string) $this->getKey(),
            'name' => $this->name ?? null,
            'email' => $decryptedEmail,
            'created_at' => $this->created_at ? $this->created_at->timestamp : null,
        ];

        // Add hash fields if they exist
        if (isset($this->email_hash)) {
            $document['email_hash'] = $this->email_hash;
        }
        
        if (isset($this->phone_hash)) {
            $document['phone_hash'] = $this->phone_hash;
        }

        // Extract email parts for search
        if (!empty($decryptedEmail)) {
            $document['email_parts'] = $this->extractEmailPartsForSearch($decryptedEmail);
        }

        return array_filter($document, function($value) {
            return !is_null($value);
        });
    }

    protected function extractEmailPartsForSearch(string $email): array
    {
        $email = strtolower(trim($email));
        $parts = [];

        if (empty($email)) {
            return $parts;
        }

        $parts[] = $email;
        
        if (str_contains($email, '@')) {
            list($localPart, $domain) = explode('@', $email, 2);
            
            $parts[] = $localPart;
            $parts[] = $domain;
            
            $domainParts = explode('.', $domain);
            if (count($domainParts) > 1) {
                $parts[] = $domainParts[0];
            }
        }
        
        return array_unique($parts);
    }

    public function encryptFields()
    {
        foreach (static::$encryptedFields ?? [] as $field) {
            if (!empty($this->attributes[$field]) && !$this->isEncrypted($this->attributes[$field])) {
                // Encrypt
                $this->attributes[$field] = Crypt::encryptString($this->attributes[$field]);
                
                // Create hash
                $hashField = $field . '_hash';
                $originalValue = $this->getOriginal($field) ?? $this->attributes[$field];
                
                try {
                    $decryptedValue = Crypt::decryptString($this->attributes[$field]);
                    $this->attributes[$hashField] = hash('sha256', 'laravel-data-encryption' . $decryptedValue);
                } catch (\Exception $e) {
                    $this->attributes[$hashField] = hash('sha256', 'laravel-data-encryption' . $originalValue);
                }
            }
        }
    }

    public function decryptFields()
    {
        foreach (static::$encryptedFields ?? [] as $field) {
            if (!empty($this->attributes[$field]) && $this->isEncrypted($this->attributes[$field])) {
                try {
                    $this->attributes[$field] = Crypt::decryptString($this->attributes[$field]);
                } catch (\Exception $e) {
                    // Keep encrypted if decryption fails
                }
            }
        }
    }

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

    public static function searchEncrypted(string $query)
    {
        $model = new static();
        
        if (config('data-encryption.meilisearch.enabled', true)) {
            try {
                $meilisearch = new \Meilisearch\Client('http://localhost:7700');
                $indexName = $model->getMeilisearchIndexName();
                
                $results = $meilisearch->index($indexName)->search($query, [
                    'attributesToSearchOn' => ['email_parts', 'name']
                ])->getHits();
                
                if (!empty($results)) {
                    $ids = collect($results)->pluck('id')->toArray();
                    return static::whereIn($model->getKeyName(), $ids);
                }
            } catch (\Exception $e) {
                Log::info('Meilisearch search failed, using database fallback: ' . $e->getMessage());
            }
        }
        
        return static::where(function ($q) use ($query) {
            $hashedQuery = hash('sha256', 'laravel-data-encryption' . $query);
            
            foreach (static::$searchableHashFields ?? [] as $field) {
                $q->orWhere($field . '_hash', $hashedQuery);
            }
            
            $q->orWhere('name', 'like', "%{$query}%");
        });
    }

    public function removeFromMeilisearch()
    {
        if (!config('data-encryption.meilisearch.enabled', true)) {
            return;
        }

        try {
            $meilisearch = new \Meilisearch\Client('http://localhost:7700');
            $indexName = $this->getMeilisearchIndexName();
            
            $meilisearch->index($indexName)->deleteDocument($this->getKey());
        } catch (\Exception $e) {
            Log::info('Failed to remove from Meilisearch: ' . $e->getMessage());
        }
    }

    public function getMeilisearchIndexName(): string
    {
        $prefix = config('data-encryption.meilisearch.index_prefix', 'encrypted_');
        return $prefix . str_replace('\\', '_', strtolower(get_class($this)));
    }
}