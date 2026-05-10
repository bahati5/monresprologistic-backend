/**
 * §17 — Widget de suivi Monrespro Logistic pour WordPress
 * Usage: <div data-monrespro-tracking></div>
 * Shortcode WordPress: [monrespro_tracking]
 *
 * Configuration:
 *   window.MonresproConfig = { apiUrl: 'https://app.monrespro.cd/api' }
 */
(function () {
  'use strict';

  var API_URL = (window.MonresproConfig && window.MonresproConfig.apiUrl)
    ? window.MonresproConfig.apiUrl.replace(/\/$/, '')
    : 'https://app.monrespro.cd/api';

  var STATUS_ICONS = {
    draft: '📦',
    received_at_hub: '🏭',
    ready_for_dispatch: '📫',
    in_transit: '✈️',
    arrived_at_destination: '🛬',
    delivered: '✅',
    customs_hold: '🛃',
    cancelled: '❌',
  };

  var STATUS_COLORS = {
    draft: '#64748b',
    received_at_hub: '#3b82f6',
    ready_for_dispatch: '#8b5cf6',
    in_transit: '#f59e0b',
    arrived_at_destination: '#10b981',
    delivered: '#22c55e',
    customs_hold: '#ef4444',
    cancelled: '#6b7280',
  };

  function createWidget(container) {
    container.innerHTML = [
      '<div class="mrp-widget" style="font-family:sans-serif;border:1px solid #e2e8f0;border-radius:8px;padding:16px;max-width:480px;">',
      '  <div style="margin-bottom:12px;font-weight:600;color:#1e293b;">Suivi de colis Monrespro</div>',
      '  <div style="display:flex;gap:8px;">',
      '    <input id="mrp-input" type="text" placeholder="Entrez votre numéro de suivi..."',
      '      style="flex:1;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;" />',
      '    <button id="mrp-btn"',
      '      style="padding:8px 16px;background:#3b82f6;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:14px;">',
      '      Suivre',
      '    </button>',
      '  </div>',
      '  <div id="mrp-result" style="margin-top:12px;"></div>',
      '</div>',
    ].join('');

    var input = container.querySelector('#mrp-input');
    var btn = container.querySelector('#mrp-btn');
    var result = container.querySelector('#mrp-result');

    function track() {
      var tracking = input.value.trim();
      if (!tracking) return;

      btn.disabled = true;
      btn.textContent = '...';
      result.innerHTML = '<div style="color:#64748b;font-size:13px;">Recherche en cours...</div>';

      fetch(API_URL + '/widget/track/' + encodeURIComponent(tracking))
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.found) {
            result.innerHTML = '<div style="color:#ef4444;font-size:13px;">Numéro de suivi introuvable.</div>';
            return;
          }

          var icon = STATUS_ICONS[data.status_code] || '📦';
          var color = STATUS_COLORS[data.status_code] || '#64748b';

          result.innerHTML = [
            '<div style="background:#f8fafc;border-radius:6px;padding:12px;">',
            '  <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">',
            '    <span style="font-size:20px;">' + icon + '</span>',
            '    <span style="font-weight:600;color:' + color + ';">' + (data.status_label || data.status_code) + '</span>',
            '  </div>',
            '  <div style="font-size:13px;color:#475569;">',
            '    <strong>Référence :</strong> ' + data.tracking_number,
            data.destination ? '<br><strong>Destination :</strong> ' + data.destination : '',
            data.estimated_arrival ? '<br><strong>Arrivée estimée :</strong> ' + data.estimated_arrival : '',
            '  </div>',
            '</div>',
          ].join('');
        })
        .catch(function () {
          result.innerHTML = '<div style="color:#ef4444;font-size:13px;">Erreur lors de la recherche.</div>';
        })
        .finally(function () {
          btn.disabled = false;
          btn.textContent = 'Suivre';
        });
    }

    btn.addEventListener('click', track);
    input.addEventListener('keypress', function (e) {
      if (e.key === 'Enter') track();
    });
  }

  function init() {
    var containers = document.querySelectorAll('[data-monrespro-tracking]');
    for (var i = 0; i < containers.length; i++) {
      createWidget(containers[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
