// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Full width / limited width toggle on templates.php
 *
 * @module     filter_genericotwo/pagewidth_toggle
 * @copyright  2026 Poodll
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {setUserPreference} from 'core_user/repository';
import Notification from 'core/notification';

const SELECTORS = {
    toggle: '[data-action="filter_genericotwo/pagewidth-toggle"]',
}

const PREFERENCE = 'filter_genericotwo_templates_fullwidth';

export const init = () => {
    document.querySelector(SELECTORS.toggle)?.addEventListener('change', (e) => {
        const fullwidth = e.target.checked;
        document.body.classList.toggle('limitedwidth', !fullwidth);
        setUserPreference(PREFERENCE, fullwidth ? 1 : 0).catch(Notification.exception);
    });
    document.querySelector('.main-inner')?.addEventListener('transitionend', (e) => {
        if (e.propertyName === 'max-width') {
            window.dispatchEvent(new Event('resize'));
        }
    });
};