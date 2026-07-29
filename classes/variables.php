<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace filter_genericotwo;

use filter_genericotwo\constants;

/**
 * The catalogue of variables that a template author can use in a template.
 *
 * This is the single source of truth for "what variables exist". It feeds the insert-variable
 * picker in the template editor and the AI helper prompt, so the two can not drift apart. The
 * variables listed here must match what \filter_genericotwo\text_filter actually resolves.
 *
 * @package    filter_genericotwo
 * @copyright  2025 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class variables {
    /**
     * User record fields we advertise. Deliberately curated rather than read from the user table,
     * so we never offer up password/secret/sesskey style columns.
     */
    const USER_FIELDS = [
        'id', 'username', 'idnumber', 'firstname', 'lastname', 'email', 'city', 'country',
        'department', 'institution', 'phone1', 'lang', 'timezone',
    ];

    /**
     * Course record fields we advertise.
     */
    const COURSE_FIELDS = [
        'id', 'fullname', 'shortname', 'idnumber', 'summary', 'category', 'format',
        'startdate', 'enddate', 'lang',
    ];

    /**
     * URL params that templates commonly want. Any other param name can be typed in.
     */
    const COMMON_URLPARAMS = ['id', 'cmid', 'attempt', 'page'];

    /**
     * Build the full variable catalogue, grouped for display.
     *
     * Each group is ['key' => string, 'label' => string, 'items' => array]. Each item is:
     *  - 'label'       string  What to show in the menu.
     *  - 'name'        string  Variable name, which the UI wraps in {{ }} (or {{{ }}} for JS).
     *  - 'snippet'     string  Literal text to insert instead of a wrapped name. May contain the
     *                          token %%SEL%%, which is replaced by the current selection.
     *  - 'placeholder' string  Substring of the inserted text to select afterwards, so the author
     *                          can type over it (e.g. the "name" of {{URLPARAM:name}}).
     *  - 'description' string  Short explanation, shown under the label.
     *
     * @return array The groups.
     */
    public static function fetch_catalogue(): array {
        $groups = [];

        // The template's own variables are parsed from the variable defaults field in the browser,
        // since they change as the author types. We only supply the empty group here.
        $groups[] = [
            'key' => 'template',
            'label' => get_string('variables_group_template', constants::M_COMPONENT),
            'items' => [],
        ];

        $groups[] = [
            'key' => 'system',
            'label' => get_string('variables_group_system', constants::M_COMPONENT),
            'items' => [
                self::make_item('AUTOID', 'variables_autoid'),
                self::make_item('uniqid', 'variables_uniqid'),
                self::make_item('WWWROOT', 'variables_wwwroot'),
                self::make_item('MOODLEPAGEID', 'variables_moodlepageid'),
            ],
        ];

        $groups[] = [
            'key' => 'user',
            'label' => get_string('variables_group_user', constants::M_COMPONENT),
            'items' => self::fetch_user_items(),
        ];

        $groups[] = [
            'key' => 'course',
            'label' => get_string('variables_group_course', constants::M_COMPONENT),
            'items' => self::fetch_course_items(),
        ];

        $groups[] = [
            'key' => 'urlparam',
            'label' => get_string('variables_group_urlparam', constants::M_COMPONENT),
            'items' => self::fetch_urlparam_items(),
        ];

        $groups[] = [
            'key' => 'dataset',
            'label' => get_string('variables_group_dataset', constants::M_COMPONENT),
            'items' => self::fetch_dataset_items(),
        ];

        $groups[] = [
            'key' => 'helpers',
            'label' => get_string('variables_group_helpers', constants::M_COMPONENT),
            'items' => self::fetch_helper_items(),
        ];

        return $groups;
    }

    /**
     * Build one plain variable item.
     *
     * @param string $name The variable name, e.g. "USER:firstname".
     * @param string $descriptionkey Lang string key for the description.
     * @param string|null $label Display label, defaults to the name.
     * @param mixed $descriptionarg Optional argument for the description lang string.
     * @return array The item.
     */
    private static function make_item(string $name, string $descriptionkey, ?string $label = null, $descriptionarg = null): array {
        return [
            'label' => $label ?? $name,
            'name' => $name,
            'description' => get_string($descriptionkey, constants::M_COMPONENT, $descriptionarg),
        ];
    }

    /**
     * User variables: curated user record fields, the picture helpers, then any custom profile
     * fields defined on this site.
     *
     * @return array The items.
     */
    private static function fetch_user_items(): array {
        global $DB;

        $items = [];
        foreach (self::USER_FIELDS as $field) {
            $items[] = self::make_item('USER:' . $field, 'variables_userfield', null, $field);
        }
        $items[] = self::make_item('USER:picurl', 'variables_userpicurl');
        $items[] = self::make_item('USER:pic', 'variables_userpic');

        // Custom profile fields are looked up by shortname in text_filter::fetch_user_props().
        $profilefields = $DB->get_records('user_info_field', null, 'sortorder ASC', 'id, shortname, name');
        foreach ($profilefields as $profilefield) {
            $items[] = self::make_item(
                'USER:' . $profilefield->shortname,
                'variables_userprofilefield',
                null,
                format_string($profilefield->name, true, ['escape' => false])
            );
        }

        return $items;
    }

    /**
     * Course variables: curated course record fields, the context id, then any course custom
     * fields defined on this site.
     *
     * @return array The items.
     */
    private static function fetch_course_items(): array {
        $items = [];
        foreach (self::COURSE_FIELDS as $field) {
            $items[] = self::make_item('COURSE:' . $field, 'variables_coursefield', null, $field);
        }
        $items[] = self::make_item('COURSE:contextid', 'variables_coursecontextid');

        // Course custom fields are looked up by shortname in text_filter::fetch_course_props().
        if (class_exists('\core_customfield\handler')) {
            $handler = \core_customfield\handler::get_handler('core_course', 'course');
            foreach ($handler->get_categories_with_fields() as $category) {
                foreach ($category->get_fields() as $field) {
                    $items[] = self::make_item(
                        'COURSE:' . $field->get('shortname'),
                        'variables_coursecustomfield',
                        null,
                        $field->get_formatted_name(false)
                    );
                }
            }
        }

        return $items;
    }

    /**
     * URL param variables. The common ones are offered by name, plus a generic entry where the
     * author types the param name over the pre-selected placeholder.
     *
     * @return array The items.
     */
    private static function fetch_urlparam_items(): array {
        $items = [];
        $items[] = [
            'label' => 'URLPARAM:...',
            'name' => 'URLPARAM:name',
            'placeholder' => 'name',
            'description' => get_string('variables_urlparamother', constants::M_COMPONENT),
        ];
        foreach (self::COMMON_URLPARAMS as $param) {
            $items[] = self::make_item('URLPARAM:' . $param, 'variables_urlparam', null, $param);
        }
        return $items;
    }

    /**
     * Dataset variables. The dataset is a list of rows, so what an author almost always wants is
     * the section snippet, with the row's own columns available inside it.
     *
     * @return array The items.
     */
    private static function fetch_dataset_items(): array {
        return [
            [
                'label' => '{{#DATASET}} ... {{/DATASET}}',
                'snippet' => "{{#DATASET}}\n%%SEL%%\n{{/DATASET}}",
                'description' => get_string('variables_datasetloop', constants::M_COMPONENT),
            ],
            [
                'label' => '{{^DATASET}} ... {{/DATASET}}',
                'snippet' => "{{^DATASET}}\n%%SEL%%\n{{/DATASET}}",
                'description' => get_string('variables_datasetempty', constants::M_COMPONENT),
            ],
            [
                'label' => '{{columnname}}',
                'snippet' => '{{columnname}}',
                'placeholder' => 'columnname',
                'description' => get_string('variables_datasetcolumn', constants::M_COMPONENT),
            ],
        ];
    }

    /**
     * Moodle's own Mustache helpers, which work in template content because the content is
     * rendered through the standard Moodle Mustache engine.
     *
     * @return array The items.
     */
    private static function fetch_helper_items(): array {
        return [
            [
                'label' => '{{#str}} ... {{/str}}',
                'snippet' => '{{#str}} identifier, component {{/str}}',
                'placeholder' => 'identifier, component',
                'description' => get_string('variables_helperstr', constants::M_COMPONENT),
            ],
            [
                'label' => '{{#cleanstr}} ... {{/cleanstr}}',
                'snippet' => '{{#cleanstr}} identifier, component {{/cleanstr}}',
                'placeholder' => 'identifier, component',
                'description' => get_string('variables_helpercleanstr', constants::M_COMPONENT),
            ],
            [
                'label' => '{{#pix}} ... {{/pix}}',
                'snippet' => '{{#pix}} t/edit, core, alt text {{/pix}}',
                'placeholder' => 't/edit, core, alt text',
                'description' => get_string('variables_helperpix', constants::M_COMPONENT),
            ],
        ];
    }

    /**
     * A plain text summary of the catalogue, for handing to the AI helper so that it generates
     * template code using variables that actually exist.
     *
     * @return string The summary.
     */
    public static function fetch_prompt_summary(): string {
        $lines = [];
        foreach (self::fetch_catalogue() as $group) {
            if (empty($group['items'])) {
                continue;
            }
            $names = [];
            foreach ($group['items'] as $item) {
                // Snippet items use their display label, which is already a readable one liner,
                // rather than the snippet body with its selection token and newlines in it.
                $names[] = isset($item['name']) ? '{{' . $item['name'] . '}}' : $item['label'];
            }
            $lines[] = '- ' . $group['label'] . ': ' . implode(', ', $names);
        }
        return implode(PHP_EOL, $lines);
    }
}
