(function () {
  if (!window.location.pathname.endsWith('/collections/gundam-universal-century')) {
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

  const MG_GRADES = new Set(['mg', 'mgex', 'mgsd']);

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
    return selectedValues('ovs-mk-uc-grade-input', 'data-ovs-mk-uc-grade');
  }

  function selectedSeries() {
    return selectedValues('ovs-mk-uc-series-input', 'data-ovs-mk-uc-series');
  }

  function selectedPrices() {
    return selectedValues('ovs-mk-uc-price-input', 'data-ovs-mk-uc-price');
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

    if (gradeKey === 'eg') {
      return grade === 'eg';
    }

    if (gradeKey === 'rg') {
      return grade === 'rg';
    }

    if (gradeKey === 'pg') {
      return grade === 'pg';
    }

    if (gradeKey === 're_100') {
      return grade === 're_100' || grade === 're-100';
    }

    if (gradeKey === 'fm') {
      return grade === 'fm';
    }

    if (gradeKey === 'option-parts') {
      return lines.has('gunpla_option_parts');
    }

    if (gradeKey === 'action-base') {
      return lines.has('action_base');
    }

    if (gradeKey === 'sd') {
      return grade === 'sd' || sublines.has('ex_standard') || sublines.has('cross_silhouette') || sublines.has('sdw') || sublines.has('bb_senshi') || sublines.has('g_generation') || sublines.has('sdbf') || sublines.has('gunpla_kun');
    }

    if (gradeKey === 'ex_standard' || gradeKey === 'cross_silhouette' || gradeKey === 'sdw' || gradeKey === 'bb_senshi' || gradeKey === 'g_generation' || gradeKey === 'sdbf' || gradeKey === 'gunpla_kun') {
      return sublines.has(gradeKey) || grade === gradeKey;
    }

    if (gradeKey === 'hg') {
      return grade === 'hg' || sublines.has('hguc') || sublines.has('hgce') || sublines.has('hgac') || sublines.has('hgibo') || sublines.has('hgbf') || sublines.has('hgbd') || grade === 'hguc' || grade === 'hgce' || grade === 'hgac' || grade === 'hgibo' || grade === 'hgbf' || grade === 'hgbd';
    }

    if (gradeKey === 'hguc' || gradeKey === 'hgce' || gradeKey === 'hgac' || gradeKey === 'hgibo' || gradeKey === 'hgbf' || gradeKey === 'hgbd') {
      return sublines.has(gradeKey) || grade === gradeKey;
    }

    if (gradeKey === 'mg') {
      return MG_GRADES.has(grade) || sublines.has('ver_ka') || lines.has('mg-standard');
    }

    if (gradeKey === 'mg-standard') {
      return lines.has('mg-standard') || (grade === 'mg' && ! sublines.has('ver_ka') && ! lines.has('mg-standard') && grade !== 'mgex' && grade !== 'mgsd');
    }

    if (gradeKey === 'ver_ka') {
      return sublines.has('ver_ka');
    }

    if (gradeKey === 'mgex') {
      return grade === 'mgex';
    }

    if (gradeKey === 'mgsd') {
      return grade === 'mgsd';
    }

    return false;
  }

  function itemMatchesSeries(item, seriesKey) {
    const series = itemSeriesSet(item);

    if (seriesKey === 'uc-all') {
      return UC_SERIES_KEYS.some((key) => itemMatchesSeries(item, key));
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

    return series.has(seriesKey);
  }

  function itemMatchesPrice(item, priceKey) {
    const cents = Number(item.getAttribute('data-ovs-price-cents') || '0');
    if (Number.isNaN(cents) || cents <= 0) {
      return false;
    }

    if (priceKey === 'under-35') {
      return cents < 3500;
    }

    if (priceKey === '35-60') {
      return cents >= 3500 && cents < 6000;
    }

    if (priceKey === '60-100') {
      return cents >= 6000 && cents < 10000;
    }

    if (priceKey === '100-plus') {
      return cents >= 10000;
    }

    return false;
  }

  function syncCheckboxLabels() {
    document.querySelectorAll('.ovs-mk-uc-grade-input, .ovs-mk-uc-series-input, .ovs-mk-uc-price-input').forEach((input) => {
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

  function syncCheckboxesFromUrl() {
    const grades = parseList('ovs_mk_grade');
    const series = parseList('ovs_mk_series');
    const prices = parseList('ovs_mk_price');

    document.querySelectorAll('.ovs-mk-uc-grade-input').forEach((input) => {
      const value = input.getAttribute('data-ovs-mk-uc-grade') || '';
      input.checked = grades.includes(value);
    });

    document.querySelectorAll('.ovs-mk-uc-series-input').forEach((input) => {
      const value = input.getAttribute('data-ovs-mk-uc-series') || '';
      input.checked = series.includes(value);
    });

    document.querySelectorAll('.ovs-mk-uc-price-input').forEach((input) => {
      const value = input.getAttribute('data-ovs-mk-uc-price') || '';
      input.checked = prices.includes(value);
    });

    syncCheckboxLabels();
  }

  function buildUrl(grades, series, prices) {
    const url = new URL(window.location.href);
    url.searchParams.delete('ovs_mk_grade');
    url.searchParams.delete('ovs_mk_series');
    url.searchParams.delete('ovs_mk_price');
    url.searchParams.delete('page');

    if (grades.length > 0) {
      url.searchParams.set('ovs_mk_grade', grades.join(','));
    }

    if (series.length > 0) {
      url.searchParams.set('ovs_mk_series', series.join(','));
    }

    if (prices.length > 0) {
      url.searchParams.set('ovs_mk_price', prices.join(','));
    }

    return url;
  }

  function updateProductCount(visibleCount) {
    document.querySelectorAll('#ProductCountDesktop, #ProductCountMobile, .product-count__text span').forEach((node) => {
      node.textContent = visibleCount === 1 ? '1 product' : `${visibleCount} products`;
    });
  }

  function togglePagination(grades, series, prices) {
    if (!pagination) {
      return;
    }

    pagination.hidden = grades.length > 0 || series.length > 0 || prices.length > 0;
  }

  function setItemVisibility(item, show) {
    item.hidden = !show;
    item.classList.toggle('hidden', !show);
    item.setAttribute('aria-hidden', show ? 'false' : 'true');
  }

  function filtersActive() {
    return selectedGrades().length > 0 || selectedSeries().length > 0 || selectedPrices().length > 0;
  }

  function applyFilters(updateHistory) {
    const grades = selectedGrades();
    const series = selectedSeries();
    const prices = selectedPrices();
    let visibleCount = 0;

    getGridItems().forEach((item) => {
      let show = true;

      if (grades.length > 0) {
        show = grades.some((gradeKey) => itemMatchesGrade(item, gradeKey));
      }

      if (show && series.length > 0) {
        show = series.some((seriesKey) => itemMatchesSeries(item, seriesKey));
      }

      if (show && prices.length > 0) {
        show = prices.some((priceKey) => itemMatchesPrice(item, priceKey));
      }

      setItemVisibility(item, show);
      if (show) {
        visibleCount += 1;
      }
    });

    syncCheckboxLabels();
    updateProductCount(visibleCount);
    togglePagination(grades, series, prices);
    hideEmptyFilterOptions();

    if (updateHistory) {
      const url = buildUrl(grades, series, prices);
      window.history.pushState({ ovsMkUcFilters: true }, '', url.pathname + url.search);
    }
  }

  function hideEmptyFilterOptions() {
    const items = getGridItems();
    if (items.length === 0) {
      return;
    }

    document.querySelectorAll('.ovs-mk-uc-grade-input, .ovs-mk-uc-series-input, .ovs-mk-uc-price-input').forEach((input) => {
      const listItem = input.closest('.facets__item');
      if (!listItem) {
        return;
      }

      const gradeKey = input.getAttribute('data-ovs-mk-uc-grade');
      const seriesKey = input.getAttribute('data-ovs-mk-uc-series');
      const priceKey = input.getAttribute('data-ovs-mk-uc-price');
      let hasMatch = false;

      for (const item of items) {
        if (gradeKey && itemMatchesGrade(item, gradeKey)) {
          hasMatch = true;
          break;
        }

        if (seriesKey && itemMatchesSeries(item, seriesKey)) {
          hasMatch = true;
          break;
        }

        if (priceKey && itemMatchesPrice(item, priceKey)) {
          hasMatch = true;
          break;
        }
      }

      listItem.classList.toggle('ovs-mk-uc-filters__empty', !hasMatch);
    });

    document.querySelectorAll('.ovs-mk-uc-filters__group').forEach((group) => {
      const visibleChildren = group.querySelectorAll('.ovs-mk-uc-filters__children .facets__item:not(.ovs-mk-uc-filters__empty)');
      const parentItem = group.querySelector(':scope > .facets__item');
      if (parentItem && visibleChildren.length === 0) {
        parentItem.classList.add('ovs-mk-uc-filters__empty');
      } else if (parentItem) {
        parentItem.classList.remove('ovs-mk-uc-filters__empty');
      }
    });
  }

  function syncParentChildren(parentInput) {
    const group = parentInput.closest('.ovs-mk-uc-filters__group');
    if (!group) {
      return;
    }

    group.querySelectorAll('.ovs-mk-uc-filters__children .ovs-mk-uc-grade-input, .ovs-mk-uc-filters__children .ovs-mk-uc-series-input').forEach((child) => {
      child.checked = parentInput.checked;
    });
  }

  function stopFacetFormPropagation(event) {
    event.stopPropagation();
  }

  document.querySelectorAll('.ovs-mk-uc-grade-input, .ovs-mk-uc-series-input, .ovs-mk-uc-price-input').forEach((input) => {
    input.addEventListener('input', stopFacetFormPropagation);
    input.addEventListener('change', (event) => {
      stopFacetFormPropagation(event);

      if (input.hasAttribute('data-ovs-mk-uc-parent')) {
        syncParentChildren(input);
      }

      if (input.classList.contains('ovs-mk-uc-grade-input')) {
        syncPanels(input, 'data-ovs-mk-uc-grade');
      } else if (input.classList.contains('ovs-mk-uc-series-input')) {
        syncPanels(input, 'data-ovs-mk-uc-series');
      } else if (input.classList.contains('ovs-mk-uc-price-input')) {
        syncPanels(input, 'data-ovs-mk-uc-price');
      }

      applyFilters(true);
    });
  });

  window.addEventListener('popstate', () => {
    syncCheckboxesFromUrl();
    applyFilters(false);
  });

  if (gridContainer) {
    const observer = new MutationObserver(() => {
      if (filtersActive()) {
        applyFilters(false);
      } else {
        hideEmptyFilterOptions();
      }
    });

    observer.observe(gridContainer, { childList: true, subtree: true });
  }

  syncCheckboxesFromUrl();
  applyFilters(false);
})();
