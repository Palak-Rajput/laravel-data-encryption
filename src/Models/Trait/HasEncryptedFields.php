<?php

namespace PalakRajput\DataEncryption\Models\Trait;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

trait HasEncryptedFields
{
    protected static function bootHasEncryptedFields()
    {
        static::saving(function ($model) {
            $model->encryptFields();
        });

        // In HasEncryptedFields trait, modify the retrieved event:
        static::retrieved(function ($model) {
            // Check if this is an authentication request
            $isAuthRequest = request()->is('login', 'api/login', 'password/*') || 
                             request()->routeIs('login') ||
                             (request()->has('email') && request()->has('password'));
            
            if (!$isAuthRequest) {
                $model->decryptFields();
            }
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
            'created_at' => $this->created_at ? $this->created_at->timestamp : null,
        ];

        // Add all fields that are marked as searchable
        $searchableFields = static::$searchableHashFields ?? [];
        
        foreach ($searchableFields as $field) {
            // Add the field itself (decrypted)
            if (isset($this->{$field})) {
                $document[$field] = $this->{$field};
            }
            
            // Add hash if it exists
            $hashField = $field . '_hash';
            if (isset($this->{$hashField})) {
                $document[$hashField] = $this->{$hashField};
            }
            
            // Add search parts for partial matching
            if (isset($this->{$field}) && is_string($this->{$field})) {
                $partsField = $field . '_parts';
                $document[$partsField] = $this->extractSearchParts($this->{$field}, $field);
            }
        }

        return array_filter($document, function($value) {
            return !is_null($value);
        });
    }

    protected function extractSearchParts(string $value, string $field): array
    {
        $value = strtolower(trim($value));
        $parts = [];

        if (empty($value)) {
            return $parts;
        }

        // Always add the full value
        $parts[] = $value;
        
        // For email fields, extract parts like before
        if ($field === 'email' && str_contains($value, '@')) {
            list($localPart, $domain) = explode('@', $value, 2);
            
            $parts[] = $localPart;
            $parts[] = $domain;
            
            $domainParts = explode('.', $domain);
            if (count($domainParts) > 1) {
                $parts[] = $domainParts[0];
            }
        } 
        // For phone fields, extract numeric parts
        elseif ($field === 'phone' || strpos($field, 'phone') !== false) {
            $numeric = preg_replace('/[^0-9]/', '', $value);
            if ($numeric) {
                $parts[] = $numeric;
                // Add parts of phone number (last 4, last 7, etc.)
                if (strlen($numeric) >= 4) {
                    $parts[] = substr($numeric, -4);
                }
                if (strlen($numeric) >= 7) {
                    $parts[] = substr($numeric, -7);
                }
                if (strlen($numeric) >= 10) {
                    $parts[] = substr($numeric, -10);
                }
            }
        }
        // For other text fields, extract words and parts
        else {
            // Split by common delimiters
            $words = preg_split('/[\s\-_\.@]+/', $value);
            foreach ($words as $word) {
                if (strlen($word) > 2) {
                    $parts[] = $word;
                }
            }
            
            // Add partial matches for longer strings
            if (strlen($value) > 3) {
                for ($i = 3; $i <= strlen($value); $i++) {
                    $parts[] = substr($value, 0, $i);
                }
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
            
            // Get searchable fields from model configuration
            $searchableFields = static::$searchableHashFields ?? [];
            
            // Build attributes to search on dynamically
            $attributesToSearchOn = [];
            foreach ($searchableFields as $field) {
                $attributesToSearchOn[] = $field . '_parts';
            }
            
            // Also search on the actual field names
            $attributesToSearchOn = array_merge($attributesToSearchOn, $searchableFields);
            
            $results = $meilisearch->index($indexName)->search($query, [
                'attributesToSearchOn' => $attributesToSearchOn
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
    
    // Database fallback - improved to support partial search for all columns
    return static::where(function ($q) use ($query, $model) {
        $searchableFields = static::$searchableHashFields ?? [];
        
        // If no searchable fields are configured, use encrypted fields
        if (empty($searchableFields)) {
            $searchableFields = static::$encryptedFields ?? [];
        }
        
        foreach ($searchableFields as $field) {
            // Check if this is a regular (non-encrypted) field
            if (in_array($field, $model->getFillable()) && !in_array($field, static::$encryptedFields ?? [])) {
                // Regular field - do partial search
                $q->orWhere($field, 'like', "%{$query}%");
            } else {
                // Encrypted field - check if we should do hash search
                $hashField = $field . '_hash';
                $table = $model->getTable();
                
                // Get the hash column name with table prefix
                $qualifiedHashColumn = "{$table}.{$hashField}";
                
                // Try hash search first (exact match)
                $hashedQuery = hash('sha256', 'laravel-data-encryption' . $query);
                $q->orWhere($qualifiedHashColumn, $hashedQuery);
                
                // For non-hash columns that might be stored unencrypted for search
                // or for backup columns
                $backupField = $field . '_backup';
                if (\Illuminate\Support\Facades\Schema::hasColumn($table, $backupField)) {
                    $qualifiedBackupColumn = "{$table}.{$backupField}";
                    $q->orWhere($qualifiedBackupColumn, 'like', "%{$query}%");
                }
            }
        }
        
        // Also search on non-encrypted, non-hash fields
        $allFields = \Illuminate\Support\Facades\Schema::getColumnListing($model->getTable());
        $encryptedFields = static::$encryptedFields ?? [];
        $hashFields = array_map(function($field) {
            return $field . '_hash';
        }, $encryptedFields);
        $backupFields = array_map(function($field) {
            return $field . '_backup';
        }, $encryptedFields);
        
        $nonEncryptedFields = array_diff($allFields, 
            array_merge($encryptedFields, $hashFields, $backupFields));
        
        foreach ($nonEncryptedFields as $field) {
            // Skip id, timestamps, etc.
            if (!in_array($field, ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                $q->orWhere($field, 'like', "%{$query}%");
            }
        }
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
    
    /**
     * Get the searchable fields for Meilisearch
     */
    public function getSearchableFields(): array
    {
        return static::$searchableHashFields ?? static::$encryptedFields ?? [];
    }
    
    /**
     * Get partial search results for a specific field
     */
   /**
 * Get partial search results for a specific field
 */
public static function searchPartial(string $field, string $query)
{
    $model = new static();
    
    if (!in_array($field, static::$searchableHashFields ?? [])) {
        throw new \InvalidArgumentException("Field {$field} is not searchable");
    }
    
    // Try Meilisearch first
    if (config('data-encryption.meilisearch.enabled', true)) {
        try {
            $meilisearch = new \Meilisearch\Client('http://localhost:7700');
            $indexName = $model->getMeilisearchIndexName();
            
            $results = $meilisearch->index($indexName)->search($query, [
                'attributesToSearchOn' => [$field . '_parts', $field]
            ])->getHits();
            
            if (!empty($results)) {
                $ids = collect($results)->pluck('id')->toArray();
                return static::whereIn($model->getKeyName(), $ids);
            }
        } catch (\Exception $e) {
            // Fall back to database search
            Log::info('Meilisearch partial search failed: ' . $e->getMessage());
        }
    }
    
    // Database fallback for partial search
    return static::where(function ($q) use ($field, $query, $model) {
        // Try backup column if it exists
        $backupField = $field . '_backup';
        $table = $model->getTable();
        
        if (\Illuminate\Support\Facades\Schema::hasColumn($table, $backupField)) {
            $q->where($backupField, 'like', "%{$query}%");
        } else {
            // Fall back to hash exact match
            $hashedQuery = hash('sha256', 'laravel-data-encryption' . $query);
            $hashField = $field . '_hash';
            $q->where($hashField, $hashedQuery);
        }
    });
}
}