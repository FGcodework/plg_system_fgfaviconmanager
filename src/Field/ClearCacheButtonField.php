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
 * Renders a "Clear icon cache" button on the plugin's own configuration screen.
 *
 * Automatic cache invalidation is based on the source file's mtime + size (see
 * IconGenerator::versionHash()), which is reliable for the normal Media Manager /
 * FTP-overwrite flow but not for a restore that preserves both timestamp and file size
 * (e.g. `rsync -a`). This button is the manual escape hatch for that case: wipe
 * everything under media/plg_system_fgfaviconmanager/cache/ and let it regenerate fresh
 * on the next page view.
 *
 * @since  1.9.0
 */
class ClearCacheButtonField extends FormField
{
    /**
     * @var    string
     * @since  1.9.0
     */
    protected $type = 'ClearCacheButton';

    /**
     * @return  string
     *
     * @since   1.9.0
     */
    protected function getInput()
    {
        // com_ajax dispatches to onAjax<PluginElement ucfirst'd> on every plugin in the
        // given group - this must match the "fgfaviconmanager" element exactly.
        $ajaxUrl = Uri::base() . 'index.php?option=com_ajax&plugin=fgfaviconmanager&group=system&format=json&'
            . Session::getFormToken() . '=1';

        $id = $this->id;

        $strings = [
            'confirm' => Text::_('PLG_SYSTEM_FGFAVICONMANAGER_CLEARCACHE_CONFIRM'),
            'busy'    => Text::_('PLG_SYSTEM_FGFAVICONMANAGER_CLEARCACHE_BUSY'),
            'done'    => Text::_('PLG_SYSTEM_FGFAVICONMANAGER_CLEARCACHE_DONE'),
            'error'   => Text::_('PLG_SYSTEM_FGFAVICONMANAGER_CLEARCACHE_ERROR'),
            'disabled' => Text::_('PLG_SYSTEM_FGFAVICONMANAGER_PREVIEW_DISABLED'),
        ];

        $html   = [];
        $html[] = '<button type="button" id="' . $id . '-btn" class="btn btn-danger" data-url="'
            . htmlspecialchars($ajaxUrl, \ENT_QUOTES) . '">'
            . htmlspecialchars(Text::_('PLG_SYSTEM_FGFAVICONMANAGER_CLEARCACHE_BUTTON'), \ENT_QUOTES)
            . '</button>';
        $html[] = '<span id="' . $id . '-status" style="margin-left:.75rem;"></span>';
        $html[] = '<script>';
        $html[] = '(function(){';
        $html[] = 'var strings = ' . json_encode($strings, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . ';';
        $html[] = 'var btn = document.getElementById(' . json_encode($id . '-btn') . ');';
        $html[] = 'var status = document.getElementById(' . json_encode($id . '-status') . ');';
        $html[] = 'if (!btn) { return; }';
        $html[] = 'btn.addEventListener("click", function () {';
        $html[] = '  if (!window.confirm(strings.confirm)) { return; }';
        $html[] = '  btn.disabled = true;';
        $html[] = '  status.textContent = strings.busy;';
        $html[] = '  fetch(btn.getAttribute("data-url"), { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest" } })';
        $html[] = '    .then(function (r) { return r.json(); })';
        $html[] = '    .then(function (data) {';
        $html[] = '      btn.disabled = false;';
        // com_ajax wraps every subscriber's return value into data.data as an array;
        // with a single subscriber that is [ourReturnValue].
        $html[] = '      var payload = data;';
        $html[] = '      if (data && Array.isArray(data.data)) { payload = data.data[0] || {}; }';
        $html[] = '      else if (data && data.data) { payload = data.data; }';
        $html[] = '      if (data && Array.isArray(data.data) && !data.data.length) { status.textContent = strings.disabled; return; }';
        $html[] = '      if (payload && payload.ok) {';
        $html[] = '        status.textContent = strings.done.replace("%d", payload.removed || 0);';
        $html[] = '      } else {';
        $html[] = '        status.textContent = (payload && payload.error) ? payload.error : strings.error;';
        $html[] = '      }';
        $html[] = '    })';
        $html[] = '    .catch(function () { btn.disabled = false; status.textContent = strings.error; });';
        $html[] = '});';
        $html[] = '})();';
        $html[] = '</script>';

        return implode('', $html);
    }
}
