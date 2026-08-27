<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DTOs\Products\ProductTaxonomyProposalDTO;
use App\Models\Product;
use App\Support\Products\ProductGradeResolver;
use App\Support\Products\ProductGunplaMgClassificationResolver;
use App\Support\Products\ProductModelKitSeriesResolver;
use App\Support\Products\Storefront\DecalProductResolver;
use App\Support\Products\Storefront\MarkerProductResolver;
use App\Support\Products\Storefront\PaintProductResolver;

final class ProductTaxonomyDerivationService
{
    /** @var array<string, string> */
    private const MANUFACTURER_ALIASES = [
        'BANDAI SPIRITS' => 'Bandai Spirits',
        'BANDAI' => 'Bandai Spirits',
        'GOOD SMILE COMPANY' => 'Good Smile Company',
        'GSC' => 'Good Smile Company',
        'KOTOBUKIYA' => 'Kotobukiya',
        'GSI CREOS' => 'GSI Creos',
        'MR HOBBY' => 'GSI Creos',
        'MR SUPER CLEAR' => 'GSI Creos',
        'MR.SUPER CLEAR' => 'GSI Creos',
        'MR SURFACER' => 'GSI Creos',
        'HASEGAWA' => 'Hasegawa',
        'MECHATROWEGO' => 'Hasegawa',
        'CCS TOYS' => 'CCS Toys',
        'CCS EVANGELION' => 'CCS Toys',
        'ROSADOPROJECT' => 'Rosa Project',
        'ROSA PROJECT' => 'Rosa Project',
        'SNAA' => 'SNAA',
        'CANG-TOYS' => 'Cang-Toys',
        'CANG TOYS' => 'Cang-Toys',
        'CANGDAO' => 'CangDao',
        'MOTOR NUCLEAR' => 'Motor Nuclear',
        'NONZERO STUDIO' => 'Nonzero Studio',
        'HEMOXIAN' => 'Hemoxian',
        'GM MODEL' => 'GM Model',
        'EDDAS TECHNOLOGY' => 'Eddas Technology',
        'CAESAR WORKS' => 'Caesar Works',
        'MECHA CORE INDUSTRY' => 'Mecha Core Industry',
        'QIANQIU SHANG' => 'Qianqiu Shang',
        'BLACK TROJAN' => 'Black Trojan',
        'SHENGGE' => 'Shengge',
        'VIENTIANE FUSION' => 'Vientiane Fusion',
        'YILICHUANGWAN' => 'YiLiChuangWan',
        'PLAYCLUB' => 'PlayClub',
        'ROBOX ANIMATION' => 'Robox Animation',
        'IN ERA' => 'IN ERA+',
        'INFINITY NOVA' => 'IN ERA+',
        'COLD STEEL POWER' => 'Cold Steel Power',
        'SWORD OF RAGE' => 'Cold Steel Power',
        'FROZEN METAL' => 'Cold Steel Power',
        'STEDI' => 'Stedi',
        'DSPIAE' => 'Dspiae',
        'G-REWORK' => 'G-Rework',
        'GREWORK' => 'G-Rework',
        'WAVE' => 'Wave',
    ];

    /** @var array<string, string> */
    private const GUNPLA_SUBLINES = [
        'HGUC' => 'HGUC',
        'HGCE' => 'HGCE',
        'HGAC' => 'HGAC',
        'HGAW' => 'HGAW',
        'HGFC' => 'HGFC',
        'HGBF' => 'HGBF',
        'HGBD' => 'HGBD',
        'HGIBO' => 'HGIBO',
        'ORPHANS HG' => 'HGIBO',
        'VER.KA' => 'Ver.Ka',
        'VER KA' => 'Ver.Ka',
        'MGEX' => 'MGEX',
        'MGSD' => 'MGSD',
    ];

    public function __construct(
        private readonly ProductGradeResolver $gradeResolver,
        private readonly DecalProductResolver $decalResolver,
        private readonly MarkerProductResolver $markerResolver,
        private readonly PaintProductResolver $paintResolver,
        private readonly ProductWorkshopTaxonomyResolver $workshopResolver,
        private readonly ProductModelKitAccessoryResolver $accessoryResolver,
        private readonly ProductMerchandiseTaxonomyResolver $merchandiseResolver,
        private readonly ProductPreAssembledFigureResolver $figureResolver,
        private readonly ProductDspiaePaintAccessoryResolver $dspiaePaintAccessoryResolver,
        private readonly ProductGunplaMgClassificationResolver $mgClassification,
        private readonly ProductModelKitSeriesResolver $seriesResolver,
    ) {}

    public function derive(Product $product): ProductTaxonomyProposalDTO
    {
        $text = $this->searchableText($product);
        $accessory = $this->accessoryResolver->resolve($product, $text);
        if ($accessory['accessory_kind'] !== null) {
            return $this->deriveAccessory($product, $text, $accessory);
        }

        $figure = $this->figureResolver->resolve($product, $text);
        if ($figure['is_figure']) {
            return $this->deriveFigure($product, $figure);
        }

        $paintAccessory = $this->dspiaePaintAccessoryResolver->resolve($product, $text);
        if ($paintAccessory['is_paint_accessory']) {
            return $this->deriveDspiaePaintAccessory($product, $paintAccessory);
        }

        $merchandise = $this->merchandiseResolver->resolve($product, $text);
        if ($merchandise['is_merchandise']) {
            return $this->deriveMerchandise($product, $merchandise);
        }

        $productLine = $this->productLine($product, $text);
        $department = $this->department($product, $text, $productLine);
        $kitTaxonomy = $department === 'model kits';
        if (! $kitTaxonomy && ! in_array($productLine, [
            'Mr. Color',
            'Stedi Model Color',
            'Stedi Markers',
            'Dspiae Markers',
            'Water Decals',
            'Decal Softeners',
        ], true)) {
            $productLine = null;
        }
        $franchise = $kitTaxonomy ? $this->franchise($product, $text, $productLine) : null;
        $manufacturer = $this->manufacturer($product, $text, $productLine);
        if ($this->decalResolver->belongsToDecalsDepartment($product)) {
            $manufacturer = $this->decalManufacturer($product) ?? $manufacturer;
        }
        if ($this->markerResolver->belongsToMarkersDepartment($product)) {
            $manufacturer = $this->markerManufacturer($product) ?? $manufacturer;
        }
        if ($this->paintResolver->belongsToPaintsDepartment($product) && $manufacturer === null) {
            $manufacturer = $this->paintManufacturer($product, $text);
        }
        $subline = $kitTaxonomy ? $this->subline($product, $text, $productLine) : null;
        $grade = $kitTaxonomy && $productLine === 'Gunpla'
            ? $this->gradeResolver->resolveFromProduct($product)
            : null;
        $series = $kitTaxonomy
            ? ($this->nullableTrim($product->series) ?? $this->seriesResolver->resolve($product, $text))
            : null;
        $scale = $kitTaxonomy ? $this->scale($product, $text, $productLine) : null;
        $confidence = $this->confidence($product, $department, $productLine, $manufacturer);
        $values = compact(
            'department',
            'manufacturer',
            'franchise',
            'productLine',
            'subline',
            'grade',
            'series',
            'scale',
        );
        $values['product_line'] = $values['productLine'];
        unset($values['productLine']);

        $workshop = $this->workshopResolver->resolve($product);
        $values['workshop_shelf'] = $workshop['workshop_shelf'];
        $values['workshop_facets'] = $workshop['workshop_facets'];
        if (! $kitTaxonomy && $workshop['canonical_department'] !== null) {
            if (in_array($department, [null, 'supplies', 'tools', 'paints', 'misc'], true)) {
                $department = $workshop['canonical_department'];
                $values['department'] = $department;
            }
        }
        $values['accessory_kind'] = null;
        if ($workshop['workshop_shelf'] !== null) {
            $confidence = max($confidence, 88);
        }

        return new ProductTaxonomyProposalDTO(
            values: $values,
            evidence: $this->evidence($values, $confidence, $product),
            overallConfidence: $confidence,
            notes: $this->notes($product, $department, $productLine),
        );
    }

    /**
     * @param  array{
     *     accessory_kind: string,
     *     product_line: string|null,
     *     scale: string|null,
     *     franchise: string|null,
     *     manufacturer: string|null
     * } $accessory
     */
    private function deriveAccessory(Product $product, string $text, array $accessory): ProductTaxonomyProposalDTO
    {
        $manufacturer = $accessory['manufacturer']
            ?? $this->manufacturer($product, $text, $accessory['product_line']);
        $values = [
            'department' => 'accessories',
            'manufacturer' => $manufacturer,
            'franchise' => $accessory['franchise'],
            'product_line' => $accessory['product_line'],
            'subline' => null,
            'grade' => null,
            'series' => null,
            'scale' => $accessory['scale'],
            'workshop_shelf' => null,
            'workshop_facets' => [],
            'accessory_kind' => $accessory['accessory_kind'],
        ];
        $confidence = $manufacturer !== null && $accessory['product_line'] !== null ? 90 : 88;

        return new ProductTaxonomyProposalDTO(
            values: $values,
            evidence: $this->evidence($values, $confidence, $product),
            overallConfidence: $confidence,
            notes: [],
        );
    }

    /**
     * @param  array{
     *     is_figure: bool,
     *     manufacturer: string|null,
     *     franchise: string|null,
     *     product_line: string|null,
     *     scale: string|null
     * } $figure
     */
    private function deriveFigure(Product $product, array $figure): ProductTaxonomyProposalDTO
    {
        $values = [
            'department' => 'figures',
            'manufacturer' => $figure['manufacturer'],
            'franchise' => $figure['franchise'],
            'product_line' => $figure['product_line'],
            'subline' => null,
            'grade' => null,
            'series' => null,
            'scale' => $figure['scale'],
            'workshop_shelf' => null,
            'workshop_facets' => [],
            'accessory_kind' => null,
        ];
        $confidence = $figure['manufacturer'] !== null ? 88 : 80;

        return new ProductTaxonomyProposalDTO(
            values: $values,
            evidence: $this->evidence($values, $confidence, $product),
            overallConfidence: $confidence,
            notes: ['Pre-assembled figure; no assembly required.'],
        );
    }

    /**
     * @param  array{is_paint_accessory: bool, label: string|null}  $paintAccessory
     */
    private function deriveDspiaePaintAccessory(Product $product, array $paintAccessory): ProductTaxonomyProposalDTO
    {
        $values = [
            'department' => 'supplies',
            'manufacturer' => 'Dspiae',
            'franchise' => null,
            'product_line' => null,
            'subline' => null,
            'grade' => null,
            'series' => null,
            'scale' => null,
            'workshop_shelf' => 'Paints',
            'workshop_facets' => ['paint_accessory' => 'mixing_paper'],
            'accessory_kind' => null,
        ];
        $confidence = 90;

        return new ProductTaxonomyProposalDTO(
            values: $values,
            evidence: $this->evidence($values, $confidence, $product),
            overallConfidence: $confidence,
            notes: array_filter([$paintAccessory['label']]),
        );
    }

    /**
     * @param  array{
     *     is_merchandise: bool,
     *     product_line: string|null,
     *     franchise: string|null,
     *     manufacturer: string|null
     * } $merchandise
     */
    private function deriveMerchandise(Product $product, array $merchandise): ProductTaxonomyProposalDTO
    {
        $values = [
            'department' => 'misc',
            'manufacturer' => $merchandise['manufacturer'],
            'franchise' => $merchandise['franchise'],
            'product_line' => $merchandise['product_line'],
            'subline' => null,
            'grade' => null,
            'series' => null,
            'scale' => null,
            'workshop_shelf' => null,
            'workshop_facets' => [],
            'accessory_kind' => null,
        ];
        $confidence = $merchandise['manufacturer'] !== null ? 85 : 80;

        return new ProductTaxonomyProposalDTO(
            values: $values,
            evidence: $this->evidence($values, $confidence, $product),
            overallConfidence: $confidence,
            notes: ['Storefront Miscellaneous / keychains shelf; not a model kit.'],
        );
    }

    /**
     * @return array<int, string>
     */
    private function notes(Product $product, ?string $department, ?string $productLine): array
    {
        $notes = [];
        if ($department === 'misc' && str_contains($this->searchableText($product), 'ACTION BASE')) {
            $notes[] = 'Action base should use accessories department; re-run research refresh.';
        }
        if ($department === 'misc' && $this->looksLikeWorkshopSupply($product)) {
            $notes[] = 'Title or SKU suggests tools/supplies; verify department manually.';
        }
        if ($productLine === null && $this->markerResolver->belongsToMarkersDepartment($product)) {
            $notes[] = 'Marker product detected from SKU/title; confirm product line.';
        }

        return $notes;
    }

    private function looksLikeWorkshopSupply(Product $product): bool
    {
        $text = $this->searchableText($product);

        return str_contains($text, 'STEDI')
            || str_contains($text, 'DSPIAE')
            || preg_match('/\bMARKERS?\b/', $text) === 1
            || preg_match('/\b(?:MK|MKF|MKM|DMM|MA|MS)-/', $text) === 1;
    }

    private function searchableText(Product $product): string
    {
        return mb_strtoupper(implode(' ', array_filter([
            $product->sku,
            $product->description,
            $product->type,
            $product->brand,
            $product->vendor,
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '')));
    }

    private function department(Product $product, string $text, ?string $productLine): ?string
    {
        if ($this->paintResolver->belongsToPaintsDepartment($product)
            || preg_match('/\b(SUPER CLEAR|SURFACER|MR COLOR|MR\. COLOR)\b/', $text) === 1
        ) {
            return 'paints';
        }
        if (preg_match('/\b(CUTTING MAT|SCRAPER|GAP SCRAPER)\b/', $text) === 1) {
            return 'tools';
        }
        if ($this->markerResolver->belongsToMarkersDepartment($product)
            || $productLine === 'Stedi Markers'
            || $productLine === 'Dspiae Markers'
            || preg_match('/\bDMM-\d/', $text) === 1
            || preg_match('/\b(?:MK|MKF|MKM|MA|MS)-/', $text) === 1
            || mb_strtoupper(trim((string) $product->type)) === 'MARKERS'
            || preg_match('/\bMARKERS?\b/', $text) === 1
        ) {
            return 'supplies';
        }
        if ($this->decalResolver->belongsToDecalsDepartment($product)) {
            return 'supplies';
        }
        if (preg_match('/\b(?:CCS TOYS|CCS EVANGELION)\b/', $text) === 1) {
            return 'figures';
        }
        if ($this->isModelKitProductLine($productLine) || $this->isModelKitLegacyType($product->main_type)) {
            return 'model kits';
        }

        return $this->normalizedLegacyDepartment($product);
    }

    private function normalizedLegacyDepartment(Product $product): ?string
    {
        $legacy = mb_strtolower(trim((string) $product->main_type));
        $type = mb_strtoupper(trim((string) $product->type));

        if ($type === 'PAINT' || $this->paintResolver->belongsToPaintsDepartment($product)) {
            return 'paints';
        }
        if ($type === 'MARKERS' || $this->markerResolver->belongsToMarkersDepartment($product)) {
            return 'supplies';
        }

        return $legacy !== '' ? $legacy : null;
    }

    private function productLine(Product $product, string $text): ?string
    {
        if ($this->markerResolver->belongsToMarkersDepartment($product)) {
            return match ($this->markerResolver->resolveMarkerBrand($product)) {
                'stedi' => 'Stedi Markers',
                'dspiae' => 'Dspiae Markers',
                default => str_contains($text, 'STEDI') ? 'Stedi Markers' : null,
            };
        }

        if ($this->paintResolver->belongsToPaintsDepartment($product)) {
            if (preg_match('/\b(?:MC-|MMC-)/', $text) === 1 || (str_contains($text, 'STEDI') && preg_match('/\bMATTE\b/', $text) === 1)) {
                return 'Stedi Model Color';
            }
            if (preg_match('/\b(MR COLOR|MR\. COLOR)\b/', $text) === 1) {
                return 'Mr. Color';
            }
        }

        return match (true) {
            str_contains($text, 'KOTO-VI') => 'Variable Infinity',
            str_contains($text, 'MEGAMI DEVICE') => 'Megami Device',
            str_contains($text, 'FRAME ARMS GIRL') => 'Frame Arms Girl',
            str_contains($text, 'POKEMON'), str_contains($text, 'POKÉMON') => 'Pokémon Plamo Collection',
            preg_match('/\b30MM\b|30 MINUTES MISSIONS/', $text) === 1 => '30 Minutes Missions',
            preg_match('/\b30MS\b|30 MINUTES SISTERS/', $text) === 1 => '30 Minutes Sisters',
            preg_match('/\b30MF\b|30 MINUTES FANTASY/', $text) === 1 => '30 Minutes Fantasy',
            str_contains($text, 'MODEROID') => 'MODEROID',
            str_contains($text, 'FIGURE-RISE') => 'Figure-rise Standard',
            str_contains($text, 'MECHATROWEGO') => 'MechatroWeGo',
            str_contains($text, 'SNAA') => 'SNAA',
            str_contains($text, 'SWORD OF RAGE'), str_contains($text, 'FROZEN METAL') => 'Frozen Metal',
            str_contains($text, 'MR COLOR'), str_contains($text, 'MR. COLOR') => 'Mr. Color',
            preg_match('/\bDMM-\d/', $text) === 1 => 'Stedi Markers',
            preg_match('/\b(?:MK|MKF|MKM)-/', $text) === 1 => 'Dspiae Markers',
            preg_match('/\b(?:MA|MS)-/', $text) === 1 => 'Stedi Markers',
            preg_match('/\bETC-0[34]\b/', $text) === 1 => 'Decal Softeners',
            preg_match('/\bWD-/', $text) === 1, str_contains($text, 'WATER DECAL') => 'Water Decals',
            $this->isGunpla($text) => 'Gunpla',
            default => null,
        };
    }

    private function franchise(Product $product, string $text, ?string $productLine): ?string
    {
        $derived = match (true) {
            $productLine === 'Gunpla', str_contains($text, 'GUNDAM') => 'Gundam',
            $productLine === 'Pokémon Plamo Collection' => 'Pokémon',
            str_contains($text, 'ARMORED CORE') => 'Armored Core',
            str_contains($text, 'VOTOMS') => 'Armored Trooper Votoms',
            str_contains($text, 'EVANGELION') => 'Evangelion',
            str_contains($text, 'EUREKA SEVEN') => 'Eureka Seven',
            default => null,
        };
        if ($derived !== null) {
            return $derived;
        }

        $brand = $this->nullableTrim($product->brand);

        return match ($brand) {
            'Mobile Suit Gundam', 'Gundam' => 'Gundam',
            'PokÃ©mon', 'Pokémon' => 'Pokémon',
            'Neon Genesis Evangelion', 'Evangelion' => 'Evangelion',
            'Macross / Robotech' => 'Macross',
            '30 Minutes Missions', '30 Minutes Sisters', '30 Minutes Fantasy',
            'Megami Device', 'Frame Arms Girl', 'Mr Hobby', 'Bandai',
            'Bandai Spirits', 'Kotobukiya', 'Good Smile Company', 'Plamod' => null,
            default => $brand,
        };
    }

    private function manufacturer(Product $product, string $text, ?string $productLine): ?string
    {
        $fromLine = $this->manufacturerFromProductLine($productLine);
        if ($fromLine !== null) {
            return $fromLine;
        }
        if (preg_match('/\b50\d{5}\b/', $text) === 1 || preg_match('/\b0\d{6}\b/', $text) === 1) {
            return 'Bandai Spirits';
        }
        if (str_starts_with(mb_strtoupper((string) $product->sku), 'HSE-')) {
            return 'Hasegawa';
        }

        foreach (self::MANUFACTURER_ALIASES as $alias => $manufacturer) {
            if (str_contains($text, $alias)) {
                return $manufacturer;
            }
        }

        return null;
    }

    private function manufacturerFromProductLine(?string $productLine): ?string
    {
        return match ($productLine) {
            'Gunpla',
            'Pokémon Plamo Collection',
            '30 Minutes Missions',
            '30 Minutes Sisters',
            '30 Minutes Fantasy',
            'Figure-rise Standard' => 'Bandai Spirits',
            'Action Base', 'Builders Parts HD' => 'Bandai Spirits',
            'Option System' => 'Wave',
            'MODEROID' => 'Good Smile Company',
            'Variable Infinity', 'Megami Device', 'Frame Arms Girl' => 'Kotobukiya',
            'MechatroWeGo' => 'Hasegawa',
            'SNAA' => 'SNAA',
            'Frozen Metal' => 'Cold Steel Power',
            'Stedi Markers' => 'Stedi',
            'Dspiae Markers' => 'Dspiae',
            'Stedi Model Color' => 'Stedi',
            'Decal Softeners' => 'Dspiae',
            default => null,
        };
    }

    private function markerManufacturer(Product $product): ?string
    {
        return match ($this->markerResolver->resolveMarkerBrand($product)) {
            'stedi' => 'Stedi',
            'dspiae' => 'Dspiae',
            default => str_contains($this->searchableText($product), 'STEDI') ? 'Stedi' : null,
        };
    }

    private function paintManufacturer(Product $product, string $text): ?string
    {
        if (preg_match('/\b(?:MC-|MMC-)/', $text) === 1 || str_contains($text, 'STEDI')) {
            return 'Stedi';
        }

        return null;
    }

    private function decalManufacturer(Product $product): ?string
    {
        return match ($this->decalResolver->resolveBrand($product)) {
            'dspiae' => 'Dspiae',
            'g-rework' => 'G-Rework',
            'stedi' => 'Stedi',
            default => null,
        };
    }

    private function subline(Product $product, string $text, ?string $productLine): ?string
    {
        if ($productLine !== 'Gunpla') {
            return null;
        }

        $mgFamily = $this->mgClassification->classify($text);
        if ($mgFamily !== null && $mgFamily['subline'] !== null) {
            return $mgFamily['subline'];
        }

        $type = mb_strtoupper(trim((string) $product->type));
        foreach (self::GUNPLA_SUBLINES as $alias => $subline) {
            if ($type === $alias || preg_match('/\b'.preg_quote($alias, '/').'\b/', $text) === 1) {
                return $subline;
            }
        }

        return null;
    }

    private function scale(Product $product, string $text, ?string $productLine): ?string
    {
        if ($productLine === 'Pokémon Plamo Collection') {
            return null;
        }

        $stored = $this->nullableTrim($product->scale);
        if ($stored !== null && preg_match('/^(?:1\/\d+|non-scale)$/i', $stored) === 1) {
            return strtolower($stored) === 'non-scale' ? 'non-scale' : $stored;
        }
        if (preg_match('/\b1\/(10|12|15|20|24|35|48|60|72|100|144|550)\b/', $text, $match) === 1) {
            return '1/'.$match[1];
        }

        return null;
    }

    private function confidence(
        Product $product,
        ?string $department,
        ?string $productLine,
        ?string $manufacturer,
    ): int {
        if ($this->markerResolver->belongsToMarkersDepartment($product) && $manufacturer !== null) {
            return 92;
        }
        if ($this->paintResolver->belongsToPaintsDepartment($product) && $manufacturer !== null) {
            return 88;
        }
        if ($this->decalResolver->belongsToDecalsDepartment($product)) {
            return 85;
        }
        if (in_array($department, ['paints', 'tools', 'supplies'], true) && $department !== null) {
            return $manufacturer !== null ? 85 : 80;
        }
        if ($department === 'misc') {
            return 75;
        }
        if ($department === 'accessories') {
            return 88;
        }
        if ($this->isModelKitProductLine($productLine) && $manufacturer !== null) {
            return 90;
        }

        return $manufacturer !== null ? 85 : 70;
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<string, array{
     *     value: string|null,
     *     source_url: string|null,
     *     source_label: string,
     *     confidence: int,
     *     notes: string|null
     * }>
     */
    private function evidence(array $values, int $confidence, Product $product): array
    {
        $label = $this->evidenceSourceLabel($product);
        $evidence = [];
        foreach ($values as $field => $value) {
            $isEmpty = $value === null || (is_array($value) && $value === []);
            $evidence[$field] = [
                'value' => is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value,
                'source_url' => $this->preferredExternalUrl($product),
                'source_label' => $label,
                'confidence' => $isEmpty ? min($confidence, 60) : $confidence,
                'notes' => $this->evidenceNote($product, $field),
            ];
        }

        return $evidence;
    }

    private function evidenceSourceLabel(Product $product): string
    {
        $content = $product->externalContents->first(
            static fn ($item): bool => is_string($item->source_url) && trim($item->source_url) !== '',
        );

        if ($content !== null) {
            return ucfirst((string) $content->source).' product page';
        }

        return 'ERP title, SKU, and legacy type';
    }

    private function preferredExternalUrl(Product $product): ?string
    {
        foreach (['bandai', 'hlj', 'gundamplanet', 'newtype', 'plamod'] as $source) {
            $match = $product->externalContents->first(
                static fn ($item): bool => $item->source === $source
                    && is_string($item->source_url)
                    && trim($item->source_url) !== '',
            );
            if ($match !== null) {
                return $match->source_url;
            }
        }

        return null;
    }

    private function evidenceNote(Product $product, string $field): string
    {
        $description = trim((string) $product->description);
        $sku = trim((string) $product->sku);

        return match (true) {
            $field === 'department' && $this->markerResolver->belongsToMarkersDepartment($product) => "Classified from marker SKU/title ({$sku}; {$description}).",
            $field === 'manufacturer' && str_contains(mb_strtoupper($description), 'STEDI') => 'Manufacturer inferred from Stedi in product title.',
            $field === 'product_line' && $this->paintResolver->belongsToPaintsDepartment($product) => 'Product line inferred from paint SKU/title.',
            $field === 'workshop_shelf' => 'Derived from storefront Tools & Supplies rules; stored in ERP until mega menu cutover.',
            $field === 'workshop_facets' => 'Filter facet values derived from storefront classifier; Shopify tags unchanged until mega menu.',
            $field === 'accessory_kind' => 'Model kit accessory kind (display stand, option parts, detail parts, scene base).',
            $field === 'department' && preg_match('/\b(?:ACTION BASE|30MS OPTION|MS HAND|OPTION SYSTEM)\b/', $this->searchableText($product)) === 1 => 'Accessories are buildable add-ons, not primary model kits or T&S supplies.',
            $field === 'department' && preg_match('/\b(?:KEYCHAIN|RUBBER MASCOT)\b/', $this->searchableText($product)) === 1 => 'Miscellaneous keychains live under storefront Miscellaneous, not model kits.',
            $field === 'department' && preg_match('/\bCCS (?:TOYS|EVANGELION)\b/', $this->searchableText($product)) === 1 => 'Pre-assembled figure; department figures, not model kits.',
            default => 'Verify against manufacturer or retailer listing when possible.',
        };
    }

    private function isGunpla(string $text): bool
    {
        return str_contains($text, 'GUNDAM')
            || preg_match('/\b(?:HGUC|HGCE|HGAC|HGAW|HGFC|HGBF|HGBD|HGIBO|MGEX|MGSD)\b/', $text) === 1;
    }

    private function isModelKitLegacyType(?string $mainType): bool
    {
        return in_array(mb_strtolower(trim((string) $mainType)), ['model kit', 'model kits'], true);
    }

    private function isModelKitProductLine(?string $productLine): bool
    {
        return in_array($productLine, [
            'Gunpla',
            'Pokémon Plamo Collection',
            '30 Minutes Missions',
            '30 Minutes Sisters',
            '30 Minutes Fantasy',
            'MODEROID',
            'Figure-rise Standard',
            'Variable Infinity',
            'Megami Device',
            'Frame Arms Girl',
            'MechatroWeGo',
            'SNAA',
            'Frozen Metal',
        ], true);
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : '';

        return $value !== '' ? $value : null;
    }
}
