<?php

declare(strict_types=1);

use App\Support\Products\Storefront\ModelKitCollectionFilterShelfDefaults;

require __DIR__.'/../vendor/autoload.php';

$themeRoot = getenv('THEME_ROOT') ?: realpath(dirname(__DIR__, 2).'/ovs-shopify-theme');
if (! is_string($themeRoot) || ! is_dir($themeRoot)) {
    fwrite(STDERR, "Theme root missing\n");
    exit(1);
}
$profile = file_get_contents($themeRoot.'/snippets/ovs-model-kit-filter-profile.liquid');
$params = file_get_contents($themeRoot.'/snippets/ovs-model-kit-filters-params.liquid');

// Strip comment headers from included parts
$paramsBody = preg_replace('/^{%\s*comment\s*%}.*?{%\s*endcomment\s*%}\s*/s', '', $params, 1);

$shelfDefaultsCase = ModelKitCollectionFilterShelfDefaults::liquidAssignCase();
$paramsBody = preg_replace(
    '/  endif\r?\n\r?\n  assign ovs_mk_grades_padded =/',
    "  endif\n\n{$shelfDefaultsCase}\n\n  assign ovs_mk_grades_padded =",
    $paramsBody,
    1,
    $injectedCount,
);
if ($injectedCount !== 1) {
    fwrite(STDERR, "Could not locate shelf-default injection point in params template\n");
    exit(1);
}

$mkPass = <<<'LIQUID'
{%- assign mk_pass = section_id | default: collection.id -%}
{%- assign mk_suffix = filter_suffix | default: '' -%}

LIQUID;

$renderGrade = "{% render 'ovs-model-kit-filters-grade-gunpla', ovs_mk_section_id: mk_pass, ovs_mk_filter_suffix: mk_suffix, ovs_mk_grades_padded: ovs_mk_grades_padded %}";
$renderSeriesUc = "{% render 'ovs-model-kit-filters-series-uc', ovs_mk_section_id: mk_pass, ovs_mk_filter_suffix: mk_suffix, ovs_mk_series_padded: ovs_mk_series_padded %}";
$renderSeriesAu = "{% render 'ovs-model-kit-filters-series-au', ovs_mk_section_id: mk_pass, ovs_mk_filter_suffix: mk_suffix, ovs_mk_series_padded: ovs_mk_series_padded %}";
$renderSeriesAll = "{% render 'ovs-model-kit-filters-series-gundam', ovs_mk_section_id: mk_pass, ovs_mk_filter_suffix: mk_suffix, ovs_mk_series_padded: ovs_mk_series_padded %}";
$renderPrice = "{% render 'ovs-model-kit-filters-price', ovs_mk_section_id: mk_pass, ovs_mk_filter_suffix: mk_suffix, ovs_mk_prices_padded: ovs_mk_prices_padded %}";

$flat = static function (string $label, string $slug, string $csv, string $class, string $attr, string $selected): string {
    return "{% render 'ovs-model-kit-filters-flat-group', group_label: '{$label}', group_id_slug: '{$slug}', options_csv: '{$csv}', input_class: '{$class}', data_attr: '{$attr}', selected_padded: {$selected}, ovs_mk_section_id: mk_pass, ovs_mk_filter_suffix: mk_suffix %}";
};

$contents = <<<'HEADER'
{% comment %}
  Model kit collection filters — profile router. Availability is native Shopify (last).
  Accepts: collection, section_id, filter_suffix
  Profile + URL params are inlined (Shopify render scope); partials receive explicit render args.
{% endcomment %}

HEADER;

$contents .= $profile."\n";
$contents .= "{%- if ovs_mk_filter_profile != blank -%}\n";
$contents .= "  {%- unless filter_suffix -%}\n";
$contents .= "    {{ 'ovs-model-kit-collection-filters.css' | asset_url | stylesheet_tag }}\n";
$contents .= "  {%- endunless -%}\n";
$contents .= $paramsBody;
$contents .= $mkPass;
$contents .= "  {%- case ovs_mk_filter_profile -%}\n";

$cases = <<<'CASES'
    {%- when 'gunpla-hub' -%}
      REPLACE_GRADE
      REPLACE_SERIES_ALL
      REPLACE_30MM
      REPLACE_FRANCHISE_MORE
      REPLACE_BRAND_OTHER
      REPLACE_PRICE
    {%- when 'uc-hub' -%}
      REPLACE_GRADE
      REPLACE_SERIES_UC
      REPLACE_PRICE
    {%- when 'au-hub' -%}
      REPLACE_GRADE
      REPLACE_SERIES_AU
      REPLACE_PRICE
    {%- when 'uc-other-hub' -%}
      REPLACE_GRADE
      REPLACE_UC_OTHER
      REPLACE_PRICE
    {%- when 'au-other-hub' -%}
      REPLACE_GRADE
      REPLACE_AU_OTHER
      REPLACE_PRICE
    {%- when 'grade-simple', 'subline-shelf' -%}
      REPLACE_GRADE
      REPLACE_SERIES_ALL
      REPLACE_PRICE
    {%- when 'series-leaf', 'franchise-leaf' -%}
      REPLACE_GRADE
      REPLACE_PRICE
    {%- when 'franchise-rollup' -%}
      REPLACE_FRANCHISE
      REPLACE_GRADE
      REPLACE_PRICE
    {%- when '30mm-hub' -%}
      REPLACE_30MM
      REPLACE_PRICE
    {%- when '30mm-leaf' -%}
      REPLACE_30MM
      REPLACE_PRICE
    {%- when 'brand-leaf' -%}
      REPLACE_GRADE
      REPLACE_PRICE
    {%- when 'accessories' -%}
      REPLACE_PRICE
CASES;

$replacements = [
    'REPLACE_GRADE' => '      '.$renderGrade,
    'REPLACE_SERIES_UC' => '      '.$renderSeriesUc,
    'REPLACE_SERIES_AU' => '      '.$renderSeriesAu,
    'REPLACE_SERIES_ALL' => '      '.$renderSeriesAll,
    'REPLACE_PRICE' => '      '.$renderPrice,
    'REPLACE_FRANCHISE' => '      '.$flat('Other series', 'OtherSeries', 'patlabor|Patlabor,macross_delta|Macross Delta,armored_trooper_votoms|Armored Trooper Votoms,mazinger|Mazinger,getter_robo|Getter Robo,kotetsu_jeeg|Kotetsu Jeeg,super_robot_wars|Super Robot Wars,armored_core|Armored Core,doraemon|Doraemon,sakura_wars|Sakura Wars,linebarrels_of_iron|Linebarrels of Iron,eureka_seven|Eureka Seven,one_piece|One Piece', 'ovs-mk-franchise-input', 'data-ovs-mk-franchise', 'ovs_mk_franchise_padded'),
    'REPLACE_FRANCHISE_MORE' => '      '.$flat('Other series', 'OtherSeries', 'pokemon|Pokémon,keroro|Keroro,armored_core|Armored Core,super_robot_wars|Super Robot Wars,evangelion|Evangelion,doraemon|Doraemon,mazinger|Mazinger,getter_robo|Getter Robo,kotetsu_jeeg|Kotetsu Jeeg,patlabor|Patlabor,macross_delta|Macross Delta,armored_trooper_votoms|Armored Trooper Votoms,sakura_wars|Sakura Wars,linebarrels_of_iron|Linebarrels of Iron,eureka_seven|Eureka Seven,one_piece|One Piece', 'ovs-mk-franchise-input', 'data-ovs-mk-franchise', 'ovs_mk_franchise_padded'),
    'REPLACE_BRAND_OTHER' => '      '.$flat('Other model kits', 'BrandOther', 'snaa|SNAA,kotobukiya|Kotobukiya,moderoid|MODEROID,plamax|PLAMAX,mechatrowego|MechatroWeGo', 'ovs-mk-franchise-input', 'data-ovs-mk-franchise', 'ovs_mk_franchise_padded'),
    'REPLACE_UC_OTHER' => '      '.$flat('UC series', 'SeriesUcOther', 'gundam_f91|Gundam F91,the_08th_ms_team|The 08th MS Team,gundam__the_origin|The Origin,gundam_thunderbolt|Thunderbolt,gundam_narrative|Narrative,gundam_sentinel|Sentinel,gundam_reconguista_in_g|Reconguista in G,gundam__requiem_for_vengeance|Requiem for Vengeance,nextuc|Next UC,advance_of_zeta|Advance of Zeta,titanomachia|Titanomachia', 'ovs-mk-series-input', 'data-ovs-mk-series', 'ovs_mk_series_padded'),
    'REPLACE_AU_OTHER' => '      '.$flat('AU series', 'SeriesAuOther', 'gundam_x|After War Gundam X', 'ovs-mk-series-input', 'data-ovs-mk-series', 'ovs_mk_series_padded'),
    'REPLACE_30MM' => '      '.$flat('30 minutes label', 'Line30mm', '30mm|30 Minutes Missions,30mm_armored_core|Armored Core,30ms|30 Minutes Sisters,30mf|30 Minutes Fantasy,30mp|30 Minutes Preference,30mm_accessories|Accessories', 'ovs-mk-line-input', 'data-ovs-mk-line', 'ovs_mk_line_padded'),
];

$cases = str_replace(array_keys($replacements), array_values($replacements), $cases);
$contents .= $cases."\n  {%- endcase -%}\n{%- endif -%}\n";

$out = $themeRoot.'/snippets/ovs-model-kit-collection-filters.liquid';
file_put_contents($out, $contents);
echo "Wrote {$out} (".strlen($contents)." bytes)\n";
