<?php

namespace App\Profile;

use App\Models\Provider;
use RuntimeException;

/**
 * The provider cannot be published yet: some requirements are missing. It happens in normal
 * use; the request turns it into a message. It carries only the codes of PublishingRequirements
 * and the provider id, no personal data.
 */
final class CannotPublish extends RuntimeException
{
    /**
     * @param  list<string>  $missing
     */
    private function __construct(string $message, private readonly array $missing)
    {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $missing
     */
    public static function because(Provider $provider, array $missing): self
    {
        return new self(
            sprintf('Provider %d cannot be published, missing: %s.', $provider->id, implode(', ', $missing)),
            $missing,
        );
    }

    /**
     * The codes of the missing requirements.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        return $this->missing;
    }
}
