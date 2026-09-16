<?php

declare(strict_types=1);

namespace App\Services\SpecialOrders;

use App\DAL\Maintenance\MaintenanceNoteRepository;
use App\Models\MaintenanceNote;
use App\Support\SpecialOrders\SpecialOrderCustomerMessageTemplate;

final class SpecialOrderCustomerMessageTemplateService
{
    public const KEY = 'special_order_customer_message';

    public function __construct(
        private readonly MaintenanceNoteRepository $notes,
    ) {}

    public function getBody(): string
    {
        $note = $this->notes->findByKey(self::KEY);
        $body = $note?->body;

        if (! is_string($body) || trim($body) === '') {
            return SpecialOrderCustomerMessageTemplate::defaultBody();
        }

        return trim($body);
    }

    public function isUsingDefault(): bool
    {
        $note = $this->notes->findByKey(self::KEY);
        $body = $note?->body;

        return ! is_string($body) || trim($body) === '';
    }

    public function upsert(string $body): MaintenanceNote
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('Template body is required.');
        }

        SpecialOrderCustomerMessageTemplate::assertPlaceholders($trimmed);

        return $this->notes->upsert(self::KEY, $trimmed);
    }

    public function resetToDefault(): void
    {
        $note = $this->notes->findByKey(self::KEY);
        if ($note !== null) {
            $note->delete();
        }
    }

    /** @return array{body: string, default_body: string, placeholders: list<string>, is_default: bool, updated_at: string|null} */
    public function toArray(): array
    {
        $note = $this->notes->findByKey(self::KEY);

        return [
            'body' => $this->getBody(),
            'default_body' => SpecialOrderCustomerMessageTemplate::defaultBody(),
            'placeholders' => SpecialOrderCustomerMessageTemplate::REQUIRED_PLACEHOLDERS,
            'is_default' => $this->isUsingDefault(),
            'updated_at' => $note?->updated_at?->toIso8601String(),
        ];
    }
}
