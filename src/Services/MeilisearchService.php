<?php

namespace PalakRajput\DataEncryption\Services;

use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;

class MeilisearchService
{
    protected Client $client;
    protected array $config;

    public function __construct()
    {
        $this->config = config('data-encryption.meilisearch');

        $this->client = new Client(
            $this->config['host'],
            $this->config['key']
        );
    }

    public function createIndex(string $indexName, array $searchableFields = [])
    {
        try {
            $index = $this->client->index($indexName);

            if (!empty($searchableFields)) {
                $index->updateSearchableAttributes($searchableFields);
            }

            $index->updateTypoTolerance(['enabled' => true]);
            $index->updatePrefixSearch('all');

            return $index;
        } catch (ApiException $e) {
            if (config('app.debug')) throw $e;
            return null;
        }
    }

    public function indexDocument(string $indexName, array $document)
    {
        try {
            $this->client
                ->index($indexName)
                ->addDocuments([$document]);
        } catch (ApiException $e) {
            if (config('app.debug')) throw $e;
        }
    }

    public function search(string $indexName, string $query, array $fields = []): array
    {
        try {
            $params = [];

            if (!empty($fields)) {
                $params['attributesToSearchOn'] = $fields;
            }

            return $this->client
                ->index($indexName)
                ->search($query, $params)
                ->getHits();
        } catch (ApiException $e) {
            if (config('app.debug')) throw $e;
            return [];
        }
    }

    public function deleteDocument(string $indexName, $id): void
    {
        try {
            $this->client->index($indexName)->deleteDocument($id);
        } catch (ApiException $e) {
            if (config('app.debug')) throw $e;
        }
    }
}
