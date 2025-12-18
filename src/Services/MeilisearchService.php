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
        // Try to get existing index
        try {
            $index = $this->client->index($indexName);
            $index->fetchInfo(); // Throws if not exists
            return $index;
        } catch (ApiException $e) {
            // Index does not exist → create it
            $this->client->createIndex($indexName, [
                'primaryKey' => 'id',
            ]);

            // IMPORTANT: fetch Index object AFTER creation
            $index = $this->client->index($indexName);

            // Initial settings
            $settings = [
                'searchableAttributes' => ['email_parts', 'name'],
                'filterableAttributes' => ['email_hash', 'phone_hash'],
                'sortableAttributes'   => ['created_at', 'name'],
            ];

            $index->updateSettings($settings);

            return $index;
        }
    } catch (ApiException $e) {
        Log::error('Failed to create/access Meilisearch index', [
            'index' => $indexName,
            'error' => $e->getMessage(),
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
  public function initializeIndex(string $indexName)
{
    try {
        // Create index if not exists
        try {
            $index = $this->client->index($indexName);
            $index->fetchInfo();
        } catch (\Throwable $e) {
            $this->client->createIndex($indexName, ['primaryKey' => 'id']);
            $index = $this->client->index($indexName);
        }

        // Apply settings
        $task = $index->updateSettings([
            'searchableAttributes' => ['email_parts', 'name'],
            'filterableAttributes' => ['email_hash', 'phone_hash'],
            'sortableAttributes'   => ['created_at', 'name'],
        ]);

        // ⏳ WAIT FOR SETTINGS TO APPLY
        $this->client->waitForTask($task['taskUid']);

        return $index;
    } catch (\Throwable $e) {
        Log::error('Meilisearch index initialization failed', [
            'index' => $indexName,
            'error' => $e->getMessage(),
        ]);
        return null;
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