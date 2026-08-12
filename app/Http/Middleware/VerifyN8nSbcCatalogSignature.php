<?php

namespace App\Http\Middleware;

final class VerifyN8nSbcCatalogSignature extends VerifyN8nCatalogSignature
{
    protected function keyConfigPath(): string
    {
        return 'services.n8n.sbc_catalog_key';
    }

    protected function secretConfigPath(): string
    {
        return 'services.n8n.sbc_catalog_secret';
    }

    protected function signatureScope(): string
    {
        return 'n8n-sbc';
    }
}
