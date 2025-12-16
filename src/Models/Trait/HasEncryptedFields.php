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
        $document = [
            'id' => (string) $this->getKey(),
            'name' => $this->name ?? null,
            'email' => $this->email ?? null,
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
        if (!empty($this->email)) {
            $document['email_parts'] = $this->extractEmailPartsForSearch($this->email);
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

        // Always add the full email
        $parts[] = $email;
        
        // Extract local part and domain
        if (str_contains($email, '@')) {
            list($localPart, $domain) = explode('@', $email, 2);
            
            // Add main parts
            $parts[] = $localPart;
            $parts[] = $domain;
            
            // Add domain without TLD
            $domainParts = explode('.', $domain);
            if (count($domainParts) > 1) {
                $parts[] = $domainParts[0]; // "gmail", "yahoo", etc.
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
        
        // Try Meilisearch first
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
                // Fall back to database search
                Log::info('Meilisearch search failed, using database fallback: ' . $e->getMessage());
            }
        }
        
        // Database fallback
        return static::where(function ($q) use ($query) {
            // Hash search
            $hashedQuery = hash('sha256', 'laravel-data-encryption' . $query);
            
            foreach (static::$searchableHashFields ?? [] as $field) {
                $q->orWhere($field . '_hash', $hashedQuery);
            }
            
            // Name search
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