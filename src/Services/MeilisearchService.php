<?php

namespace PalakRajput\DataEncryption\Services;

use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;

class MeilisearchService
{
    protected $client;
    protected $config;
    
    public function __construct(array $config)
    {
        $this->config = $config['meilisearch'];
        $this->client = new Client(
            $this->config['host'],
            $this->config['key']
        );
    }
    
    public function createIndex(string $indexName, array $fields = [])
    {
        try {
            $index = $this->client->index($indexName);
            
            // Configure searchable attributes
            if (!empty($fields)) {
                $index->updateSearchableAttributes($fields);
            }
            
            // Configure filterable attributes
            $index->updateFilterableAttributes($fields);
            
            // Configure sortable attributes
            $index->updateSortableAttributes($fields);
            
            return $index;
        } catch (ApiException $e) {
            if (config('app.debug')) {
                throw $e;
            }
            return null;
        }
    }
    
    public function indexDocument(string $indexName, array $document)
    {
        try {
            $index = $this->client->index($indexName);
            $index->addDocuments([$document]);
        } catch (ApiException $e) {
            if (config('app.debug')) {
                throw $e;
            }
        }
    }
    
    public function search(string $indexName, string $query, array $fields = [])
    {
        try {
            $index = $this->client->index($indexName);
            
            $searchParams = [];
            if (!empty($fields)) {
                $searchParams['attributesToSearchOn'] = $fields;
            }
            
            $results = $index->search($query, $searchParams);
            
            return $results->getHits();
        } catch (ApiException $e) {
            if (config('app.debug')) {
                throw $e;
            }
            return [];
        }
    }
    
    public function deleteDocument(string $indexName, $documentId)
    {
        try {
            $index = $this->client->index($indexName);
            $index->deleteDocument($documentId);
        } catch (ApiException $e) {
            if (config('app.debug')) {
                throw $e;
            }
        }
    }
    
    public function getIndexStats(string $indexName)
    {
        try {
            $index = $this->client->index($indexName);
            return $index->stats();
        } catch (ApiException $e) {
            return [];
        }
    }
}