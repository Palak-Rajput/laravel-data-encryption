<?php

namespace PalakRajput\DataEncryption\Models\Trait;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

trait HasEncryptedFields
{
    /**
     * Cached list of encrypted fields for each model
     */
    protected static $cachedEncryptedFields = [];

    /**
     * Cached list of searchable hash fields for each model
     */
    protected static $cachedSearchableHashFields = [];

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
     * Auto-detect fields that should be encrypted
     */
    protected function getAutoDetectedFields(): array
    {
        $modelClass = get_class($this);
        
        // Return cached fields if already detected
        if (isset(static::$cachedEncryptedFields[$modelClass])) {
            return static::$cachedEncryptedFields[$modelClass];
        }
        
        $table = $this->getTable();
        $columns = Schema::getColumnListing($table);
        
        // Default sensitive field patterns to look for
        $sensitivePatterns = [
            'email', 'phone', 'mobile', 'telephone',
            'ssn', 'social_security', 'tax_id',
            'credit_card', 'card_number',
            'passport', 'driver_license',
            'address', 'street', 'city', 'zip', 'postal_code',
            'dob', 'date_of_birth', 'birth_date',
            'national_id', 'identity_number'
        ];
        
        $encryptedFields = [];
        
        foreach ($columns as $column) {
            $columnLower = strtolower($column);
            
            // Check if column matches any sensitive pattern
            foreach ($sensitivePatterns as $pattern) {
                if (str_contains($columnLower, $pattern)) {
                    $encryptedFields[] = $column;
                    break;
                }
            }
            
            // Special case for common email variations
            if (preg_match('/^(email|e_mail|mail)$/i', $column)) {
                $encryptedFields[] = $column;
            }
            
            // Special case for common phone variations
            if (preg_match('/^(phone|mobile|tel|telephone|contact_number)$/i', $column)) {
                $encryptedFields[] = $column;
            }
        }
        
        // Remove duplicates
        $encryptedFields = array_unique($encryptedFields);
        
        // Cache the result
        static::$cachedEncryptedFields[$modelClass] = $encryptedFields;
        
        return $encryptedFields;
    }

    /**
     * Get fields that should be searchable via hash
     */
    protected function getSearchableHashFields(): array
    {
        $modelClass = get_class($this);
        
        // Return cached fields if already detected
        if (isset(static::$cachedSearchableHashFields[$modelClass])) {
            return static::$cachedSearchableHashFields[$modelClass];
        }
        
        $encryptedFields = $this->getEncryptedFields();
        $searchableHashFields = [];
        
        // By default, make email and phone fields searchable
        foreach ($encryptedFields as $field) {
            $fieldLower = strtolower($field);
            
            // Make these field types searchable
            if (str_contains($fieldLower, 'email') || 
                str_contains($fieldLower, 'phone') ||
                str_contains($fieldLower, 'mobile') ||
                str_contains($fieldLower, 'ssn') ||
                str_contains($fieldLower, 'tax_id')) {
                $searchableHashFields[] = $field;
            }
        }
        
        // Cache the result
        static::$cachedSearchableHashFields[$modelClass] = $searchableHashFields;
        
        return $searchableHashFields;
    }

    /**
     * Get all fields to encrypt (auto-detected or manually configured)
     */
    protected function getEncryptedFields(): array
    {
        // First check for manually configured fields
        if (isset(static::$encryptedFields) && !empty(static::$encryptedFields)) {
            return static::$encryptedFields;
        }
        
        // Auto-detect fields
        return $this->getAutoDetectedFields();
    }

    /**
     * Get all searchable hash fields (auto-detected or manually configured)
     */
    protected function getSearchableHashFieldsList(): array
    {
        // First check for manually configured fields
        if (isset(static::$searchableHashFields) && !empty(static::$searchableHashFields)) {
            return static::$searchableHashFields;
        }
        
        // Auto-detect fields
        return $this->getSearchableHashFields();
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
            'model_type' => get_class($this),
            'created_at' => $this->created_at ? $this->created_at->timestamp : null,
        ];

        // Add name if the model has it
        if (isset($this->name)) {
            $document['name'] = $this->name;
        }

        // Add all encrypted fields with their hashes
        foreach ($this->getEncryptedFields() as $field) {
            $hashField = $field . '_hash';
            if (isset($this->$hashField)) {
                $document[$hashField] = $this->$hashField;
            }
            
            // Extract email parts for email fields
            if (str_contains(strtolower($field), 'email') && !empty($this->$field)) {
                $document['email_parts'] = $this->extractEmailPartsForSearch($this->$field);
            }
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
        foreach ($this->getEncryptedFields() as $field) {
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
        foreach ($this->getEncryptedFields() as $field) {
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
            
            foreach ($model->getSearchableHashFieldsList() as $field) {
                $q->orWhere($field . '_hash', $hashedQuery);
            }
            
            // Name search if column exists
            if (Schema::hasColumn($model->getTable(), 'name')) {
                $q->orWhere('name', 'like', "%{$query}%");
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
     * Get information about encrypted fields for debugging
     */
    public function getEncryptionInfo(): array
    {
        return [
            'model' => get_class($this),
            'table' => $this->getTable(),
            'encrypted_fields' => $this->getEncryptedFields(),
            'searchable_hash_fields' => $this->getSearchableHashFieldsList(),
            'has_email' => $this->hasEmailField(),
            'has_phone' => $this->hasPhoneField(),
        ];
    }

    /**
     * Check if model has email field
     */
    protected function hasEmailField(): bool
    {
        foreach ($this->getEncryptedFields() as $field) {
            if (str_contains(strtolower($field), 'email')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if model has phone field
     */
    protected function hasPhoneField(): bool
    {
        foreach ($this->getEncryptedFields() as $field) {
            if (str_contains(strtolower($field), 'phone') || 
                str_contains(strtolower($field), 'mobile') ||
                str_contains(strtolower($field), 'tel')) {
                return true;
            }
        }
        return false;
    }
}