(() => {
  const stopDawnDrawer = (event) => {
    event.stopPropagation();
    event.stopImmediatePropagation();
  };

  const initNav = (root) => {
    if (root.dataset.ovsNavInit === 'true') return;
    root.dataset.ovsNavInit = 'true';

    const panels = root.querySelectorAll('[data-ovs-panel]');
    const titleEl = root.querySelector('[data-ovs-nav-title]');
    const backBtn = root.querySelector('[data-ovs-nav-back]');
    const closeBtn = root.querySelector('[data-ovs-nav-close]');
    const dawnClose = root
      .closest('.menu-drawer__inner-submenu')
      ?.querySelector('.menu-drawer__close-button');

    let titles = {};
    try {
      titles = JSON.parse(root.dataset.ovsTitles || '{}');
    } catch {
      titles = {};
    }

    const stack = ['hub'];

    const show = (panelId) => {
      panels.forEach((panel) => {
        panel.hidden = panel.dataset.ovsPanel !== panelId;
      });
      if (titleEl) {
        titleEl.textContent = titles[panelId] || titles.hub || '';
      }
    };

    const reset = () => {
      stack.length = 0;
      stack.push('hub');
      show('hub');
      root.querySelectorAll('.ovs-model-mega__details[open]').forEach((el) => {
        el.open = false;
      });
    };

    const go = (panelId, event) => {
      if (event) {
        event.preventDefault();
        stopDawnDrawer(event);
      }
      if (!panelId || !root.querySelector(`[data-ovs-panel="${panelId}"]`)) return;
      stack.push(panelId);
      show(panelId);
    };

    const back = (event) => {
      if (event) {
        event.preventDefault();
        stopDawnDrawer(event);
      }
      if (stack.length <= 1) {
        dawnClose?.click();
        return;
      }
      stack.pop();
      show(stack[stack.length - 1]);
    };

    root.querySelectorAll('[data-ovs-go]').forEach((trigger) => {
      trigger.addEventListener('click', (event) => go(trigger.dataset.ovsGo, event), true);
    });

    backBtn?.addEventListener('click', back, true);
    closeBtn?.addEventListener(
      'click',
      (event) => {
        stopDawnDrawer(event);
        dawnClose?.click();
      },
      true
    );

    root.querySelectorAll('.ovs-model-mega__details').forEach((detailsEl) => {
      const summary = detailsEl.querySelector(':scope > .ovs-model-mega__details-summary');
      const parentLink = detailsEl.querySelector(':scope > summary .ovs-model-mega__details-link');
      const sublinks = detailsEl.querySelector(':scope > .ovs-model-mega__sublinks');

      if (parentLink && sublinks && !sublinks.querySelector('[data-ovs-parent-shelf]')) {
        const li = document.createElement('li');
        const allLink = document.createElement('a');
        allLink.href = parentLink.href;
        allLink.textContent = `All ${parentLink.textContent.trim()}`;
        allLink.setAttribute('data-ovs-parent-shelf', '');
        li.appendChild(allLink);
        sublinks.prepend(li);
      }

      const toggleAccordion = (event) => {
        if (event.target.closest('.ovs-model-mega__sublinks a')) return;
        event.preventDefault();
        stopDawnDrawer(event);
        detailsEl.open = !detailsEl.open;
      };

      summary?.addEventListener('click', toggleAccordion, true);
      parentLink?.addEventListener('click', toggleAccordion, true);
    });

    const detailsEl = root.closest('details');
    detailsEl?.addEventListener('toggle', () => {
      if (!detailsEl.open) reset();
    });

    reset();
  };

  document.querySelectorAll('.ovs-mobile-nav').forEach(initNav);
})();
