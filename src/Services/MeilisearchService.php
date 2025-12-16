<?php

namespace PalakRajput\DataEncryption\Services;

use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;
use Illuminate\Support\Facades\Log;

class MeilisearchService
{
    protected Client $client;
    protected array $config;

    public function __construct()
    {
        $this->config = config('data-encryption.meilisearch', []);
        
        $this->client = new Client(
            $this->config['host'] ?? 'http://localhost:7700',
            $this->config['key'] ?? ''
        );
    }

    public function createIndex(string $indexName)
    {
        try {
            // First check if index exists
            try {
                $index = $this->client->index($indexName);
                $index->fetchInfo(); // Test if index exists
                return $index; // Index already exists
            } catch (ApiException $e) {
                // Index doesn't exist, create it
                $index = $this->client->createIndex($indexName, ['primaryKey' => 'id']);
                
                // Configure for partial email search
                $settings = [
                    'searchableAttributes' => ['email_parts', 'name', 'phone_token'],
                    'filterableAttributes' => ['email_hash', 'phone_hash'],
                    'sortableAttributes' => ['created_at', 'name'],
                ];

                $index->updateSettings($settings);

                return $index;
            }
        } catch (ApiException $e) {
            Log::error('Failed to create/access Meilisearch index', [
                'index' => $indexName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function indexDocument(string $indexName, array $document)
    {
        try {
            $index = $this->createIndex($indexName);
            if ($index) {
                $index->addDocuments([$document]);
                return true;
            }
            return false;
        } catch (ApiException $e) {
            Log::error('Failed to index document', [
                'index' => $indexName,
                'document_id' => $document['id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function search(string $indexName, string $query, array $fields = []): array
    {
        try {
            $index = $this->client->index($indexName);
            
            $params = [];
            if (!empty($fields)) {
                $params['attributesToSearchOn'] = $fields;
            }
            
            $results = $index->search($query, $params);
            return $results->getHits();
        } catch (ApiException $e) {
            if ($e->getCode() === 404) {
                // Index doesn't exist
                Log::info("Meilisearch index {$indexName} doesn't exist yet");
            } else {
                Log::error('Meilisearch search failed', [
                    'index' => $indexName,
                    'query' => $query,
                    'error' => $e->getMessage()
                ]);
            }
            return [];
        }
    }

    public function deleteDocument(string $indexName, $id): void
    {
        try {
            $this->client->index($indexName)->deleteDocument($id);
        } catch (ApiException $e) {
            Log::error('Failed to delete document', [
                'index' => $indexName,
                'document_id' => $id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Initialize index with email_parts searchable
     */
    public function initializeIndex(string $indexName): bool
    {
        try {
            $index = $this->createIndex($indexName);
            
            if (!$index) {
                return false;
            }
            
            // Update settings for optimal partial search
            $settings = [
                'searchableAttributes' => ['email_parts', 'name', 'phone_token'],
                'filterableAttributes' => ['email_hash', 'phone_hash'],
                'sortableAttributes' => ['created_at', 'name'],
            ];
            
            $index->updateSettings($settings);
            
            // Wait for the update to be processed
            sleep(1);
            
            return true;
        } catch (ApiException $e) {
            Log::error('Meilisearch initialization failed', [
                'error' => $e->getMessage(),
                'index' => $indexName
            ]);
            return false;
        }
    }
    
    /**
     * Get client instance
     */
    public function getClient(): Client
    {
        return $this->client;
    }
}