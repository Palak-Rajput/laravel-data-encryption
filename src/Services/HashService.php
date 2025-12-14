<?php

namespace PalakRajput\DataEncryption\Services;

class HashService
{
    protected $config;
    
    public function __construct(array $config)
    {
        $this->config = $config['hashing'];
    }
    
    public function hash(string $value): string
    {
        $algorithm = $this->config['algorithm'] ?? 'sha256';
        $salt = $this->config['salt'] ?? '';
        
        return hash($algorithm, $salt . $value);
    }
    
    public function hashPartial(string $value, int $length = 8): string
    {
        // For partial matching (like searching by phone prefix)
        $fullHash = $this->hash($value);
        return substr($fullHash, 0, $length);
    }
    
    public function compare(string $plainValue, string $hashedValue): bool
    {
        return hash_equals($this->hash($plainValue), $hashedValue);
    }
    
    public function hashArray(array $data, array $fields): array
    {
        $hashed = [];
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $hashed[$field . '_hash'] = $this->hash($data[$field]);
            }
        }
        return $hashed;
    }
}