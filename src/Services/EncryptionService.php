<?php

namespace PalakRajput\DataEncryption\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

class EncryptionService
{
    protected $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    public function encrypt(string $value): string
    {
        return Crypt::encryptString($value);
    }
    
    public function decrypt(string $encryptedValue): string
    {
        try {
            return Crypt::decryptString($encryptedValue);
        } catch (DecryptException $e) {
            // Handle corrupted data
            if (config('app.debug')) {
                throw $e;
            }
            return '';
        }
    }
    
    public function encryptArray(array $data): array
    {
        $encrypted = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $encrypted[$key] = $this->encrypt($value);
            } else {
                $encrypted[$key] = $value;
            }
        }
        return $encrypted;
    }
    
    public function decryptArray(array $encryptedData): array
    {
        $decrypted = [];
        foreach ($encryptedData as $key => $value) {
            if (is_string($value)) {
                $decrypted[$key] = $this->decrypt($value);
            } else {
                $decrypted[$key] = $value;
            }
        }
        return $decrypted;
    }
}