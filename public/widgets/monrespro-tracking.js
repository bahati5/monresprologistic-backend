/**
 * Monrespro Tracking Widget
 * Usage: embed on any site with:
 *   <div id="monrespro-tracking"></div>
 *   <script src="https://your-domain.com/widgets/monrespro-tracking.js" data-api="https://your-domain.com"></script>
 *
 * WordPress shortcode: [monrespro_tracking]
 */
(function () {
  var script = document.currentScript;
  var apiBase = (script && script.getAttribute('data-api')) || '';
  var container = document.getElementById('monrespro-tracking');

  if (!container) return;

  var styles = [
    '.mrp-widget { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 480px; margin: 0 auto; }',
    '.mrp-input-group { display: flex; gap: 8px; margin-bottom: 16px; }',
    '.mrp-input { flex: 1; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; }',
    '.mrp-input:focus { border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37,99,235,0.15); }',
    '.mrp-btn { padding: 10px 20px; background: #2563eb; color: #fff; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; font-weight: 600; }',
    '.mrp-btn:hover { background: #1d4ed8; }',
    '.mrp-btn:disabled { opacity: 0.6; cursor: not-allowed; }',
    '.mrp-status { padding: 8px 12px; border-radius: 6px; background: #f0f9ff; border: 1px solid #bae6fd; margin-bottom: 12px; font-weight: 600; }',
    '.mrp-timeline { list-style: none; padding: 0; margin: 0; }',
    '.mrp-step { display: flex; align-items: flex-start; gap: 12px; padding: 8px 0; }',
    '.mrp-dot { width: 12px; height: 12px; border-radius: 50%; margin-top: 4px; flex-shrink: 0; }',
    '.mrp-dot--completed { background: #10b981; }',
    '.mrp-dot--current { background: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.2); }',
    '.mrp-dot--pending { background: #d1d5db; }',
    '.mrp-step-label { font-size: 13px; }',
    '.mrp-step-date { font-size: 11px; color: #6b7280; }',
    '.mrp-error { color: #dc2626; font-size: 13px; padding: 8px; }',
  ].join('\n');

  var styleEl = document.createElement('style');
  styleEl.textContent = styles;
  document.head.appendChild(styleEl);

  container.innerHTML = [
    '<div class="mrp-widget">',
    '  <div class="mrp-input-group">',
    '    <input class="mrp-input" id="mrp-tracking-input" placeholder="Numéro de suivi..." />',
    '    <button class="mrp-btn" id="mrp-track-btn">Suivre</button>',
    '  </div>',
    '  <div id="mrp-result"></div>',
    '</div>',
  ].join('');

  var input = document.getElementById('mrp-tracking-input');
  var btn = document.getElementById('mrp-track-btn');
  var result = document.getElementById('mrp-result');

  btn.addEventListener('click', function () {
    var num = (input.value || '').trim();
    if (!num) return;

    btn.disabled = true;
    btn.textContent = '...';
    result.innerHTML = '';

    fetch(apiBase + '/api/track/' + encodeURIComponent(num))
      .then(function (r) {
        if (!r.ok) throw new Error('Colis introuvable');
        return r.json();
      })
      .then(function (data) {
        var html = '<div class="mrp-status">Statut : ' + (data.status && data.status.label || '—') + '</div>';
        if (data.steps && data.steps.length) {
          html += '<ul class="mrp-timeline">';
          data.steps.forEach(function (step) {
            var dotClass = step.completed ? 'mrp-dot--completed' : step.current ? 'mrp-dot--current' : 'mrp-dot--pending';
            html += '<li class="mrp-step">';
            html += '  <div class="mrp-dot ' + dotClass + '"></div>';
            html += '  <div>';
            html += '    <div class="mrp-step-label">' + step.label + '</div>';
            if (step.date) {
              html += '    <div class="mrp-step-date">' + new Date(step.date).toLocaleString('fr-FR') + '</div>';
            }
            html += '  </div>';
            html += '</li>';
          });
          html += '</ul>';
        }
        result.innerHTML = html;
      })
      .catch(function (err) {
        result.innerHTML = '<div class="mrp-error">' + err.message + '</div>';
      })
      .finally(function () {
        btn.disabled = false;
        btn.textContent = 'Suivre';
      });
  });

  input.addEventListener('keypress', function (e) {
    if (e.key === 'Enter') btn.click();
  });
})();
