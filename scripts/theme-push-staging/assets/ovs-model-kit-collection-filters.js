(function () {
  const handles = window.OVS_MK_FILTER_HANDLES;
  if (!handles || !(handles instanceof Set)) {
    return;
  }

  const pathParts = window.location.pathname.split('/').filter(Boolean);
  const collectionHandle = pathParts[pathParts.length - 1] || '';
  if (!handles.has(collectionHandle)) {
    return;
  }

  const pagination = document.querySelector('.pagination-wrapper');
  const gridContainer = document.getElementById('ProductGridContainer');

  const OTHER_UC_SERIES = new Set([
    'gundam_f91',
    'the_08th_ms_team',
    'gundam__the_origin',
    'gundam_thunderbolt',
    'gundam_narrative',
    'gundam_sentinel',
    'gundam_reconguista_in_g',
    'gundam__requiem_for_vengeance',
    'nextuc',
    'advance_of_zeta',
    'titanomachia',
  ]);

  const UC_SERIES_KEYS = [
    'mobile_suit_gundam',
    'zeta_gundam',
    'gundam_zz',
    'char_s_counterattack',
    'gundam_0080__war_in_the_pocket',
    'gundam_0083__stardust_memory',
    'gundam_unicorn',
    'hathaway',
    'other_uc',
  ];

  const AU_SERIES_KEYS = [
    'g_gundam',
    'gundam_wing',
    'gundam_seed',
    'gundam_00',
    'gundam_age',
    'gundam_build_fighters',
    'iron_blooded_orphans',
    'gundam_build_divers',
    'the_witch_from_mercury',
    'other_au',
  ];

  const OTHER_AU_SERIES = new Set(['gundam_x']);

  const OTHER_FRANCHISE_SERIES = new Set([
    'doraemon',
    'mazinger',
    'getter_robo',
    'kotetsu_jeeg',
    'patlabor',
    'macross_delta',
    'armored_trooper_votoms',
    'sakura_wars',
    'linebarrels_of_iron',
    'eureka_seven',
    'one_piece',
  ]);

  const MG_GRADES = new Set(['mg', 'mgex', 'mgsd']);
  const GUNPLA_HUB_HANDLE = window.OVS_MK_GUNPLA_HUB_HANDLE || 'gunpla';

  const FILTER_INPUTS =
    '.ovs-mk-grade-input, .ovs-mk-series-input, .ovs-mk-franchise-input, .ovs-mk-line-input, .ovs-mk-price-input';

  function getGridItems() {
    const grid = document.getElementById('product-grid');
    if (!grid) {
      return [];
    }

    return [...grid.querySelectorAll('.grid__item[data-ovs-mk-grade], .grid__item[data-ovs-mk-lines]')];
  }

  function parseList(param) {
    const raw = new URLSearchParams(window.location.search).get(param);
    if (!raw) {
      return [];
    }

    return raw
      .split(',')
      .map((value) => value.trim())
      .filter((value) => value !== '');
  }

  function csvSet(raw) {
    if (!raw) {
      return new Set();
    }

    return new Set(
      raw
        .split(',')
        .map((value) => value.trim())
        .filter((value) => value !== ''),
    );
  }

  function selectedValues(inputClass, dataAttr) {
    const values = new Set();
    document.querySelectorAll(`.${inputClass}:checked`).forEach((input) => {
      const value = input.getAttribute(dataAttr) || '';
      if (value !== '') {
        values.add(value);
      }
    });
    return [...values];
  }

  function selectedGrades() {
    return selectedValues('ovs-mk-grade-input', 'data-ovs-mk-grade');
  }

  function selectedSeries() {
    return selectedValues('ovs-mk-series-input', 'data-ovs-mk-series');
  }

  function selectedFranchises() {
    return selectedValues('ovs-mk-franchise-input', 'data-ovs-mk-franchise');
  }

  function selectedLines() {
    return selectedValues('ovs-mk-line-input', 'data-ovs-mk-line');
  }

  function selectedPrices() {
    return selectedValues('ovs-mk-price-input', 'data-ovs-mk-price');
  }

  function itemSeriesSet(item) {
    return csvSet(item.getAttribute('data-ovs-mk-series') || '');
  }

  function itemSublinesSet(item) {
    return csvSet(item.getAttribute('data-ovs-mk-sublines') || '');
  }

  function itemLinesSet(item) {
    return csvSet(item.getAttribute('data-ovs-mk-lines') || '');
  }

  function itemMatchesGrade(item, gradeKey) {
    const grade = item.getAttribute('data-ovs-mk-grade') || '';
    const sublines = itemSublinesSet(item);
    const lines = itemLinesSet(item);

    if (gradeKey === 'eg') return grade === 'eg';
    if (gradeKey === 'rg') return grade === 'rg';
    if (gradeKey === 'pg') return grade === 'pg';
    if (gradeKey === 're_100') return grade === 're_100' || grade === 're-100';
    if (gradeKey === 'fm') return grade === 'fm';
    if (gradeKey === 'option-parts') return lines.has('gunpla_option_parts');
    if (gradeKey === 'action-base') return lines.has('action_base');

    if (gradeKey === 'sd') {
      return (
        grade === 'sd' ||
        sublines.has('ex_standard') ||
        sublines.has('cross_silhouette') ||
        sublines.has('sdw') ||
        sublines.has('bb_senshi') ||
        sublines.has('g_generation') ||
        sublines.has('sdbf') ||
        sublines.has('gunpla_kun')
      );
    }

    if (
      gradeKey === 'ex_standard' ||
      gradeKey === 'cross_silhouette' ||
      gradeKey === 'sdw' ||
      gradeKey === 'bb_senshi' ||
      gradeKey === 'g_generation' ||
      gradeKey === 'sdbf' ||
      gradeKey === 'gunpla_kun'
    ) {
      return sublines.has(gradeKey) || grade === gradeKey;
    }

    if (gradeKey === 'hg') {
      return (
        grade === 'hg' ||
        sublines.has('hguc') ||
        sublines.has('hgce') ||
        sublines.has('hgac') ||
        sublines.has('hgibo') ||
        sublines.has('hgbf') ||
        sublines.has('hgbd') ||
        grade === 'hguc' ||
        grade === 'hgce' ||
        grade === 'hgac' ||
        grade === 'hgibo' ||
        grade === 'hgbf' ||
        grade === 'hgbd'
      );
    }

    if (gradeKey === 'hguc' || gradeKey === 'hgce' || gradeKey === 'hgac' || gradeKey === 'hgibo' || gradeKey === 'hgbf' || gradeKey === 'hgbd') {
      return sublines.has(gradeKey) || grade === gradeKey;
    }

    if (gradeKey === 'mg') {
      return MG_GRADES.has(grade) || sublines.has('ver_ka') || lines.has('mg-standard');
    }

    if (gradeKey === 'mg-standard') {
      return (
        lines.has('mg-standard') ||
        (grade === 'mg' && !sublines.has('ver_ka') && !lines.has('mg-standard') && grade !== 'mgex' && grade !== 'mgsd')
      );
    }

    if (gradeKey === 'ver_ka') return sublines.has('ver_ka');
    if (gradeKey === 'mgex') return grade === 'mgex';
    if (gradeKey === 'mgsd') return grade === 'mgsd';

    return false;
  }

  function itemMatchesSeries(item, seriesKey) {
    const series = itemSeriesSet(item);

    if (seriesKey === 'uc-all') {
      return UC_SERIES_KEYS.some((key) => itemMatchesSeries(item, key));
    }

    if (seriesKey === 'au-all') {
      return AU_SERIES_KEYS.some((key) => itemMatchesSeries(item, key));
    }

    if (seriesKey === 'hathaway') {
      return series.has('gundam_hathaway') || series.has('hathaway');
    }

    if (seriesKey === 'other_uc') {
      for (const slug of OTHER_UC_SERIES) {
        if (series.has(slug)) {
          return true;
        }
      }
      return false;
    }

    if (seriesKey === 'gundam_wing') {
      return series.has('gundam_wing') || series.has('gundam_wing__endless_waltz');
    }

    if (seriesKey === 'gundam_seed') {
      return (
        series.has('gundam_seed') ||
        series.has('gundam_seed_destiny') ||
        series.has('gundam_seed_freedom') ||
        series.has('gundam_seed_astray') ||
        series.has('gundam_seed_stargazer')
      );
    }

    if (seriesKey === 'gundam_build_divers') {
      return (
        series.has('gundam_build_divers') ||
        series.has('gundam_build_divers_re_rise') ||
        series.has('gundam_build_metaverse') ||
        series.has('buildmetaverse') ||
        series.has('gundam_breaker_battlogue')
      );
    }

    if (seriesKey === 'the_witch_from_mercury') {
      return series.has('the_witch_from_mercury') || series.has('mobile_suit_gundam_gquuuuuux');
    }

    if (seriesKey === 'other_au') {
      for (const slug of OTHER_AU_SERIES) {
        if (series.has(slug)) {
          return true;
        }
      }
      return false;
    }

    return series.has(seriesKey);
  }

  function itemMatchesFranchise(item, franchiseKey) {
    const series = itemSeriesSet(item);
    const lines = itemLinesSet(item);

    if (franchiseKey === 'other_franchise') {
      for (const slug of OTHER_FRANCHISE_SERIES) {
        if (series.has(slug) || lines.has(slug)) {
          return true;
        }
      }
      return false;
    }

    return series.has(franchiseKey) || lines.has(franchiseKey);
  }

  function itemMatchesLine(item, lineKey) {
    const grade = item.getAttribute('data-ovs-mk-grade') || '';
    const lines = itemLinesSet(item);

    if (lineKey === '30mm') return grade === '30mm';
    if (lineKey === '30ms') return grade === '30ms';
    if (lineKey === '30mf') return grade === '30mf';
    if (lineKey === '30mp') return grade === '30mp';

    return lines.has(lineKey);
  }

  function itemMatchesPrice(item, priceKey) {
    const cents = Number(item.getAttribute('data-ovs-price-cents') || '0');
    if (Number.isNaN(cents) || cents <= 0) {
      return false;
    }

    if (priceKey === 'under-35') return cents < 3500;
    if (priceKey === '35-60') return cents >= 3500 && cents < 6000;
    if (priceKey === '60-100') return cents >= 6000 && cents < 10000;
    if (priceKey === '100-plus') return cents >= 10000;

    return false;
  }

  function syncCheckboxLabels() {
    document.querySelectorAll(FILTER_INPUTS).forEach((input) => {
      const label = input.closest('.facet-checkbox');
      if (label) {
        label.classList.toggle('active', input.checked);
      }
    });
  }

  function syncPanels(changedInput, attribute) {
    const value = changedInput.getAttribute(attribute) || '';
    if (value === '') {
      return;
    }

    document.querySelectorAll(`[${attribute}="${value}"]`).forEach((input) => {
      input.checked = changedInput.checked;
    });
  }

  function shelfDefaults() {
    const handle = window.OVS_MK_COLLECTION_HANDLE || '';
    const entry = window.OVS_MK_SHELF_DEFAULTS?.[handle];
    if (!entry || typeof entry !== 'object') {
      return { grades: [], lines: [] };
    }

    return {
      grades: Array.isArray(entry.grades) ? entry.grades : [],
      lines: Array.isArray(entry.lines) ? entry.lines : [],
    };
  }

  function syncCheckboxesFromUrl() {
    const defaults = shelfDefaults();
    let grades = parseList('ovs_mk_grade');
    let series = parseList('ovs_mk_series');
    let franchises = parseList('ovs_mk_franchise');
    let lines = parseList('ovs_mk_line');
    let prices = parseList('ovs_mk_price');

    if (grades.length === 0 && defaults.grades.length > 0) {
      grades = defaults.grades;
    }
    if (lines.length === 0 && defaults.lines.length > 0) {
      lines = defaults.lines;
    }

    document.querySelectorAll('.ovs-mk-grade-input').forEach((input) => {
      input.checked = grades.includes(input.getAttribute('data-ovs-mk-grade') || '');
    });
    document.querySelectorAll('.ovs-mk-series-input').forEach((input) => {
      input.checked = series.includes(input.getAttribute('data-ovs-mk-series') || '');
    });
    document.querySelectorAll('.ovs-mk-franchise-input').forEach((input) => {
      input.checked = franchises.includes(input.getAttribute('data-ovs-mk-franchise') || '');
    });
    document.querySelectorAll('.ovs-mk-line-input').forEach((input) => {
      input.checked = lines.includes(input.getAttribute('data-ovs-mk-line') || '');
    });
    document.querySelectorAll('.ovs-mk-price-input').forEach((input) => {
      input.checked = prices.includes(input.getAttribute('data-ovs-mk-price') || '');
    });

    syncCheckboxLabels();
  }

  function buildUrl(grades, series, franchises, lines, prices) {
    const url = new URL(window.location.href);
    url.searchParams.delete('ovs_mk_grade');
    url.searchParams.delete('ovs_mk_series');
    url.searchParams.delete('ovs_mk_franchise');
    url.searchParams.delete('ovs_mk_line');
    url.searchParams.delete('ovs_mk_price');
    url.searchParams.delete('page');

    if (grades.length > 0) url.searchParams.set('ovs_mk_grade', grades.join(','));
    if (series.length > 0) url.searchParams.set('ovs_mk_series', series.join(','));
    if (franchises.length > 0) url.searchParams.set('ovs_mk_franchise', franchises.join(','));
    if (lines.length > 0) url.searchParams.set('ovs_mk_line', lines.join(','));
    if (prices.length > 0) url.searchParams.set('ovs_mk_price', prices.join(','));

    const pathHandle = resolvePathHandle(grades, series, franchises, lines, prices);
    url.pathname = `/collections/${pathHandle}`;
    preservePreviewThemeId(url);

    return url;
  }

  let cachedPreviewThemeId = new URLSearchParams(window.location.search).get('preview_theme_id');

  function currentPreviewThemeId() {
    if (cachedPreviewThemeId) {
      return cachedPreviewThemeId;
    }

    cachedPreviewThemeId = new URLSearchParams(window.location.search).get('preview_theme_id');
    return cachedPreviewThemeId;
  }

  function updateProductCount(visibleCount) {
    document.querySelectorAll('#ProductCountDesktop, #ProductCountMobile, .product-count__text span').forEach((node) => {
      node.textContent = visibleCount === 1 ? '1 product' : `${visibleCount} products`;
    });
  }

  function togglePagination(grades, series, franchises, lines, prices) {
    if (!pagination) {
      return;
    }

    pagination.hidden = grades.length > 0 || series.length > 0 || franchises.length > 0 || lines.length > 0 || prices.length > 0;
  }

  function setItemVisibility(item, show) {
    item.hidden = !show;
    item.classList.toggle('hidden', !show);
    item.setAttribute('aria-hidden', show ? 'false' : 'true');
  }

  function filtersActive() {
    return (
      selectedGrades().length > 0 ||
      selectedSeries().length > 0 ||
      selectedFranchises().length > 0 ||
      selectedLines().length > 0 ||
      selectedPrices().length > 0
    );
  }

  function applyFilters(updateHistory) {
    const grades = selectedGrades();
    const series = selectedSeries();
    const franchises = selectedFranchises();
    const lines = selectedLines();
    const prices = selectedPrices();
    let visibleCount = 0;

    getGridItems().forEach((item) => {
      let show = true;

      if (grades.length > 0) {
        show = grades.some((key) => itemMatchesGrade(item, key));
      }

      if (show && series.length > 0) {
        show = series.some((key) => itemMatchesSeries(item, key));
      }

      if (show && franchises.length > 0) {
        show = franchises.some((key) => itemMatchesFranchise(item, key));
      }

      if (show && lines.length > 0) {
        show = lines.some((key) => itemMatchesLine(item, key));
      }

      if (show && prices.length > 0) {
        show = prices.some((key) => itemMatchesPrice(item, key));
      }

      setItemVisibility(item, show);
      if (show) {
        visibleCount += 1;
      }
    });

    syncCheckboxLabels();
    updateProductCount(visibleCount);
    togglePagination(grades, series, franchises, lines, prices);
    syncNestedGroupExpansion();

    if (updateHistory) {
      const url = buildUrl(grades, series, franchises, lines, prices);
      window.OVS_MK_COLLECTION_HANDLE = resolvePathHandle(grades, series, franchises, lines, prices);
      window.history.pushState({ ovsMkFilters: true, searchParams: url.search.slice(1) }, '', url.pathname + url.search);
    }
  }

  function showAllCatalogFilterOptions() {
    document.querySelectorAll('.ovs-mk-filters .facets__item, .ovs-mk-filters .ovs-mk-filters__group').forEach((node) => {
      node.classList.remove('ovs-mk-filters__empty');
    });
  }

  function hideEmptyFilterOptions() {
    showAllCatalogFilterOptions();
  }

  function initNestedCollapsibles() {
    document.querySelectorAll('.ovs-mk-filters__group').forEach((group) => {
      const children = group.querySelector(':scope > .ovs-mk-filters__children');
      const parentLabel = group.querySelector(':scope > label.facets__label');
      if (!children || !parentLabel || group.querySelector('.ovs-mk-filters__group-head')) {
        return;
      }

      const head = document.createElement('div');
      head.className = 'ovs-mk-filters__group-head';
      parentLabel.parentNode.insertBefore(head, parentLabel);
      head.appendChild(parentLabel);

      const toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'ovs-mk-filters__toggle';
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-label', 'Expand group');
      toggle.innerHTML = '<span class="ovs-mk-filters__toggle-icon" aria-hidden="true"></span>';
      head.appendChild(toggle);

      toggle.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        const collapsed = children.classList.toggle('ovs-mk-filters__children--collapsed');
        toggle.setAttribute('aria-expanded', String(!collapsed));
      });
    });

    syncNestedGroupExpansion();
  }

  function syncNestedGroupExpansion() {
    document.querySelectorAll('.ovs-mk-filters__group').forEach((group) => {
      const children = group.querySelector(':scope > .ovs-mk-filters__children');
      const toggle = group.querySelector('.ovs-mk-filters__toggle');
      if (!children) {
        return;
      }

      const hasChecked = Boolean(group.querySelector('input:checked'));
      children.classList.toggle('ovs-mk-filters__children--collapsed', !hasChecked);
      if (toggle) {
        toggle.setAttribute('aria-expanded', String(hasChecked));
      }
    });
  }

  async function fetchRemainingGunplaPages() {
    const grid = document.getElementById('product-grid');
    if (!grid || grid.dataset.ovsMkGunplaPool !== 'true') {
      return;
    }

    const totalPages = Number(grid.dataset.ovsMkTotalPages || '1');
    if (totalPages <= 1) {
      return;
    }

    const sectionId = grid.dataset.ovsMkSectionId || grid.dataset.id || '';
    if (sectionId === '') {
      return;
    }

    const preview = new URLSearchParams(window.location.search).get('preview_theme_id');
    const seenSkus = new Set(
      getGridItems()
        .map((item) => item.querySelector('[data-product-id]')?.getAttribute('data-product-id') || item.textContent?.slice(0, 20) || '')
        .filter((value) => value !== ''),
    );

    for (let page = 2; page <= totalPages; page += 1) {
      const url = new URL(`/collections/${GUNPLA_HUB_HANDLE}`, window.location.origin);
      url.searchParams.set('page', String(page));
      url.searchParams.set('section_id', sectionId);
      if (preview) {
        url.searchParams.set('preview_theme_id', preview);
      }

      const response = await fetch(url.toString());
      if (!response.ok) {
        break;
      }

      const html = await response.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const items = doc.querySelectorAll('#product-grid .grid__item[data-ovs-mk-grade], #product-grid .grid__item[data-ovs-mk-lines]');
      items.forEach((item) => {
        const productId = item.querySelector('[data-product-id]')?.getAttribute('data-product-id') || '';
        if (productId !== '' && seenSkus.has(productId)) {
          return;
        }
        if (productId !== '') {
          seenSkus.add(productId);
        }
        grid.appendChild(document.importNode(item, true));
      });
    }
  }

  function usesGunplaPool() {
    if (typeof window.OVS_MK_USE_GUNPLA_POOL === 'boolean') {
      return window.OVS_MK_USE_GUNPLA_POOL;
    }

    const pool = window.OVS_MK_GUNPLA_POOL_HANDLES;
    const handle = window.OVS_MK_COLLECTION_HANDLE || collectionHandle;
    return pool instanceof Set && pool.has(handle);
  }

  function collectionHandleFromPath() {
    const parts = window.location.pathname.split('/').filter(Boolean);
    return parts[parts.length - 1] || '';
  }

  function preservePreviewThemeId(url) {
    const preview = currentPreviewThemeId();
    if (preview) {
      url.searchParams.set('preview_theme_id', preview);
    }
  }

  function resolvePathHandle(grades, series, franchises, lines, prices) {
    if (!usesGunplaPool()) {
      return window.OVS_MK_COLLECTION_HANDLE || collectionHandleFromPath();
    }

    const gradeRoutes = window.OVS_MK_GRADE_COLLECTIONS || {};
    const lineRoutes = window.OVS_MK_LINE_COLLECTIONS || {};

    const filterCount =
      (grades.length > 0 ? 1 : 0) +
      (series.length > 0 ? 1 : 0) +
      (franchises.length > 0 ? 1 : 0) +
      (lines.length > 0 ? 1 : 0) +
      (prices.length > 0 ? 1 : 0);

    if (filterCount !== 1) {
      return GUNPLA_HUB_HANDLE;
    }

    if (lines.length === 1 && grades.length === 0 && series.length === 0 && franchises.length === 0 && prices.length === 0) {
      return lineRoutes[lines[0]] || GUNPLA_HUB_HANDLE;
    }

    if (grades.length === 1 && lines.length === 0 && series.length === 0 && franchises.length === 0 && prices.length === 0) {
      return gradeRoutes[grades[0]] || GUNPLA_HUB_HANDLE;
    }

    return GUNPLA_HUB_HANDLE;
  }

  function syncCollectionHandleFromPath() {
    const handle = collectionHandleFromPath();
    if (handle !== '') {
      window.OVS_MK_COLLECTION_HANDLE = handle;
    }
  }

  function syncParentChildren(parentInput) {
    const group = parentInput.closest('.ovs-mk-filters__group');
    if (!group) {
      return;
    }

    group.querySelectorAll('.ovs-mk-filters__children .ovs-mk-grade-input, .ovs-mk-filters__children .ovs-mk-series-input').forEach((child) => {
      child.checked = parentInput.checked;
    });
  }

  function handleFilterInput(event) {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !input.matches(FILTER_INPUTS)) {
      return;
    }

    event.stopPropagation();
    event.stopImmediatePropagation();
  }

  function handleFilterChange(event) {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !input.matches(FILTER_INPUTS)) {
      return;
    }

    event.stopPropagation();
    event.stopImmediatePropagation();

    if (input.hasAttribute('data-ovs-mk-parent')) {
      syncParentChildren(input);
    }

    if (input.classList.contains('ovs-mk-grade-input')) {
      syncPanels(input, 'data-ovs-mk-grade');
    } else if (input.classList.contains('ovs-mk-series-input')) {
      syncPanels(input, 'data-ovs-mk-series');
    } else if (input.classList.contains('ovs-mk-franchise-input')) {
      syncPanels(input, 'data-ovs-mk-franchise');
    } else if (input.classList.contains('ovs-mk-line-input')) {
      syncPanels(input, 'data-ovs-mk-line');
    } else if (input.classList.contains('ovs-mk-price-input')) {
      syncPanels(input, 'data-ovs-mk-price');
    }

    applyFilters(true);
  }

  document.addEventListener('input', handleFilterInput, true);
  document.addEventListener('change', handleFilterChange, true);

  window.addEventListener('popstate', () => {
    syncCollectionHandleFromPath();
    syncCheckboxesFromUrl();
    applyFilters(false);
  });

  if (gridContainer) {
    const observer = new MutationObserver(() => {
      if (filtersActive()) {
        applyFilters(false);
      }
    });

    observer.observe(gridContainer, { childList: true, subtree: true });
  }

  initNestedCollapsibles();
  syncCheckboxesFromUrl();
  applyFilters(false);

  fetchRemainingGunplaPages().then(() => {
    syncCheckboxesFromUrl();
    applyFilters(false);
  });
})();
