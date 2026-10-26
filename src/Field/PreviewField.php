<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.Fgfaviconmanager
 *
 * @copyright   Copyright (C) 2026. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace FG\Plugin\System\Fgfaviconmanager\Field;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

defined('_JEXEC') or die;

/**
 * "Preview icons" and "Download ZIP" buttons for the Frontend Favicon tab.
 *
 * Works on the images currently chosen in the form, saved or not: the JS reads the value
 * of the Favicon Source / Dark Mode Favicon media fields and posts it to the plugin's
 * com_ajax endpoint, which generates (or reuses from cache) the icon set and returns the
 * files. The preview therefore shows exactly what the site will serve.
 *
 * @since  1.18.0
 */
class PreviewField extends FormField
{
    /**
     * @var    string
     * @since  1.18.0
     */
    protected $type = 'Preview';

    /**
     * @return  string
     *
     * @since   1.18.0
     */
    protected function getInput()
    {
        $base = Uri::base() . 'index.php?option=com_ajax&plugin=fgfaviconmanager&group=system&format=json&'
            . Session::getFormToken() . '=1';

        $id = $this->id;

        $strings = [];

        foreach (
            ['BUSY', 'ERROR', 'NOSOURCE', 'MAIN', 'DARK', 'NOTSQUARE', 'SMALL', 'EMPTY'] as $key
        ) {
            $strings[strtolower($key)] = Text::_('PLG_SYSTEM_FGFAVICONMANAGER_PREVIEW_' . $key);
        }

        $config = [
            'url'     => $base,
            'strings' => $strings,
            // Ids of the media fields in the same form (Joomla names them jform_params_<field>).
            'main'    => 'jform_params_favicon_source',
            'dark'    => 'jform_params_favicon_dark_source',
        ];

        $html   = [];
        $html[] = '<div id="' . $id . '-box">';
        $html[] = '<button type="button" class="btn btn-primary" id="' . $id . '-run">'
            . htmlspecialchars(Text::_('PLG_SYSTEM_FGFAVICONMANAGER_PREVIEW_BUTTON'), \ENT_QUOTES) . '</button> ';
        $html[] = '<button type="button" class="btn btn-secondary" id="' . $id . '-zip">'
            . htmlspecialchars(Text::_('PLG_SYSTEM_FGFAVICONMANAGER_PREVIEW_ZIP'), \ENT_QUOTES) . '</button>';
        $html[] = '<span id="' . $id . '-status" style="margin-left:.75rem;"></span>';
        $html[] = '<div id="' . $id . '-out" style="margin-top:1rem;"></div>';
        $html[] = '</div>';
        $html[] = '<script>';
        $html[] = '(function(){';
        $html[] = 'var cfg = ' . json_encode($config, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . ';';
        $html[] = 'var id = ' . json_encode($id) . ';';
        $html[] = <<<'JS'
var run = document.getElementById(id + '-run'), zip = document.getElementById(id + '-zip');
var status = document.getElementById(id + '-status'), out = document.getElementById(id + '-out');
if (!run || !zip) { return; }
function val(fieldId) { var el = document.getElementById(fieldId); return el ? el.value || '' : ''; }
function params() {
  var p = new URLSearchParams();
  p.set('source', val(cfg.main));
  p.set('dark', val(cfg.dark));
  return p;
}
function el(tag, text, css) {
  var e = document.createElement(tag);
  if (text) { e.textContent = text; }
  if (css) { e.style.cssText = css; }
  return e;
}
function human(b) { return b >= 1024 ? (b / 1024).toFixed(1) + ' KB' : b + ' B'; }
function render(sets) {
  out.textContent = '';
  ['main', 'dark'].forEach(function (key) {
    var set = sets[key];
    if (!set) { return; }
    out.appendChild(el('h4', cfg.strings[key], 'margin:.5rem 0;'));
    var info = set.info || {};
    if (info.width && info.height) {
      if (info.width !== info.height) {
        out.appendChild(el('div', cfg.strings.notsquare.replace('%1$d', info.width).replace('%2$d', info.height), 'color:#b45309;'));
      }
      if (Math.min(info.width, info.height) < 512) {
        out.appendChild(el('div', cfg.strings.small.replace('%1$d', info.width).replace('%2$d', info.height), 'color:#b45309;'));
      }
    }
    var grid = el('div', '', 'display:flex;flex-wrap:wrap;gap:1rem;margin:.5rem 0 1rem;');
    var names = Object.keys(set.files || {});
    if (!names.length) { out.appendChild(el('div', cfg.strings.empty)); }
    names.forEach(function (name) {
      var f = set.files[name];
      var card = el('div', '', 'text-align:center;font-size:.8rem;');
      var box = el('div', '', 'width:96px;height:96px;display:flex;align-items:center;justify-content:center;border:1px solid #ccc;border-radius:4px;'
        + 'background:repeating-conic-gradient(#e5e5e5 0% 25%, #fff 0% 50%) 50% / 12px 12px;');
      if (/\.(png|svg|ico)$/.test(name)) {
        var img = document.createElement('img');
        img.src = f.url + '?v=' + f.bytes;
        img.alt = name;
        img.style.cssText = 'max-width:88px;max-height:88px;image-rendering:auto;';
        box.appendChild(img);
      }
      card.appendChild(box);
      card.appendChild(el('div', name, 'margin-top:.25rem;'));
      card.appendChild(el('div', human(f.bytes), 'color:#777;'));
      grid.appendChild(card);
    });
    out.appendChild(grid);
  });
}
run.addEventListener('click', function () {
  if (!val(cfg.main)) { status.textContent = cfg.strings.nosource; return; }
  run.disabled = true; status.textContent = cfg.strings.busy;
  var p = params(); p.set('fgaction', 'preview');
  fetch(cfg.url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: p })
    .then(function (r) {
      return r.text().then(function (t) {
        try { return JSON.parse(t); }
        catch (e) {
          // Not JSON: show what the server really answered, so the cause is visible.
          var plain = t.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300);
          throw new Error('HTTP ' + r.status + ': ' + plain);
        }
      });
    })
    .then(function (data) {
      run.disabled = false;
      var payload = data;
      if (data && Array.isArray(data.data)) { payload = data.data[0] || {}; }
      else if (data && data.data) { payload = data.data; }
      if (payload && payload.ok) { status.textContent = ''; render(payload.sets); }
      else { status.textContent = (payload && payload.error) ? payload.error : cfg.strings.error; }
    })
    .catch(function (e) { run.disabled = false; status.textContent = cfg.strings.error + (e && e.message ? ' [' + e.message + ']' : ''); });
});
zip.addEventListener('click', function () {
  if (!val(cfg.main)) { status.textContent = cfg.strings.nosource; return; }
  status.textContent = '';
  var p = params(); p.set('fgaction', 'export');
  window.location.href = cfg.url.replace('format=json', 'format=raw') + '&' + p.toString();
});
JS;
        $html[] = '})();';
        $html[] = '</script>';

        return implode("\n", $html);
    }
}
