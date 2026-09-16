<?php

declare(strict_types=1);

namespace App\Services\StoreMarketing;

use App\DAL\StoreMarketing\StoreMarketingNoteRepository;
use App\Models\StoreMarketingNote;
use App\Services\StoreMarketing\Exceptions\StoreMarketingNoteNotFoundException;
use App\Support\StoreMarketing\StoreMarketingNoteWriteData;
use Illuminate\Support\Collection;

final class StoreMarketingNoteService
{
    public function __construct(
        private readonly StoreMarketingNoteRepository $notes,
    ) {}

    /**
     * @return Collection<int, StoreMarketingNote>
     */
    public function list(?string $search): Collection
    {
        return $this->notes->list($search);
    }

    public function create(StoreMarketingNoteWriteData $data): StoreMarketingNote
    {
        return $this->notes->create($this->attributes($data));
    }

    public function update(string $uuid, StoreMarketingNoteWriteData $data): StoreMarketingNote
    {
        return $this->notes->update($this->require($uuid), $this->attributes($data));
    }

    public function delete(string $uuid): void
    {
        $this->notes->delete($this->require($uuid));
    }

    public function require(string $uuid): StoreMarketingNote
    {
        $note = $this->notes->findByUuid($uuid);
        if ($note === null) {
            throw new StoreMarketingNoteNotFoundException('Marketing note not found.');
        }

        return $note;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(StoreMarketingNoteWriteData $data): array
    {
        return [
            'name' => $data->name,
            'happened_on' => $data->happenedOn,
            'notes' => $data->notes,
        ];
    }
}
