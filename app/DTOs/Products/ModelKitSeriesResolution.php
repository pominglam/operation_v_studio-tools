<?php

declare(strict_types=1);

namespace App\DTOs\Products;

final readonly class ModelKitSeriesResolution
{
    public function __construct(
        public string $sku,
        public ?string $erp,
        public ?string $plamod,
        public ?string $rules,
        public ?string $wiki,
        public ?string $bandai,
        public ?string $finalDecision,
        public string $confidence,
        public ?string $plamodUrl = null,
        public ?string $wikiUrl = null,
        public ?string $bandaiUrl = null,
        public ?string $decisionReason = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'erp' => $this->erp,
            'plamod' => $this->plamod,
            'rules' => $this->rules,
            'wiki' => $this->wiki,
            'bandai' => $this->bandai,
            'final_decision' => $this->finalDecision,
            'confidence' => $this->confidence,
            'plamod_url' => $this->plamodUrl,
            'wiki_url' => $this->wikiUrl,
            'bandai_url' => $this->bandaiUrl,
            'decision_reason' => $this->decisionReason,
        ];
    }
}
